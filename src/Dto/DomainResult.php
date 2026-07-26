<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

use Drupal\domain_availability\Pricing\PriceValue;

/**
 * Immutable outcome of a single domain lookup.
 *
 * Carries an optional price so the presentation layer only ever renders. A
 * template that worked out what a `.com` costs would be a template that has to
 * be re-implemented in every theme that overrides it, and re-tested in none.
 * Providers never set the price: it is attached after the lookup, by
 * PricingManager, because it comes from configuration rather than from a
 * registry.
 *
 * @api
 *   Public and stable since 1.0.0. Returned inside a CheckReport, and by every
 *   provider. The `$price` property was added in 1.1.0 and defaults to NULL, so
 *   existing constructor calls and consumers keep working unchanged.
 */
final readonly class DomainResult {

  /**
   * Constructs a DomainResult.
   *
   * @param string $domain
   *   The fully qualified domain, e.g. `neixora.com`.
   * @param string $extension
   *   The TLD with a leading dot, e.g. `.com`.
   * @param \Drupal\domain_availability\Dto\DomainStatus $status
   *   The lookup outcome.
   * @param string|null $provider
   *   The provider that produced the answer.
   * @param string|null $reason
   *   Non-sensitive detail for an unknown result.
   * @param \Drupal\domain_availability\Pricing\PriceValue|null $price
   *   What this domain costs, when the site prices its extension.
   */
  public function __construct(
    public string $domain,
    public string $extension,
    public DomainStatus $status,
    public ?string $provider = NULL,
    public ?string $reason = NULL,
    public ?PriceValue $price = NULL,
  ) {}

  /**
   * Builds an "available" result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The TLD with a leading dot.
   * @param string $provider
   *   The provider that answered.
   *
   * @return self
   *   The result.
   */
  public static function available(string $domain, string $extension, string $provider): self {
    return new self($domain, $extension, DomainStatus::Available, $provider);
  }

  /**
   * Builds a "registered" result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The TLD with a leading dot.
   * @param string $provider
   *   The provider that answered.
   *
   * @return self
   *   The result.
   */
  public static function registered(string $domain, string $extension, string $provider): self {
    return new self($domain, $extension, DomainStatus::Registered, $provider);
  }

  /**
   * Builds an "unknown" result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The TLD with a leading dot.
   * @param string|null $provider
   *   The provider that last tried, if any.
   * @param string|null $reason
   *   Why the lookup is unknown.
   *
   * @return self
   *   The result.
   */
  public static function unknown(
    string $domain,
    string $extension,
    ?string $provider = NULL,
    ?string $reason = NULL,
  ): self {
    return new self($domain, $extension, DomainStatus::Unknown, $provider, $reason);
  }

  /**
   * Whether the lookup reached a definite answer.
   *
   * @return bool
   *   TRUE for available or registered, FALSE for unknown.
   */
  public function isConclusive(): bool {
    return $this->status->isConclusive();
  }

  /**
   * Copies the result with a price attached.
   *
   * @param \Drupal\domain_availability\Pricing\PriceValue|null $price
   *   The price, or NULL to leave the result unpriced.
   *
   * @return self
   *   The priced result.
   */
  public function withPrice(?PriceValue $price): self {
    return new self(
      $this->domain,
      $this->extension,
      $this->status,
      $this->provider,
      $this->reason,
      $price,
    );
  }

  /**
   * Serialises the result to the API contract.
   *
   * @return array<string, mixed>
   *   The result as domain, extension, available (tri-state), status,
   *   provider, and reason and price when present.
   */
  public function toArray(): array {
    $payload = [
      'domain' => $this->domain,
      'extension' => $this->extension,
      'available' => $this->status->toAvailability(),
      'status' => $this->status->value,
      'provider' => $this->provider,
    ];

    if ($this->reason !== NULL) {
      $payload['reason'] = $this->reason;
    }

    // Additive, and omitted entirely on a site that prices nothing: a client
    // written against 1.0.0 sees exactly the keys it saw before.
    if ($this->price !== NULL) {
      $payload['price'] = $this->price->toArray();
    }

    return $payload;
  }

}
