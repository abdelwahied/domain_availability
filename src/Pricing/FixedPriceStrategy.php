<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One price for every extension.
 *
 * The right default for a reseller with a single headline rate, and the mode a
 * site starts on: `fixed_price` is the only value it reads.
 *
 * @internal
 *   A pricing strategy. Resolve prices through PricingManager.
 */
final class FixedPriceStrategy extends PricingStrategyBase implements ConfigurablePricingStrategyInterface {

  /**
   * The strategy id, and the `pricing.mode` value that selects it.
   */
  public const ID = 'fixed';

  /**
   * The configuration key holding the price.
   */
  public const PRICE_KEY = 'fixed_price';

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::ID;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('Same price for all domains');
  }

  /**
   * {@inheritdoc}
   */
  public function description(): TranslatableMarkup {
    return $this->t('Every extension is offered at one price.');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function priceFor(PricingContext $context): ?PriceValue {
    return PriceValue::fromNumeric($this->settings->get(self::PRICE_KEY));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(): array {
    return [
      self::PRICE_KEY => [
        '#type' => 'number',
        '#title' => $this->t('Fixed price'),
        '#description' => $this->t('Charged for every extension. Decimals are allowed, for example 35.50.'),
        '#field_suffix' => PriceValue::DEFAULT_CURRENCY,
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $this->settings->get(self::PRICE_KEY),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array $values, array $parents, FormStateInterface $formState): void {
    $raw = $values[self::PRICE_KEY] ?? '';
    $raw = is_string($raw) ? trim($raw) : $raw;
    $name = implode('][', [...$parents, self::PRICE_KEY]);

    // Empty is an error only in this mode: a site that selected "same price for
    // all domains" and gave no price would show no price anywhere, which is
    // never what was meant. Per-extension pricing treats blank as "not sold".
    if ($raw === '') {
      $formState->setErrorByName($name, $this->t('Enter a fixed price, or choose a different pricing mode.'));

      return;
    }

    if (!is_numeric($raw)) {
      $formState->setErrorByName($name, $this->t('The fixed price must be a number, for example 35 or 35.50.'));

      return;
    }

    if ((float) $raw < 0) {
      $formState->setErrorByName($name, $this->t('The fixed price cannot be negative.'));

      return;
    }

    // Said here rather than left to surprise: zero resolves to no price at all,
    // so an administrator who types it would otherwise save successfully and
    // find every result unpriced. #min stays 0 so this message is the one that
    // reaches the page — the number element's own error would win otherwise.
    if ((float) $raw === 0.0) {
      $formState->setErrorByName($name, $this->t('The fixed price must be greater than zero. Choose a different pricing mode if this site does not sell domains.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array $values): array {
    $price = PriceValue::fromNumeric($values[self::PRICE_KEY] ?? NULL);

    // Zero counts as no answer, the same way PricingManager reads it.
    if ($price !== NULL && !$price->isFree()) {
      return [self::PRICE_KEY => $price->amount];
    }

    // Blank, and blank is never a deliberate answer here: this mode refuses to
    // save without a price, so an empty value can only mean the field was
    // hidden behind another mode's radio. Keep what is stored — coercing it to
    // 0.0 destroyed a working price every time an administrator saved the form
    // in per-extension mode. Same rule ExtensionPriceStrategy already follows.
    $stored = PriceValue::fromNumeric($this->settings->get(self::PRICE_KEY));

    // Nothing worth keeping: omit the key rather than invent a price for it.
    return $stored === NULL ? [] : [self::PRICE_KEY => $stored->amount];
  }

}
