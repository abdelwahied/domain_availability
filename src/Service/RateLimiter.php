<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Fixed-window rate limiter with a minimum-interval throttle, per client IP.
 *
 * Every lookup fans out to ~20 registries, so an unthrottled client turns this
 * module into an amplifier and gets the site's IP blocked by the registries.
 * That is what this protects — the registries' patience, not just the CPU.
 *
 * Why not core's flood service: it is the usual answer and a fine one, but it
 * only reports "allowed or not". This module reports its quota back to clients
 * through X-RateLimit-Limit / -Remaining / -Reset and Retry-After, and flood
 * exposes neither the count nor the window start, so those headers would have
 * to be dropped or invented. The expirable key-value store keeps the exact
 * counters the standalone version kept in files, and entries expire on their
 * own.
 *
 * Concurrency: read-modify-write under core's lock service, so two PHP-FPM
 * workers cannot lose an increment to a race — the same job flock() did before.
 *
 * @internal
 *   Implementation detail of the endpoint.
 */
final class RateLimiter {

  /**
   * The key-value collection holding the counters.
   */
  private const COLLECTION = 'domain_availability.rate_limit';

  /**
   * Prefix for the per-client lock name.
   */
  private const LOCK_PREFIX = 'domain_availability_rate_limit:';

  /**
   * How long to wait for a client's lock, in seconds.
   */
  private const LOCK_TIMEOUT = 0.2;

  /**
   * Constructs a RateLimiter.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $keyValueFactory
   *   The expirable key-value factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   */
  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ModuleSettings $settings,
  ) {}

  /**
   * Registers a hit for a client and reports the resulting quota state.
   *
   * @param string $identifier
   *   The client identifier, normally an IP address.
   *
   * @return array{allowed: bool, remaining: int, retry_after: int, limit: int, reset: int}
   *   The quota state, whether or not the request was allowed.
   */
  public function hit(string $identifier): array {
    $now = $this->time->getRequestTime();
    $max = $this->settings->rateLimitMaxRequests();
    $window = $this->settings->rateLimitWindow();
    $minInterval = $this->settings->rateLimitMinInterval();

    if (!$this->settings->rateLimitEnabled()) {
      return $this->allowed($now, $max, $window);
    }

    $key = $this->key($identifier);
    $lockName = self::LOCK_PREFIX . $key;

    // Never fail closed on infrastructure contention: if the lock cannot be
    // taken, the request is allowed. A stampede is a smaller problem than an
    // API that stops answering because a lock backend is busy.
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return $this->allowed($now, $max, $window);
    }

    try {
      $store = $this->keyValueFactory->get(self::COLLECTION);
      $state = $this->read($store->get($key), $now);

      if ($state['window_start'] + $window <= $now) {
        $state = ['window_start' => $now, 'count' => 0, 'last_hit' => 0];
      }

      $reset = $state['window_start'] + $window;

      // Throttle: too soon after the previous request.
      if ($minInterval > 0 && $state['last_hit'] > 0 && $now - $state['last_hit'] < $minInterval) {
        $retryAfter = max(1, $minInterval - ($now - $state['last_hit']));
        $this->touch($store, $key, $state, $now, $window);

        return [
          'allowed' => FALSE,
          'remaining' => max(0, $max - $state['count']),
          'retry_after' => $retryAfter,
          'limit' => $max,
          'reset' => $reset,
        ];
      }

      // Quota exhausted for this window.
      if ($state['count'] >= $max) {
        $this->touch($store, $key, $state, $now, $window);

        return [
          'allowed' => FALSE,
          'remaining' => 0,
          'retry_after' => max(1, $reset - $now),
          'limit' => $max,
          'reset' => $reset,
        ];
      }

      $state['count']++;
      $state['last_hit'] = $now;
      $this->write($store, $key, $state, $window);

      return [
        'allowed' => TRUE,
        'remaining' => max(0, $max - $state['count']),
        'retry_after' => 0,
        'limit' => $max,
        'reset' => $reset,
      ];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Forgets every counter.
   *
   * Used by the settings form: tightening a limit while an old, generous window
   * is still counting would otherwise take a full window to take effect.
   */
  public function reset(): void {
    $this->keyValueFactory->get(self::COLLECTION)->deleteAll();
  }

  /**
   * Normalises stored state.
   *
   * Anything unrecognised starts a fresh window rather than being repaired:
   * a corrupt counter must not be able to lock a client out.
   *
   * @param mixed $stored
   *   Whatever came out of the store.
   * @param int $now
   *   The current request time.
   *
   * @return array{window_start: int, count: int, last_hit: int}
   *   The state.
   */
  private function read(mixed $stored, int $now): array {
    if (!is_array($stored) || !isset($stored['window_start'], $stored['count'])) {
      return ['window_start' => $now, 'count' => 0, 'last_hit' => 0];
    }

    return [
      'window_start' => (int) $stored['window_start'],
      'count' => (int) $stored['count'],
      'last_hit' => (int) ($stored['last_hit'] ?? 0),
    ];
  }

  /**
   * Stores state with an expiry a little past the window.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $store
   *   The expirable key-value store.
   * @param string $key
   *   The hashed client key.
   * @param array{window_start: int, count: int, last_hit: int} $state
   *   The state to store.
   * @param int $window
   *   The window length in seconds.
   */
  private function write(object $store, string $key, array $state, int $window): void {
    // The +60 keeps the record alive slightly beyond its window so a client
    // right on the boundary cannot win a fresh quota a second early.
    $store->setWithExpire($key, $state, $window + 60);
  }

  /**
   * Stamps a rejected attempt without crediting it.
   *
   * A client that keeps hammering keeps resetting its own interval, which is
   * the point of the throttle.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $store
   *   The expirable key-value store.
   * @param string $key
   *   The hashed client key.
   * @param array{window_start: int, count: int, last_hit: int} $state
   *   The state to preserve.
   * @param int $now
   *   The current request time.
   * @param int $window
   *   The window length in seconds.
   */
  private function touch(object $store, string $key, array $state, int $now, int $window): void {
    $this->write($store, $key, [
      'window_start' => $state['window_start'],
      'count' => $state['count'],
      'last_hit' => $now,
    ], $window);
  }

  /**
   * The "no limit applied" answer.
   *
   * Returned when limiting is off, and when the lock or the store is
   * unavailable: this module fails open, because a stampede is a smaller
   * problem than an API that stops answering.
   *
   * @param int $now
   *   The current request time.
   * @param int $max
   *   The configured quota.
   * @param int $window
   *   The window length in seconds.
   *
   * @return array{allowed: bool, remaining: int, retry_after: int, limit: int, reset: int}
   *   The quota state.
   */
  private function allowed(int $now, int $max, int $window): array {
    return [
      'allowed' => TRUE,
      'remaining' => $max,
      'retry_after' => 0,
      'limit' => $max,
      'reset' => $now + $window,
    ];
  }

  /**
   * Hashes the identifier.
   *
   * It is a client IP: hashing keeps raw addresses out of the key-value table,
   * which is a database this module has no business storing addresses in.
   *
   * @param string $identifier
   *   The client identifier.
   *
   * @return string
   *   The hashed key.
   */
  private function key(string $identifier): string {
    return hash('sha256', $identifier);
  }

}
