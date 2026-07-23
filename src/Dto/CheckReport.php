<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Dto;

/**
 * The full outcome of one availability check: every lookup plus its metadata.
 *
 * Immutable by design. A report is the answer to one question asked at one
 * moment; mutating it after the fact would mean `took_ms` and `cached` no
 * longer describe the results they sit next to.
 *
 * @api
 *   Public and stable since 1.0.0. Returned by DomainCheckService::check().
 */
final readonly class CheckReport {

  /**
   * Constructs a CheckReport.
   *
   * @param string $query
   *   The sanitised label that was checked.
   * @param array<int, \Drupal\domain_availability\Dto\DomainResult> $results
   *   One result per configured TLD, in configured order.
   * @param int $tookMs
   *   How long the check took, in milliseconds.
   * @param bool $cached
   *   Whether the results came from cache.
   */
  public function __construct(
    public string $query,
    public array $results,
    public int $tookMs,
    public bool $cached,
  ) {}

  /**
   * Copies the report, flagged as served from cache.
   *
   * The elapsed time is re-stamped with the cache hit's own cost: a client
   * reading took_ms wants to know what this request cost, not what the
   * original lookup cost.
   *
   * @param int $tookMs
   *   How long the cache hit took, in milliseconds.
   *
   * @return self
   *   The cached report.
   */
  public function asCached(int $tookMs): self {
    return new self($this->query, $this->results, $tookMs, TRUE);
  }

  /**
   * Serialises the report to the API contract.
   *
   * This shape is the standalone application's contract, kept byte for byte so
   * existing clients keep working.
   *
   * @return array<string, mixed>
   *   The payload as success, query, took_ms, cached and results.
   */
  public function toArray(): array {
    return [
      'success' => TRUE,
      'query' => $this->query,
      'took_ms' => $this->tookMs,
      'cached' => $this->cached,
      'results' => array_map(
        static fn (DomainResult $result): array => $result->toArray(),
        $this->results,
      ),
    ];
  }

}
