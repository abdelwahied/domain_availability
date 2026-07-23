<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Exception;

/**
 * Base class for every exception thrown by this module.
 *
 * Carries the HTTP status and a machine readable code so the API controller can
 * build a response without inspecting concrete classes, and a public message
 * that is always safe to expose to clients.
 *
 * @api
 *   Public and stable since 1.0.0. The base every exception this module throws
 *   extends; catch this to catch them all.
 */
abstract class DomainAvailabilityException extends \RuntimeException {

  /**
   * The HTTP status the API should answer with.
   */
  public function statusCode(): int {
    return 500;
  }

  /**
   * A stable, machine readable error identifier for API consumers.
   */
  public function errorCode(): string {
    return 'internal_error';
  }

  /**
   * A message safe for public exposure.
   *
   * Subclasses whose message may carry internal details must override this
   * with a generic sentence.
   */
  public function publicMessage(): string {
    return $this->getMessage();
  }

  /**
   * Extra, non-sensitive context merged into the JSON error payload.
   *
   * @return array<string, mixed>
   *   The context.
   */
  public function context(): array {
    return [];
  }

}
