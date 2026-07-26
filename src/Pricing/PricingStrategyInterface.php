<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Contract every pricing strategy implements.
 *
 * One strategy is active at a time, chosen by `pricing.mode`, and a strategy's
 * `id()` *is* that mode value. Adding a pricing model is therefore one class
 * plus one tagged service: the manager, the settings form and the templates
 * discover it, so none of them is edited. That is the whole point of this
 * seam — `ProviderPricingStrategy`, `PremiumPricingStrategy` and
 * `PromotionPricingStrategy` should each arrive as an addition, never as a
 * change to the two strategies already here.
 *
 * Implementations must be side-effect free and must never throw for an
 * unpriceable input: an extension nobody set a price for is `NULL`, which the
 * presentation layer renders as no price at all. The manager catches anything
 * that escapes, but a strategy that relies on that is a strategy with a bug.
 *
 * A strategy is also a cacheable dependency. Whatever it reads — configuration,
 * a promotion window, the active currency — has to be declared here, or the
 * pages that render its prices will keep serving yesterday's numbers.
 *
 * @api
 *   Public and stable since 1.1.0. The module's pricing extension point; tag a
 *   service with `domain_availability_pricing_strategy` to register one.
 */
interface PricingStrategyInterface extends CacheableDependencyInterface {

  /**
   * The stable machine name, which is also the `pricing.mode` value.
   *
   * Lower-case, underscores only. It is stored in configuration, so treat it
   * as permanent: renaming one strips pricing from every site that used it.
   *
   * @return string
   *   The strategy id, e.g. `fixed`.
   */
  public function id(): string;

  /**
   * The human-readable name, shown as the radio label on the settings form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated label.
   */
  public function label(): TranslatableMarkup;

  /**
   * What this mode does, shown under its radio.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated description.
   */
  public function description(): TranslatableMarkup;

  /**
   * The ordering weight; the lowest number is listed and defaulted to first.
   *
   * @return int
   *   The weight.
   */
  public function weight(): int;

  /**
   * Prices one thing.
   *
   * @param \Drupal\domain_availability\Pricing\PricingContext $context
   *   What is being priced.
   *
   * @return \Drupal\domain_availability\Pricing\PriceValue|null
   *   The price, or NULL when this strategy has no price for it.
   */
  public function priceFor(PricingContext $context): ?PriceValue;

}
