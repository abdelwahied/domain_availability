<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

/**
 * Immutable outcome of a single domain lookup.
 *
 * @api
 *   Public and stable since 1.0.0. Returned inside a CheckReport, and by every
 *   provider.
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
   */
  public function __construct(
    public string $domain,
    public string $extension,
    public DomainStatus $status,
    public ?string $provider = NULL,
    public ?string $reason = NULL,
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
   * Serialises the result to the API contract.
   *
   * @return array<string, mixed>
   *   The result as domain, extension, available (tri-state), status,
   *   provider, and reason when present.
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

    return $payload;
  }

}
