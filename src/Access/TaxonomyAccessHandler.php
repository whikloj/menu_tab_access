<?php
namespace Drupal\menu_tab_access\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

class TaxonomyAccessHandler implements ContainerInjectionInterface
{
  private EntityTypeManagerInterface $entityTypeManager;
  public function __construct(EntityTypeManagerInterface $entityTypeManager)
  {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager')
    );
  }
  /**
   * @inheritDoc
   */
  public function access(Route $route, AccountInterface $account, array $options)
  {
    if (!isset($options['taxonomy_access'])) {
      return AccessResultNeutral::neutral();
    }
    $request = \Drupal::request();
    $node = $request->get('node');
    if ($node) {
      try {
        if (is_numeric($node)) {
          $node = $this->entityTypeManager->getStorage('node')->load($node);
        }
        $terms = $node->get('field_model')->referencedEntities();
        $chosen_terms = $options['taxonomy_access']['term_ids'];
        $invert = $options['taxonomy_access']['invert_terms'];
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
        return AccessResult::allowedIf($match);
      } catch (\Exception $e) {
        \Drupal::logger('menu_tab_access')->error($e->getMessage());
      }
    }
    return AccessResultNeutral::neutral();
  }
}
