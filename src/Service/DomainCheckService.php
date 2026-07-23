<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Cache\DomainCacheInterface;
use Drupal\domain_availability\Dto\CheckReport;
use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Provider\DomainProviderInterface;
use Drupal\domain_availability\Provider\ProviderRegistry;
use Drupal\domain_availability\Utility\Timer;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one availability check across every configured TLD.
 *
 * Strategy, per round:
 * 1. Group the outstanding domains by the provider that owns their TLD.
 * 2. Ask each provider for its whole group at once — providers resolve their
 *    group concurrently, so a round costs about one slow lookup rather than
 *    the sum of them.
 * 3. Keep the conclusive answers; re-queue the `unknown` ones against the next
 *    provider in their chain.
 * 4. Stop when nothing is outstanding, every chain is exhausted, or the lookup
 *    budget runs out. Whatever is still unresolved stays `unknown`.
 *
 * The service knows nothing about RDAP or WHOIS. It only talks to the provider
 * registry, which is what keeps a new protocol a one-class, one-service change.
 *
 * @api
 *   Public and stable since 1.0.0. The module's entry point: one label in, a
 *   CheckReport out.
 */
final class DomainCheckService {

  /**
   * Namespace for cached reports.
   */
  private const CACHE_PREFIX = 'check:v1:';

  /**
   * How many fallback rounds a check may take before it gives up.
   */
  private const MAX_ROUNDS = 4;

  /**
   * Constructs a DomainCheckService.
   *
   * @param \Drupal\domain_availability\Provider\ProviderRegistry $registry
   *   The provider registry.
   * @param \Drupal\domain_availability\Cache\DomainCacheInterface $cache
   *   The lookup cache.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   */
  public function __construct(
    private readonly ProviderRegistry $registry,
    private readonly DomainCacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
  ) {}

  /**
   * Checks a validated, sanitised label across every configured TLD.
   *
   * @param string $label
   *   The validated domain label, without a TLD.
   *
   * @return \Drupal\domain_availability\Dto\CheckReport
   *   The report, one result per configured TLD.
   */
  public function check(string $label): CheckReport {
    $timer = Timer::start();
    $tlds = $this->settings->tlds();
    $cacheKey = $this->cacheKey($label, $tlds);

    $cached = $this->readCache($cacheKey, $label);

    if ($cached !== NULL) {
      return $cached->asCached($timer->elapsedMs());
    }

    $results = $this->resolve($label, $tlds);
    $report = new CheckReport($label, $results, $timer->elapsedMs(), FALSE);

    $this->writeCache($cacheKey, $report);

    return $report;
  }

  /**
   * Runs the provider rounds until every domain is answered or time runs out.
   *
   * @param string $label
   *   The validated domain label.
   * @param array<int, string> $tlds
   *   The TLDs to check.
   *
   * @return array<int, \Drupal\domain_availability\Dto\DomainResult>
   *   The results, in configured TLD order.
   */
  private function resolve(string $label, array $tlds): array {
    $resolved = [];
    $attempted = [];
    $outstanding = [];

    foreach ($tlds as $tld) {
      $outstanding[$label . '.' . $tld] = TRUE;
    }

    $deadline = microtime(TRUE) + $this->settings->maxLookupTime();

    for ($round = 0; $round < self::MAX_ROUNDS && $outstanding !== []; $round++) {
      // The whole check is bounded, not just each provider: a chain of slow
      // rounds must never outlive the request the user is waiting on.
      if (microtime(TRUE) >= $deadline) {
        $this->logger->warning('Lookup budget exhausted; @count domains left unresolved.', [
          '@count' => count($outstanding),
          'label' => $label,
        ]);

        break;
      }

      $groups = $this->group(array_keys($outstanding), $attempted);

      if ($groups === []) {
        break;
      }

      foreach ($groups as $providerName => $domains) {
        $provider = $this->providerByName($providerName);

        if ($provider === NULL) {
          continue;
        }

        foreach ($this->runProvider($provider, $domains) as $domain => $result) {
          $attempted[$domain][] = $providerName;

          if ($result->isConclusive()) {
            $resolved[$domain] = $result;
            unset($outstanding[$domain]);

            continue;
          }

          // Keep the best explanation seen so far, in case every provider in
          // the chain ends up failing.
          $resolved[$domain] = $result;
        }
      }
    }

    foreach (array_keys($outstanding) as $domain) {
      $resolved[$domain] ??= DomainResult::unknown(
        $domain,
        Tld::withDot(Tld::fromDomain($domain)),
        NULL,
        'no_provider_available',
      );
    }

    return $this->ordered($label, $tlds, $resolved);
  }

  /**
   * Assigns each outstanding domain to the next untried provider in its chain.
   *
   * @param array<int, string> $domains
   *   The outstanding domains.
   * @param array<string, array<int, string>> $attempted
   *   Domain => provider names already tried.
   *
   * @return array<string, array<int, string>>
   *   Provider name => domains.
   */
  private function group(array $domains, array $attempted): array {
    $groups = [];

    foreach ($domains as $domain) {
      $tried = $attempted[$domain] ?? [];

      foreach ($this->registry->chainFor(Tld::fromDomain($domain)) as $provider) {
        if (!in_array($provider->name(), $tried, TRUE)) {
          $groups[$provider->name()][] = $domain;

          break;
        }
      }
    }

    return $groups;
  }

  /**
   * Runs one provider over its group.
   *
   * An unexpected failure becomes `unknown` results rather than an exception,
   * so a broken provider degrades the answer instead of failing the request.
   *
   * @param \Drupal\domain_availability\Provider\DomainProviderInterface $provider
   *   The provider to run.
   * @param array<int, string> $domains
   *   The domains it is responsible for.
   *
   * @return array<string, \Drupal\domain_availability\Dto\DomainResult>
   *   One result per domain, keyed by domain.
   */
  private function runProvider(DomainProviderInterface $provider, array $domains): array {
    try {
      $results = $provider->lookup($domains);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Provider @provider failed: @message', [
        '@provider' => $provider->name(),
        '@message' => $exception->getMessage(),
        'domains' => $domains,
        'exception' => $exception,
      ]);

      $results = [];
    }

    $normalised = [];

    foreach ($domains as $domain) {
      $normalised[$domain] = $results[$domain] ?? DomainResult::unknown(
        $domain,
        Tld::withDot(Tld::fromDomain($domain)),
        $provider->name(),
        'provider_failed',
      );
    }

    return $normalised;
  }

  /**
   * Finds a registered provider by name.
   *
   * @param string $name
   *   The provider name.
   *
   * @return \Drupal\domain_availability\Provider\DomainProviderInterface|null
   *   The provider, or NULL when no provider carries that name.
   */
  private function providerByName(string $name): ?DomainProviderInterface {
    foreach ($this->registry->all() as $provider) {
      if ($provider->name() === $name) {
        return $provider;
      }
    }

    return NULL;
  }

  /**
   * Returns results in configured TLD order, so the UI is stable across calls.
   *
   * @param string $label
   *   The validated domain label.
   * @param array<int, string> $tlds
   *   The TLDs to check.
   * @param array<string, \Drupal\domain_availability\Dto\DomainResult> $resolved
   *   The resolved results, keyed by domain.
   *
   * @return array<int, \Drupal\domain_availability\Dto\DomainResult>
   *   The ordered results.
   */
  private function ordered(string $label, array $tlds, array $resolved): array {
    $ordered = [];

    foreach ($tlds as $tld) {
      $domain = $label . '.' . $tld;

      $ordered[] = $resolved[$domain] ?? DomainResult::unknown(
        $domain,
        Tld::withDot($tld),
        NULL,
        'not_checked',
      );
    }

    return $ordered;
  }

  /**
   * Reads a cached report.
   *
   * A payload that does not match the expected shape is treated as a miss: a
   * cache written by an older version of this module must never be coerced
   * into a report.
   *
   * @param string $key
   *   The cache key.
   * @param string $label
   *   The validated domain label.
   *
   * @return \Drupal\domain_availability\Dto\CheckReport|null
   *   The cached report, or NULL on a miss.
   */
  private function readCache(string $key, string $label): ?CheckReport {
    if (!$this->settings->cacheEnabled()) {
      return NULL;
    }

    $payload = $this->cache->get($key);

    if (!is_array($payload) || !isset($payload['results']) || !is_array($payload['results'])) {
      return NULL;
    }

    $results = [];

    foreach ($payload['results'] as $result) {
      if (!is_array($result) || !isset($result['domain'], $result['extension'], $result['status'])) {
        return NULL;
      }

      $status = DomainStatus::tryFrom((string) $result['status']);

      if ($status === NULL) {
        return NULL;
      }

      $results[] = new DomainResult(
        (string) $result['domain'],
        (string) $result['extension'],
        $status,
        isset($result['provider']) ? (string) $result['provider'] : NULL,
        isset($result['reason']) ? (string) $result['reason'] : NULL,
      );
    }

    return new CheckReport($label, $results, 0, TRUE);
  }

  /**
   * Caches a report, tagged so one label can be invalidated on its own.
   *
   * @param string $key
   *   The cache key.
   * @param \Drupal\domain_availability\Dto\CheckReport $report
   *   The report to cache.
   */
  private function writeCache(string $key, CheckReport $report): void {
    if (!$this->settings->cacheEnabled()) {
      return;
    }

    $this->cache->set($key, [
      'results' => array_map(
        static fn (DomainResult $result): array => $result->toArray(),
        $report->results,
      ),
    ], $this->settings->cacheTtl(), ['domain_availability:label:' . $report->query]);
  }

  /**
   * Builds the cache key for a label.
   *
   * The key covers the label *and* the TLD set, so changing the configured
   * list can never serve a stale, partial answer from before the change.
   *
   * @param string $label
   *   The validated domain label.
   * @param array<int, string> $tlds
   *   The TLDs being checked.
   *
   * @return string
   *   The cache key.
   */
  private function cacheKey(string $label, array $tlds): string {
    return self::CACHE_PREFIX . $label . ':' . substr(sha1(implode(',', $tlds)), 0, 12);
  }

}
