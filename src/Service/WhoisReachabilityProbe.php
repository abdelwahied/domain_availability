<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Cache\DomainCacheInterface;

/**
 * Diagnostic probe: can this host actually open a WHOIS (port 43) connection?
 *
 * Exists because the single most common reason a TLD reports `unknown` in
 * production is an egress firewall, not a bug — and that is invisible from the
 * outside. It answers "is port 43 open from this node" so a deploy can be
 * checked without SSH.
 * It resolves addresses through HostResolver and walks the same candidate list
 * WhoisClient does, in the same order. A probe that connects differently from
 * the client is worse than no probe: dialling the hostname reports SaudiNIC as
 * unreachable — its AAAA record is dead — while the client is happily talking
 * to it over IPv4. The reported `address` is the one that answered.
 * Deliberately cheap and cached: a health endpoint gets hit constantly, and an
 * uncached probe would open a socket to a registry on every load balancer poll.
 *
 * @internal
 *   Implementation detail of the status report.
 */
final class WhoisReachabilityProbe {
  private const CACHE_PREFIX = 'probe:whois:v2:';

  public function __construct(
    private readonly DomainCacheInterface $cache,
    private readonly HostResolver $resolver,
    private readonly ModuleSettings $settings,
    private readonly int $ttl = 300,
    private readonly int $port = 43,
  ) {
  }

  /**
   * Probes a WHOIS host, reusing a cached verdict when one is fresh.
   *
   * Only the handshake is measured — no query is sent, so this costs the
   * registry nothing and cannot trip its rate limiter.
   *
   * @param string $server
   *   The WHOIS host.
   *
   * @return array<string, mixed>
   *   Verdict with keys reachable, address, latency_ms, error and cached.
   */
  public function probe(string $server): array {
    $key = self::CACHE_PREFIX . $server . ':' . $this->port;
    $cached = $this->cache->get($key);

    if (is_array($cached) && isset($cached['reachable'])) {
      return $cached + ['cached' => TRUE];
    }

    $result = $this->attempt($server);
    $this->cache->set($key, $result, $this->ttl);

    return $result + ['cached' => FALSE];
  }

  /**
   * Tries each address in turn; the first that connects wins.
   *
   * @param string $server
   *   The WHOIS host.
   *
   * @return array<string, mixed>
   *   Verdict with keys reachable, address, latency_ms and error.
   */
  private function attempt(string $server): array {
    $candidates = $this->resolver->resolve($server);

    if ($candidates === []) {
      return [
        'reachable' => FALSE,
        'address' => NULL,
        'latency_ms' => NULL,
        'error' => 'dns_resolution_failed',
      ];
    }

    $lastError = 'connect_failed';
    $startedAt = microtime(TRUE);

    foreach ($candidates as $address) {
      $result = $this->connect($address);

      if ($result['reachable']) {
        return $result;
      }

      $lastError = $result['error'] ?? $lastError;
    }

    // Every address failed: report the total spent, since that is what a
    // real lookup would have paid before giving up.
    return [
      'reachable' => FALSE,
      'address' => NULL,
      'latency_ms' => (int) round((microtime(TRUE) - $startedAt) * 1000),
      'error' => $lastError,
    ];
  }

  /**
   * Attempts a single blocking connection to one address.
   *
   * @param string $address
   *   The IP address.
   *
   * @return array<string, mixed>
   *   Verdict with keys reachable, address, latency_ms and error.
   */
  private function connect(string $address): array {
    $errorCode = 0;
    $errorMessage = '';
    $startedAt = microtime(TRUE);

    $socket = @stream_socket_client(
          HostResolver::toSocketAddress($address, $this->port),
          $errorCode,
          $errorMessage,
          $this->settings->whoisConnectTimeoutMs() / 1000,
      );

    $latency = (int) round((microtime(TRUE) - $startedAt) * 1000);

    if ($socket === FALSE) {
      return [
        'reachable' => FALSE,
        'address' => $address,
        'latency_ms' => $latency,
            // A refused connection means the port is closed; a timeout
            // means a firewall is dropping the packets. Both end up here,
            // and the distinction is what tells you which one to fix.
        'error' => $errorMessage !== '' ? $errorMessage : 'connect_failed_' . $errorCode,
      ];
    }

    fclose($socket);

    return ['reachable' => TRUE, 'address' => $address, 'latency_ms' => $latency, 'error' => NULL];
  }

}
