<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\domain_availability\Dto\CheckReport;
use Drupal\domain_availability\Form\DomainRegistrationRequestForm;
use Drupal\domain_availability\Service\RegistrationSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prepares variables for the module's templates.
 *
 * Registered as the theme hook's `initial preprocess` callback, which replaces
 * the template_preprocess_HOOK() function Drupal 11.3 deprecated. Being a class
 * also means the registration settings arrive by injection rather than through
 * a static service lookup.
 *
 * @internal
 *   A hook implementation.
 */
final class DomainAvailabilityThemePreprocess implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a DomainAvailabilityThemePreprocess.
   *
   * @param \Drupal\domain_availability\Service\RegistrationSettings $registration
   *   The registration settings.
   */
  public function __construct(private readonly RegistrationSettings $registration) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('domain_availability.registration_settings'));
  }

  /**
   * Prepares variables for the results template.
   *
   * Flattens the CheckReport into plain, translated values so the template
   * never has to reach into value objects or decide what a status means. Status
   * labels are translated here — in a template they would be strings a
   * translator can never reach.
   *
   * @param array<string, mixed> $variables
   *   The variables, containing a 'report' CheckReport.
   */
  public function preprocessResults(array &$variables): void {
    $report = $variables['report'];

    if (!$report instanceof CheckReport) {
      $variables['results'] = [];
      $variables['summary'] = [];

      return;
    }

    $labels = [
      'available' => $this->t('Available'),
      'registered' => $this->t('Registered'),
      'unknown' => $this->t('Unknown'),
    ];

    $rows = [];
    $availableCount = 0;

    foreach ($report->results as $result) {
      $status = $result->status->value;

      if ($status === 'available') {
        $availableCount++;
      }

      $row = [
        'domain' => $result->domain,
        'extension' => $result->extension,
        'status' => $status,
        'available' => $result->status->toAvailability(),
        'provider' => $result->provider,
        'reason' => $result->reason,
        'label' => $labels[$status],
      ];

      // The registration button is an optional, self-contained add-on: it only
      // ever appears on an available result whose TLD the feature accepts, and
      // its absence leaves the original card untouched.
      if ($status === 'available' && $this->registration->allowsDomain($result->domain)) {
        $row['register'] = [
          '#type' => 'link',
          '#title' => $this->t('+ Register this domain'),
          '#url' => Url::fromRoute('domain_availability.registration_request.form', ['domain' => $result->domain]),
          '#attributes' => [
            'class' => ['use-ajax', 'button', 'domain-availability-card__register'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => 640,
              'dialogClass' => 'domain-availability-register-dialog',
            ]),
            'id' => DomainRegistrationRequestForm::buttonId($result->domain),
          ],
        ];
      }

      $rows[] = $row;
    }

    $variables['results'] = $rows;
    // The modal is opened by a use-ajax link, so the dialog behaviour must be
    // present wherever results render.
    $variables['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $variables['summary'] = [
      'query' => $report->query,
      'count' => count($report->results),
      'available_count' => $availableCount,
      'took_ms' => $report->tookMs,
      'cached' => $report->cached,
    ];
  }

}
