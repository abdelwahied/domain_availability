<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Cache\DomainCacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a hostname into an ordered list of candidate IP addresses.
 *
 * WHY THIS EXISTS — a hostname is not one address, and the resolver's own
 * ordering cannot be trusted. SaudiNIC's whois.nic.sa publishes an AAAA record
 * (2001:67c:130:410::a) that does not accept connections, while its A record
 * answers in 10 ms. PHP hands the hostname to the OS, which prefers IPv6, so
 * every .sa lookup hung until it timed out — on a machine with perfectly
 * working IPv6. The registry was never blocking us; one dead address record
 * was.
 *
 * Registry WHOIS hosts are a legacy, IPv4-first estate, and this failure mode
 * is not unique to .sa: whois.verisign-grs.com, whois.nic.io and whois.nic.me
 * all publish AAAA records too. Handing the caller every address of the
 * preferred family lets it fall back instead of hanging on the first dead one;
 * the other family is queried only when the preferred one answers nothing.
 *
 * Resolution goes through BoundedDnsResolver rather than dns_get_record(),
 * which takes no timeout: a single stalled query used to run to PHP's
 * max_execution_time and end the request in a fatal. Because that resolver
 * reports "nothing answered" separately from "answered, no records", a network
 * failure is no longer cached as though it were a fact about the host.
 *
 * @internal
 *   Implementation detail of the WHOIS path.
 */
final class HostResolver {
  public const PREFER_IPV4 = 'ipv4';
  public const PREFER_IPV6 = 'ipv6';
  public const PREFER_SYSTEM = 'system';

  private const CACHE_PREFIX = 'dns:host:';

  /**
   * Cached candidate addresses per host.
   *
   * @var array<string, list<string>>
   */
  private array $memo = [];

  public function __construct(
    private readonly DomainCacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
    private readonly LookupBudget $budget,
    private readonly BoundedDnsResolver $resolver,
  ) {
  }

  /**
   * Candidate addresses for a host, best first.
   *
   * Never empty: if resolution fails entirely the hostname itself is
   * returned, so the caller degrades to the old behaviour rather than losing
   * the lookup.
   *
   * @param string $host
   *   The hostname to resolve.
   *
   * @return list<string>
   *   The candidate addresses, best family first.
   */
  public function resolve(string $host): array {
    $host = strtolower(trim($host));

    if ($host === '') {
      return [];
    }

    // Already an address, or the caller asked to leave resolution to the
    // OS — nothing to do either way.
    if ($this->settings->whoisAddressFamily() === self::PREFER_SYSTEM || filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [$host];
    }

    if (isset($this->memo[$host])) {
      return $this->memo[$host];
    }

    $cached = $this->cache->get(self::CACHE_PREFIX . $host);

    if (is_array($cached) && $cached !== []) {
      /** @var list<string> $cached */
      return $this->memo[$host] = $cached;
    }

    // Queries are bounded now, but an exhausted budget still means there is no
    // time to spend: system resolution is the class's own degraded answer, and
    // taking it here costs a possibly-dead first address, not the request.
    if ($this->budget->exhausted()) {
      return $this->memo[$host] = [$host];
    }

    $candidates = $this->lookup($host);

    if ($candidates === NULL || $candidates === []) {
      $this->logger->warning('Host could not be resolved; falling back to system resolution.', [
        'host' => $host,
        'reason' => $candidates === NULL ? 'no_answer' : 'no_records',
      ]);

      // Not cached either way, but for different reasons: an unanswered query
      // is a fact about the network that must not be remembered, and a host
      // with genuinely no address records is not one this module can use.
      return $this->memo[$host] = [$host];
    }

    $this->cache->set(self::CACHE_PREFIX . $host, $candidates, $this->settings->whoisDnsTtl());

    return $this->memo[$host] = $candidates;
  }

  /**
   * Formats an address for a stream_socket_client() target.
   *
   * IPv6 literals need brackets so the port stays unambiguous.
   *
   * @param string $address
   *   The IP address.
   * @param int $port
   *   The TCP port.
   *
   * @return string
   *   The socket target, e.g. tcp://host:port.
   */
  public static function toSocketAddress(string $address, int $port): string {
    $isIpv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE;

    return sprintf($isIpv6 ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $address, $port);
  }

  /**
   * Looks up the addresses for a host, preferred family first.
   *
   * The other family is only queried when the preferred one answers nothing:
   * collecting both is a fallback for a host with no usable address in the
   * preferred family, and a fallback should not be paid for up front. The
   * queries are serial, so halving them still halves the worst case of a cold
   * sweep — they are simply no longer unbounded while they run.
   *
   * @param string $host
   *   The hostname to resolve.
   *
   * @return list<string>|null
   *   The resolved addresses, an empty array when the host has none, or NULL
   *   when the resolver did not answer.
   */
  private function lookup(string $host): ?array {
    $preferIpv6 = $this->settings->whoisAddressFamily() === self::PREFER_IPV6;

    $preferred = $preferIpv6
            ? $this->records($host, BoundedDnsResolver::TYPE_AAAA)
            : $this->records($host, BoundedDnsResolver::TYPE_A);

    // Nothing answered. Distinct from "answered with nothing": the caller must
    // not cache this, and must not spend the rest of the budget asking the
    // other family a question the resolver is already failing to answer.
    if ($preferred === NULL) {
      return NULL;
    }

    if ($preferred !== [] || $this->budget->exhausted()) {
      return $preferred;
    }

    $other = $preferIpv6
            ? $this->records($host, BoundedDnsResolver::TYPE_A)
            : $this->records($host, BoundedDnsResolver::TYPE_AAAA);

    // The preferred family answered conclusively with nothing, so an
    // unanswered second query still leaves us knowing the host has no address
    // in the family we prefer — which is an empty result, not an unknown one.
    return $other ?? [];
  }

  /**
   * Extracts valid IP addresses of one DNS record type for a host.
   *
   * @param string $host
   *   The hostname to resolve.
   * @param int $type
   *   The record type, one of the BoundedDnsResolver TYPE_* constants.
   *
   * @return list<string>|null
   *   The unique valid addresses, an empty array when the resolver answered
   *   and there are none, or NULL when nothing answered in time.
   */
  private function records(string $host, int $type): ?array {
    $addresses = $this->resolver->query($host, $type, $this->settings->dnsQueryTimeoutMs());

    if ($addresses === NULL) {
      return NULL;
    }

    $valid = array_values(array_filter(
      $addresses,
      static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== FALSE,
    ));

    return array_values(array_unique($valid));
  }

}
