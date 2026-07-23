<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Exception\ConfigurationException;
use Drupal\domain_availability\Utility\Tld;

/**
 * Priority-ordered collection of providers.
 *
 * This is the seam that makes the lookup strategy data driven: the check
 * service asks the registry which provider owns a TLD and which one comes next
 * when an answer is inconclusive, so adding a protocol never touches the
 * service or the controller.
 *
 * @internal
 *   The extension point is the `domain_availability_provider` service tag, not
 *   this collector. Tag a service; do not call the registry.
 */
final class ProviderRegistry {
  /**
   * The registered providers, sorted by ascending priority.
   *
   * @var list<DomainProviderInterface>
   */
  private array $providers = [];

  /**
   * Constructs a ProviderRegistry.
   *
   * @param iterable<DomainProviderInterface> $providers
   *   The providers to register.
   *
   * @throws \Drupal\domain_availability\Exception\ConfigurationException
   *   When two providers share a name.
   */
  public function __construct(iterable $providers = []) {
    foreach ($providers as $provider) {
      $this->add($provider);
    }
  }

  /**
   * Adds a provider to the registry.
   *
   * @param DomainProviderInterface $provider
   *   The provider to add.
   *
   * @throws \Drupal\domain_availability\Exception\ConfigurationException
   *   When the name is already taken.
   */
  public function add(DomainProviderInterface $provider): void {
    foreach ($this->providers as $existing) {
      if ($existing->name() === $provider->name()) {
        throw new ConfigurationException(
              "Duplicate provider name: {$provider->name()}.",
          );
      }
    }

    $this->providers[] = $provider;

    usort(
          $this->providers,
          static fn (DomainProviderInterface $a, DomainProviderInterface $b): int
              => $a->priority() <=> $b->priority(),
      );
  }

  /**
   * The best provider for a TLD, or null when nothing supports it.
   */
  public function bestFor(string $tld): ?DomainProviderInterface {
    return $this->chainFor($tld)[0] ?? NULL;
  }

  /**
   * Every provider that supports a TLD, best first.
   *
   * The tail of the list is the fallback chain used when a provider answers
   * `unknown`.
   *
   * @return list<DomainProviderInterface>
   *   The supporting providers, best first.
   */
  public function chainFor(string $tld): array {
    $tld = Tld::normalise($tld);

    return array_values(array_filter(
          $this->providers,
          static fn (DomainProviderInterface $provider): bool => $provider->supports($tld),
      ));
  }

  /**
   * The provider that follows `$after` in a TLD's chain.
   *
   * Returns null when the chain is exhausted.
   */
  public function nextAfter(string $tld, string $after): ?DomainProviderInterface {
    $chain = $this->chainFor($tld);

    foreach ($chain as $index => $provider) {
      if ($provider->name() === $after) {
        return $chain[$index + 1] ?? NULL;
      }
    }

    // The previous provider does not support this TLD (it was reached as a
    // generic fallback), so the chain simply starts from the top.
    return $chain[0] ?? NULL;
  }

  /**
   * All registered providers, in priority order.
   *
   * @return list<DomainProviderInterface>
   *   The registered providers.
   */
  public function all(): array {
    return $this->providers;
  }

  /**
   * The names of all registered providers.
   *
   * @return list<string>
   *   The provider names.
   */
  public function names(): array {
    return array_map(
          static fn (DomainProviderInterface $provider): string => $provider->name(),
          $this->providers,
      );
  }

}
