<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Exception\ConfigurationException;
use Drupal\domain_availability\Provider\DomainProviderInterface;
use Drupal\domain_availability\Provider\ProviderRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests provider ordering and fallback chains.
 */
#[Group('domain_availability')]
#[CoversMethod(ProviderRegistry::class, 'all')]
#[CoversMethod(ProviderRegistry::class, 'names')]
#[CoversMethod(ProviderRegistry::class, 'chainFor')]
#[CoversMethod(ProviderRegistry::class, 'bestFor')]
#[CoversMethod(ProviderRegistry::class, 'nextAfter')]
#[CoversMethod(ProviderRegistry::class, 'add')]
final class ProviderRegistryTest extends UnitTestCase {

  /**
   * Providers are ordered by their own priority, not registration order.
   */
  public function testOrdersByPriority(): void {
    $registry = new ProviderRegistry([
      $this->provider('slow', 30),
      $this->provider('fast', 10),
      $this->provider('middle', 20),
    ]);

    self::assertSame(['fast', 'middle', 'slow'], $registry->names());
  }

  /**
   * A chain lists only the providers that support the TLD, best first.
   */
  public function testChainForTld(): void {
    $registry = new ProviderRegistry([
      $this->provider('rdap', 10, ['com']),
      $this->provider('whois', 20, ['com', 'sa']),
      $this->provider('dns', 30),
    ]);

    self::assertSame('rdap', $registry->bestFor('com')?->name());
    self::assertSame(['rdap', 'whois', 'dns'], array_map(
      static fn (DomainProviderInterface $p): string => $p->name(),
      $registry->chainFor('com'),
    ));

    // .sa has no RDAP: the chain must skip it rather than fail.
    self::assertSame('whois', $registry->bestFor('sa')?->name());
    self::assertSame(['whois', 'dns'], array_map(
      static fn (DomainProviderInterface $p): string => $p->name(),
      $registry->chainFor('sa'),
    ));
  }

  /**
   * The next provider after one that answered "unknown".
   */
  public function testNextAfter(): void {
    $registry = new ProviderRegistry([
      $this->provider('rdap', 10, ['com']),
      $this->provider('whois', 20, ['com']),
      $this->provider('dns', 30),
    ]);

    self::assertSame('whois', $registry->nextAfter('com', 'rdap')?->name());
    self::assertSame('dns', $registry->nextAfter('com', 'whois')?->name());
    self::assertNull($registry->nextAfter('com', 'dns'));
  }

  /**
   * Two providers cannot share a name: results report the provider, and an.
   *
   * Ambiguous name would make a result untraceable.
   */
  public function testDuplicateNameRejected(): void {
    $this->expectException(ConfigurationException::class);

    new ProviderRegistry([
      $this->provider('rdap', 10),
      $this->provider('rdap', 20),
    ]);
  }

  /**
   * An unsupported TLD yields no provider rather than a wrong one.
   */
  public function testUnsupportedTld(): void {
    $registry = new ProviderRegistry([$this->provider('rdap', 10, ['com'])]);

    self::assertNull($registry->bestFor('sa'));
    self::assertSame([], $registry->chainFor('sa'));
  }

  /**
   * Builds a provider double.
   *
   * @param string $name
   *   The provider name.
   * @param int $priority
   *   The priority.
   * @param array<int, string>|null $tlds
   *   Supported TLDs, or NULL for all.
   *
   * @return \Drupal\domain_availability\Provider\DomainProviderInterface
   *   The provider double.
   */
  private function provider(string $name, int $priority, ?array $tlds = NULL): DomainProviderInterface {
    return new class($name, $priority, $tlds) implements DomainProviderInterface {

      /**
       * Constructs the provider double.
       *
       * @param string $name
       *   The provider name.
       * @param int $priority
       *   The priority.
       * @param array<int, string>|null $tlds
       *   Supported TLDs, or NULL for all.
       */
      public function __construct(
        private readonly string $name,
        private readonly int $priority,
        private readonly ?array $tlds,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function name(): string {
        return $this->name;
      }

      /**
       * {@inheritdoc}
       */
      public function priority(): int {
        return $this->priority;
      }

      /**
       * {@inheritdoc}
       */
      public function supports(string $tld): bool {
        return $this->tlds === NULL || in_array($tld, $this->tlds, TRUE);
      }

      /**
       * {@inheritdoc}
       */
      public function lookup(array $domains): array {
        $results = [];

        foreach ($domains as $domain) {
          $results[$domain] = DomainResult::available($domain, '.test', $this->name);
        }

        return $results;
      }

    };
  }

}
