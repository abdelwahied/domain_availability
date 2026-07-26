<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\domain_availability\Exception\ConfigurationException;
use Drupal\domain_availability\Pricing\PriceValue;
use Drupal\domain_availability\Pricing\PricingContext;
use Drupal\domain_availability\Pricing\PricingStrategyInterface;
use Drupal\domain_availability\Pricing\PricingStrategyRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the collector that turns the service tag into an ordered set.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\Pricing\PricingStrategyRegistry
 */
#[Group('domain_availability')]
#[CoversClass(PricingStrategyRegistry::class)]
final class PricingStrategyRegistryTest extends UnitTestCase {

  /**
   * Strategies are ordered by weight, whatever order they were collected in.
   */
  public function testOrderedByWeight(): void {
    $registry = new PricingStrategyRegistry([
      $this->strategy('c', 30),
      $this->strategy('a', 10),
      $this->strategy('b', 20),
    ]);

    self::assertSame(['a', 'b', 'c'], $registry->ids());
    self::assertSame('a', $registry->first()?->id());
  }

  /**
   * A strategy is found by the id that `pricing.mode` stores.
   */
  public function testLookupById(): void {
    $registry = new PricingStrategyRegistry([$this->strategy('fixed', 0)]);

    self::assertTrue($registry->has('fixed'));
    self::assertSame('fixed', $registry->get('fixed')?->id());
    self::assertFalse($registry->has('promotional'));
    self::assertNull($registry->get('promotional'));
  }

  /**
   * Two strategies claiming one mode is a configuration error, not a race.
   */
  public function testDuplicateIdIsRejected(): void {
    $this->expectException(ConfigurationException::class);

    new PricingStrategyRegistry([
      $this->strategy('fixed', 0),
      $this->strategy('fixed', 10),
    ]);
  }

  /**
   * An empty registry is a valid state, not a crash.
   */
  public function testEmptyRegistry(): void {
    $registry = new PricingStrategyRegistry();

    self::assertSame([], $registry->all());
    self::assertSame([], $registry->ids());
    self::assertNull($registry->first());
  }

  /**
   * Builds a stub strategy.
   *
   * @param string $id
   *   The strategy id.
   * @param int $weight
   *   The ordering weight.
   *
   * @return \Drupal\domain_availability\Pricing\PricingStrategyInterface
   *   The stub.
   */
  private function strategy(string $id, int $weight): PricingStrategyInterface {
    return new class($id, $weight) implements PricingStrategyInterface {

      /**
       * Constructs the stub.
       *
       * @param string $id
       *   The strategy id.
       * @param int $weight
       *   The ordering weight.
       */
      public function __construct(private readonly string $id, private readonly int $weight) {}

      /**
       * {@inheritdoc}
       */
      public function id(): string {
        return $this->id;
      }

      /**
       * {@inheritdoc}
       */
      public function label(): TranslatableMarkup {
        return new TranslatableMarkup('Stub');
      }

      /**
       * {@inheritdoc}
       */
      public function description(): TranslatableMarkup {
        return new TranslatableMarkup('Stub');
      }

      /**
       * {@inheritdoc}
       */
      public function weight(): int {
        return $this->weight;
      }

      /**
       * {@inheritdoc}
       */
      public function priceFor(PricingContext $context): ?PriceValue {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheTags(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheContexts(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheMaxAge(): int {
        return Cache::PERMANENT;
      }

    };
  }

}
