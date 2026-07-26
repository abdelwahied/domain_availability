<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\domain_availability\Service\RegistrationMailer;

/**
 * The registration emails, as Drupal 11.1 and later want them declared.
 *
 * The module still ships the procedural hook_mail() in its .module file for
 * Drupal 10.3, where this class is never discovered. That function carries
 * #[LegacyHook] and delegates back here, so both Drupal versions send byte-for
 * byte the same email.
 *
 * Being a class also means the date formatter and the translator arrive by
 * injection rather than through \Drupal::service() lookups, which is what the
 * conversion is for.
 *
 * @internal
 *   A hook implementation.
 */
final class DomainAvailabilityMail {

  use StringTranslationTrait;

  /**
   * Constructs a DomainAvailabilityMail.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats the submission time in the recipient's own language.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The translator, used for the pluralised registration period.
   */
  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Implements hook_mail().
   *
   * Builds the confirmation and administrator-notification emails for a new
   * registration request. Both share the same facts (reference, domain,
   * company, commercial registration, time); only the framing differs.
   *
   * @param string $key
   *   The message key.
   * @param array<string, mixed> $message
   *   The message, altered in place.
   * @param array<string, mixed> $params
   *   The parameters the mailer passed.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    // Every string is translated into the recipient's language, not the
    // language of whoever happened to trigger the send.
    $options = ['langcode' => $message['langcode']];
    $submitted = $this->dateFormatter->format((int) ($params['submitted'] ?? 0), 'long');

    $lines = [
      $this->t('Reference: @ref', ['@ref' => $params['reference'] ?? ''], $options),
      $this->t('Domain: @domain', ['@domain' => $params['domain'] ?? ''], $options),
      $this->formatPlural((int) ($params['registration_years'] ?? 1), 'Registration period: 1 year', 'Registration period: @count years', [], $options),
      $this->t('Applicant type: @type', [
        '@type' => ($params['applicant_type'] ?? 'company') === 'individual'
          ? $this->t('Individual', [], $options)
          : $this->t('Company', [], $options),
      ], $options),
      $this->t('Company (Arabic): @c', ['@c' => $params['company_ar'] ?? ''], $options),
      $this->t('Company (English): @c', ['@c' => $params['company_en'] ?? ''], $options),
      $this->t('Commercial registration: @cr', ['@cr' => $params['commercial_registration'] ?? ''], $options),
      $this->t('Submitted: @time', ['@time' => $submitted], $options),
    ];

    switch ($key) {
      case RegistrationMailer::KEY_CONFIRMATION:
        $message['subject'] = $this->t('Your domain registration request (@ref)', ['@ref' => $params['reference'] ?? ''], $options);
        $message['body'][] = $this->t('Thank you. Your domain registration request has been received and is pending review.', [], $options);
        $message['body'][] = implode("\n", array_map('strval', $lines));
        $message['body'][] = $this->t('Our team will contact you after reviewing the request.', [], $options);
        break;

      case RegistrationMailer::KEY_ADMIN_NOTIFICATION:
        $message['subject'] = $this->t('New domain registration request: @domain (@ref)', [
          '@domain' => $params['domain'] ?? '',
          '@ref' => $params['reference'] ?? '',
        ], $options);
        $message['body'][] = $this->t('A new domain registration request has been submitted.', [], $options);
        $message['body'][] = implode("\n", array_map('strval', $lines));
        break;
    }
  }

}
