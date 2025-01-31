<?php

namespace Drupal\menu_tab_access\Plugin\views\access;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\taxonomy\VocabularyStorageInterface;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 *
 * @ViewsAccess(
 *   id = "menu_tab_access.taxonomy_choice_access",
 *   title = @Translation("Taxonomy Choice Access"),
 *   help = @Translation("Access will be granted to users that have access to the content and the object has one of the selected taxonomy terms.")
 * )
 */
class TaxonomyChoiceAccessHandler extends AccessPluginBase implements ContainerFactoryPluginInterface
{
  /**
   * {@inheritdoc}
   */
  protected $usesOptions = TRUE;

  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * @param array $configuration The configuration.
   * @param string $plugin_id The plugin_id.
   * @param mixed $plugin_definition The plugin implementation definition.
   * @param EntityTypeManagerInterface $entityTypeManager The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
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
  public function access(AccountInterface $account)
  {
    $node = \Drupal::request()->get('node');
    if ($node) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node);
      $terms = $node->get('field_tags')->referencedEntities();
      $chosen_terms = $this->options['taxonomy_access']['term_ids'];
      $invert = $this->options['taxonomy_access']['invert_terms'];
      $match = FALSE;
      foreach ($terms as $term) {
        if (in_array($term->id(), $chosen_terms)) {
          $match = TRUE;
          break;
        }
      }
      if ($invert) {
        $match = !$match;
      }
      return $match;
    }
    return FALSE;
  }

  /**
   * @inheritDoc
   */
  public function alterRouteDefinition(Route $route)
  {
    $route->setRequirement('_custom_access', 'menu_tab_access.taxonomy_access_handler');
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
  public function summaryTitle()
  {
    if (!empty($this->options['taxonomy_access']['term_ids']) && !empty($this->options['taxonomy_access']['vocabulary_id'])) {
      $vocab = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($this->options['taxonomy_access']['vocabulary_id']);
      return $vocab->label();
    }
    return "No terms selected";
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

    $chosen_vocab = NestedArray::getValue($form_state->getUserInput(), ['access_options', 'taxonomy_access', 'vocabulary_id'])
      ?? $this->options['taxonomy_access']['vocabulary_id'];

    $chosen_terms = $this->options['taxonomy_access']['term_ids'];
    if (!empty($chosen_vocab)) {
      $chosen_terms = NestedArray::getValue($form_state->getUserInput(), ['access_options', 'taxonomy_access', 'term_ids'])
        ?? $this->options['taxonomy_access']['term_ids'];

      if ($chosen_vocab !== $this->options['taxonomy_access']['vocabulary_id']) {
        // If we changed the vocabulary, reset the terms.
        $chosen_terms = [];
      }
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($chosen_vocab);
      $term_options = [];
      foreach ($terms as $term) {
        $term_options[$term->tid] = $term->name;
      }
    }

    $chosen_not = NestedArray::getValue($form_state->getUserInput(), ['access_options', 'invert_terms']) ??
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
    ];
    $form['taxonomy_access']['term_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Terms'),
      '#options' => $term_options ?? [],
      '#default_value' => $chosen_terms,
    ];
    $form['taxonomy_access']['invert_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invert terms match'),
      '#description' => $this->t('If checked, access will be granted if the object does not have any of the selected terms.'),
      '#default_value' => $chosen_not,
    ];
    // Changing the vocabulary_id dropdown updates $form['taxonomy_access'] via AJAX in the views UI.
    views_ui_add_ajax_trigger($form['taxonomy_access'], 'vocabulary_id', ['options', 'access_options', 'taxonomy_access', 'term_ids']);
  }

  /**
   * @inheritDoc
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state)
  {
    parent::validateOptionsForm($form, $form_state);
    $values = $form_state->getValue(['access_options', 'taxonomy_access']);
    if (!empty($values['vocabulary_id'])) {
      if (empty(array_filter($values['term_ids']))) {
        $form_state->setErrorByName('term_ids', $this->t('You must select at least one term.'));
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state)
  {
    $values = &$form_state->getValue(['access_options', 'taxonomy_access', 'term_ids']);
    $values = array_filter($values);
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [
      'url.path',
      'views'
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return [];
  }
}
