<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the search page, the form, the block and the admin settings.
 */
#[Group('domain_availability')]
#[RunTestsInSeparateProcesses]
final class DomainSearchFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Only the stub answers: a real provider round would put a registry in the
    // path of a UI test.
    $this->config('domain_availability.settings')
      ->set('tlds', ['com', 'sa'])
      ->set('rate_limit_min_interval', 0)
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();
  }

  /**
   * The search page is closed without the permission.
   */
  public function testSearchPageRequiresPermission(): void {
    $this->drupalGet('/domain-search');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));
    $this->drupalGet('/domain-search');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('domain');
  }

  /**
   * Submitting renders results without JavaScript.
   *
   * The form is server-rendered on purpose: availability must not depend on
   * JavaScript succeeding, and this test is what keeps that true.
   */
  public function testSearchRendersResults(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'taken'], 'Search');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('taken.com');
    $this->assertSession()->pageTextContains('Registered');
    $this->assertSession()->pageTextContains('taken.sa');
    $this->assertSession()->elementExists('css', '.domain-availability-card--registered');
  }

  /**
   * An available result is labelled and styled as available.
   */
  public function testAvailableResult(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'free'], 'Search');

    $this->assertSession()->pageTextContains('Available');
    $this->assertSession()->elementExists('css', '.domain-availability-card--available');
  }

  /**
   * An unknown result says the registry did not answer — never "available".
   */
  public function testUnknownResultWording(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'mystery'], 'Search');

    $this->assertSession()->pageTextContains('Unknown');
    $this->assertSession()->pageTextContains('Registry did not answer');
    $this->assertSession()->elementExists('css', '.domain-availability-card--unknown');
  }

  /**
   * A full URL is accepted and reduced to its label.
   */
  public function testFormSanitizesUrl(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'https://www.taken.com/pricing'], 'Search');

    $this->assertSession()->pageTextContains('taken.com');
  }

  /**
   * Invalid input is refused by the Form API with a readable message.
   */
  public function testFormValidation(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'bad_name'], 'Search');

    $this->assertSession()->pageTextContains('Only letters, numbers and hyphens are allowed.');
    $this->assertSession()->elementNotExists('css', '.domain-availability-card');
  }

  /**
   * A reflected XSS payload is escaped, not executed.
   */
  public function testXssPayloadIsEscaped(): void {
    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => '<script>alert(1)</script>'], 'Search');

    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
  }

  /**
   * The block places the same component, behind the same permission.
   */
  public function testSearchBlock(): void {
    $this->drupalPlaceBlock('domain_availability_search', ['region' => 'content', 'id' => 'da_search']);

    // Without the permission the block must not render at all.
    $this->drupalGet('<front>');
    $this->assertSession()->fieldNotExists('domain');

    $this->drupalLogin($this->drupalCreateUser(['access domain availability search']));
    $this->drupalGet('<front>');
    $this->assertSession()->fieldExists('domain');
  }

  /**
   * The settings form is admin-only, saves, and takes effect.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer domain availability']));
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'tlds' => ".com\n.org\n.sa",
      'cache_ttl' => 300,
      'rate_limit_max_requests' => 15,
      'debug' => FALSE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('domain_availability.settings');
    self::assertSame(['com', 'org', 'sa'], $config->get('tlds'));
    self::assertSame(300, $config->get('cache_ttl'));
    self::assertSame(15, $config->get('rate_limit_max_requests'));
  }

  /**
   * The settings form refuses a nonsense TLD instead of failing every lookup.
   */
  public function testSettingsFormValidation(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer domain availability']));

    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm(['tlds' => 'not a tld!'], 'Save configuration');

    $this->assertSession()->pageTextContains('is not a valid TLD');
  }

  /**
   * Enabling the authoritative provider without a key is refused: a.
   *
   * Half-configured provider would silently shadow WHOIS.
   */
  public function testAuthoritativeProviderRequiresKey(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer domain availability']));

    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      'saudinic_enabled' => TRUE,
      'saudinic_api_key' => '',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('An API key is required');
  }

  /**
   * The authoritative API key is write-only: stored, never rendered back.
   */
  public function testAuthoritativeKeyIsWriteOnly(): void {
    $key = 'secret-whoisfreaks-key-abc123';
    $this->config('domain_availability.settings')
      ->set('saudinic_enabled', TRUE)
      ->set('saudinic_api_key', $key)
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['administer domain availability']));
    $this->drupalGet('/admin/config/system/domain-availability');

    // The stored key must appear nowhere in the page or its source.
    $this->assertSession()->responseNotContains($key);
    $this->assertSession()->pageTextContains('A key is currently stored; leave this blank to keep it.');

    // Saving with the field left blank keeps the stored key.
    $this->submitForm([
      'saudinic_enabled' => TRUE,
      'saudinic_api_key' => '',
    ], 'Save configuration');
    $this->assertSame($key, $this->config('domain_availability.settings')->get('saudinic_api_key'));

    // Entering a new key replaces it.
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      'saudinic_enabled' => TRUE,
      'saudinic_api_key' => 'a-new-key-999',
    ], 'Save configuration');
    $this->assertSame('a-new-key-999', $this->config('domain_availability.settings')->get('saudinic_api_key'));
  }

  /**
   * Saving settings drops cached results produced under the old settings.
   */
  public function testSavingSettingsInvalidatesCache(): void {
    $searcher = $this->drupalCreateUser(['access domain availability search']);
    $this->drupalLogin($searcher);
    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'cached'], 'Search');
    $this->assertSession()->pageTextContains('cached.com');

    $this->drupalLogin($this->drupalCreateUser(['administer domain availability']));
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm(['tlds' => ".com\n.net"], 'Save configuration');

    $this->drupalLogin($searcher);
    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'cached'], 'Search');

    // The new TLD list must be reflected, not the cached old one.
    $this->assertSession()->pageTextContains('cached.net');
    $this->assertSession()->pageTextNotContains('cached.sa');
  }

}
