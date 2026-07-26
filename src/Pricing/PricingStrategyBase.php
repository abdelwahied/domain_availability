<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Shared plumbing for strategies that read the module's own configuration.
 *
 * Optional: the contract is PricingStrategyInterface, and a strategy that
 * prices from somewhere else — a registrar API, a promotion entity, a price
 * list on a node — should implement that directly rather than inherit config
 * cacheability it does not have.
 *
 * @api
 *   Public and stable since 1.1.0. A convenience base for configuration-backed
 *   pricing strategies.
 */
abstract class PricingStrategyBase implements PricingStrategyInterface {

  use StringTranslationTrait;

  /**
   * Constructs a PricingStrategyBase.
   *
   * @param \Drupal\domain_availability\Pricing\PricingSettings $settings
   *   The pricing settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly PricingSettings $settings,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
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
  public function getCacheTags(): array {
    return $this->settings->cacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // A configured price is the same number for every visitor. A strategy that
    // varies by user, currency or language must say so by overriding this.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
