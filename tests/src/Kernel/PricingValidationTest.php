<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\domain_availability\Pricing\ConfigurablePricingStrategyInterface;
use Drupal\domain_availability\Pricing\ExtensionPriceStrategy;
use Drupal\domain_availability\Pricing\FixedPriceStrategy;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests each strategy's own validation, message by message.
 *
 * Asserted here rather than through the browser because the `number` element
 * rejects a non-numeric or negative value first, and FormState keeps only the
 * first error per element — so the strategy's wording never reaches the page
 * for those two cases. It is still the guard that matters: a programmatic form
 * submission, or a strategy reused by another form, meets it with no element
 * validation in front of it.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\Pricing\FixedPriceStrategy::validateConfigurationForm
 * @covers \Drupal\domain_availability\Pricing\ExtensionPriceStrategy::validateConfigurationForm
 * @covers \Drupal\domain_availability\Pricing\ExtensionPriceStrategy::submitConfigurationForm
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(FixedPriceStrategy::class, 'validateConfigurationForm')]
#[CoversMethod(ExtensionPriceStrategy::class, 'validateConfigurationForm')]
#[CoversMethod(ExtensionPriceStrategy::class, 'submitConfigurationForm')]
final class PricingValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['domain_availability'];

  /**
   * The subform parents both strategies are mounted under.
   */
  private const PARENTS = ['pricing', 'settings'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);

    $this->config(ModuleSettings::CONFIG_NAME)->set('tlds', ['com', 'sa'])->save();
  }

  /**
   * A blank fixed price is refused, with an actionable message.
   */
  public function testFixedPriceRejectsBlank(): void {
    $errors = $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => '']);

    self::assertArrayHasKey('pricing][settings][fixed][fixed_price', $errors);
    self::assertStringContainsString(
      'Enter a fixed price, or choose a different pricing mode.',
      (string) $errors['pricing][settings][fixed][fixed_price'],
    );
  }

  /**
   * A fixed price that is not a number is refused.
   */
  public function testFixedPriceRejectsNonNumeric(): void {
    $errors = $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => 'free']);

    self::assertStringContainsString(
      'must be a number, for example 35 or 35.50',
      (string) $errors['pricing][settings][fixed][fixed_price'],
    );
  }

  /**
   * A negative fixed price is refused.
   */
  public function testFixedPriceRejectsNegative(): void {
    $errors = $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => '-5']);

    self::assertStringContainsString(
      'cannot be negative',
      (string) $errors['pricing][settings][fixed][fixed_price'],
    );
  }

  /**
   * Decimals are accepted.
   */
  public function testFixedPriceAcceptsDecimals(): void {
    self::assertSame([], $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => '35.50']));
    self::assertSame([], $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => '0.01']));
  }

  /**
   * Zero is refused, and the message says what to do instead.
   */
  public function testFixedPriceRejectsZero(): void {
    $errors = $this->validate('fixed', [FixedPriceStrategy::PRICE_KEY => '0']);

    self::assertStringContainsString(
      'must be greater than zero',
      (string) $errors['pricing][settings][fixed][fixed_price'],
    );
  }

  /**
   * A blank fixed price keeps whatever is already stored.
   *
   * The regression: the field is hidden — and therefore unvalidated — whenever
   * another mode is selected, and an empty submission used to be normalised to
   * 0.00, wiping a working price every time the form was saved.
   */
  public function testBlankFixedPricePreservesStoredValue(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('pricing', [
        'mode' => 'extension',
        FixedPriceStrategy::PRICE_KEY => 35.0,
      ])
      ->save();

    self::assertSame(
      [FixedPriceStrategy::PRICE_KEY => 35.0],
      $this->strategy('fixed')->submitConfigurationForm([FixedPriceStrategy::PRICE_KEY => '']),
    );
    self::assertSame(
      [FixedPriceStrategy::PRICE_KEY => 35.0],
      $this->strategy('fixed')->submitConfigurationForm([]),
    );
  }

  /**
   * A real value still overwrites the stored one.
   */
  public function testFixedPriceStillOverwrites(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('pricing', ['mode' => 'fixed', FixedPriceStrategy::PRICE_KEY => 35.0])
      ->save();

    self::assertSame(
      [FixedPriceStrategy::PRICE_KEY => 49.99],
      $this->strategy('fixed')->submitConfigurationForm([FixedPriceStrategy::PRICE_KEY => '49.99']),
    );
  }

  /**
   * With nothing stored, a blank submission stores nothing.
   *
   * Omitting the key beats inventing a 0.00 for it.
   */
  public function testBlankFixedPriceWithNothingStoredOmitsTheKey(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('pricing', ['mode' => 'extension'])
      ->save();

    self::assertSame([], $this->strategy('fixed')->submitConfigurationForm([FixedPriceStrategy::PRICE_KEY => '']));
  }

  /**
   * A zero typed into the table is stored as nothing at all.
   */
  public function testZeroExtensionPriceIsNotStored(): void {
    self::assertSame(
      [ExtensionPriceStrategy::PRICES_KEY => []],
      $this->strategy('extension')->submitConfigurationForm([
        ExtensionPriceStrategy::PRICES_KEY => ['com' => ['price' => '0']],
      ]),
    );
  }

  /**
   * Zero in the table is refused, pointing at the blank field instead.
   */
  public function testExtensionPriceRejectsZero(): void {
    $errors = $this->validate('extension', [
      ExtensionPriceStrategy::PRICES_KEY => ['com' => ['price' => '0.00']],
    ]);

    self::assertStringContainsString(
      'Leave it blank to show .com without a price',
      (string) $errors['pricing][settings][extension][extension_prices][com][price'],
    );
  }

  /**
   * A per-extension price that is not a number names the extension it broke on.
   */
  public function testExtensionPriceRejectsNonNumeric(): void {
    $errors = $this->validate('extension', [
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => ['price' => 'cheap'],
        'sa' => ['price' => '120'],
      ],
    ]);

    self::assertCount(1, $errors);
    self::assertStringContainsString(
      'The price for .com must be a number',
      (string) $errors['pricing][settings][extension][extension_prices][com][price'],
    );
  }

  /**
   * A negative per-extension price is refused.
   */
  public function testExtensionPriceRejectsNegative(): void {
    $errors = $this->validate('extension', [
      ExtensionPriceStrategy::PRICES_KEY => ['com' => ['price' => '-0.01']],
    ]);

    self::assertStringContainsString(
      'The price for .com cannot be negative.',
      (string) $errors['pricing][settings][extension][extension_prices][com][price'],
    );
  }

  /**
   * Two rows naming one extension are refused rather than silently merged.
   */
  public function testExtensionPriceRejectsDuplicates(): void {
    $errors = $this->validate('extension', [
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => ['price' => '45'],
        '.COM' => ['price' => '60'],
      ],
    ]);

    self::assertCount(1, $errors);
    self::assertStringContainsString(
      '.com is listed more than once',
      (string) reset($errors),
    );
  }

  /**
   * Blank rows are a valid table: an unpriced extension is not an error.
   */
  public function testExtensionPriceAcceptsBlanks(): void {
    self::assertSame([], $this->validate('extension', [
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => ['price' => ''],
        'sa' => ['price' => '120.50'],
      ],
    ]));
  }

  /**
   * An entirely empty submission validates and stores nothing.
   */
  public function testEmptyExtensionSubmissionIsGraceful(): void {
    self::assertSame([], $this->validate('extension', []));
    self::assertSame(
      [ExtensionPriceStrategy::PRICES_KEY => []],
      $this->strategy('extension')->submitConfigurationForm([]),
    );
  }

  /**
   * Clearing a price removes the key instead of storing a zero.
   *
   * Storing 0.00 would advertise the extension as free, which is a different
   * statement from not selling it.
   */
  public function testClearingPriceRemovesIt(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('pricing', [
        'mode' => 'extension',
        ExtensionPriceStrategy::PRICES_KEY => ['com' => 45.0, 'sa' => 120.5],
      ])
      ->save();

    $stored = $this->strategy('extension')->submitConfigurationForm([
      ExtensionPriceStrategy::PRICES_KEY => [
        'com' => ['price' => ''],
        'sa' => ['price' => '130'],
      ],
    ]);

    self::assertSame([ExtensionPriceStrategy::PRICES_KEY => ['sa' => 130.0]], $stored);
  }

  /**
   * A price for a TLD that is no longer enabled survives, rather than going.
   */
  public function testDisabledExtensionKeepsItsStoredPrice(): void {
    $this->config(ModuleSettings::CONFIG_NAME)
      ->set('pricing', [
        'mode' => 'extension',
        ExtensionPriceStrategy::PRICES_KEY => ['com' => 45.0, 'io' => 60.0],
      ])
      ->save();

    // Only `com` has a row: `io` is not in the enabled TLD list.
    $stored = $this->strategy('extension')->submitConfigurationForm([
      ExtensionPriceStrategy::PRICES_KEY => ['com' => ['price' => '50']],
    ]);

    self::assertSame(
      [ExtensionPriceStrategy::PRICES_KEY => ['com' => 50.0, 'io' => 60.0]],
      $stored,
    );
  }

  /**
   * The generated table has exactly one row per enabled TLD.
   */
  public function testTableIsBuiltFromTheEnabledTlds(): void {
    $this->config(ModuleSettings::CONFIG_NAME)->set('tlds', ['com', 'sa', 'io'])->save();

    $form = $this->strategy('extension')->buildConfigurationForm();
    $table = $form[ExtensionPriceStrategy::PRICES_KEY];

    self::assertSame('table', $table['#type']);
    self::assertSame(
      ['com', 'sa', 'io'],
      array_values(array_filter(array_keys($table), static fn ($key): bool => !str_starts_with((string) $key, '#'))),
    );
    self::assertSame('number', $table['com']['price']['#type']);
    // Every input carries its own label; a column header is not one.
    self::assertSame('invisible', $table['com']['price']['#title_display']);
    self::assertSame('Price for .com', (string) $table['com']['price']['#title']);
  }

  /**
   * Runs one strategy's validation and returns the errors it set.
   *
   * @param string $id
   *   The strategy id.
   * @param array<string, mixed> $values
   *   The subform values.
   *
   * @return array<string, mixed>
   *   The errors, keyed by element name.
   */
  private function validate(string $id, array $values): array {
    $formState = new FormState();
    $parents = [...self::PARENTS, $id];

    $this->strategy($id)->validateConfigurationForm($values, $parents, $formState);

    return $formState->getErrors();
  }

  /**
   * One configurable strategy from the container.
   *
   * @param string $id
   *   The strategy id.
   *
   * @return \Drupal\domain_availability\Pricing\ConfigurablePricingStrategyInterface
   *   The strategy.
   */
  private function strategy(string $id): ConfigurablePricingStrategyInterface {
    $strategy = $this->container->get('domain_availability.pricing_manager')->strategies()[$id];
    self::assertInstanceOf(ConfigurablePricingStrategyInterface::class, $strategy);

    return $strategy;
  }

}
