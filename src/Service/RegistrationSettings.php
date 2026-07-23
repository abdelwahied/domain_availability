<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\domain_availability\Utility\Tld;

/**
 * Typed, read-only access to domain_availability.registration.
 *
 * A separate config object from the lookup settings on purpose: the
 * registration feature is optional and self-contained, so its configuration
 * lives apart and the existing settings never had to change to accommodate it.
 * Values are read through the factory on every call, so a change on the
 * settings form takes effect at once.
 *
 * @api
 *   Public and stable since 1.0.0. Typed, read-only access to
 *   `domain_availability.registration`.
 */
final class RegistrationSettings {

  public const CONFIG_NAME = 'domain_availability.registration';

  /**
   * Constructs a RegistrationSettings.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Whether the registration-request feature is enabled at all.
   *
   * @return bool
   *   TRUE when enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->config()->get('enabled');
  }

  /**
   * TLDs that accept registration requests.
   *
   * @return array<int, string>
   *   Normalised, dot-less TLDs.
   */
  public function allowedTlds(): array {
    $value = $this->config()->get('allowed_tlds');

    return Tld::normaliseList(is_array($value) ? $value : []);
  }

  /**
   * Whether a domain's TLD may be registered through this feature.
   *
   * @param string $domain
   *   A fully qualified domain, e.g. `neixora.sa`.
   *
   * @return bool
   *   TRUE when the feature is enabled and the TLD is allowed.
   */
  public function allowsDomain(string $domain): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }

    $allowed = $this->allowedTlds();

    // An empty allow-list means "every TLD"; the shipped default is `.sa` only.
    return $allowed === [] || in_array(Tld::fromDomain($domain), $allowed, TRUE);
  }

  /**
   * The maximum upload size, in bytes.
   *
   * @return int
   *   The size in bytes.
   */
  public function maxUploadBytes(): int {
    $mb = (int) $this->config()->get('max_upload_size');

    return ($mb > 0 ? $mb : 10) * 1024 * 1024;
  }

  /**
   * The maximum upload size, in megabytes.
   *
   * @return int
   *   The size in megabytes.
   */
  public function maxUploadMegabytes(): int {
    $mb = (int) $this->config()->get('max_upload_size');

    return $mb > 0 ? $mb : 10;
  }

  /**
   * The allowed file extensions, space separated.
   *
   * @return string
   *   The extensions, e.g. `pdf`.
   */
  public function allowedExtensions(): string {
    $value = trim((string) $this->config()->get('allowed_extensions'));

    return $value !== '' ? $value : 'pdf';
  }

  /**
   * The administrator notification recipients.
   *
   * @return array<int, string>
   *   Valid email addresses.
   */
  public function adminEmails(): array {
    $raw = (string) $this->config()->get('admin_emails');

    $emails = array_map('trim', preg_split('/[,\s]+/', $raw) ?: []);

    return array_values(array_filter(
      $emails,
      static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

  /**
   * The window within which a repeated request for a domain is a duplicate.
   *
   * @return int
   *   The window in seconds. Zero disables duplicate detection.
   */
  public function duplicateWindowSeconds(): int {
    $hours = (int) $this->config()->get('duplicate_window_hours');

    return max(0, $hours) * 3600;
  }

  /**
   * The immutable configuration object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config.
   */
  private function config(): object {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
