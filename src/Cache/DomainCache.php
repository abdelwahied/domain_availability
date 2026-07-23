<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Drupal Cache API implementation of the lookup cache.
 *
 * Replaces the standalone filesystem cache. Everything lands in the module's
 * own `domain_availability` bin, so a site can bind it to Redis or Memcache in
 * settings.php without touching a line of this module, and flushing lookups
 * never disturbs render or discovery caches.
 * Every entry carries the DomainCache::TAG tag, which is what makes "clear the
 * lookup cache" a single tag invalidation — from the settings form, from Drush,
 * or from any other module that has a reason to.
 *
 * @internal
 *   The implementation; depend on DomainCacheInterface.
 */
final class DomainCache implements DomainCacheInterface {

  /**
   * The cache tag applied to every entry this module writes.
   */
  public const TAG = 'domain_availability';

  /**
   * Constructs a DomainCache.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The module's cache bin.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service. Injected rather than calling time() so tests can
   *   travel through an expiry without sleeping.
   */
  public function __construct(
    private readonly CacheBackendInterface $cacheBackend,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key, mixed $default = NULL): mixed {
    $item = $this->cacheBackend->get($this->key($key));

    // A cache item is only trustworthy when it is both present and valid:
    // an invalidated-but-not-yet-deleted item still comes back from the
    // backend, and serving it would resurrect data the site just flushed.
    if ($item === FALSE || $item->valid === FALSE) {
      return $default;
    }

    return $item->data;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value, int $ttl, array $tags = []): bool {
    if ($ttl <= 0) {
      return FALSE;
    }

    $this->cacheBackend->set(
      $this->key($key),
      $value,
      $this->time->getRequestTime() + $ttl,
      Cache::mergeTags([self::TAG], $tags),
    );

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $key): bool {
    return $this->get($key, NULL) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): bool {
    $this->cacheBackend->delete($this->key($key));

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::TAG]);
  }

  /**
   * Namespaces a key inside the bin.
   *
   * Hashing keeps arbitrary user input out of the cache ID and bounds its
   * length, which the database backend's cid column cares about.
   *
   * @param string $key
   *   The caller's key.
   *
   * @return string
   *   The namespaced, hashed cache ID.
   */
  private function key(string $key): string {
    return self::TAG . ':' . hash('sha256', $key);
  }

}
