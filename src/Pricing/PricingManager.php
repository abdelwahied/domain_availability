<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\domain_availability\Dto\CheckReport;
use Drupal\domain_availability\Dto\DomainResult;
use Psr\Log\LoggerInterface;

/**
 * The only way to ask what something costs.
 *
 * Selects the strategy named by `pricing.mode` and delegates to it. Nothing
 * outside this subsystem knows which strategy is active, what the modes are
 * called, or where the numbers are stored — a consumer asks for a price and
 * gets a PriceValue or NULL.
 *
 * Failure is always NULL, never an exception. An unset mode, a mode belonging
 * to an uninstalled module, an extension nobody priced, or a third-party
 * strategy that throws: each ends as an unpriced result, because a domain
 * search that returns no price is degraded and a domain search that returns
 * HTTP 500 is broken.
 *
 * @api
 *   Public and stable since 1.1.0. The module's pricing entry point.
 */
final class PricingManager {

  /**
   * Constructs a PricingManager.
   *
   * @param \Drupal\domain_availability\Pricing\PricingStrategyRegistry $registry
   *   The strategy registry.
   * @param \Drupal\domain_availability\Pricing\PricingSettings $settings
   *   The pricing settings.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    private readonly PricingStrategyRegistry $registry,
    private readonly PricingSettings $settings,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The price for an extension.
   *
   * @param string $extension
   *   The TLD, with or without a leading dot.
   *
   * @return \Drupal\domain_availability\Pricing\PriceValue|null
   *   The price, or NULL when the extension is not priced.
   */
  public function getPrice(string $extension): ?PriceValue {
    return $this->price(PricingContext::forExtension($extension));
  }

  /**
   * The price for anything a strategy can price.
   *
   * @param \Drupal\domain_availability\Pricing\PricingContext $context
   *   What is being priced.
   *
   * @return \Drupal\domain_availability\Pricing\PriceValue|null
   *   The price, or NULL when nothing prices it.
   */
  public function price(PricingContext $context): ?PriceValue {
    $strategy = $this->activeStrategy();

    if ($strategy === NULL) {
      return NULL;
    }

    try {
      $price = $strategy->priceFor($context);

      // Zero is not a price, and this is the one place that can say so for
      // every strategy at once, including contributed ones.
      //
      // WHY — Drupal's typed configuration casts on save, driven by this
      // module's own `float` schema: a stray string imported into
      // `extension_prices` is stored as 0.0, and TRUE is stored as 1.0. The
      // cast happens inside Config::save(), so by the time anything here reads
      // the value a malformed import is indistinguishable from a deliberate
      // zero. There is no read-side test that can tell them apart.
      //
      // Given that, treating zero as unpriced is what keeps one bad config
      // import from advertising every domain on the site as free. Selling at
      // zero is not a supported configuration in 1.1.0; an extension the site
      // does not sell is left blank, which reads as no price at all.
      return $price === NULL || $price->isFree() ? NULL : $price;
    }
    catch (\Throwable $exception) {
      // A contributed strategy is third-party code on the hot path of every
      // search. It degrades to "no price"; it does not take the page down.
      $this->logger->error('Pricing strategy @strategy failed: @message', [
        '@strategy' => $strategy->id(),
        '@message' => $exception->getMessage(),
        'extension' => $context->extension,
        'exception' => $exception,
      ]);

      return NULL;
    }
  }

  /**
   * Copies a report with a price attached to every result.
   *
   * Applied after the lookup cache, never before it: a cached report holds what
   * the registries said, and prices come from configuration that can change
   * between two hits of the same cache entry.
   *
   * @param \Drupal\domain_availability\Dto\CheckReport $report
   *   The report to price.
   *
   * @return \Drupal\domain_availability\Dto\CheckReport
   *   The priced report, or the original when no strategy is active.
   */
  public function applyTo(CheckReport $report): CheckReport {
    if ($this->activeStrategy() === NULL) {
      return $report;
    }

    return $report->withResults(array_map(
      fn (DomainResult $result): DomainResult => $result->withPrice(
        $this->price(PricingContext::forResult($result)),
      ),
      $report->results,
    ));
  }

  /**
   * The strategy named by the current configuration.
   *
   * @return \Drupal\domain_availability\Pricing\PricingStrategyInterface|null
   *   The active strategy, or NULL when pricing is unconfigured or the
   *   configured mode is not registered.
   */
  public function activeStrategy(): ?PricingStrategyInterface {
    $mode = $this->settings->mode();

    if ($mode === '') {
      return NULL;
    }

    $strategy = $this->registry->get($mode);

    if ($strategy === NULL) {
      // Reachable in the wild: uninstalling the module that supplied a mode
      // leaves the mode name behind in exported configuration.
      $this->logger->warning('Unknown pricing mode "@mode"; results are shown without prices.', [
        '@mode' => $mode,
      ]);
    }

    return $strategy;
  }

  /**
   * Every registered strategy, for the settings form.
   *
   * @return array<string, \Drupal\domain_availability\Pricing\PricingStrategyInterface>
   *   The strategies, keyed by id, ordered by ascending weight.
   */
  public function strategies(): array {
    return $this->registry->all();
  }

  /**
   * The default mode for a site that has never configured pricing.
   *
   * @return string
   *   The lowest-weight strategy id, or an empty string when none exists.
   */
  public function defaultMode(): string {
    return $this->registry->first()?->id() ?? '';
  }

  /**
   * What a render array carrying these prices depends on.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheable metadata of the pricing configuration and the active
   *   strategy.
   */
  public function getCacheableMetadata(): CacheableMetadata {
    $metadata = new CacheableMetadata();
    $metadata->addCacheTags($this->settings->cacheTags());

    $strategy = $this->activeStrategy();

    if ($strategy !== NULL) {
      $metadata->addCacheableDependency($strategy);
    }

    return $metadata;
  }

}
