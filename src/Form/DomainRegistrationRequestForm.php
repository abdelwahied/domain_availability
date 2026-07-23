<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Drupal\domain_availability\Service\RegistrationMailer;
use Drupal\domain_availability\Service\RegistrationSettings;
use Drupal\domain_availability\Utility\Tld;
use Drupal\saudi_id_validator\SaudiIdValidatorInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The visitor-facing registration request form, shown in a modal.
 *
 * Built as a plain form rather than the entity's own add form so the modal, the
 * read-only domain, the Nafath helper text, the AJAX submit and the
 * duplicate-window check are all under direct control. It still uses the Entity
 * API to persist — the form validates and gathers, the entity stores.
 *
 * The domain is a route argument, never an editable field: the button that
 * opens this form already decided which available domain it is for, and letting
 * the visitor change it here would let them request a domain the search never
 * showed as available.
 *
 * @internal
 *   A form; the route is the contract.
 */
final class DomainRegistrationRequestForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\domain_availability\Service\RegistrationSettings $settings
   *   The registration settings.
   * @param \Drupal\domain_availability\Service\RegistrationMailer $mailer
   *   The registration mailer.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $httpRequestStack
   *   The request stack. Named to avoid FormBase's own requestStack property.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\file\FileUsage\FileUsageInterface $fileUsage
   *   The file usage service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   * @param \Drupal\saudi_id_validator\SaudiIdValidatorInterface $saudiIds
   *   The identification-number validator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RegistrationSettings $settings,
    protected RegistrationMailer $mailer,
    protected AccountProxyInterface $currentUser,
    protected RequestStack $httpRequestStack,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected FileUsageInterface $fileUsage,
    protected LoggerInterface $logger,
    protected SaudiIdValidatorInterface $saudiIds,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('domain_availability.registration_settings'),
      $container->get('domain_availability.registration_mailer'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('stream_wrapper_manager'),
      $container->get('file.usage'),
      $container->get('logger.channel.domain_availability'),
      $container->get(SaudiIdValidatorInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_registration_request_form';
  }

  /**
   * The stable DOM id of the register button for a domain.
   *
   * Both the results template and this form derive it from the domain, so the
   * form's success response can swap the exact button that opened it.
   *
   * @param string $domain
   *   The fully qualified domain.
   *
   * @return string
   *   The button id.
   */
  public static function buttonId(string $domain): string {
    return 'da-register-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($domain));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $domain = ''): array {
    // Carry the domain in a value element, not form_state storage: it must
    // survive the managed_file upload rebuild and the final submit intact, and
    // a value element round-trips regardless of form caching.
    $form['domain'] = ['#type' => 'value', '#value' => $domain];
    $form['#prefix'] = '<div id="domain-availability-register-form">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'domain_availability/registration_form';
    $form['#attached']['drupalSettings']['domainAvailability']['certificateRequiredFor'] = DomainRegistrationRequestInterface::APPLICANT_COMPANY;

    // Errors from a failed AJAX submit render here without a page reload.
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -100,
    ];

    $form['domain_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Requested domain'),
      '#markup' => '<strong dir="ltr">' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong>',
    ];

    $form['applicant_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Applicant type'),
      '#required' => TRUE,
      '#options' => [
        DomainRegistrationRequestInterface::APPLICANT_COMPANY => $this->t('Company'),
        DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL => $this->t('Individual'),
      ],
      '#default_value' => DomainRegistrationRequestInterface::APPLICANT_COMPANY,
      '#description' => $this->t('An individual only has to provide a mobile number; the company documents below are optional.'),
    ];

    // The company documents stay visible to everyone but are only demanded of a
    // company applicant. #states drives the client-side asterisk and
    // constraint; validateForm repeats the rule on the server, where it counts.
    $isCompany = [
      'required' => [
        ':input[name="applicant_type"]' => ['value' => DomainRegistrationRequestInterface::APPLICANT_COMPANY],
      ],
    ];

    $form['registration_years'] = [
      '#type' => 'select',
      '#title' => $this->t('Registration period'),
      '#required' => TRUE,
      '#options' => $this->registrationYearOptions(),
      '#default_value' => 1,
      '#description' => $this->t('The number of years the domain will be reserved for.'),
    ];

    $form['company_name_ar'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name (Arabic)'),
      '#states' => $isCompany,
      '#maxlength' => 255,
      '#attributes' => ['dir' => 'rtl'],
    ];

    $form['company_name_en'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name (English)'),
      '#states' => $isCompany,
      '#maxlength' => 255,
      '#attributes' => ['dir' => 'ltr'],
    ];

    $form['national_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('National address'),
      '#states' => $isCompany,
      '#maxlength' => 255,
    ];

    $form['commercial_registration'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Commercial registration number'),
      '#states' => $isCompany,
      '#maxlength' => 10,
      '#attributes' => [
        'inputmode' => 'numeric',
        'placeholder' => '7XXXXXXXXX',
      ],
      '#description' => $this->t('10 digits starting with 7 (unified establishment number).'),
    ];

    $form['mobile'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile number'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#attributes' => [
        'inputmode' => 'tel',
        'placeholder' => '05XXXXXXXX',
      ],
      '#description' => $this->t('Saudi mobile in the form 05XXXXXXXX. It must belong to the authorized representative; for Saudi domains (.sa), ownership is verified through Nafath.'),
    ];

    // On a .sa domain the number is wanted from everyone: a company gives its
    // representative's, and an individual must prove Saudi citizenship with it.
    // Elsewhere it stays a company-only field.
    $isSaudi = $this->isSaudiDomain($domain);

    $form['national_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('National ID / Iqama number'),
      '#required' => $isSaudi,
      '#maxlength' => 10,
      '#attributes' => [
        'inputmode' => 'numeric',
        'placeholder' => '1XXXXXXXXX / 2XXXXXXXXX',
      ],
      '#description' => $isSaudi
        ? $this->t('10 digits: a National ID starts with 1, an Iqama starts with 2. An individual applicant must be a Saudi citizen — a .sa domain cannot be held by an Iqama holder except through a registered company.')
        : $this->t('10 digits: a National ID starts with 1, an Iqama starts with 2.'),
    ];

    if (!$isSaudi) {
      $form['national_id']['#states'] = $isCompany;
    }

    $form['certificate'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Commercial registration certificate'),
      // #states cannot mark a managed_file required: its preprocess copies only
      // the id and class off #attributes, so data-drupal-states never reaches
      // the DOM. The class below is the hook the module's own script uses to
      // toggle the asterisk instead; validateForm does the actual enforcing.
      '#attributes' => ['class' => ['domain-availability-certificate']],
      '#description' => $this->t('PDF only, up to @size MB. Required for a company applicant.', ['@size' => $this->settings->maxUploadMegabytes()]),
      '#upload_location' => $this->uploadLocation(),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => $this->settings->allowedExtensions()],
        'FileSizeLimit' => ['fileLimit' => $this->settings->maxUploadBytes()],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit request'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'domain-availability-register-form',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Submitting…')],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $domain = (string) $form_state->getValue("domain");

    // The domain must still be one the feature accepts. This guards a request
    // forged straight at the route, bypassing the button.
    if (!$this->settings->allowsDomain($domain)) {
      $form_state->setErrorByName('', $this->t('Registration requests are not available for this domain.'));

      return;
    }

    $applicantType = (string) $form_state->getValue('applicant_type');

    if (!in_array($applicantType, [
      DomainRegistrationRequestInterface::APPLICANT_COMPANY,
      DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL,
    ], TRUE)) {
      $form_state->setErrorByName('applicant_type', $this->t('Select whether the applicant is an individual or a company.'));

      return;
    }

    $mobile = preg_replace('/[\s\-]/', '', (string) $form_state->getValue('mobile'));

    // Saudi mobile: local 05XXXXXXXX, or the +966/966/0 international forms of
    // a 5-leading nine-digit subscriber number.
    if (preg_match('/^(?:\+?966|0)?5\d{8}$/', $mobile) !== 1) {
      $form_state->setErrorByName('mobile', $this->t('Enter a valid Saudi mobile number, for example 05XXXXXXXX.'));
    }

    // A company must supply its documents; an individual may leave them blank.
    // #states already says so in the browser, but that is a convenience — the
    // rule is enforced here, where a forged post cannot skip it.
    if ($applicantType === DomainRegistrationRequestInterface::APPLICANT_COMPANY) {
      foreach ($this->companyFieldLabels() as $name => $label) {
        if ($this->isBlank($form_state->getValue($name))) {
          $form_state->setErrorByName($name, $this->t('@field is required for a company applicant.', ['@field' => $label]));
        }
      }
    }

    // Saudi commercial registration: the 10-digit unified establishment number,
    // which always begins with 7. Only checked when given, so an individual who
    // leaves it blank is not asked for a format they have no number for.
    $commercialRegistration = preg_replace('/\s+/', '', (string) $form_state->getValue('commercial_registration'));

    if ($commercialRegistration !== '' && preg_match('/^7\d{9}$/', $commercialRegistration) !== 1) {
      $form_state->setErrorByName('commercial_registration', $this->t('Enter a valid Saudi commercial registration number: 10 digits starting with 7.'));
    }

    // National ID / Iqama. What makes a number well formed — the length, the
    // leading digit and the check digit — is the saudi_id_validator module's
    // answer, not this form's, so the rules exist in one place and this form
    // only decides what it needs on top of them.
    $nationalId = preg_replace('/\s+/', '', (string) $form_state->getValue('national_id'));

    $nationalIdIsSound = TRUE;

    if ($nationalId !== '') {
      $verdict = $this->saudiIds->getMetadata($nationalId);

      if (!$verdict->valid) {
        $form_state->setErrorByName('national_id', $verdict->reason->message());
        $nationalIdIsSound = FALSE;
      }
    }

    // The citizenship rule below only makes sense once the number itself is
    // sound; asking a malformed value which type it is would be meaningless.
    // Note this is not an early return — the remaining fields are still checked
    // so the user sees every mistake at once rather than one per submission.
    if ($nationalIdIsSound && $applicantType === DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL && $this->isSaudiDomain($domain)) {
      // An individual may only hold a .sa domain as a Saudi citizen, so the
      // National ID is mandatory here and an Iqama is not enough. A non-Saudi
      // individual has to apply through a registered company.
      if ($nationalId === '') {
        $form_state->setErrorByName('national_id', $this->t('A National ID is required to register a .sa domain as an individual.'));
      }
      elseif (!$this->saudiIds->isSaudiCitizen($nationalId)) {
        $form_state->setErrorByName('national_id', $this->t('Only a Saudi citizen may register a .sa domain as an individual: the National ID must start with 1. A resident holding an Iqama has to apply as a company.'));
      }
    }

    // The select only offers valid years; re-check so a forged post cannot
    // store a period the feature never offered.
    $years = (int) $form_state->getValue('registration_years');

    if (!array_key_exists($years, $this->registrationYearOptions())) {
      $form_state->setErrorByName('registration_years', $this->t('Select a registration period between 1 and 10 years.'));
    }

    if ($this->isDuplicate($domain)) {
      $form_state->setErrorByName('', $this->t('A registration request for this domain was already submitted recently. Please wait before submitting another.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $domain = (string) $form_state->getValue("domain");
    $request = $this->httpRequestStack->getCurrentRequest();

    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $entity */
    $entity = $this->entityTypeManager->getStorage('domain_registration_request')->create([
      'domain' => $domain,
      'applicant_type' => (string) $form_state->getValue('applicant_type'),
      'registration_years' => (int) $form_state->getValue('registration_years'),
      'company_name_ar' => trim((string) $form_state->getValue('company_name_ar')),
      'company_name_en' => trim((string) $form_state->getValue('company_name_en')),
      'national_address' => trim((string) $form_state->getValue('national_address')),
      'commercial_registration' => trim((string) $form_state->getValue('commercial_registration')),
      'mobile' => trim((string) $form_state->getValue('mobile')),
      'national_id' => trim((string) $form_state->getValue('national_id')),
      'status' => DomainRegistrationRequestInterface::STATUS_PENDING,
      'uid' => (int) $this->currentUser->id(),
      'ip_address' => $request?->getClientIp() ?? '',
      'user_agent' => mb_substr((string) $request?->headers->get('User-Agent'), 0, 512),
    ]);

    $fids = (array) $form_state->getValue('certificate');
    $fid = (int) ($fids[0] ?? 0);

    if ($fid > 0) {
      $entity->set('certificate', $fid);
    }

    $entity->save();

    // Record file usage so deleting the request later frees the certificate,
    // while the entity's own postSave has already marked it permanent.
    $file = $entity->getCertificateFile();

    if ($file !== NULL) {
      $this->fileUsage->add($file, 'domain_availability', 'domain_registration_request', (string) $entity->id());
    }

    $this->logger->info('Registration request @ref submitted for @domain by @uid.', [
      '@ref' => $entity->getReferenceNumber(),
      '@domain' => $domain,
      '@uid' => $this->currentUser->id(),
    ]);

    $customerEmail = $this->currentUser->isAuthenticated() ? $this->currentUser->getEmail() : NULL;
    $this->mailer->sendForNewRequest($entity, $customerEmail);

    $form_state->set('created_request', $entity);
  }

  /**
   * The AJAX callback: re-render on error, or confirm and close on success.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      // Re-open the same modal with the form, now carrying its error messages.
      $response->addCommand(new OpenModalDialogCommand(
        $this->t('Register this domain'),
        $form,
        ['width' => 640, 'dialogClass' => 'domain-availability-register-dialog'],
      ));

      return $response;
    }

    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $entity */
    $entity = $form_state->get('created_request');
    $domain = $entity->getDomain();

    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new MessageCommand(
      $this->t('Your registration request for @domain has been submitted. Reference: @ref.', [
        '@domain' => $domain,
        '@ref' => $entity->getReferenceNumber(),
      ]),
    ));

    // Swap the exact button that opened this modal for a confirmation, so it
    // cannot be clicked again for the same domain.
    $submitted = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('✓ Registration request submitted'),
      '#attributes' => [
        'class' => ['domain-availability-card__submitted'],
        'id' => self::buttonId($domain),
      ],
    ];
    $response->addCommand(new ReplaceCommand('#' . self::buttonId($domain), $submitted));

    return $response;
  }

  /**
   * Whether the domain sits under the Saudi ccTLD.
   *
   * @param string $domain
   *   The fully qualified domain.
   *
   * @return bool
   *   TRUE for a .sa domain.
   */
  private function isSaudiDomain(string $domain): bool {
    return Tld::fromDomain($domain) === 'sa';
  }

  /**
   * The fields a company applicant must fill, keyed by form element name.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, used both to test for emptiness and to word the error.
   */
  private function companyFieldLabels(): array {
    return [
      'company_name_ar' => $this->t('Company name (Arabic)'),
      'company_name_en' => $this->t('Company name (English)'),
      'national_address' => $this->t('National address'),
      'commercial_registration' => $this->t('Commercial registration number'),
      'national_id' => $this->t('National ID / Iqama number'),
      'certificate' => $this->t('Commercial registration certificate'),
    ];
  }

  /**
   * Whether a submitted value counts as empty.
   *
   * Handles the managed_file element too, whose value is an array of file ids
   * that is present but empty when nothing was uploaded.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return bool
   *   TRUE when nothing was provided.
   */
  private function isBlank(mixed $value): bool {
    if (is_array($value)) {
      return array_filter($value) === [];
    }

    return trim((string) $value) === '';
  }

  /**
   * The selectable registration periods, keyed by year count.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  private function registrationYearOptions(): array {
    $options = [];

    foreach (range(1, 10) as $years) {
      $options[$years] = $this->formatPlural($years, '1 year', '@count years');
    }

    return $options;
  }

  /**
   * Whether a still-open request for this domain exists inside the window.
   *
   * @param string $domain
   *   The domain.
   *
   * @return bool
   *   TRUE when a pending or approved request was created within the window.
   */
  private function isDuplicate(string $domain): bool {
    $window = $this->settings->duplicateWindowSeconds();

    if ($window <= 0) {
      return FALSE;
    }

    $since = $this->httpRequestStack->getCurrentRequest()?->server->get('REQUEST_TIME');
    $since = (int) ($since ?? time()) - $window;

    $count = $this->entityTypeManager->getStorage('domain_registration_request')->getQuery()
      ->accessCheck(FALSE)
      ->condition('domain', $domain)
      ->condition('created', $since, '>=')
      ->condition('status', [
        DomainRegistrationRequestInterface::STATUS_PENDING,
        DomainRegistrationRequestInterface::STATUS_APPROVED,
      ], 'IN')
      ->count()
      ->execute();

    return (int) $count > 0;
  }

  /**
   * The upload destination, private when the private scheme is available.
   *
   * Certificates carry a national ID and a commercial registration, so they
   * belong behind access control whenever the site offers it.
   *
   * @return string
   *   A stream-wrapper URI.
   */
  private function uploadLocation(): string {
    $scheme = $this->streamWrapperManager->getViaScheme('private') instanceof StreamWrapperInterface
      ? 'private'
      : 'public';

    return $scheme . '://domain-registration/' . date('Y-m');
  }

}
