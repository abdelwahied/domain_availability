<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Cache\DomainCacheInterface;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;

/**
 * Resolves the registry WHOIS host for a TLD.
 *
 * Configured hosts win; anything unlisted is discovered once through
 * whois.iana.org and cached, so adding a TLD to config/app.php is usually
 * enough to support it.
 *
 * @internal
 *   Implementation detail of the WHOIS path.
 */
final class WhoisServerResolver {
  private const CACHE_PREFIX = 'whois:server:';

  /**
   * Cached discovery results per TLD.
   *
   * @var array<string, string|null>
   */
  private array $memo = [];

  /**
   * Constructs a WhoisServerResolver.
   *
   * @param array<string, string> $servers
   *   Configured TLD => WHOIS host map.
   * @param \Drupal\domain_availability\Service\WhoisClient $client
   *   The WHOIS client.
   * @param \Drupal\domain_availability\Service\WhoisResponseParser $parser
   *   The WHOIS response parser.
   * @param \Drupal\domain_availability\Cache\DomainCacheInterface $cache
   *   The domain cache.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param string $ianaServer
   *   The IANA WHOIS host used for discovery.
   * @param int $discoveryTtl
   *   How long, in seconds, to cache a discovered host.
   */
  public function __construct(
    private readonly array $servers,
    private readonly WhoisClient $client,
    private readonly WhoisResponseParser $parser,
    private readonly DomainCacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly string $ianaServer = 'whois.iana.org',
    private readonly int $discoveryTtl = 604800,
  ) {
  }

  /**
   * Whether a WHOIS host is already known without touching the network.
   */
  public function isConfigured(string $tld): bool {
    return isset($this->servers[Tld::normalise($tld)]);
  }

  /**
   * Resolve the WHOIS host for a TLD, or null when none can be found.
   */
  public function resolve(string $tld): ?string {
    $tld = Tld::normalise($tld);

    if ($tld === '') {
      return NULL;
    }

    if (isset($this->servers[$tld])) {
      return $this->servers[$tld];
    }

    if (array_key_exists($tld, $this->memo)) {
      return $this->memo[$tld];
    }

    $cached = $this->cache->get(self::CACHE_PREFIX . $tld);

    if (is_string($cached) && $cached !== '') {
      return $this->memo[$tld] = $cached;
    }

    $server = $this->discover($tld);
    $this->memo[$tld] = $server;

    if ($server !== NULL) {
      $this->cache->set(self::CACHE_PREFIX . $tld, $server, $this->discoveryTtl);
    }

    return $server;
  }

  /**
   * Resolve several TLDs, batching the IANA discovery queries in parallel.
   *
   * @param list<string> $tlds
   *   The TLDs to resolve.
   *
   * @return array<string, string>
   *   TLD => host, omitting unresolvable TLDs.
   */
  public function resolveMany(array $tlds): array {
    $resolved = [];
    $toDiscover = [];

    foreach (Tld::normaliseList($tlds) as $tld) {
      if (isset($this->servers[$tld])) {
        $resolved[$tld] = $this->servers[$tld];

        continue;
      }

      if (array_key_exists($tld, $this->memo)) {
        if ($this->memo[$tld] !== NULL) {
          $resolved[$tld] = $this->memo[$tld];
        }

        continue;
      }

      $cached = $this->cache->get(self::CACHE_PREFIX . $tld);

      if (is_string($cached) && $cached !== '') {
        $this->memo[$tld] = $cached;
        $resolved[$tld] = $cached;

        continue;
      }

      $toDiscover[$tld] = ['server' => $this->ianaServer, 'query' => $tld . "\r\n"];
    }

    if ($toDiscover === []) {
      return $resolved;
    }

    foreach ($this->client->queryMany($toDiscover) as $tld => $response) {
      $server = $this->parser->extractReferral($response);
      $this->memo[$tld] = $server;

      if ($server === NULL) {
        $this->logger->warning('No WHOIS server could be discovered for TLD.', [
          'tld' => $tld,
          'provider' => 'whois',
          'response' => $response->error ?? substr($response->body, 0, 200),
        ]);

        continue;
      }

      $resolved[$tld] = $server;
      $this->cache->set(self::CACHE_PREFIX . $tld, $server, $this->discoveryTtl);
    }

    return $resolved;
  }

  /**
   * Discovers the WHOIS host for a single TLD via IANA.
   *
   * @param string $tld
   *   The TLD to discover.
   *
   * @return string|null
   *   The discovered WHOIS host, or NULL when none was found.
   */
  private function discover(string $tld): ?string {
    return $this->resolveMany([$tld])[$tld] ?? NULL;
  }

}
