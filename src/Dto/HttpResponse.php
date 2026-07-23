<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

/**
 * Transport level result of a single HTTP request.
 *
 * A transport failure is represented as `status = 0` plus an `error`, rather
 * than an exception, so one dead endpoint cannot abort a whole parallel batch.
 *
 * @internal
 *   An internal transport value between services.
 */
final readonly class HttpResponse {

  /**
   * Constructs an HttpResponse.
   *
   * @param int $status
   *   The HTTP status code, or 0 for a transport failure.
   * @param string $body
   *   The response body.
   * @param string|null $error
   *   The transport error, or NULL on success.
   * @param float $totalTime
   *   The elapsed transfer time in seconds.
   */
  public function __construct(
    public int $status,
    public string $body,
    public ?string $error = NULL,
    public float $totalTime = 0.0,
  ) {}

  /**
   * Builds a transport-failure response.
   *
   * @param string $error
   *   The transport error.
   * @param float $totalTime
   *   The elapsed transfer time in seconds.
   *
   * @return self
   *   The response.
   */
  public static function transportError(string $error, float $totalTime = 0.0): self {
    return new self(0, '', $error, $totalTime);
  }

  /**
   * Whether the request succeeded with a 2xx status.
   *
   * @return bool
   *   TRUE on a 2xx with no transport error.
   */
  public function successful(): bool {
    return $this->error === NULL && $this->status >= 200 && $this->status < 300;
  }

  /**
   * Whether the request failed at the transport level.
   *
   * @return bool
   *   TRUE when a transport error is present.
   */
  public function hasError(): bool {
    return $this->error !== NULL;
  }

  /**
   * Decodes a JSON body.
   *
   * @return array<string, mixed>|null
   *   The decoded payload, or NULL when it is absent or not valid JSON.
   */
  public function json(): ?array {
    if ($this->body === '') {
      return NULL;
    }

    $decoded = json_decode($this->body, TRUE);

    return is_array($decoded) ? $decoded : NULL;
  }

}
