<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\domain_availability\Provider\ProviderRegistry;
use Drupal\domain_availability\Utility\Tld;

/**
 * Builds the module's health picture, for both /domain-check/health and.
 *
 * Drupal's status report.
 * One service, two consumers: a load balancer cannot read
 * /admin/reports/status, and a site builder should not have to curl an
 * endpoint. Sharing the source means the two can never disagree.
 * Two kinds of information live here and they are deliberately separated:
 * `checks` decide the HTTP status — only what this process owns (registered
 * providers, the extensions it needs). A slow or hostile registry can never
 * take a node out of a load balancer.
 * `diagnostics` never change the status. WHOIS egress (port 43) is the most
 * common cause of `unknown` results in production and is invisible from
 * outside, but a blocked port is a degraded lookup, not a dead node: RDAP
 * still answers for most TLDs, and failing the probe would pull a perfectly
 * serving node offline.
 *
 * @internal
 *   Feeds the status report; read it there.
 */
final class StatusReportService {

  /**
   * Constructs a StatusReportService.
   *
   * @param \Drupal\domain_availability\Provider\ProviderRegistry $registry
   *   The provider registry.
   * @param \Drupal\domain_availability\Service\WhoisReachabilityProbe $probe
   *   The WHOIS egress probe.
   * @param \Drupal\domain_availability\Service\WhoisServerResolver $whoisServers
   *   The WHOIS server resolver.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly ProviderRegistry $registry,
    private readonly WhoisReachabilityProbe $probe,
    private readonly WhoisServerResolver $whoisServers,
    private readonly ModuleSettings $settings,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the full health payload.
   *
   * @return array<string, mixed>
   *   The report: success, status, timestamp, checks, diagnostics, providers
   *   and tld_count.
   */
  public function build(): array {
    $checks = [
      'providers_registered' => $this->registry->all() !== [],
      'json_available' => extension_loaded('json'),
      'sockets_available' => function_exists('stream_socket_client'),
    ];

    $healthy = !in_array(FALSE, $checks, TRUE);

    return [
      'success' => $healthy,
      'status' => $healthy ? 'ok' : 'degraded',
      'timestamp' => date('c', $this->time->getRequestTime()),
      'checks' => $checks,
      'diagnostics' => ['whois_egress' => $this->whoisEgress()],
      'providers' => $this->registry->names(),
      'tld_count' => count($this->settings->tlds()),
    ];
  }

  /**
   * Probes WHOIS egress for the configured TLDs.
   *
   * Only WHOIS-only TLDs are worth probing: everything else falls back to RDAP
   * and never notices a blocked port.
   *
   * @return array<string, array<string, mixed>>
   *   Probe results, keyed by TLD with a leading dot.
   */
  public function whoisEgress(): array {
    $results = [];

    foreach ($this->settings->healthProbeTlds() as $tld) {
      $server = $this->whoisServers->resolve($tld);

      if ($server === NULL) {
        continue;
      }

      $results[Tld::withDot($tld)] = $this->probe->probe($server) + ['server' => $server];
    }

    return $results;
  }

}
