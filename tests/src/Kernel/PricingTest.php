<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\domain_availability\Dto\CheckReport;
use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Pricing\ExtensionPriceStrategy;
use Drupal\domain_availability\Pricing\FixedPriceStrategy;
use Drupal\domain_availability\Pricing\PriceValue;
use Drupal\domain_availability\Pricing\PricingManager;
use Drupal\domain_availability\Pricing\PricingSettings;
use Drupal\domain_availability\Service\DomainCheckService;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests price resolution against the container, real config and real cache.
 *
 * Everything here goes through PricingManager, on purpose: it is the only
 * supported way to ask what something costs, and a test that reached into the
 * configuration would be testing the storage rather than the contract.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\Pricing\PricingManager
 * @covers \Drupal\domain_availability\Pricing\FixedPriceStrategy
 * @covers \Drupal\domain_availability\Pricing\ExtensionPriceStrategy
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversClass(PricingManager::class)]
#[CoversClass(FixedPriceStrategy::class)]
#[CoversClass(ExtensionPriceStrategy::class)]
final class PricingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test'];

  /**
   * The manager under test.
   */
  private PricingManager $pricing;

  /**
   * The check service, for the end-to-end assertions.
   */
  private DomainCheckService $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);

    // The stub provider answers everything, so no test here can reach a
    // registry just because it wanted a priced result.
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('tlds', ['com', 'sa', 'net'])
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();

    $this->pricing = $this->container->get('domain_availability.pricing_manager');
    $this->checker = $this->container->get('domain_availability.checker');
  }

  /**
   * The shipped defaults price every extension the same.
   */
  public function testFixedPricingIsTheInstalledDefault(): void {
    self::assertSame(FixedPriceStrategy::ID, $this->pricing->activeStrategy()?->id());

    foreach (['.com', '.sa', '.net', '.xyz'] as $extension) {
      self::assertSame('35.00', $this->pricing->getPrice($extension)?->formatted());
    }
  }

  /**
   * A fixed price accepts decimals and keeps them.
   */
  public function testFixedPricingKeepsDecimals(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => FixedPriceStrategy::ID,
      FixedPriceStrategy::PRICE_KEY => 49.99,
    ]);

    $price = $this->pricing->getPrice('com');

    self::assertNotNull($price);
    self::assertSame(49.99, $price->amount);
    self::assertSame('49.99', $price->formatted());
  }

  /**
   * Per-extension pricing gives each TLD its own number.
   */
  public function testExtensionPricing(): void {
    $this->useExtensionPricing();

    self::assertSame('45.00', $this->pricing->getPrice('com')?->formatted());
    self::assertSame('120.50', $this->pricing->getPrice('sa')?->formatted());
    self::assertSame('40.00', $this->pricing->getPrice('net')?->formatted());
  }

  /**
   * A leading dot is accepted everywhere a TLD is.
   */
  public function testExtensionLookupIsDotAndCaseInsensitive(): void {
    $this->useExtensionPricing();

    self::assertSame('45.00', $this->pricing->getPrice('.com')?->formatted());
    self::assertSame('45.00', $this->pricing->getPrice('COM')?->formatted());
    self::assertSame('45.00', $this->pricing->getPrice('  .CoM ')?->formatted());
  }

  /**
   * An extension nobody priced has no price — it is not free, and not an error.
   */
  public function testUnknownExtensionIsUnpriced(): void {
    $this->useExtensionPricing();

    self::assertNull($this->pricing->getPrice('org'));
    self::assertNull($this->pricing->getPrice('.example'));
    self::assertNull($this->pricing->getPrice(''));
  }

  /**
   * A blank price in the table means "not sold", not "free".
   */
  public function testBlankExtensionPriceIsUnpriced(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => ['com' => 45.0, 'sa' => ''],
    ]);

    self::assertSame('45.00', $this->pricing->getPrice('com')?->formatted());
    self::assertNull($this->pricing->getPrice('sa'));
  }

  /**
   * Configuration with no pricing at all degrades to unpriced results.
   *
   * Reachable on a site that imported a configuration export taken before this
   * feature existed.
   */
  public function testEmptyConfigurationIsUnpriced(): void {
    $this->config(ModuleSettings::CONFIG_NAME)->clear(PricingSettings::CONFIG_KEY)->save();

    self::assertNull($this->pricing->activeStrategy());
    self::assertNull($this->pricing->getPrice('com'));

    $report = $this->checker->check('empty');

    foreach ($report->results as $result) {
      self::assertNull($result->price);
      self::assertArrayNotHasKey('price', $result->toArray());
    }
  }

  /**
   * Configuration that was never a price never becomes one.
   *
   * This is the config-import path, which no form validation stands in front
   * of. Drupal's typed configuration casts on save — the schema says these keys
   * are floats, so `'not-a-number'` is stored as 0.0 before anything here can
   * object. Discarding a zero price is what keeps a bad import from
   * advertising the whole site as free.
   *
   * Only values a real save survives are used: an array under a float key is
   * rejected outright by ConfigSchemaChecker, so it cannot reach this code
   * through configuration at all. That non-scalars still resolve to NULL is
   * covered where it can be, in PriceValueTest.
   */
  public function testMalformedImportedConfigurationIsNotPriced(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => 'not-a-number',
        'net' => NULL,
        'org' => '45.50',
      ],
    ]);

    self::assertNull($this->pricing->getPrice('com'), 'A string cast to 0.0 must not render as a price.');
    self::assertNull($this->pricing->getPrice('net'));
    self::assertSame('45.50', $this->pricing->getPrice('org')?->formatted(), 'A numeric string is still a valid price.');
  }

  /**
   * A malformed fixed price leaves every extension unpriced.
   */
  public function testMalformedFixedPriceIsNotPriced(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => FixedPriceStrategy::ID,
      FixedPriceStrategy::PRICE_KEY => 'thirty five',
    ]);

    self::assertNull($this->pricing->getPrice('com'));
  }

  /**
   * An explicit zero is discarded, from any strategy.
   */
  public function testZeroIsNotPriced(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => FixedPriceStrategy::ID,
      FixedPriceStrategy::PRICE_KEY => 0.0,
    ]);

    self::assertNull($this->pricing->getPrice('com'));

    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => ['com' => 0.0, 'sa' => 120.5],
    ]);

    self::assertNull($this->pricing->getPrice('com'));
    self::assertSame('120.50', $this->pricing->getPrice('sa')?->formatted());
  }

  /**
   * An invalid price never reaches the results, or the API payload.
   */
  public function testInvalidPriceIsNeverRendered(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => ['com' => 'free!', 'sa' => 0.0, 'net' => 40.0],
    ]);

    $byDomain = $this->index($this->checker->check('broken'));

    self::assertNull($byDomain['broken.com']->price);
    self::assertNull($byDomain['broken.sa']->price);
    self::assertSame('40.00', $byDomain['broken.net']->price?->formatted());

    $payload = $this->checker->check('broken')->toArray();

    self::assertArrayNotHasKey('price', $payload['results'][0], 'com must carry no price key at all.');
    self::assertArrayNotHasKey('price', $payload['results'][1]);
    self::assertSame('40.00', $payload['results'][2]['price']['formatted']);
  }

  /**
   * A mode nobody implements is degraded, not fatal.
   */
  public function testUnknownModeIsUnpriced(): void {
    $this->setPricing([PricingSettings::MODE_KEY => 'promotional']);

    self::assertNull($this->pricing->activeStrategy());
    self::assertNull($this->pricing->getPrice('com'));
  }

  /**
   * Switching modes switches the answer, with no cache to flush in between.
   */
  public function testSwitchingModes(): void {
    self::assertSame('35.00', $this->pricing->getPrice('sa')?->formatted());

    $this->useExtensionPricing();
    self::assertSame('120.50', $this->pricing->getPrice('sa')?->formatted());

    $this->setPricing([
      PricingSettings::MODE_KEY => FixedPriceStrategy::ID,
      FixedPriceStrategy::PRICE_KEY => 35.0,
      // The table survives the switch, so switching back restores it.
      ExtensionPriceStrategy::PRICES_KEY => ['com' => 45.0, 'sa' => 120.5, 'net' => 40.0],
    ]);
    self::assertSame('35.00', $this->pricing->getPrice('sa')?->formatted());

    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => ['com' => 45.0, 'sa' => 120.5, 'net' => 40.0],
    ]);
    self::assertSame('120.50', $this->pricing->getPrice('sa')?->formatted());
  }

  /**
   * A check attaches the price of each result's own extension.
   */
  public function testReportResultsArePriced(): void {
    $this->useExtensionPricing();

    $report = $this->checker->check('priced');
    $byDomain = [];

    foreach ($report->results as $result) {
      $byDomain[$result->domain] = $result;
    }

    self::assertSame('45.00', $byDomain['priced.com']->price?->formatted());
    self::assertSame('120.50', $byDomain['priced.sa']->price?->formatted());
    self::assertSame('40.00', $byDomain['priced.net']->price?->formatted());
  }

  /**
   * The API payload carries the price, and keeps every key it carried before.
   */
  public function testPriceIsInTheApiPayload(): void {
    $payload = $this->checker->check('taken')->toArray();
    $first = $payload['results'][0];

    self::assertSame(['domain', 'extension', 'available', 'status', 'provider', 'price'], array_keys($first));
    self::assertSame([
      'amount' => 35.0,
      'currency' => 'SAR',
      'formatted' => '35.00',
    ], $first['price']);
  }

  /**
   * A price change reaches a cached report without waiting for the cache.
   *
   * The regression this locks down: a cached report must be priced from the
   * configuration as it is *now*. The lookup cache holds what the registries
   * answered, which stays true for its whole TTL; a price is configuration an
   * administrator expects to take effect on the next search. Fails if the
   * cached path stops being priced, or if a stale price is ever restored from
   * the cache instead.
   */
  public function testPricesAreNotBakedIntoTheLookupCache(): void {
    $first = $this->checker->check('cached');
    self::assertFalse($first->cached);
    self::assertSame('35.00', $first->results[0]->price?->formatted());

    $this->setPricing([
      PricingSettings::MODE_KEY => FixedPriceStrategy::ID,
      FixedPriceStrategy::PRICE_KEY => 99.0,
    ]);

    $second = $this->checker->check('cached');

    self::assertTrue($second->cached, 'The lookup itself should still be served from cache.');
    self::assertSame('99.00', $second->results[0]->price?->formatted(), 'The new price should apply to a cached report.');

    // And switching pricing off entirely leaves the same cached lookup
    // unpriced, rather than resurrecting the number it was first served with.
    $this->config(ModuleSettings::CONFIG_NAME)->clear(PricingSettings::CONFIG_KEY)->save();

    $third = $this->checker->check('cached');

    self::assertTrue($third->cached);
    self::assertNull($third->results[0]->price);
  }

  /**
   * Rendered prices declare the configuration they came from.
   */
  public function testCacheabilityFollowsTheConfiguration(): void {
    $metadata = $this->pricing->getCacheableMetadata();

    self::assertContains('config:' . ModuleSettings::CONFIG_NAME, $metadata->getCacheTags());
  }

  /**
   * A result can be priced without being rebuilt, and stays immutable.
   */
  public function testWithPriceIsNonDestructiveCopy(): void {
    $result = new DomainResult('example.com', '.com', DomainStatus::Available, 'stub');
    $priced = $result->withPrice(new PriceValue(45.0));

    self::assertNull($result->price);
    self::assertSame(45.0, $priced->price?->amount);
    self::assertSame($result->domain, $priced->domain);
    self::assertSame($result->status, $priced->status);
    self::assertSame($result->provider, $priced->provider);
  }

  /**
   * Both shipped strategies are registered and discoverable.
   */
  public function testShippedStrategiesAreCollectedFromTheTag(): void {
    self::assertSame(
      [FixedPriceStrategy::ID, ExtensionPriceStrategy::ID],
      array_keys($this->pricing->strategies()),
    );
    self::assertSame(FixedPriceStrategy::ID, $this->pricing->defaultMode());
  }

  /**
   * Switches to a per-extension table with a decimal in it.
   */
  private function useExtensionPricing(): void {
    $this->setPricing([
      PricingSettings::MODE_KEY => ExtensionPriceStrategy::ID,
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => 45.0,
        'sa' => 120.5,
        'net' => 40.0,
      ],
    ]);
  }

  /**
   * Indexes a report's results by domain.
   *
   * @param \Drupal\domain_availability\Dto\CheckReport $report
   *   The report.
   *
   * @return array<string, \Drupal\domain_availability\Dto\DomainResult>
   *   The results, keyed by domain.
   */
  private function index(CheckReport $report): array {
    $byDomain = [];

    foreach ($report->results as $result) {
      $byDomain[$result->domain] = $result;
    }

    return $byDomain;
  }

  /**
   * Writes the pricing mapping.
   *
   * @param array<string, mixed> $pricing
   *   The mapping to store.
   */
  private function setPricing(array $pricing): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set(PricingSettings::CONFIG_KEY, $pricing)
      ->save();
  }

}
