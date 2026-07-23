<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * The search page.
 *
 * As thin as a controller gets: the page is the render element, and the
 * element builds the form. Nothing here to unit test, which is the point —
 * every surface (page, block, Twig function) resolves to the same element, so
 * they cannot drift apart.
 *
 * @internal
 *   A controller; the route is the contract.
 */
final class DomainSearchController extends ControllerBase {

  /**
   * Builds the search page.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function page(): array {
    return [
      '#type' => 'domain_availability_search',
    ];
  }

}
