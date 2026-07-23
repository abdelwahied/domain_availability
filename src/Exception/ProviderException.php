<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Exception;

/**
 * Thrown when a lookup provider fails.
 *
 * Never surfaced verbatim to clients: it may carry registry hostnames and
 * transport level details that belong in the log only.
 *
 * @api
 *   Public and stable since 1.0.0. Thrown when a provider cannot answer.
 */
final class ProviderException extends DomainAvailabilityException {

  /**
   * Constructs a ProviderException.
   *
   * @param string $provider
   *   The provider that failed.
   * @param string $message
   *   The internal message, for the log only.
   * @param \Throwable|null $previous
   *   The previous exception, if any.
   */
  public function __construct(
    private readonly string $provider,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * {@inheritdoc}
   */
  public function statusCode(): int {
    return 502;
  }

  /**
   * {@inheritdoc}
   */
  public function errorCode(): string {
    return 'provider_error';
  }

  /**
   * {@inheritdoc}
   */
  public function publicMessage(): string {
    return 'The domain lookup service is temporarily unavailable.';
  }

  /**
   * The provider that failed.
   *
   * @return string
   *   The provider name.
   */
  public function provider(): string {
    return $this->provider;
  }

}
