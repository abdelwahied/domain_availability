<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Service\DomainCheckService;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests the check service against the container, config and real cache.
 *
 * Uses the stub provider, so nothing here touches a registry: the test suite
 * must not fail because a WHOIS server is having a bad day.
  *
  * @group domain_availability
  *
  * @covers \Drupal\domain_availability\Service\DomainCheckService::check
  *
  * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(DomainCheckService::class, 'check')]
final class DomainCheckServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test'];

  /**
   * The service under test.
   */
  private DomainCheckService $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);

    // The stub is the only provider allowed to answer. Leaving the real ones
    // on would let an "unknown" fall through the chain to RDAP and reach the
    // network — which is exactly what happened the first time this suite ran,
    // and it made the test depend on a registry being both reachable and
    // truthful about a made-up domain.
    $this->config('domain_availability.settings')
      ->set('tlds', ['com', 'sa', 'net'])
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();

    $this->checker = $this->container->get('domain_availability.checker');
  }

  /**
   * Every configured TLD comes back, in configured order.
   */
  public function testChecksEveryTldInOrder(): void {
    $report = $this->checker->check('free');

    self::assertSame('free', $report->query);
    self::assertCount(3, $report->results);
    self::assertSame(['free.com', 'free.sa', 'free.net'], array_map(
      static fn ($result): string => $result->domain,
      $report->results,
    ));
  }

  /**
   * The three outcomes map to the right tri-state.
   */
  public function testStatusesAndAvailability(): void {
    $report = $this->checker->check('taken');
    $byDomain = $this->index($report);

    self::assertSame(DomainStatus::Registered, $byDomain['taken.com']->status);
    self::assertFalse($byDomain['taken.com']->status->toAvailability());

    $report = $this->checker->check('free');
    $byDomain = $this->index($report);
    self::assertSame(DomainStatus::Available, $byDomain['free.com']->status);
    self::assertTrue($byDomain['free.com']->status->toAvailability());
  }

  /**
   * An unanswerable lookup is unknown, and availability stays NULL.
   *
   * This is the module's central promise: null is not false, and it is
   * certainly not true.
   */
  public function testUnknownIsNeverAvailable(): void {
    $report = $this->checker->check('mystery');
    $byDomain = $this->index($report);

    self::assertSame(DomainStatus::Unknown, $byDomain['mystery.com']->status);
    self::assertNull($byDomain['mystery.com']->status->toAvailability());
    self::assertNotTrue($byDomain['mystery.com']->status->toAvailability());
  }

  /**
   * The second identical check is served from cache.
   */
  public function testCacheHit(): void {
    $first = $this->checker->check('cacheme');
    self::assertFalse($first->cached);

    $second = $this->checker->check('cacheme');
    self::assertTrue($second->cached);
    self::assertSame(
      array_map(static fn ($r): string => $r->domain, $first->results),
      array_map(static fn ($r): string => $r->domain, $second->results),
    );
  }

  /**
   * Disabling the cache makes every check hit the providers.
   */
  public function testCacheCanBeDisabled(): void {
    $this->config('domain_availability.settings')->set('cache_enabled', FALSE)->save();

    self::assertFalse($this->checker->check('nocache')->cached);
    self::assertFalse($this->checker->check('nocache')->cached);
  }

  /**
   * Changing the TLD list must not serve a stale, partial answer.
   *
   * The cache key includes the TLD set precisely so this cannot happen.
   */
  public function testTldChangeBypassesStaleCache(): void {
    self::assertCount(3, $this->checker->check('shifting')->results);

    $this->config('domain_availability.settings')->set('tlds', ['com', 'sa', 'net', 'org'])->save();

    $report = $this->checker->check('shifting');
    self::assertCount(4, $report->results);
    self::assertFalse($report->cached);
  }

  /**
   * Invalidating the module's cache tag drops cached lookups.
   */
  public function testCacheTagInvalidation(): void {
    $this->checker->check('tagged');
    self::assertTrue($this->checker->check('tagged')->cached);

    $this->container->get('domain_availability.cache')->invalidateAll();

    self::assertFalse($this->checker->check('tagged')->cached);
  }

  /**
   * The stub answers ahead of the real providers, proving tag collection works.
   */
  public function testProviderIsCollectedFromTag(): void {
    $report = $this->checker->check('free');

    foreach ($report->results as $result) {
      self::assertSame('stub', $result->provider);
    }

    self::assertContains('stub', $this->container->get('domain_availability.provider_registry')->names());
  }

  /**
   * The API payload keeps the standalone contract.
   */
  public function testReportArrayShape(): void {
    $payload = $this->checker->check('taken')->toArray();

    self::assertTrue($payload['success']);
    self::assertSame('taken', $payload['query']);
    self::assertIsInt($payload['took_ms']);
    self::assertFalse($payload['cached']);
    self::assertArrayHasKey('results', $payload);

    $first = $payload['results'][0];
    self::assertSame(['domain', 'extension', 'available', 'status', 'provider'], array_keys($first));
    self::assertSame('taken.com', $first['domain']);
    self::assertSame('.com', $first['extension']);
    self::assertFalse($first['available']);
    self::assertSame('registered', $first['status']);
  }

  /**
   * Indexes results by domain.
   *
   * @param \Drupal\domain_availability\Dto\CheckReport $report
   *   The report.
   *
   * @return array<string, \Drupal\domain_availability\Dto\DomainResult>
   *   The results, keyed by domain.
   */
  private function index($report): array {
    $indexed = [];

    foreach ($report->results as $result) {
      $indexed[$result->domain] = $result;
    }

    return $indexed;
  }

}
