<?php

namespace Drupal\menu_tab_access\Plugin\views\argument_validator;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\argument_validator\ArgumentValidatorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates the Taxonomy Choice argument.
 *
 * @ViewsArgumentValidator(
 *   id = "menu_tab_access.taxonomy_choice_argument_validator",
 *   title = @Translation("Taxonomy Choice Argument Validator"),
 *   help = @Translation("Validates that argument has field_model with the selected taxonomy terms.")
 * )
 */
class TaxonomyChoiceArgumentValidator extends ArgumentValidatorPluginBase
{
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * @inheritDoc
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $manager)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $manager;
  }

  /**
   * @inheritDoc
   */
  protected function defineOptions()
  {
    $options = parent::defineOptions();
    $options['taxonomy_access'] = ['default' => [
      'vocabulary_id' => '',
      'term_ids' => [],
      'invert_terms' => FALSE,
    ]];
    return $options;
  }

  /**
   * @inheritDoc
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state)
  {
    parent::buildOptionsForm($form, $form_state);

    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $vocabularies = array_map(function ($vocabulary) {
      return $vocabulary->label();
    }, $vocabularies);
    $vocabularies = array_merge(['' => $this->t('Select one')], $vocabularies);

    $term_options = [];
    $chosen_vocab = NestedArray::getValue($form_state->getUserInput(), ['validate_options', 'taxonomy_access', 'vocabulary_id'])
      ?? $this->options['taxonomy_access']['vocabulary_id'];

    $chosen_terms = $this->options['taxonomy_access']['term_ids'];
    if (!empty($chosen_vocab)) {
      $chosen_terms = NestedArray::getValue($form_state->getUserInput(), ['validate_options', 'taxonomy_access', 'term_ids'])
        ?? $this->options['taxonomy_access']['term_ids'];

      if ($chosen_vocab !== $this->options['taxonomy_access']['vocabulary_id']) {
        // If we changed the vocabulary, reset the terms.
        $chosen_terms = [];
      }
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($chosen_vocab);
      foreach ($terms as $term) {
        $term_options[$term->tid] = $term->name;
      }
    }

    $chosen_not = NestedArray::getValue($form_state->getUserInput(), ['validate_options', 'invert_terms']) ??
      $this->options['taxonomy_access']['invert_terms'];

    $form['taxonomy_access'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Taxonomy Access'),
      'vocabulary_id' => [
        '#type' => 'select',
        '#title' => $this->t('Vocabulary'),
        '#options' => $vocabularies,
        '#default_value' => $chosen_vocab,
      ],
      'submit_vocabulary' => [
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
        '#name' => 'submit_vocabulary',
      ]
    ];
    views_ui_add_ajax_trigger($form['taxonomy_access'], 'vocabulary_id', [
      'options',
      'validate',
      'options',
      'menu_tab_access.taxonomy_choice_argument_validator',
      'taxonomy_access',
      'term_ids'
    ]);
    $form['taxonomy_access']['term_ids'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Terms'),
        '#options' => $term_options,
        '#default_value' => $chosen_terms,
    ];
    $form['invert_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invert terms match'),
      '#description' => $this->t('If checked, access will be granted if the object does not have any of the selected terms.'),
      '#default_value' => $chosen_not,
    ];
    // Changing the vocabulary_id dropdown updates $form['taxonomy_access'] via AJAX in the views UI.
  }

  /**
   * @inheritDoc
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state)
  {
    if (!empty($form_state->getUserInput()['taxonomy_access']['vocabulary_id'])){
      $tids = array_filter($form_state->getUserInput()['taxonomy_access']['term_ids']);
      if (empty($tids)) {
        $form->setErrorByName('taxonomy_access][term_ids', $this->t('You must select at least one term.'));
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state, &$options = [])
  {
    if (!empty($options['taxonomy_access']['vocabulary_id']) && empty($options['taxonomy_access']['term_ids'])) {
      $options['taxonomy_access']['term_ids'] = array_filter($options['taxonomy_access']['term_ids']);
    }
  }

  /**
   * @inheritDoc
   */
  public function validateArgument($arg)
  {
    if (isset($this->options['taxonomy_access']) && isset($arg)) {
      try {
        if (is_numeric($arg)) {
          $node = $this->entityTypeManager->getStorage('node')->load($arg);
        } else {
          $node = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $arg]);
          $node = reset($node);
        }
        $terms = $node->get('field_model')->referencedEntities();
        $chosen_terms = $this->options['taxonomy_access']['term_ids'];
        $invert = $this->options['taxonomy_access']['invert_terms'];
        $match = false;
        foreach ($terms as $term) {
          if (in_array($term->id(), $chosen_terms)) {
            $match = true;
            break;
          }
        }
        if ($invert) {
          $match = !$match;
        }
        return $match;
      } catch (\Exception $e) {
        \Drupal::logger('menu_tab_access')->error($e->getMessage());
      }
    }
    return false;
  }
}
