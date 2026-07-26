<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Utility\Tld;

/**
 * Everything a strategy is allowed to price on.
 *
 * WHY THIS EXISTS — the two strategies shipped today need only the extension,
 * so `priceFor(string $extension)` would be the smaller interface. It would
 * also be the one that has to change the day a strategy needs the lookup
 * result: premium pricing reads the registry's own verdict, provider pricing
 * reads which provider answered, and a promotional strategy reads the label.
 * Widening a parameter object is additive; widening an interface method is not.
 *
 * Immutable, and never carries request state: a strategy that needs the current
 * user, the language or a session should take that by injection, so it stays
 * testable and its cache contexts stay declarable.
 *
 * @api
 *   Public and stable since 1.1.0. Passed to every pricing strategy.
 */
final readonly class PricingContext {

  /**
   * Constructs a PricingContext.
   *
   * @param string $extension
   *   The normalised, dot-less TLD, e.g. `com`.
   * @param string|null $domain
   *   The fully qualified domain, when one is being priced.
   * @param \Drupal\domain_availability\Dto\DomainResult|null $result
   *   The lookup result, when the price is for a result rather than a bare TLD.
   */
  private function __construct(
    public string $extension,
    public ?string $domain = NULL,
    public ?DomainResult $result = NULL,
  ) {}

  /**
   * Builds a context for a bare TLD.
   *
   * @param string $extension
   *   The TLD, with or without a leading dot.
   *
   * @return self
   *   The context.
   */
  public static function forExtension(string $extension): self {
    return new self(Tld::normalise($extension));
  }

  /**
   * Builds a context for a lookup result.
   *
   * @param \Drupal\domain_availability\Dto\DomainResult $result
   *   The result being priced.
   *
   * @return self
   *   The context.
   */
  public static function forResult(DomainResult $result): self {
    return new self(Tld::normalise($result->extension), $result->domain, $result);
  }

  /**
   * The extension with a leading dot, e.g. `.com`.
   *
   * @return string
   *   The dotted extension.
   */
  public function dottedExtension(): string {
    return Tld::withDot($this->extension);
  }

}
