<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

/**
 * Contract every lookup provider implements.
 *
 * Adding a protocol means writing one class against this interface and tagging
 * it `domain_availability_provider` in a services.yml — the controller and the
 * check service stay untouched (Open/Closed). The registry collects every
 * tagged service, so a provider can live in another module entirely.
 *
 * Implementations must be batch oriented: `lookup()` receives every domain the
 * provider is responsible for at once and is expected to resolve them
 * concurrently.
 *
 * @api
 *   Public and stable since 1.0.0. This is the module's main extension point;
 *   see API.md and CONTRIBUTING.md for a worked example.
 */
interface DomainProviderInterface {

  /**
   * The stable identifier reported in results and logs, e.g. `rdap`.
   *
   * @return string
   *   The provider name.
   */
  public function name(): string;

  /**
   * The selection order; the lowest number wins.
   *
   * The registry asks providers in ascending priority, so RDAP (10) is
   * preferred over WHOIS (20), and any provider may act as the fallback for
   * the ones before it.
   *
   * @return int
   *   The priority.
   */
  public function priority(): int;

  /**
   * Whether this provider can answer for a TLD (dot-less, e.g. `com`).
   *
   * Must not perform network I/O on the hot path: implementations resolve
   * endpoints from cached registries instead.
   *
   * @param string $tld
   *   The TLD, without a leading dot.
   *
   * @return bool
   *   TRUE when the provider can answer for the TLD.
   */
  public function supports(string $tld): bool;

  /**
   * Resolves a batch of fully qualified domains concurrently.
   *
   * Implementations must never throw for a single failed domain: an
   * unanswerable lookup is returned as an `unknown` result so the rest of the
   * batch still reaches the client.
   *
   * @param array<int, string> $domains
   *   Fully qualified domains, e.g. `neixora.com`.
   *
   * @return array<string, \Drupal\domain_availability\Dto\DomainResult>
   *   One result per domain, keyed by domain.
   */
  public function lookup(array $domains): array;

}
