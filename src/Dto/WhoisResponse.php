<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

/**
 * Raw payload returned by a WHOIS (port 43) conversation.
 *
 * @internal
 *   An internal transport value between services.
 */
final readonly class WhoisResponse {

  /**
   * Constructs a WhoisResponse.
   *
   * @param string $server
   *   The WHOIS host that answered.
   * @param string $body
   *   The raw payload.
   * @param string|null $error
   *   The transport error, or NULL on success.
   */
  public function __construct(
    public string $server,
    public string $body,
    public ?string $error = NULL,
  ) {}

  /**
   * Builds a transport-failure response.
   *
   * @param string $server
   *   The WHOIS host that was contacted.
   * @param string $error
   *   The transport error.
   *
   * @return self
   *   The response.
   */
  public static function transportError(string $server, string $error): self {
    return new self($server, '', $error);
  }

  /**
   * Whether the query failed at the transport level.
   *
   * @return bool
   *   TRUE when a transport error is present.
   */
  public function hasError(): bool {
    return $this->error !== NULL;
  }

  /**
   * Whether the payload is empty.
   *
   * @return bool
   *   TRUE when the body is blank.
   */
  public function isEmpty(): bool {
    return trim($this->body) === '';
  }

}
