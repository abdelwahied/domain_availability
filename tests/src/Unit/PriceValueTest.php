<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\domain_availability\Pricing\PriceValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the money value object every pricing strategy returns.
 *
 * The `@group` and `@covers` annotations mirror the PHP attributes so the suite
 * runs on both PHPUnit 9.6 (Drupal 10.3) and PHPUnit 11 (Drupal 11) without
 * deprecations.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\Pricing\PriceValue
 */
#[Group('domain_availability')]
#[CoversClass(PriceValue::class)]
final class PriceValueTest extends UnitTestCase {

  /**
   * Anything that is not a usable amount becomes NULL, never an exception.
   *
   * @param mixed $input
   *   The raw configured or submitted value.
   * @param float|null $expected
   *   The amount it must produce, or NULL when it is not a price.
   *
   * @dataProvider numericProvider
   */
  #[DataProvider('numericProvider')]
  public function testFromNumeric(mixed $input, ?float $expected): void {
    $price = PriceValue::fromNumeric($input);

    if ($expected === NULL) {
      self::assertNull($price);

      return;
    }

    self::assertNotNull($price);
    self::assertSame($expected, $price->amount);
  }

  /**
   * Raw values and the amounts they are worth.
   *
   * @return array<string, array{mixed, float|null}>
   *   Test cases.
   */
  public static function numericProvider(): array {
    return [
      'integer' => [35, 35.0],
      'float' => [35.5, 35.5],
      'two decimals' => [45.25, 45.25],
      'numeric string' => ['120.75', 120.75],
      'padded string' => ['  99.99  ', 99.99],
      'rounded to scale' => [10.005, 10.01],
      'zero is a price' => [0, 0.0],
      'empty string' => ['', NULL],
      'whitespace only' => ['   ', NULL],
      'null' => [NULL, NULL],
      'not numeric' => ['free', NULL],
      'boolean true' => [TRUE, NULL],
      'boolean false' => [FALSE, NULL],
      'array' => [[35], NULL],
      'negative' => [-1, NULL],
      'negative string' => ['-0.01', NULL],
    ];
  }

  /**
   * Decimals survive the round trip to a display string.
   */
  public function testFormattingKeepsScale(): void {
    self::assertSame('35.00', (new PriceValue(35.0))->formatted());
    self::assertSame('45.50', (new PriceValue(45.5))->formatted());
    self::assertSame('120.75', (new PriceValue(120.75))->formatted());
    self::assertSame('0.00', (new PriceValue(0.0))->formatted());
  }

  /**
   * The serialised shape is what the API contract promises.
   */
  public function testToArray(): void {
    self::assertSame([
      'amount' => 45.5,
      'currency' => 'SAR',
      'formatted' => '45.50',
    ], (new PriceValue(45.5))->toArray());
  }

  /**
   * A currency travels with the amount, so a second one can be added later.
   */
  public function testCurrencyIsCarried(): void {
    $price = PriceValue::fromNumeric('19.99', 'USD');

    self::assertNotNull($price);
    self::assertSame('USD', $price->currency);
    self::assertSame('SAR', PriceValue::DEFAULT_CURRENCY);
  }

  /**
   * Zero is free; anything above it is not.
   */
  public function testIsFree(): void {
    self::assertTrue((new PriceValue(0.0))->isFree());
    self::assertFalse((new PriceValue(0.01))->isFree());
  }

  /**
   * Constructing an impossible price is a programming error, not a bad input.
   */
  public function testDirectConstructionRejectsNegative(): void {
    $this->expectException(\InvalidArgumentException::class);

    new PriceValue(-5.0);
  }

  /**
   * A currency code that is not ISO 4217 shaped is rejected.
   */
  public function testDirectConstructionRejectsBadCurrency(): void {
    $this->expectException(\InvalidArgumentException::class);

    new PriceValue(10.0, 'Riyal');
  }

}
