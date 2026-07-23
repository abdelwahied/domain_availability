<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Renders the domain search component.
 *
 * The one place the component is assembled. The page controller, the block
 * plugin and the {{ domain_availability_search() }} Twig function all resolve
 * to this element, so there is exactly one definition of what the component is.
 * Usage in a render array:
 *
 * @code
 *   $build['search'] = ['#type' => 'domain_availability_search'];
 *
 * @endcode
 *
 * @api
 *   Public and stable since 1.0.0. The render element id
 *   `domain_availability_search` is the contract.
 */
#[RenderElement('domain_availability_search')]
final class DomainAvailabilitySearch extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#pre_render' => [
        [static::class, 'preRenderSearch'],
      ],
      // The form's state is per-user and its results are per-request, so the
      // component must never land in a shared render cache entry.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds the form.
   *
   * @param array<string, mixed> $element
   *   The element.
   *
   * @return array<string, mixed>
   *   The processed element.
   */
  public static function preRenderSearch(array $element): array {
    // \Drupal::formBuilder() rather than injection: render element plugins are
    // instantiated by the element info manager, which passes no container, so
    // there is no constructor to inject into. This is the documented core
    // pattern for elements, and the one case in this module where the service
    // locator is unavoidable.
    $element['form'] = \Drupal::formBuilder()->getForm('Drupal\domain_availability\Form\DomainSearchForm');
    $element['#attached']['library'][] = 'domain_availability/search';

    return $element;
  }

}
