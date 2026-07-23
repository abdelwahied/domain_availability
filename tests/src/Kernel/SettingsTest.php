<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests default configuration, its schema, and the typed reader.
  *
  * @group domain_availability
  *
  * @covers \Drupal\domain_availability\Service\ModuleSettings::tlds
  * @covers \Drupal\domain_availability\Service\ModuleSettings::cacheTtl
  * @covers \Drupal\domain_availability\Service\ModuleSettings::rdapTimeoutMs
  * @covers \Drupal\domain_availability\Service\ModuleSettings::saudinicEnabled
  * @covers \Drupal\domain_availability\Service\ModuleSettings::corsAllowedOrigins
  *
  * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(ModuleSettings::class, 'tlds')]
#[CoversMethod(ModuleSettings::class, 'cacheTtl')]
#[CoversMethod(ModuleSettings::class, 'rdapTimeoutMs')]
#[CoversMethod(ModuleSettings::class, 'saudinicEnabled')]
#[CoversMethod(ModuleSettings::class, 'corsAllowedOrigins')]
final class SettingsTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);
  }

  /**
   * Shipped defaults match the schema, so the config is exportable.
   */
  public function testDefaultConfigMatchesSchema(): void {
    $config = $this->config(ModuleSettings::CONFIG_NAME);

    $result = $this->checkConfigSchema(
      $this->container->get('config.typed'),
      ModuleSettings::CONFIG_NAME,
      $config->get(),
    );

    self::assertTrue($result, is_array($result) ? print_r($result, TRUE) : 'Schema mismatch.');
  }

  /**
   * The 20 shipped TLDs are the standalone application's list.
   */
  public function testDefaultTlds(): void {
    $settings = $this->container->get('domain_availability.settings');

    self::assertCount(20, $settings->tlds());
    self::assertContains('sa', $settings->tlds());
    self::assertContains('com', $settings->tlds());
  }

  /**
   * Config is read at call time, so a change applies without a rebuild.
   */
  public function testReadsConfigAtCallTime(): void {
    $settings = $this->container->get('domain_availability.settings');
    self::assertSame(600, $settings->cacheTtl());

    $this->config(ModuleSettings::CONFIG_NAME)->set('cache_ttl', 120)->save();

    self::assertSame(120, $settings->cacheTtl());
  }

  /**
   * TLDs are normalised however they were entered.
   */
  public function testTldNormalisation(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('tlds', ['.COM', 'net', '.sa', 'com'])
      ->save();

    // Dots stripped, lower-cased, duplicates dropped, order kept.
    self::assertSame(['com', 'net', 'sa'], $this->container->get('domain_availability.settings')->tlds());
  }

  /**
   * A junk or zero value falls back to the documented default.
   *
   * Config is typed, so 'not-a-number' is cast to 0 on save rather than
   * rejected. A 0 ms timeout is not a configuration choice — it is a lookup
   * that can never succeed — so the reader refuses it.
   */
  public function testInvalidValueFallsBackToDefault(): void {
    $settings = $this->container->get('domain_availability.settings');

    $this->config(ModuleSettings::CONFIG_NAME)->set('rdap_timeout_ms', 'not-a-number')->save();
    self::assertSame(3000, $settings->rdapTimeoutMs());

    $this->config(ModuleSettings::CONFIG_NAME)->set('rdap_timeout_ms', 0)->save();
    self::assertSame(3000, $settings->rdapTimeoutMs());

    $this->config(ModuleSettings::CONFIG_NAME)->set('cache_ttl', -5)->save();
    self::assertSame(600, $settings->cacheTtl());
  }

  /**
   * The authoritative provider stays inert without a key.
   */
  public function testAuthoritativeProviderDefaultsOff(): void {
    $settings = $this->container->get('domain_availability.settings');

    self::assertFalse($settings->saudinicEnabled());
    self::assertSame('', $settings->saudinicApiKey());

    $provider = $this->container->get('domain_availability.provider.authoritative');
    self::assertFalse($provider->supports('sa'), 'The provider must decline every TLD until it is configured.');
  }

  /**
   * CORS is off unless origins are configured.
   */
  public function testCorsOriginsParsing(): void {
    $settings = $this->container->get('domain_availability.settings');
    self::assertSame([], $settings->corsAllowedOrigins());

    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('cors_allowed_origins', 'https://a.example.com/, https://b.example.com')
      ->save();

    self::assertSame(
      ['https://a.example.com', 'https://b.example.com'],
      $settings->corsAllowedOrigins(),
    );
  }

}
