<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Exception;

/**
 * Thrown when the module is misconfigured.
 *
 * Details stay server-side: a misconfiguration message names services and
 * providers, which is useful in a log and noise (or worse) to a client.
 *
 * @api
 *   Public and stable since 1.0.0. Thrown when configuration is unusable.
 */
final class ConfigurationException extends DomainAvailabilityException {

  /**
   * {@inheritdoc}
   */
  public function errorCode(): string {
    return 'configuration_error';
  }

  /**
   * {@inheritdoc}
   */
  public function publicMessage(): string {
    return 'The service is misconfigured. Please try again later.';
  }

}
