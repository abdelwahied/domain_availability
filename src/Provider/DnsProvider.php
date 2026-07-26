<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Utility\Tld;
use Drupal\domain_availability\Service\BoundedDnsResolver;
use Drupal\domain_availability\Service\LookupBudget;
use Drupal\domain_availability\Service\ModuleSettings;
use Psr\Log\LoggerInterface;

/**
 * Last-resort provider: DNS delegation check.
 *
 * IMPORTANT — this provider is deliberately one-sided. A domain with NS records
 * in the parent zone is certainly registered, but the reverse does not hold:
 * plenty of registered domains are never delegated (parked, expired-but-held,
 * client-hold). So it can only ever prove `registered`, and returns `unknown`
 * for everything else instead of implying availability from silence.
 * It runs only when RDAP and WHOIS both failed to answer, so the batch reaching
 * this point is normally empty — but "normally" is a statement about the happy
 * path, and this code only runs once the happy path has already failed. The
 * names it looks up come from the public search box, so the choice of which
 * authoritative servers to wait on is the visitor's. Every query therefore goes
 * through BoundedDnsResolver and the check budget is consulted before each one:
 * with dns_get_record() this loop could spend the whole request, five times
 * over, on names it did not choose.
 *
 * @internal
 *   One implementation of DomainProviderInterface.
 */
final class DnsProvider implements DomainProviderInterface {
  private const PRIORITY = 30;
  private const MAX_DOMAINS = 5;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
    private readonly BoundedDnsResolver $resolver,
    private readonly LookupBudget $budget,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'dns';
  }

  /**
   * {@inheritdoc}
   */
  public function priority(): int {
    return self::PRIORITY;
  }

  /**
   * {@inheritdoc}
   *
   * DNS delegation works the same for every TLD, but this provider must never
   * be *selected* ahead of RDAP or WHOIS — its priority guarantees the
   * registry only reaches it as the final fallback.
   */
  public function supports(string $tld): bool {
    // The resolver is unusable when no nameserver could be discovered. Saying
    // so here is deliberate: the alternative is falling back to
    // dns_get_record(), and an unbounded query is exactly what this provider
    // must never make.
    return $this->settings->dnsFallbackEnabled() && $tld !== '' && $this->resolver->isUsable();
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $results = [];

    // Bounded on purpose: each query is blocking, and this path exists to
    // rescue a handful of stragglers, not to resolve a full batch.
    foreach (array_slice($domains, 0, self::MAX_DOMAINS) as $domain) {
      // Checked per domain, not once for the loop: five queries share one
      // budget, and the fifth must not start on time the first four spent.
      if ($this->budget->exhausted()) {
        $results[$domain] = DomainResult::unknown(
          $domain,
          Tld::withDot(Tld::fromDomain($domain)),
          $this->name(),
          'budget_exhausted',
        );

        continue;
      }

      $results[$domain] = $this->check($domain);
    }

    foreach (array_slice($domains, self::MAX_DOMAINS) as $domain) {
      $results[$domain] = DomainResult::unknown(
            $domain,
            Tld::withDot(Tld::fromDomain($domain)),
            $this->name(),
            'skipped_batch_limit',
        );
    }

    return $results;
  }

  /**
   * Checks a single domain for DNS delegation.
   *
   * @param string $domain
   *   The fully qualified domain name.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   Registered when NS records exist, unknown otherwise.
   */
  private function check(string $domain): DomainResult {
    $extension = Tld::withDot(Tld::fromDomain($domain));

    try {
      $records = $this->resolver->query($domain, BoundedDnsResolver::TYPE_NS, $this->settings->dnsQueryTimeoutMs());
    }
    catch (\Throwable $exception) {
      $this->logger->warning('DNS lookup failed.', [
        'provider' => $this->name(),
        'domain' => $domain,
        'response' => $exception->getMessage(),
      ]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'dns_error');
    }

    // Nothing answered. Emphatically not the same as "no delegation": this
    // says something about the resolver, not about the domain, so it must not
    // be reported as evidence either way.
    if ($records === NULL) {
      return DomainResult::unknown($domain, $extension, $this->name(), 'dns_timeout');
    }

    if ($records !== []) {
      return DomainResult::registered($domain, $extension, $this->name());
    }

    return DomainResult::unknown($domain, $extension, $this->name(), 'no_delegation_inconclusive');
  }

}
