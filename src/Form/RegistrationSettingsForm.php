<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\domain_availability\Service\RegistrationSettings;
use Drupal\domain_availability\Utility\Tld;

/**
 * Settings for the domain registration request feature.
 *
 * Plain configuration, so it exports with the rest of the site's config and
 * deploys the same way. Kept separate from the lookup settings form: the two
 * features are independent, and a site can enable lookups without ever turning
 * this on.
 *
 * @internal
 *   A form; the route and the configuration are the contract.
 */
final class RegistrationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_registration_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [RegistrationSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $name = RegistrationSettings::CONFIG_NAME;

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable registration requests'),
      '#description' => $this->t('When on, an available result shows a "Register this domain" button that opens the request form.'),
      '#config_target' => $name . ':enabled',
    ];

    $form['allowed_tlds'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed TLDs'),
      '#description' => $this->t('Comma separated, with or without a leading dot. Only available domains on these TLDs show the button. Leave empty to allow every TLD. Default: .sa'),
      // Stored as a list, edited as a comma separated string, so the two
      // conversions travel with the element rather than living in submitForm().
      '#config_target' => new ConfigTarget(
        $name,
        'allowed_tlds',
        [self::class, 'tldsToString'],
        [self::class, 'tldsFromString'],
      ),
    ];

    $form['max_upload_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum upload size'),
      '#field_suffix' => $this->t('MB'),
      '#min' => 1,
      '#max' => 50,
      '#config_target' => new ConfigTarget(
        $name,
        'max_upload_size',
        [self::class, 'uploadSizeOrDefault'],
        [self::class, 'toInt'],
      ),
    ];

    $form['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#description' => $this->t('Space separated, without dots. The specification requires PDF only.'),
      '#config_target' => new ConfigTarget(
        $name,
        'allowed_extensions',
        [self::class, 'extensionsOrDefault'],
        [self::class, 'extensionsOrDefault'],
      ),
    ];

    $form['admin_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Administrator notification emails'),
      '#description' => $this->t('One or more addresses (comma or newline separated) that receive a notification for every new request. Leave empty to send only the customer confirmation.'),
      '#config_target' => new ConfigTarget($name, 'admin_emails', NULL, [self::class, 'trimValue']),
      '#rows' => 3,
    ];

    $form['duplicate_window_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Duplicate submission window'),
      '#field_suffix' => $this->t('hours'),
      '#description' => $this->t('A second request for the same domain within this window is rejected, unless the earlier one was rejected or cancelled. Set to 0 to allow duplicates.'),
      '#min' => 0,
      '#max' => 8760,
      '#config_target' => new ConfigTarget(
        $name,
        'duplicate_window_hours',
        [self::class, 'windowOrDefault'],
        [self::class, 'toInt'],
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Renders the stored TLD list as the comma separated string the field shows.
   *
   * @param mixed $value
   *   The stored list.
   *
   * @return string
   *   The field value.
   */
  public static function tldsToString(mixed $value): string {
    return implode(', ', array_map(
      static fn (string $tld): string => Tld::withDot($tld),
      Tld::normaliseList(is_array($value) ? $value : []),
    ));
  }

  /**
   * Parses the field back into the stored list.
   *
   * @param mixed $value
   *   The submitted string.
   *
   * @return array<int, string>
   *   Normalised, dot-less TLDs.
   */
  public static function tldsFromString(mixed $value): array {
    $parts = preg_split('/[\s,]+/', (string) $value) ?: [];

    return Tld::normaliseList(array_values(array_filter($parts, static fn (string $item): bool => trim($item) !== '')));
  }

  /**
   * The stored upload size, or the shipped default when it is missing or zero.
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return int
   *   A usable size in MB.
   */
  public static function uploadSizeOrDefault(mixed $value): int {
    return ((int) $value) ?: 10;
  }

  /**
   * The stored extension list, or the only one the specification allows.
   *
   * Used in both directions: an empty stored value and an emptied field both
   * mean "pdf", which is what the form has always done.
   *
   * @param mixed $value
   *   The stored or submitted value.
   *
   * @return string
   *   The extension list.
   */
  public static function extensionsOrDefault(mixed $value): string {
    return trim((string) $value) ?: 'pdf';
  }

  /**
   * The stored duplicate window, or the shipped default when it is absent.
   *
   * Distinct from the upload size: 0 is a meaningful value here — it turns the
   * duplicate check off — so only a missing key falls back.
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return int
   *   The window in hours.
   */
  public static function windowOrDefault(mixed $value): int {
    return (int) ($value ?? 24);
  }

  /**
   * Casts a submitted value to an integer.
   *
   * A method rather than `intval`, because ConfigTarget calls a toConfig
   * callable as ($value, $form_state) and PHP's internal functions reject the
   * second argument. A user-defined function simply ignores it.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return int
   *   The value as an integer.
   */
  public static function toInt(mixed $value): int {
    return (int) $value;
  }

  /**
   * Trims a submitted value, for the same reason toInt() exists.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return string
   *   The trimmed value.
   */
  public static function trimValue(mixed $value): string {
    return trim((string) $value);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ($this->parseTlds((string) $form_state->getValue('allowed_tlds')) as $tld) {
      if (preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $tld) !== 1) {
        $form_state->setErrorByName('allowed_tlds', $this->t('"@tld" is not a valid TLD.', ['@tld' => $tld]));
      }
    }

    foreach ($this->parseEmails((string) $form_state->getValue('admin_emails')) as $email) {
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('admin_emails', $this->t('"@email" is not a valid email address.', ['@email' => $email]));
      }
    }
  }

  /**
   * Parses a comma separated TLD list.
   *
   * @param string $value
   *   The raw field value.
   *
   * @return array<int, string>
   *   Normalised, dot-less TLDs.
   */
  private function parseTlds(string $value): array {
    return self::tldsFromString($value);
  }

  /**
   * Parses an email list.
   *
   * @param string $value
   *   The raw field value.
   *
   * @return array<int, string>
   *   The trimmed, non-empty addresses.
   */
  private function parseEmails(string $value): array {
    $parts = array_map('trim', preg_split('/[,\s]+/', $value) ?: []);

    return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
  }

}
