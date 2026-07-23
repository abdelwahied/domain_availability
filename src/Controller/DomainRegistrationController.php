<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Drupal\domain_availability\Form\DomainRegistrationRequestForm;
use Drupal\domain_availability\Service\RegistrationSettings;
use Drupal\domain_availability\Utility\DomainSanitizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the registration-request modal and the admin detail page.
 *
 * Thin by design: the modal returns a form, the detail page renders the entity.
 * No lookup or storage logic lives here — status changes go through the status
 * form, deletion through the entity delete form.
 *
 * @internal
 *   A controller; the route is the contract.
 */
final class DomainRegistrationController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\domain_availability\Service\RegistrationSettings $settings
   *   The registration settings.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    private readonly RegistrationSettings $settings,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('domain_availability.registration_settings'),
      $container->get('date.formatter'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Builds the modal submission form for a domain.
   *
   * The `use-ajax` link on the result card opens the returned form in a modal;
   * returning a plain render array is all that requires.
   *
   * @param string $domain
   *   The requested domain, from the route.
   *
   * @return array<string, mixed>
   *   The form render array.
   */
  public function registerForm(string $domain): array {
    $domain = $this->normaliseDomain($domain);

    if (!$this->settings->allowsDomain($domain)) {
      throw new NotFoundHttpException();
    }

    return $this->formBuilder()->getForm(DomainRegistrationRequestForm::class, $domain);
  }

  /**
   * Access for the public register route: only when the feature is enabled.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function registerAccess(): AccessResultInterface {
    return AccessResult::allowedIf($this->settings->isEnabled())
      ->addCacheableDependency($this->config(RegistrationSettings::CONFIG_NAME));
  }

  /**
   * Renders the full detail page for one request.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $domain_registration_request
   *   The request, upcast from the route.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function detail(DomainRegistrationRequestInterface $domain_registration_request): array {
    $request = $domain_registration_request;

    $rows = [
      [$this->t('Reference'), $request->getReferenceNumber()],
      [$this->t('Requested domain'), $request->getDomain()],
      [$this->t('Status'), $this->statusLabel($request->getStatus())],
      [
        $this->t('Applicant type'),
        $request->isIndividual() ? $this->t('Individual') : $this->t('Company'),
      ],
      [
        $this->t('Registration period'),
        $this->formatPlural((int) $request->get('registration_years')->value, '1 year', '@count years'),
      ],
      [$this->t('Company name (Arabic)'), $request->get('company_name_ar')->value],
      [$this->t('Company name (English)'), $request->get('company_name_en')->value],
      [$this->t('National address'), $request->get('national_address')->value],
      [$this->t('Commercial registration'), $request->get('commercial_registration')->value],
      [$this->t('Mobile'), $request->get('mobile')->value],
      [$this->t('National ID / Iqama'), $request->get('national_id')->value],
      [$this->t('Certificate'), $this->certificateLink($request->getCertificateFile())],
      [$this->t('Submitted'), $this->dateFormatter->format($request->getCreatedTime(), 'long')],
      [$this->t('IP address'), $request->get('ip_address')->value ?: $this->t('Not recorded')],
      [$this->t('User agent'), $request->get('user_agent')->value ?: $this->t('Not recorded')],
      [$this->t('Notes'), $request->get('notes')->value ?: $this->t('None')],
    ];

    $table_rows = [];

    foreach ($rows as [$label, $value]) {
      $table_rows[] = [
        ['header' => TRUE, 'data' => $label],
        ['data' => $value],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['domain-registration-detail']],
      'operations' => [
        '#type' => 'operations',
        '#links' => $this->detailOperations($request),
      ],
      'table' => [
        '#type' => 'table',
        '#rows' => $table_rows,
        '#attributes' => ['class' => ['domain-registration-detail__table']],
      ],
    ];
  }

  /**
   * The page title for a detail page.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $domain_registration_request
   *   The request.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title.
   */
  public function detailTitle(DomainRegistrationRequestInterface $domain_registration_request): object {
    return $this->t('Request @ref', ['@ref' => $domain_registration_request->getReferenceNumber()]);
  }

  /**
   * The edit-status / delete links shown on the detail page.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request
   *   The request.
   *
   * @return array<string, array<string, mixed>>
   *   Operation links, each already access-checked.
   */
  private function detailOperations(DomainRegistrationRequestInterface $request): array {
    $links = [];

    if ($request->access('update')) {
      $links['status'] = [
        'title' => $this->t('Change status'),
        'url' => Url::fromRoute('domain_availability.registration_request.status', [
          'domain_registration_request' => $request->id(),
        ]),
      ];
    }

    if ($request->access('delete')) {
      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => $request->toUrl('delete-form'),
      ];
    }

    return $links;
  }

  /**
   * Builds a download link for the certificate.
   *
   * @param \Drupal\file\FileInterface|null $file
   *   The file, or NULL.
   *
   * @return array<string, mixed>|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A link render array, or a placeholder when there is no file.
   */
  private function certificateLink(?object $file): array|object {
    if ($file === NULL) {
      return $this->t('No file');
    }

    return [
      '#type' => 'link',
      '#title' => $this->t('Download PDF'),
      '#url' => Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($file->getFileUri())),
      '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
    ];
  }

  /**
   * A translated status label.
   *
   * @param string $status
   *   The status machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  private function statusLabel(string $status): object {
    return match ($status) {
      DomainRegistrationRequestInterface::STATUS_APPROVED => $this->t('Approved'),
      DomainRegistrationRequestInterface::STATUS_REJECTED => $this->t('Rejected'),
      DomainRegistrationRequestInterface::STATUS_CANCELLED => $this->t('Cancelled'),
      default => $this->t('Pending'),
    };
  }

  /**
   * Normalises a domain route argument to a safe, bare hostname.
   *
   * @param string $domain
   *   The raw route value.
   *
   * @return string
   *   The label plus its TLD, e.g. `neixora.sa`.
   */
  private function normaliseDomain(string $domain): string {
    $lower = strtolower(trim($domain));
    $label = DomainSanitizer::sanitize($lower);
    $tld = str_contains($lower, '.')
      ? preg_replace('/[^a-z0-9\-]/', '', substr($lower, strrpos($lower, '.') + 1))
      : '';

    return $tld !== '' ? $label . '.' . $tld : $label;
  }

}
