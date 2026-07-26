<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Utility\Tld;

/**
 * A separate price per extension.
 *
 * Prices are keyed by the normalised, dot-less TLD — `com`, not `.com` — so a
 * key never depends on how it was typed. Extensions with no price stay
 * unpriced: the result still renders, without a number next to it, which is the
 * honest outcome for a TLD the site does not sell.
 *
 * The settings table is generated from the enabled TLD list, so a TLD added
 * tomorrow gets a row tomorrow with nothing to change here. Prices for TLDs
 * that are no longer enabled are kept rather than pruned, so disabling a TLD
 * for a week does not lose its price.
 *
 * @internal
 *   A pricing strategy. Resolve prices through PricingManager.
 */
final class ExtensionPriceStrategy extends PricingStrategyBase implements ConfigurablePricingStrategyInterface {

  /**
   * The strategy id, and the `pricing.mode` value that selects it.
   */
  public const ID = 'extension';

  /**
   * The configuration key holding the price table.
   */
  public const PRICES_KEY = 'extension_prices';

  /**
   * The subform column holding one extension's price.
   */
  private const PRICE_COLUMN = 'price';

  /**
   * Constructs an ExtensionPriceStrategy.
   *
   * @param \Drupal\domain_availability\Pricing\PricingSettings $settings
   *   The pricing settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\domain_availability\Service\ModuleSettings $moduleSettings
   *   The module settings, read for the enabled TLD list.
   */
  public function __construct(
    PricingSettings $settings,
    TranslationInterface $stringTranslation,
    private readonly ModuleSettings $moduleSettings,
  ) {
    parent::__construct($settings, $stringTranslation);
  }

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
    return $this->t('Different price per extension');
  }

  /**
   * {@inheritdoc}
   */
  public function description(): TranslatableMarkup {
    return $this->t('Each enabled extension is priced on its own. Extensions left blank are shown without a price.');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 10;
  }

  /**
   * {@inheritdoc}
   */
  public function priceFor(PricingContext $context): ?PriceValue {
    return PriceValue::fromNumeric($this->settings->getMapping(self::PRICES_KEY)[$context->extension] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(): array {
    $stored = $this->settings->getMapping(self::PRICES_KEY);

    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Extension'),
        $this->t('Price (@currency)', ['@currency' => PriceValue::DEFAULT_CURRENCY]),
      ],
      '#empty' => $this->t('No extensions are enabled yet. Add them to "Enabled TLDs" above and save; a row appears here for each one.'),
      '#caption' => $this->t('Generated from the enabled TLD list. Save the settings after changing that list to add or remove rows. Leave a price blank to show that extension without one.'),
    ];

    foreach ($this->moduleSettings->tlds() as $tld) {
      $dotted = Tld::withDot($tld);

      $table[$tld]['extension'] = [
        '#type' => 'item',
        '#markup' => '<span dir="ltr">' . $dotted . '</span>',
        '#wrapper_attributes' => ['class' => ['domain-availability-price-table__extension']],
      ];
      $table[$tld][self::PRICE_COLUMN] = [
        '#type' => 'number',
        // Every input needs its own label for a screen reader: a column header
        // is not one, and "Price" repeated twenty times says nothing about
        // which row the cursor is in.
        '#title' => $this->t('Price for @extension', ['@extension' => $dotted]),
        '#title_display' => 'invisible',
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $stored[$tld] ?? NULL,
      ];
    }

    return [self::PRICES_KEY => $table];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array $values, array $parents, FormStateInterface $formState): void {
    $rows = $values[self::PRICES_KEY] ?? [];

    if (!is_array($rows)) {
      return;
    }

    $seen = [];

    foreach ($rows as $tld => $row) {
      $tld = Tld::normalise((string) $tld);
      $raw = is_array($row) ? ($row[self::PRICE_COLUMN] ?? '') : '';
      $raw = is_string($raw) ? trim($raw) : $raw;
      $name = implode('][', [...$parents, self::PRICES_KEY, $tld, self::PRICE_COLUMN]);

      // Two rows cannot normalise to one extension through the generated table,
      // because the TLD list is de-duplicated before it gets here. Checked
      // anyway: the day someone builds this table from somewhere else, a
      // silently overwritten price is a very quiet bug.
      if (isset($seen[$tld])) {
        $formState->setErrorByName($name, $this->t('@extension is listed more than once. Each extension may have only one price.', [
          '@extension' => Tld::withDot($tld),
        ]));

        continue;
      }

      $seen[$tld] = TRUE;

      // Blank is a valid answer here: it means the site does not sell this
      // extension, and the result renders without a price.
      if ($raw === '') {
        continue;
      }

      if (!is_numeric($raw)) {
        $formState->setErrorByName($name, $this->t('The price for @extension must be a number, for example 45 or 45.50.', [
          '@extension' => Tld::withDot($tld),
        ]));

        continue;
      }

      if ((float) $raw < 0) {
        $formState->setErrorByName($name, $this->t('The price for @extension cannot be negative.', [
          '@extension' => Tld::withDot($tld),
        ]));

        continue;
      }

      // Zero resolves to no price at all, and a blank field already says that
      // unambiguously. Point at the blank rather than accepting a value that
      // silently does nothing.
      if ((float) $raw === 0.0) {
        $formState->setErrorByName($name, $this->t('The price for @extension must be greater than zero. Leave it blank to show @extension without a price.', [
          '@extension' => Tld::withDot($tld),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array $values): array {
    $rows = $values[self::PRICES_KEY] ?? [];
    // Start from what is stored so an extension that is currently disabled
    // keeps its price: the table only offers rows for enabled TLDs, and an
    // absent row must not read as "delete this".
    $prices = $this->settings->getMapping(self::PRICES_KEY);

    if (!is_array($rows)) {
      return [self::PRICES_KEY => $prices];
    }

    foreach ($rows as $tld => $row) {
      $tld = Tld::normalise((string) $tld);

      if ($tld === '') {
        continue;
      }

      $price = PriceValue::fromNumeric(is_array($row) ? ($row[self::PRICE_COLUMN] ?? NULL) : NULL);

      // Zero is treated exactly like blank, here and in PricingManager: both
      // mean "no price for this extension". Storing the zero would put a value
      // in configuration that resolves to nothing, which is the sort of state
      // that takes an afternoon to explain.
      if ($price === NULL || $price->isFree()) {
        unset($prices[$tld]);

        continue;
      }

      $prices[$tld] = $price->amount;
    }

    ksort($prices);

    return [self::PRICES_KEY => $prices];
  }

}
