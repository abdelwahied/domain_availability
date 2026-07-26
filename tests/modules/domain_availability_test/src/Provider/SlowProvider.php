<?php

declare(strict_types=1);

namespace Drupal\domain_availability_test\Provider;

use Drupal\Core\State\StateInterface;
use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Provider\DomainProviderInterface;
use Drupal\domain_availability\Utility\Tld;

/**
 * A provider that burns a controllable amount of the lookup budget.
 *
 * Stands in for a registry that has gone slow, so a test can assert the check
 * gives up inside `max_lookup_time` instead of running to PHP's execution
 * limit. Inert unless a test sets its state keys, so it cannot affect any other
 * test in the suite.
 */
final class SlowProvider implements DomainProviderInterface {

  /**
   * State key holding the TLD this provider claims.
   */
  public const TLD_KEY = 'domain_availability_test.slow_tld';

  /**
   * State key holding how long one lookup takes, in milliseconds.
   */
  public const DELAY_KEY = 'domain_availability_test.slow_delay_ms';

  /**
   * Constructs a SlowProvider.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service holding the claimed TLD and the delay.
   */
  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'slow';
  }

  /**
   * {@inheritdoc}
   */
  public function priority(): int {
    // Ahead of the stub, so the TLD it claims is grouped to it first.
    return -1;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $tld): bool {
    return $tld !== '' && $tld === $this->state->get(self::TLD_KEY, '');
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    usleep((int) $this->state->get(self::DELAY_KEY, 0) * 1000);

    $results = [];

    foreach ($domains as $domain) {
      $results[$domain] = DomainResult::unknown(
        $domain,
        Tld::withDot(Tld::fromDomain($domain)),
        $this->name(),
        'slow_unknown',
      );
    }

    return $results;
  }

}
