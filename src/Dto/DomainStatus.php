<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

/**
 * The three outcomes a lookup can produce.
 *
 * `Unknown` is a first class result, not a failure: it is returned whenever no
 * provider could answer authoritatively, which is preferable to guessing.
 *
 * @api
 *   Public and stable since 1.0.0. The three outcomes. Cases will not change
 *   within a major version.
 */
enum DomainStatus: string {

  case Available = 'available';
  case Registered = 'registered';
  case Unknown = 'unknown';

  /**
   * Availability as a tri-state.
   *
   * @return bool|null
   *   TRUE when available, FALSE when registered, NULL when undetermined.
   */
  public function toAvailability(): ?bool {
    return match ($this) {
      self::Available => TRUE,
      self::Registered => FALSE,
      self::Unknown => NULL,
    };
  }

  /**
   * Whether this is a definite answer.
   *
   * @return bool
   *   TRUE for available or registered, FALSE for unknown.
   */
  public function isConclusive(): bool {
    return $this !== self::Unknown;
  }

}
