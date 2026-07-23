<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Form\ConfigFormBase;
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
    $config = $this->config(RegistrationSettings::CONFIG_NAME);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable registration requests'),
      '#description' => $this->t('When on, an available result shows a "Register this domain" button that opens the request form.'),
      '#default_value' => $config->get('enabled'),
    ];

    $allowed = is_array($config->get('allowed_tlds')) ? $config->get('allowed_tlds') : [];
    $form['allowed_tlds'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed TLDs'),
      '#description' => $this->t('Comma separated, with or without a leading dot. Only available domains on these TLDs show the button. Leave empty to allow every TLD. Default: .sa'),
      '#default_value' => implode(', ', array_map(
        static fn (string $tld): string => Tld::withDot($tld),
        Tld::normaliseList($allowed),
      )),
    ];

    $form['max_upload_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum upload size'),
      '#field_suffix' => $this->t('MB'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => $config->get('max_upload_size') ?: 10,
    ];

    $form['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#description' => $this->t('Space separated, without dots. The specification requires PDF only.'),
      '#default_value' => $config->get('allowed_extensions') ?: 'pdf',
    ];

    $form['admin_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Administrator notification emails'),
      '#description' => $this->t('One or more addresses (comma or newline separated) that receive a notification for every new request. Leave empty to send only the customer confirmation.'),
      '#default_value' => $config->get('admin_emails'),
      '#rows' => 3,
    ];

    $form['duplicate_window_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Duplicate submission window'),
      '#field_suffix' => $this->t('hours'),
      '#description' => $this->t('A second request for the same domain within this window is rejected, unless the earlier one was rejected or cancelled. Set to 0 to allow duplicates.'),
      '#min' => 0,
      '#max' => 8760,
      '#default_value' => $config->get('duplicate_window_hours') ?? 24,
    ];

    return parent::buildForm($form, $form_state);
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(RegistrationSettings::CONFIG_NAME)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('allowed_tlds', $this->parseTlds((string) $form_state->getValue('allowed_tlds')))
      ->set('max_upload_size', (int) $form_state->getValue('max_upload_size'))
      ->set('allowed_extensions', trim((string) $form_state->getValue('allowed_extensions')) ?: 'pdf')
      ->set('admin_emails', trim((string) $form_state->getValue('admin_emails')))
      ->set('duplicate_window_hours', (int) $form_state->getValue('duplicate_window_hours'))
      ->save();

    parent::submitForm($form, $form_state);
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
    $parts = preg_split('/[\s,]+/', $value) ?: [];

    return Tld::normaliseList(array_values(array_filter($parts, static fn (string $item): bool => trim($item) !== '')));
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
