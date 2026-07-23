<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Cache;

/**
 * The caching contract every lookup service depends on.
 *
 * Deliberately narrower than CacheBackendInterface: services here only ever
 * need "give me this value or nothing" and "keep this value for N seconds".
 * Depending on the small contract instead of the backend keeps the services
 * unit-testable with a plain array double, and leaves the backend (database,
 * Redis, Memcache — whatever the site binds to the bin) entirely a deployment
 * decision.
 *
 * @api
 *   Public and stable since 1.0.0. Swap the cache by binding another
 *   implementation to `domain_availability.cache`.
 */
interface DomainCacheInterface {

  /**
   * Fetches a value.
   *
   * @param string $key
   *   The cache key.
   * @param mixed $default
   *   Returned when the key is missing or expired.
   *
   * @return mixed
   *   The cached value, or $default.
   */
  public function get(string $key, mixed $default = NULL): mixed;

  /**
   * Stores a value.
   *
   * @param string $key
   *   The cache key.
   * @param mixed $value
   *   The value to store.
   * @param int $ttl
   *   Lifetime in seconds. A TTL of 0 or less is a no-op.
   * @param list<string> $tags
   *   Cache tags, merged with the module's own tag.
   *
   * @return bool
   *   TRUE when the value was stored.
   */
  public function set(string $key, mixed $value, int $ttl, array $tags = []): bool;

  /**
   * Whether a fresh value exists for a key.
   *
   * @param string $key
   *   The cache key.
   *
   * @return bool
   *   TRUE when a fresh value exists.
   */
  public function has(string $key): bool;

  /**
   * Deletes one entry.
   *
   * @param string $key
   *   The cache key.
   *
   * @return bool
   *   TRUE when the entry was deleted.
   */
  public function delete(string $key): bool;

  /**
   * Invalidates every entry this cache owns.
   */
  public function invalidateAll(): void;

}
