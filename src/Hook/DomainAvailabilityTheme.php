<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * The module's theme hooks, as Drupal 11.1 and later want them declared.
 *
 * The module still ships the procedural hook_theme() in its .module file for
 * Drupal 10.3, where this class is never discovered. That function carries
 * #[LegacyHook] and delegates back here, so the registry is defined once.
 *
 * The preprocessing itself lives in DomainAvailabilityThemePreprocess, not
 * here: the registry is a declaration, and the variables are a computation.
 *
 * @internal
 *   A hook implementation.
 */
final class DomainAvailabilityTheme {

  /**
   * Implements hook_theme().
   *
   * @return array<string, mixed>
   *   The theme registry entries.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'domain_availability_results' => [
        'variables' => [
          'report' => NULL,
          'results' => [],
          'summary' => [],
        ],
      ],
      'domain_availability_error' => [
        'variables' => [
          'message' => '',
        ],
      ],
    ];
  }

}
