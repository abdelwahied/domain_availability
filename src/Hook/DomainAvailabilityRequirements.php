<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\domain_availability\Service\StatusReportService;

/**
 * The status-report entry, as Drupal 11.3 and later want it declared.
 *
 * The module still ships the procedural hook_requirements() in its .install
 * file, because it supports Drupal 10.3 where this class is never discovered.
 * That function carries #[LegacyRequirementsHook] so that on 11.3 and later
 * only this implementation runs and the check is never performed twice. The
 * report itself is identical either way — both ask the same service the same
 * question.
 *
 * @internal
 *   A hook implementation.
 */
final class DomainAvailabilityRequirements {

  use StringTranslationTrait;

  /**
   * Constructs a DomainAvailabilityRequirements.
   *
   * @param \Drupal\domain_availability\Service\StatusReportService $statusReport
   *   The service that probes WHOIS egress.
   */
  public function __construct(private readonly StatusReportService $statusReport) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Surfaces WHOIS egress on the status report. This is the single most useful
   * thing an administrator can know about this module: a blocked outbound port
   * 43 is invisible from the outside and is the usual reason a WHOIS-only TLD
   * such as .sa reports "unknown" forever. Checking it here means nobody has to
   * SSH in and run nc to find out.
   *
   * Runtime only: an install-time socket probe would make installing the module
   * depend on a third-party registry answering.
   *
   * @return array<string, mixed>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];

    if (!function_exists('stream_socket_client')) {
      $requirements['domain_availability_sockets'] = [
        'title' => new TranslatableMarkup('Domain Availability: sockets'),
        'value' => new TranslatableMarkup('stream_socket_client() is unavailable'),
        'description' => new TranslatableMarkup('WHOIS speaks raw TCP on port 43, so WHOIS-only TLDs cannot be checked. RDAP-backed TLDs still work.'),
        'severity' => REQUIREMENT_WARNING,
      ];
    }

    $egress = $this->statusReport->whoisEgress();

    if ($egress === []) {
      return $requirements;
    }

    $reachable = [];
    $blocked = [];

    foreach ($egress as $tld => $result) {
      if ($result['reachable'] === TRUE) {
        $reachable[] = new TranslatableMarkup('@tld via @address (@latency ms)', [
          '@tld' => $tld,
          '@address' => $result['address'] ?? '',
          '@latency' => $result['latency_ms'] ?? 0,
        ]);
      }
      else {
        $blocked[] = new TranslatableMarkup('@tld (@server): @error', [
          '@tld' => $tld,
          '@server' => $result['server'] ?? '',
          '@error' => $result['error'] ?? 'unreachable',
        ]);
      }
    }

    $requirements['domain_availability_whois_egress'] = [
      'title' => new TranslatableMarkup('Domain Availability: WHOIS egress'),
      'value' => $blocked === []
        ? new TranslatableMarkup('Port 43 reachable')
        : new TranslatableMarkup('Port 43 blocked for @count TLD(s)', ['@count' => count($blocked)]),
      // A warning, never an error: a blocked port is a degraded lookup, not a
      // broken site. RDAP still answers for most TLDs.
      'severity' => $blocked === [] ? REQUIREMENT_OK : REQUIREMENT_WARNING,
      'description' => $blocked === []
        ? new TranslatableMarkup('WHOIS-only TLDs resolve normally: @list', [
          '@list' => implode(', ', array_map('strval', $reachable)),
        ])
        : new TranslatableMarkup('These TLDs will report "unknown" instead of "available": @list. Open outbound TCP port 43, or enable the authoritative provider on the settings page.', [
          '@list' => implode('; ', array_map('strval', $blocked)),
        ]),
    ];

    return $requirements;
  }

}
