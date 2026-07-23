<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Cache\DomainCacheInterface;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;

/**
 * Resolves the RDAP base URL for a TLD.
 *
 * The map comes from IANA's bootstrap registry (RFC 9224), falling back to a
 * static map when IANA is unreachable. The bootstrap file is fetched once and
 * cached for a week, so a lookup never pays for it on the hot path after the
 * first request.
 *
 * @internal
 *   Implementation detail of the RDAP path.
 */
final class RdapRegistryResolver {
  private const CACHE_KEY = 'rdap:bootstrap:v1';

  /**
   * The TLD => base URL map, lazily built.
   *
   * @var array<string, string>|null
   */
  private ?array $map = NULL;

  /**
   * Constructs a RdapRegistryResolver.
   *
   * @param \Drupal\domain_availability\Service\ParallelHttpClient $client
   *   The HTTP client.
   * @param \Drupal\domain_availability\Cache\DomainCacheInterface $cache
   *   The domain cache.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param string $bootstrapUrl
   *   The IANA bootstrap URL.
   * @param array<string, string> $fallbackServers
   *   TLD => base URL.
   * @param list<string> $excludedTlds
   *   TLDs forced off RDAP.
   * @param int $bootstrapTtl
   *   The bootstrap cache lifetime, in seconds.
   */
  public function __construct(
    private readonly ParallelHttpClient $client,
    private readonly DomainCacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly string $bootstrapUrl,
    private readonly array $fallbackServers,
    private readonly array $excludedTlds = [],
    private readonly int $bootstrapTtl = 604800,
  ) {
  }

  /**
   * Base URL for a TLD's RDAP service, or null when the registry has none.
   */
  public function resolve(string $tld): ?string {
    $tld = Tld::normalise($tld);

    if ($tld === '' || in_array($tld, $this->excludedTlds, TRUE)) {
      return NULL;
    }

    return $this->map()[$tld] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $tld): bool {
    return $this->resolve($tld) !== NULL;
  }

  /**
   * The TLD => base URL map, resolved from cache, IANA, or fallback.
   *
   * @return array<string, string>
   *   The TLD => base URL map.
   */
  private function map(): array {
    if ($this->map !== NULL) {
      return $this->map;
    }

    $cached = $this->cache->get(self::CACHE_KEY);

    if (is_array($cached) && $cached !== []) {
      /** @var array<string, string> $cached */
      return $this->map = $cached;
    }

    $map = $this->fetchBootstrap();

    if ($map === []) {
      $this->logger->warning('IANA RDAP bootstrap unavailable; using fallback endpoints.', [
        'provider' => 'rdap',
      ]);

      return $this->map = $this->normaliseMap($this->fallbackServers);
    }

    // IANA is authoritative: when the bootstrap loads, it is used alone.
    // Merging the fallback in would invent RDAP endpoints for TLDs IANA
    // says have none (.io, .co, .me), and those lookups would fail on
    // every request instead of going straight to WHOIS.
    $this->cache->set(self::CACHE_KEY, $map, $this->bootstrapTtl);

    return $this->map = $map;
  }

  /**
   * Fetch and flatten IANA's bootstrap document.
   *
   * @return array<string, string>
   *   The TLD => base URL map, or an empty array on failure.
   */
  private function fetchBootstrap(): array {
    $response = $this->client->get($this->bootstrapUrl, ['Accept' => 'application/json']);

    if (!$response->successful()) {
      $this->logger->warning('IANA RDAP bootstrap fetch failed.', [
        'provider' => 'rdap',
        'status' => $response->status,
        'response' => $response->error,
      ]);

      return [];
    }

    $payload = $response->json();

    if ($payload === NULL || !isset($payload['services']) || !is_array($payload['services'])) {
      return [];
    }

    $map = [];

    /** @var mixed $service */
    foreach ($payload['services'] as $service) {
      if (!is_array($service) || count($service) < 2) {
        continue;
      }

      [$tlds, $urls] = $service;

      if (!is_array($tlds) || !is_array($urls)) {
        continue;
      }

      $url = $this->preferHttps($urls);

      if ($url === NULL) {
        continue;
      }

      foreach ($tlds as $tld) {
        if (is_string($tld) && $tld !== '') {
          $map[Tld::normalise($tld)] = $url;
        }
      }
    }

    return $map;
  }

  /**
   * Picks the preferred URL, favouring HTTPS.
   *
   * @param array<array-key, mixed> $urls
   *   The candidate URLs.
   *
   * @return string|null
   *   The preferred URL, or NULL when none is usable.
   */
  private function preferHttps(array $urls): ?string {
    $fallback = NULL;

    foreach ($urls as $url) {
      if (!is_string($url) || $url === '') {
        continue;
      }

      $url = $this->withTrailingSlash($url);

      if (str_starts_with($url, 'https://')) {
        return $url;
      }

      $fallback ??= $url;
    }

    return $fallback;
  }

  /**
   * Normalises a TLD => URL map.
   *
   * @param array<string, string> $servers
   *   The TLD => base URL map.
   *
   * @return array<string, string>
   *   The normalised TLD => base URL map.
   */
  private function normaliseMap(array $servers): array {
    $map = [];

    foreach ($servers as $tld => $url) {
      $map[Tld::normalise($tld)] = $this->withTrailingSlash($url);
    }

    return $map;
  }

  /**
   * Ensures the URL ends with a single trailing slash.
   */
  private function withTrailingSlash(string $url): string {
    return rtrim($url, '/') . '/';
  }

}
