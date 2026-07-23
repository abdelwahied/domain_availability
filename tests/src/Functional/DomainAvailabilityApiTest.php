<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JSON endpoint end to end: permissions, contract, errors.
 *
 * The stub provider answers, so the contract is tested without depending on a
 * registry being reachable from wherever this suite runs.
 */
#[Group('domain_availability')]
#[RunTestsInSeparateProcesses]
final class DomainAvailabilityApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('domain_availability.settings')
      ->set('tlds', ['com', 'sa'])
      // The throttle would refuse the second request of every test method.
      ->set('rate_limit_min_interval', 0)
      // The stub is the only provider allowed to answer. With the real ones on,
      // an "unknown" falls through the chain to RDAP and reaches the network —
      // which makes the assertion depend on a registry being reachable and
      // truthful about a made-up domain.
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();
  }

  /**
   * The endpoint is closed to anonymous users by default.
   */
  public function testApiRequiresPermission(): void {
    $this->drupalGet('/domain-check', ['query' => ['domain' => 'free']]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The JSON contract matches the standalone application byte for byte.
   */
  public function testApiContract(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'taken']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertTrue($payload['success']);
    self::assertSame('taken', $payload['query']);
    self::assertIsInt($payload['took_ms']);
    self::assertFalse($payload['cached']);
    self::assertCount(2, $payload['results']);

    self::assertSame([
      'domain' => 'taken.com',
      'extension' => '.com',
      'available' => FALSE,
      'status' => 'registered',
      'provider' => 'stub',
    ], $payload['results'][0]);
  }

  /**
   * A URL is reduced to its label, exactly as the form does.
   */
  public function testApiSanitizesInput(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'https://www.Example.com/pricing?x=1']]);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertSame('example', $payload['query']);
  }

  /**
   * Unknown never claims availability, and says why.
   */
  public function testUnknownCarriesReasonAndNullAvailability(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'mystery']]);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertSame('unknown', $payload['results'][0]['status']);
    self::assertNull($payload['results'][0]['available']);
    self::assertSame('stub_unknown', $payload['results'][0]['reason']);
  }

  /**
   * Tests that bad input is a 422 with a safe, structured error.
   *
   * @param string $input
   *   The invalid input.
   */
  #[DataProvider('invalidInputProvider')]
  public function testValidationErrors(string $input): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => $input]]);
    $this->assertSession()->statusCodeEquals(422);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertFalse($payload['success']);
    self::assertSame('validation_error', $payload['error']);
    self::assertArrayHasKey('domain', $payload['errors']);
    // The reflected input must never come back as markup.
    self::assertStringNotContainsString('<script>', $payload['message']);
  }

  /**
   * Invalid inputs.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function invalidInputProvider(): array {
    return [
      'empty' => [''],
      'underscore' => ['bad_name'],
      'leading hyphen' => ['-lead'],
      'too long' => [str_repeat('a', 64)],
      'xss' => ['<script>alert(1)</script>'],
    ];
  }

  /**
   * The quota is enforced and reported through headers.
   */
  public function testRateLimiting(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->config('domain_availability.settings')
      ->set('rate_limit_max_requests', 2)
      ->set('rate_limit_min_interval', 0)
      ->save();

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'one']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-RateLimit-Limit', '2');
    $this->assertSession()->responseHeaderEquals('X-RateLimit-Remaining', '1');

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'two']]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'three']]);
    $this->assertSession()->statusCodeEquals(429);
    $this->assertSession()->responseHeaderExists('Retry-After');

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    self::assertSame('rate_limited', $payload['error']);
  }

  /**
   * The second identical request is served from cache.
   */
  public function testCachedFlag(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'repeat']]);
    $first = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    self::assertFalse($first['cached']);

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'repeat']]);
    $second = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    self::assertTrue($second['cached']);
  }

  /**
   * Hardening headers are applied, and results are never browser-cached.
   */
  public function testSecurityHeaders(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check', ['query' => ['domain' => 'headers']]);

    $this->assertSession()->responseHeaderEquals('X-Content-Type-Options', 'nosniff');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
  }

  /**
   * The health endpoint reports providers and diagnostics.
   */
  public function testHealthEndpoint(): void {
    $this->drupalLogin($this->drupalCreateUser(['use domain availability api']));

    $this->drupalGet('/domain-check/health');
    $this->assertSession()->statusCodeEquals(200);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    self::assertTrue($payload['success']);
    self::assertSame('ok', $payload['status']);
    self::assertTrue($payload['checks']['providers_registered']);
    self::assertContains('stub', $payload['providers']);
    self::assertArrayHasKey('whois_egress', $payload['diagnostics']);
  }

}
