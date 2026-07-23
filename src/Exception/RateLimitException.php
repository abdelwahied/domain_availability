<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Exception;

/**
 * Thrown when a client exceeds its request quota.
 *
 * @api
 *   Public and stable since 1.0.0. Thrown when a caller exceeds the rate limit.
 */
final class RateLimitException extends DomainAvailabilityException {

  /**
   * Constructs a RateLimitException.
   *
   * @param int $retryAfter
   *   Seconds the client should wait before retrying.
   */
  public function __construct(private readonly int $retryAfter) {
    parent::__construct('Too many requests. Please slow down.');
  }

  /**
   * {@inheritdoc}
   */
  public function statusCode(): int {
    return 429;
  }

  /**
   * {@inheritdoc}
   */
  public function errorCode(): string {
    return 'rate_limited';
  }

  /**
   * Seconds the client should wait before retrying.
   */
  public function retryAfter(): int {
    return $this->retryAfter;
  }

  /**
   * {@inheritdoc}
   */
  public function context(): array {
    return ['retry_after' => $this->retryAfter];
  }

}
