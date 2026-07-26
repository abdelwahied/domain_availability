<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

/**
 * The wall-clock budget for one check, shared by everything it drives.
 *
 * `max_lookup_time` is a promise about the request the user is waiting on, so
 * it has to reach the code that actually spends the time: the DNS loop, the
 * WHOIS sockets and the HTTP pool. Passing a deadline down through
 * DomainProviderInterface would break a published contract, so the budget is a
 * service instead — DomainCheckService opens it, every lookup client reads it,
 * and work that no longer fits degrades to `unknown` rather than running the
 * request into PHP's max_execution_time.
 *
 * @internal
 *   Implementation detail of the check pipeline.
 */
final class LookupBudget {

  /**
   * Absolute deadline, or NULL when no check is running.
   */
  private ?float $deadline = NULL;

  /**
   * Opens a budget of the given length.
   *
   * @param float $seconds
   *   How long the check may run.
   */
  public function start(float $seconds): void {
    $this->deadline = microtime(TRUE) + max(0.0, $seconds);
  }

  /**
   * Closes the budget, so later work outside a check stays unbounded.
   */
  public function stop(): void {
    $this->deadline = NULL;
  }

  /**
   * Seconds left in the budget.
   *
   * @return float|null
   *   The remaining seconds, or NULL when no budget is open.
   */
  public function remaining(): ?float {
    return $this->deadline === NULL ? NULL : max(0.0, $this->deadline - microtime(TRUE));
  }

  /**
   * Whether an open budget has run out.
   *
   * @return bool
   *   TRUE when a budget is open and nothing is left of it.
   */
  public function exhausted(): bool {
    return $this->deadline !== NULL && microtime(TRUE) >= $this->deadline;
  }

  /**
   * Caps a timeout so a single request cannot outlive the check.
   *
   * Never returns zero: Guzzle and stream_socket_client() both read 0 as "no
   * timeout", so an exhausted budget must clamp to the smallest positive value
   * rather than to none at all.
   *
   * @param int $milliseconds
   *   The configured timeout.
   *
   * @return int
   *   The timeout, reduced to what is left of the budget.
   */
  public function clampMs(int $milliseconds): int {
    $remaining = $this->remaining();

    if ($remaining === NULL) {
      return $milliseconds;
    }

    return max(1, (int) min($milliseconds, $remaining * 1000));
  }

}
