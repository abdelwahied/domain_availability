<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Utility\Tld;
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
 * It runs only when RDAP and WHOIS both failed to answer, which is why it is
 * acceptable that PHP's resolver API is synchronous: the batch reaching this
 * point is normally empty.
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
    return $this->settings->dnsFallbackEnabled() && $tld !== '' && function_exists('dns_get_record');
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $results = [];

    // Bounded on purpose: each query is blocking, and this path exists to
    // rescue a handful of stragglers, not to resolve a full batch.
    foreach (array_slice($domains, 0, self::MAX_DOMAINS) as $domain) {
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

    set_error_handler(static fn (): bool => TRUE);

    try {
      $records = dns_get_record($domain, DNS_NS);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('DNS lookup failed.', [
        'provider' => $this->name(),
        'domain' => $domain,
        'response' => $exception->getMessage(),
      ]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'dns_error');
    } finally {
      restore_error_handler();
    }

    if (is_array($records) && $records !== []) {
      return DomainResult::registered($domain, $extension, $this->name());
    }

    return DomainResult::unknown($domain, $extension, $this->name(), 'no_delegation_inconclusive');
  }

}
