<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the two emails a new registration request triggers.
 *
 * The confirmation reassures the visitor their request arrived; the
 * notification tells administrators there is something to review. Both go
 * through Drupal's Mail API, so a site's configured transport, spool and
 * translation all apply, and a test run can capture them without a real SMTP
 * server.
 *
 * A mail failure never aborts a submission: the request is already saved, and
 * losing an email is a smaller problem than losing the record.
 *
 * @internal
 *   Implementation detail of the registration workflow.
 */
final class RegistrationMailer {

  public const KEY_CONFIRMATION = 'registration_confirmation';
  public const KEY_ADMIN_NOTIFICATION = 'registration_admin_notification';

  /**
   * Constructs a RegistrationMailer.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\domain_availability\Service\RegistrationSettings $settings
   *   The registration settings.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager, for the recipient's default language.
   */
  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly RegistrationSettings $settings,
    private readonly LoggerInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Sends both emails for a freshly created request.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request
   *   The saved request.
   * @param string|null $customerEmail
   *   The customer address, when the submitter is authenticated or supplied
   *   one; NULL sends only the administrator notification.
   */
  public function sendForNewRequest(DomainRegistrationRequestInterface $request, ?string $customerEmail): void {
    $params = $this->buildParams($request);

    if ($customerEmail !== NULL && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) !== FALSE) {
      $this->send(self::KEY_CONFIRMATION, $customerEmail, $params);
    }

    foreach ($this->settings->adminEmails() as $adminEmail) {
      $this->send(self::KEY_ADMIN_NOTIFICATION, $adminEmail, $params);
    }
  }

  /**
   * Builds the shared template parameters for one request.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request
   *   The request.
   *
   * @return array<string, mixed>
   *   The parameters consumed by hook_mail().
   */
  private function buildParams(DomainRegistrationRequestInterface $request): array {
    return [
      'reference' => $request->getReferenceNumber(),
      'domain' => $request->getDomain(),
      'registration_years' => (int) $request->get('registration_years')->value,
      'applicant_type' => $request->isIndividual() ? 'individual' : 'company',
      'company_ar' => (string) $request->get('company_name_ar')->value,
      'company_en' => (string) $request->get('company_name_en')->value,
      'commercial_registration' => (string) $request->get('commercial_registration')->value,
      'submitted' => $request->getCreatedTime(),
    ];
  }

  /**
   * Dispatches one message, logging rather than throwing on failure.
   *
   * @param string $key
   *   The mail key.
   * @param string $to
   *   The recipient address.
   * @param array<string, mixed> $params
   *   The template parameters.
   */
  private function send(string $key, string $to, array $params): void {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('domain_availability', $key, $to, $langcode, $params);

    if (empty($result['result'])) {
      $this->logger->warning('Registration email @key to @to could not be sent.', [
        '@key' => $key,
        '@to' => $to,
      ]);
    }
  }

}
