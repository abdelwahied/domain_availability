<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Utility;

/**
 * Monotonic-ish elapsed time helper for the `took_ms` field.
 *
 * @internal
 *   A measurement helper.
 */
final class Timer {

  private function __construct(private readonly float $startedAt) {
  }

  /**
   * Start.
   */
  public static function start(): self {
    return new self(microtime(TRUE));
  }

  /**
   * Elapsed ms.
   */
  public function elapsedMs(): int {
    return (int) round((microtime(TRUE) - $this->startedAt) * 1000);
  }

}
