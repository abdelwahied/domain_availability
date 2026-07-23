<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Exception;

/**
 * Thrown when user supplied input fails validation.
 *
 * @api
 *   Public and stable since 1.0.0. Thrown when a submitted label is not usable.
 */
final class ValidationException extends DomainAvailabilityException {

  /**
   * Constructs a ValidationException.
   *
   * @param string $message
   *   The internal message.
   * @param array<string, string> $errors
   *   Field name => human readable reason.
   */
  public function __construct(string $message, private readonly array $errors = []) {
    parent::__construct($message);
  }

  /**
   * {@inheritdoc}
   */
  public function statusCode(): int {
    return 422;
  }

  /**
   * {@inheritdoc}
   */
  public function errorCode(): string {
    return 'validation_error';
  }

  /**
   * {@inheritdoc}
   */
  public function context(): array {
    return $this->errors === [] ? [] : ['errors' => $this->errors];
  }

  /**
   * The per-field validation errors.
   *
   * @return array<string, string>
   *   Field name => human readable reason.
   */
  public function errors(): array {
    return $this->errors;
  }

}
