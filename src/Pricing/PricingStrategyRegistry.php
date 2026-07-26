<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\domain_availability\Exception\ConfigurationException;

/**
 * Weight-ordered collection of pricing strategies.
 *
 * The same shape as ProviderRegistry, for the same reason: the set of
 * strategies is data, collected from a service tag, so neither the manager that
 * resolves prices nor the form that configures them holds a list that has to be
 * edited when a mode is added.
 *
 * @internal
 *   The extension point is the `domain_availability_pricing_strategy` service
 *   tag, not this collector. Tag a service; do not call the registry.
 */
final class PricingStrategyRegistry {

  /**
   * The registered strategies, keyed by id, ordered by ascending weight.
   *
   * @var array<string, \Drupal\domain_availability\Pricing\PricingStrategyInterface>
   */
  private array $strategies = [];

  /**
   * Constructs a PricingStrategyRegistry.
   *
   * @param iterable<\Drupal\domain_availability\Pricing\PricingStrategyInterface> $strategies
   *   The strategies to register.
   *
   * @throws \Drupal\domain_availability\Exception\ConfigurationException
   *   When two strategies share an id.
   */
  public function __construct(iterable $strategies = []) {
    foreach ($strategies as $strategy) {
      $this->add($strategy);
    }
  }

  /**
   * Adds a strategy to the registry.
   *
   * @param \Drupal\domain_availability\Pricing\PricingStrategyInterface $strategy
   *   The strategy to add.
   *
   * @throws \Drupal\domain_availability\Exception\ConfigurationException
   *   When the id is already taken.
   */
  public function add(PricingStrategyInterface $strategy): void {
    $id = $strategy->id();

    if (isset($this->strategies[$id])) {
      // Two strategies answering to one `pricing.mode` would make the active
      // one depend on service registration order — a price that changes when
      // an unrelated module is installed.
      throw new ConfigurationException("Duplicate pricing strategy id: {$id}.");
    }

    $this->strategies[$id] = $strategy;

    uasort(
      $this->strategies,
      static fn (PricingStrategyInterface $a, PricingStrategyInterface $b): int
        => [$a->weight(), $a->id()] <=> [$b->weight(), $b->id()],
    );
  }

  /**
   * Every registered strategy.
   *
   * @return array<string, \Drupal\domain_availability\Pricing\PricingStrategyInterface>
   *   The strategies, keyed by id, ordered by ascending weight.
   */
  public function all(): array {
    return $this->strategies;
  }

  /**
   * One strategy by id.
   *
   * @param string $id
   *   The strategy id.
   *
   * @return \Drupal\domain_availability\Pricing\PricingStrategyInterface|null
   *   The strategy, or NULL when nothing carries that id.
   */
  public function get(string $id): ?PricingStrategyInterface {
    return $this->strategies[$id] ?? NULL;
  }

  /**
   * Whether a strategy is registered under an id.
   *
   * @param string $id
   *   The strategy id.
   *
   * @return bool
   *   TRUE when the id is registered.
   */
  public function has(string $id): bool {
    return isset($this->strategies[$id]);
  }

  /**
   * The lowest-weight strategy, used as the default selection.
   *
   * @return \Drupal\domain_availability\Pricing\PricingStrategyInterface|null
   *   The first strategy, or NULL when none is registered.
   */
  public function first(): ?PricingStrategyInterface {
    foreach ($this->strategies as $strategy) {
      return $strategy;
    }

    return NULL;
  }

  /**
   * Every registered strategy id.
   *
   * @return array<int, string>
   *   The ids, ordered by ascending weight.
   */
  public function ids(): array {
    return array_keys($this->strategies);
  }

}
