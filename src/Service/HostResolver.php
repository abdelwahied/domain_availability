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
 * all publish AAAA records too. Handing the caller every address, best family
 * first, lets it fall back instead of hanging on the first dead one.
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

    $candidates = $this->lookup($host);

    if ($candidates === []) {
      $this->logger->warning('Host could not be resolved; falling back to system resolution.', [
        'host' => $host,
      ]);

      // Not cached: a resolver hiccup must not be remembered for 5 minutes.
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
   * Looks up all addresses for a host, ordered by the configured family.
   *
   * @param string $host
   *   The hostname to resolve.
   *
   * @return list<string>
   *   The resolved addresses.
   */
  private function lookup(string $host): array {
    $ipv4 = $this->records($host, DNS_A, 'ip');
    $ipv6 = $this->records($host, DNS_AAAA, 'ipv6');

    return $this->settings->whoisAddressFamily() === self::PREFER_IPV6
            ? [...$ipv6, ...$ipv4]
            : [...$ipv4, ...$ipv6];
  }

  /**
   * Extracts valid IP addresses of one DNS record type for a host.
   *
   * @param string $host
   *   The hostname to resolve.
   * @param int $type
   *   The DNS record type, e.g. DNS_A or DNS_AAAA.
   * @param string $field
   *   The record field holding the address, e.g. 'ip' or 'ipv6'.
   *
   * @return list<string>
   *   The unique valid addresses.
   */
  private function records(string $host, int $type, string $field): array {
    // dns_get_record() emits warnings on NXDOMAIN and can return false;
    // both are ordinary outcomes here, not errors worth surfacing.
    $records = @dns_get_record($host, $type);

    if (!is_array($records)) {
      return [];
    }

    $addresses = [];

    foreach ($records as $record) {
      $address = $record[$field] ?? NULL;

      if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== FALSE) {
        $addresses[] = $address;
      }
    }

    return array_values(array_unique($addresses));
  }

}
