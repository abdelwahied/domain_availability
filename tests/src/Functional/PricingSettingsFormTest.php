<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Functional;

use Drupal\domain_availability\Pricing\ExtensionPriceStrategy;
use Drupal\domain_availability\Pricing\FixedPriceStrategy;
use Drupal\domain_availability\Pricing\PricingSettings;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the pricing section of the settings form, end to end.
 *
 * @group domain_availability
 *
 * @runTestsInSeparateProcesses
 */
#[Group('domain_availability')]
#[RunTestsInSeparateProcesses]
final class PricingSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability', 'domain_availability_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The form element name of the mode radios.
   */
  private const MODE_FIELD = 'pricing[mode]';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('tlds', ['com', 'net', 'org', 'sa'])
      ->set('rate_limit_min_interval', 0)
      ->set('rdap_enabled', FALSE)
      ->set('whois_enabled', FALSE)
      ->set('dns_fallback_enabled', FALSE)
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['administer domain availability', 'access domain availability search']));
  }

  /**
   * Both shipped modes are offered, with the installed one selected.
   */
  public function testModesAreOffered(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->pageTextContains('Pricing mode');
    $this->assertSession()->pageTextContains('Same price for all domains');
    $this->assertSession()->pageTextContains('Different price per extension');
    $this->assertSession()->fieldValueEquals('pricing[settings][fixed][fixed_price]', '35');
    $this->assertSession()->checkboxChecked('edit-pricing-mode-fixed');
  }

  /**
   * The per-extension table has one row per enabled TLD, and no others.
   */
  public function testTableIsGeneratedFromTheEnabledTlds(): void {
    $this->drupalGet('/admin/config/system/domain-availability');

    foreach (['com', 'net', 'org', 'sa'] as $tld) {
      $this->assertSession()->fieldExists($this->priceField($tld));
    }

    $this->assertSession()->fieldNotExists($this->priceField('io'));
    $this->assertSession()->pageTextContains('Price for .com');
  }

  /**
   * A TLD enabled today has a price field tomorrow, with nothing to code.
   */
  public function testNewlyEnabledTldGetsItsOwnRow(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->fieldNotExists($this->priceField('io'));

    $this->submitForm([
      'tlds' => ".com\n.net\n.org\n.sa\n.io",
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '35',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->fieldExists($this->priceField('io'));
    $this->assertSession()->pageTextContains('Price for .io');
  }

  /**
   * A TLD listed twice, in either spelling, still gets exactly one row.
   */
  public function testDuplicateTldsProduceOneRow(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      'tlds' => ".com\ncom\n.COM\n.net",
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '35',
    ], 'Save configuration');

    self::assertSame(['com', 'net'], $this->config(ModuleSettings::CONFIG_NAME)->get('tlds'));

    $this->drupalGet('/admin/config/system/domain-availability');
    self::assertCount(
      1,
      $this->getSession()->getPage()->findAll('named', ['field', $this->priceField('com')]),
    );
  }

  /**
   * A per-extension table saves, keeps decimals, and drops what was cleared.
   */
  public function testSavingExtensionPrices(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '45',
      $this->priceField('net') => '40.50',
      $this->priceField('org') => '',
      $this->priceField('sa') => '120',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $pricing = $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY);

    self::assertSame(ExtensionPriceStrategy::ID, $pricing[PricingSettings::MODE_KEY]);
    self::assertSame(45.0, $pricing[ExtensionPriceStrategy::PRICES_KEY]['com']);
    self::assertSame(40.5, $pricing[ExtensionPriceStrategy::PRICES_KEY]['net']);
    self::assertSame(120.0, $pricing[ExtensionPriceStrategy::PRICES_KEY]['sa']);
    self::assertArrayNotHasKey('org', $pricing[ExtensionPriceStrategy::PRICES_KEY]);
  }

  /**
   * Switching modes keeps the other mode's settings.
   */
  public function testSwitchingModesKeepsBothSetsOfSettings(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '45',
      'pricing[settings][fixed][fixed_price]' => '35',
    ], 'Save configuration');

    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([self::MODE_FIELD => FixedPriceStrategy::ID], 'Save configuration');

    $pricing = $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY);

    self::assertSame(FixedPriceStrategy::ID, $pricing[PricingSettings::MODE_KEY]);
    self::assertSame(35.0, $pricing[FixedPriceStrategy::PRICE_KEY]);
    self::assertSame(45.0, $pricing[ExtensionPriceStrategy::PRICES_KEY]['com'], 'The table should survive a switch away from it.');
  }

  /**
   * A fixed price that is not a number, negative, or missing is refused.
   *
   * The exact wording of the first two is core's: the `number` element rejects
   * them before the strategy is reached, and FormState keeps the first error
   * per element. The strategy's own messages are asserted directly in
   * \Drupal\Tests\domain_availability\Kernel\PricingValidationTest. What
   * matters here is that neither value can reach configuration.
   */
  public function testFixedPriceValidation(): void {
    $this->drupalGet('/admin/config/system/domain-availability');

    $this->submitForm([
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => 'free',
    ], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');
    $this->assertSession()->pageTextContains('must be a number');

    $this->submitForm([
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '-5',
    ], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');

    $this->submitForm([
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Enter a fixed price, or choose a different pricing mode.');

    self::assertSame(
      35.0,
      $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY)[FixedPriceStrategy::PRICE_KEY],
      'No rejected value should have reached configuration.',
    );
  }

  /**
   * A per-extension price that is not a number or is negative is refused.
   */
  public function testExtensionPriceValidation(): void {
    $this->drupalGet('/admin/config/system/domain-availability');

    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => 'cheap',
    ], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');
    $this->assertSession()->pageTextContains('must be a number');

    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '-1',
    ], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');

    self::assertSame(
      [],
      $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY)[ExtensionPriceStrategy::PRICES_KEY],
    );
  }

  /**
   * An empty table saves cleanly: nothing priced is a valid configuration.
   */
  public function testEmptyExtensionTableSaves(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '',
      $this->priceField('net') => '',
      $this->priceField('org') => '',
      $this->priceField('sa') => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $pricing = $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY);

    self::assertSame([], $pricing[ExtensionPriceStrategy::PRICES_KEY]);
  }

  /**
   * Saving in per-extension mode never wipes the stored fixed price.
   *
   * The exact regression, through the browser: the fixed-price field is hidden
   * behind the other radio, so it is not validated, and an administrator who
   * clears it — or a site whose configuration never carried the key — used to
   * have the stored price silently rewritten to 0.00 on the next save.
   */
  public function testSavingInExtensionModeKeepsTheStoredFixedPrice(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '35',
    ], 'Save configuration');

    // The hidden field is cleared, which validation cannot object to.
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '',
      $this->priceField('com') => '45',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $pricing = $this->config(ModuleSettings::CONFIG_NAME)->get(PricingSettings::CONFIG_KEY);

    self::assertSame(35.0, $pricing[FixedPriceStrategy::PRICE_KEY], 'The stored fixed price must survive.');

    // And switching back shows the price that was kept, not a zero.
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->assertSession()->fieldValueEquals('pricing[settings][fixed][fixed_price]', '35');
  }

  /**
   * A zero price is refused in both modes.
   */
  public function testZeroPriceIsRefused(): void {
    $this->drupalGet('/admin/config/system/domain-availability');

    $this->submitForm([
      self::MODE_FIELD => FixedPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '0',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The fixed price must be greater than zero.');

    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '0',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Leave it blank to show .com without a price.');
  }

  /**
   * The hidden mode's fields never block a save.
   *
   * They are on the page and they do submit, so validating them would make the
   * form impossible to save with a blank fixed price and per-extension pricing
   * selected — which is a perfectly ordinary configuration.
   */
  public function testHiddenModeSettingsDoNotBlockSaving(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      'pricing[settings][fixed][fixed_price]' => '',
      $this->priceField('com') => '45',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSession()->pageTextNotContains('Enter a fixed price');
  }

  /**
   * Saved prices reach the search results a visitor sees.
   */
  public function testPriceIsRenderedInResults(): void {
    $this->drupalGet('/admin/config/system/domain-availability');
    $this->submitForm([
      'tlds' => ".com\n.sa",
      self::MODE_FIELD => ExtensionPriceStrategy::ID,
      $this->priceField('com') => '45',
      $this->priceField('sa') => '120.50',
    ], 'Save configuration');

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'free'], 'Search');

    $this->assertSession()->pageTextContains('45.00 SAR');
    $this->assertSession()->pageTextContains('120.50 SAR');
  }

  /**
   * A registered domain is shown without a price; only what is for sale is.
   */
  public function testRegisteredResultsAreNotPriced(): void {
    // Every one of these is on the stub's registered list, so nothing on the
    // page is for sale.
    $this->config(ModuleSettings::CONFIG_NAME)->set('tlds', ['com', 'sa', 'net'])->save();

    $this->drupalGet('/domain-search');
    $this->submitForm(['domain' => 'taken'], 'Search');

    $this->assertSession()->pageTextContains('Registered');
    $this->assertSession()->pageTextNotContains('35.00 SAR');
  }

  /**
   * The form element name of one extension's price field.
   *
   * @param string $tld
   *   The dot-less TLD.
   *
   * @return string
   *   The element name.
   */
  private function priceField(string $tld): string {
    return 'pricing[settings][extension][extension_prices][' . $tld . '][price]';
  }

}
