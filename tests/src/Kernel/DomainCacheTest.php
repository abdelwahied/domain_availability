<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\domain_availability\Cache\DomainCache;
use Drupal\domain_availability\Cache\DomainCacheInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests the Drupal Cache API adapter.
  *
  * @group domain_availability
  *
  * @covers \Drupal\domain_availability\Cache\DomainCache::set
  * @covers \Drupal\domain_availability\Cache\DomainCache::get
  * @covers \Drupal\domain_availability\Cache\DomainCache::has
  * @covers \Drupal\domain_availability\Cache\DomainCache::delete
  * @covers \Drupal\domain_availability\Cache\DomainCache::invalidateAll
  *
  * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(DomainCache::class, 'set')]
#[CoversMethod(DomainCache::class, 'get')]
#[CoversMethod(DomainCache::class, 'has')]
#[CoversMethod(DomainCache::class, 'delete')]
#[CoversMethod(DomainCache::class, 'invalidateAll')]
final class DomainCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability'];

  /**
   * The cache under test.
   */
  private DomainCacheInterface $cache;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->cache = $this->container->get('domain_availability.cache');
  }

  /**
   * A stored value comes back intact.
   */
  public function testRoundTrip(): void {
    self::assertNull($this->cache->get('missing'));
    self::assertFalse($this->cache->has('missing'));
    self::assertSame('fallback', $this->cache->get('missing', 'fallback'));

    self::assertTrue($this->cache->set('key', ['a' => 1], 60));
    self::assertSame(['a' => 1], $this->cache->get('key'));
    self::assertTrue($this->cache->has('key'));
  }

  /**
   * A non-positive TTL stores nothing.
   */
  public function testZeroTtlIsNoop(): void {
    self::assertFalse($this->cache->set('zero', 'value', 0));
    self::assertNull($this->cache->get('zero'));
  }

  /**
   * Deleting removes one entry only.
   */
  public function testDelete(): void {
    $this->cache->set('one', 1, 60);
    $this->cache->set('two', 2, 60);

    $this->cache->delete('one');

    self::assertNull($this->cache->get('one'));
    self::assertSame(2, $this->cache->get('two'));
  }

  /**
   * The module tag drops everything this module cached.
   */
  public function testInvalidateAll(): void {
    $this->cache->set('one', 1, 60);
    $this->cache->set('two', 2, 60);

    $this->cache->invalidateAll();

    self::assertNull($this->cache->get('one'));
    self::assertNull($this->cache->get('two'));
  }

  /**
   * A custom tag invalidates a single lookup without touching the rest.
   */
  public function testCustomTagInvalidation(): void {
    $this->cache->set('one', 1, 60, ['domain_availability:label:one']);
    $this->cache->set('two', 2, 60, ['domain_availability:label:two']);

    $this->container->get('cache_tags.invalidator')->invalidateTags(['domain_availability:label:one']);

    self::assertNull($this->cache->get('one'));
    self::assertSame(2, $this->cache->get('two'));
  }

  /**
   * Entries land in the module's own bin, not in someone else's.
   */
  public function testUsesOwnBin(): void {
    $this->cache->set('binned', 'value', 60);

    $bin = $this->container->get('cache.domain_availability');
    $item = $bin->get(DomainCache::TAG . ':' . hash('sha256', 'binned'));

    self::assertNotFalse($item);
    self::assertSame('value', $item->data);
    self::assertContains(DomainCache::TAG, $item->tags);
  }

}
