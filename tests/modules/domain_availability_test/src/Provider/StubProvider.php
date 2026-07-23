<?php

declare(strict_types=1);

namespace Drupal\domain_availability_test\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Provider\DomainProviderInterface;
use Drupal\domain_availability\Utility\Tld;

/**
 * A deterministic provider for tests.
 *
 * Answers from a fixed table instead of a registry, so the test suite is fast,
 * offline and repeatable. It also proves the extension point works: a provider
 * is a class plus a tagged service, with no change anywhere else.
 */
final class StubProvider implements DomainProviderInterface {

  /**
   * Domains this stub reports as registered.
   */
  public const REGISTERED = ['taken.com', 'taken.sa', 'taken.net'];

  /**
   * Domains this stub cannot answer for.
   */
  public const UNKNOWN = ['mystery.com', 'mystery.sa'];

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'stub';
  }

  /**
   * {@inheritdoc}
   */
  public function priority(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $tld): bool {
    return $tld !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $results = [];

    foreach ($domains as $domain) {
      $extension = Tld::withDot(Tld::fromDomain($domain));

      $results[$domain] = match (TRUE) {
        in_array($domain, self::REGISTERED, TRUE) => DomainResult::registered($domain, $extension, $this->name()),
        in_array($domain, self::UNKNOWN, TRUE) => DomainResult::unknown($domain, $extension, $this->name(), 'stub_unknown'),
        default => DomainResult::available($domain, $extension, $this->name()),
      };
    }

    return $results;
  }

}
