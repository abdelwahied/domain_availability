<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\domain_availability\Dto\CheckReport;
use Drupal\domain_availability\Form\DomainRegistrationRequestForm;
use Drupal\domain_availability\Pricing\PricingManager;
use Drupal\domain_availability\Service\RegistrationSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prepares variables for the module's templates.
 *
 * On Drupal 11.1 and later this is found by the #[Hook] attribute below. On
 * Drupal 10.3 no OOP hook is discovered at all, so the procedural
 * hook_preprocess_HOOK() in the .module file delegates here instead; it carries
 * #[LegacyHook] so the two never both run. Either way the logic lives here
 * once, and the registration settings arrive by injection rather than through
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
   * @param \Drupal\domain_availability\Pricing\PricingManager $pricing
   *   The pricing manager, read only for its cacheability.
   */
  public function __construct(
    private readonly RegistrationSettings $registration,
    private readonly PricingManager $pricing,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('domain_availability.registration_settings'),
      $container->get('domain_availability.pricing_manager'),
    );
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
  #[Hook('preprocess_domain_availability_results')]
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

      // Already resolved by PricingManager before the report got here; this
      // only flattens it. The template never asks what something costs, which
      // is what keeps a theme override from having to re-implement pricing.
      if ($result->price !== NULL && $status === 'available') {
        $row['price'] = [
          'amount' => $result->price->amount,
          'currency' => $result->price->currency,
          'formatted' => $this->t('@amount @currency', [
            '@amount' => $result->price->formatted(),
            '@currency' => $result->price->currency,
          ]),
        ];
      }

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
              'classes' => ['ui-dialog' => 'domain-availability-register-dialog'],
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

    // The rendered prices belong to whatever the active strategy reads. The
    // component itself is max-age 0, but this output can be embedded in
    // something that is not, and a stale price is worse than a stale status.
    $pricing = $this->pricing->getCacheableMetadata();

    $variables['#cache']['tags'] = Cache::mergeTags(
      $variables['#cache']['tags'] ?? [],
      $pricing->getCacheTags(),
    );
    $variables['#cache']['contexts'] = Cache::mergeContexts(
      $variables['#cache']['contexts'] ?? [],
      $pricing->getCacheContexts(),
    );
    $variables['#cache']['max-age'] = Cache::mergeMaxAges(
      $variables['#cache']['max-age'] ?? Cache::PERMANENT,
      $pricing->getCacheMaxAge(),
    );

    $variables['summary'] = [
      'query' => $report->query,
      'count' => count($report->results),
      'available_count' => $availableCount,
      'took_ms' => $report->tookMs,
      'cached' => $report->cached,
    ];
  }

}
