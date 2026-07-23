<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\domain_availability\Service\RateLimiter;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests the per-IP quota and throttle.
  *
  * @group domain_availability
  *
  * @covers \Drupal\domain_availability\Service\RateLimiter::hit
  * @covers \Drupal\domain_availability\Service\RateLimiter::reset
  *
  * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(RateLimiter::class, 'hit')]
#[CoversMethod(RateLimiter::class, 'reset')]
final class RateLimiterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability'];

  /**
   * The limiter under test.
   */
  private RateLimiter $limiter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);
    $this->limiter = $this->container->get('domain_availability.rate_limiter');
  }

  /**
   * The quota is spent and then refused, with a Retry-After to honour.
   *
   * The minimum interval is off here: this test is about the window, and with
   * the throttle on the second request would be refused for the other reason.
   */
  public function testWindowQuota(): void {
    $this->config('domain_availability.settings')
      ->set('rate_limit_max_requests', 3)
      ->set('rate_limit_min_interval', 0)
      ->save();

    $remaining = [];

    for ($i = 0; $i < 3; $i++) {
      $state = $this->limiter->hit('198.51.100.7');
      self::assertTrue($state['allowed'], "Request $i should be allowed.");
      $remaining[] = $state['remaining'];
    }

    self::assertSame([2, 1, 0], $remaining);

    $state = $this->limiter->hit('198.51.100.7');
    self::assertFalse($state['allowed']);
    self::assertSame(0, $state['remaining']);
    self::assertGreaterThan(0, $state['retry_after']);
    self::assertSame(3, $state['limit']);
  }

  /**
   * The throttle refuses a second request inside the minimum interval.
   */
  public function testMinIntervalThrottle(): void {
    $this->config('domain_availability.settings')
      ->set('rate_limit_max_requests', 100)
      ->set('rate_limit_min_interval', 5)
      ->save();

    self::assertTrue($this->limiter->hit('203.0.113.9')['allowed']);

    $state = $this->limiter->hit('203.0.113.9');
    self::assertFalse($state['allowed']);
    self::assertGreaterThan(0, $state['retry_after']);
    self::assertLessThanOrEqual(5, $state['retry_after']);
  }

  /**
   * Clients are counted separately.
   */
  public function testPerIpIsolation(): void {
    $this->config('domain_availability.settings')
      ->set('rate_limit_max_requests', 1)
      ->set('rate_limit_min_interval', 0)
      ->save();

    self::assertTrue($this->limiter->hit('192.0.2.1')['allowed']);
    self::assertFalse($this->limiter->hit('192.0.2.1')['allowed']);
    // A different client must be unaffected by the first one's spending.
    self::assertTrue($this->limiter->hit('192.0.2.2')['allowed']);
  }

  /**
   * Disabling the limiter allows everything.
   */
  public function testDisabled(): void {
    $this->config('domain_availability.settings')
      ->set('rate_limit_enabled', FALSE)
      ->set('rate_limit_max_requests', 1)
      ->save();

    for ($i = 0; $i < 5; $i++) {
      self::assertTrue($this->limiter->hit('198.51.100.50')['allowed']);
    }
  }

  /**
   * Resetting forgets every counter.
   */
  public function testReset(): void {
    $this->config('domain_availability.settings')
      ->set('rate_limit_max_requests', 1)
      ->set('rate_limit_min_interval', 0)
      ->save();

    self::assertTrue($this->limiter->hit('198.51.100.60')['allowed']);
    self::assertFalse($this->limiter->hit('198.51.100.60')['allowed']);

    $this->limiter->reset();

    self::assertTrue($this->limiter->hit('198.51.100.60')['allowed']);
  }

}
