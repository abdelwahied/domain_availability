<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Validator;

use Drupal\domain_availability\Exception\ValidationException;

/**
 * Validates a bare domain label against the LDH rule (RFC 1035 / RFC 5891).
 *
 * Runs after DomainSanitizer: by this point the value must already be a single
 * label, so anything still illegal is a genuine input error.
 *
 * @internal
 *   Validates a submitted label for this module's own forms and endpoint.
 */
final class DomainValidator {
  public const MIN_LENGTH = 1;
  public const MAX_LENGTH = 63;

  /**
   * Validates a bare domain label.
   *
   * @param string $label
   *   The sanitised label to validate.
   *
   * @return string
   *   The same label, unchanged, when it is valid.
   *
   * @throws \Drupal\domain_availability\Exception\ValidationException
   *   When the label is unusable.
   */
  public function validate(string $label): string {
    if ($label === '') {
      throw new ValidationException(
            'A domain name is required.',
            ['domain' => 'Enter a domain name, for example: neixora'],
        );
    }

    $length = strlen($label);

    if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
      throw new ValidationException(
            'The domain name has an invalid length.',
            [
              'domain' => sprintf(
                'The name must be between %d and %d characters.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
              ),
            ],
        );
    }

    if (preg_match('/^[a-z0-9\-]+$/', $label) !== 1) {
      throw new ValidationException(
            'The domain name contains invalid characters.',
            ['domain' => 'Only letters, numbers and hyphens are allowed.'],
        );
    }

    if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
      throw new ValidationException(
            'The domain name cannot start or end with a hyphen.',
            ['domain' => 'Remove the leading or trailing hyphen.'],
        );
    }

    // Reserved by IDNA for punycode; only valid when it really is an
    // IDN label produced by the sanitizer.
    if (
          str_starts_with($label, 'xn--') === FALSE && str_contains($label, '--')
          && preg_match('/^..--/', $label) === 1
      ) {
      throw new ValidationException(
            'The domain name uses a reserved format.',
            ['domain' => 'The third and fourth characters cannot both be hyphens.'],
        );
    }

    return $label;
  }

}
