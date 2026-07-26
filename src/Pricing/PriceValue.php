<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

/**
 * An immutable amount of money attached to a domain result.
 *
 * A bare float would work today and stop working the moment a second currency
 * appears: 45.00 means nothing without knowing what 45 of. Carrying the
 * currency from the start is what lets a future currency-aware strategy be a
 * new class rather than a change to every consumer that already reads a price.
 *
 * The amount is held as a float rounded to `SCALE` decimals, which is exact
 * enough for display and for the arithmetic this module does (none). A strategy
 * that has to add tax, convert currencies or reconcile with a payment gateway
 * should work in minor units; that is a decision for the strategy that needs
 * it, not a tax on the two that do not.
 *
 * @api
 *   Public and stable since 1.1.0. Returned by PricingManager and carried on
 *   DomainResult.
 */
final readonly class PriceValue {

  /**
   * The currency used when a strategy does not name one.
   */
  public const DEFAULT_CURRENCY = 'SAR';

  /**
   * How many decimal places a price keeps.
   */
  public const SCALE = 2;

  /**
   * Constructs a PriceValue.
   *
   * @param float $amount
   *   The amount, zero or greater.
   * @param string $currency
   *   The ISO 4217 currency code.
   *
   * @throws \InvalidArgumentException
   *   When the amount is negative or the currency code is malformed.
   */
  public function __construct(
    public float $amount,
    public string $currency = self::DEFAULT_CURRENCY,
  ) {
    if ($amount < 0.0 || !is_finite($amount)) {
      throw new \InvalidArgumentException('A price cannot be negative or non-finite.');
    }

    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
      throw new \InvalidArgumentException('A currency must be a three-letter ISO 4217 code.');
    }
  }

  /**
   * Builds a price from a configured or submitted value.
   *
   * Returns NULL rather than throwing for anything that is not a usable
   * amount — an unset key, an empty field, a stray string, a negative number.
   * Callers treat "no price" as a normal outcome, so a misconfiguration must
   * degrade to an unpriced result instead of a 500.
   *
   * @param mixed $value
   *   The raw value.
   * @param string $currency
   *   The ISO 4217 currency code.
   *
   * @return self|null
   *   The price, or NULL when the value cannot be one.
   */
  public static function fromNumeric(mixed $value, string $currency = self::DEFAULT_CURRENCY): ?self {
    if (is_string($value)) {
      $value = trim($value);
    }

    if ($value === NULL || $value === '' || is_bool($value) || !is_numeric($value)) {
      return NULL;
    }

    $amount = round((float) $value, self::SCALE);

    if ($amount < 0.0 || !is_finite($amount)) {
      return NULL;
    }

    return new self($amount, $currency);
  }

  /**
   * Whether the price is zero.
   *
   * @return bool
   *   TRUE when nothing is charged.
   */
  public function isFree(): bool {
    return $this->amount === 0.0;
  }

  /**
   * The amount as a fixed-scale decimal string, e.g. `45.00`.
   *
   * Deliberately currency-agnostic: symbol placement and digit shaping are a
   * rendering concern, and this module renders in a template that a theme is
   * expected to override.
   *
   * @return string
   *   The formatted amount.
   */
  public function formatted(): string {
    return number_format($this->amount, self::SCALE, '.', '');
  }

  /**
   * Serialises the price for the API contract.
   *
   * @return array<string, mixed>
   *   The price as amount, currency and formatted.
   */
  public function toArray(): array {
    return [
      'amount' => $this->amount,
      'currency' => $this->currency,
      'formatted' => $this->formatted(),
    ];
  }

}
