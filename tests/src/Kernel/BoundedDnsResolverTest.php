<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\domain_availability\Provider\DnsProvider;
use Drupal\domain_availability\Service\BoundedDnsResolver;
use Drupal\domain_availability\Service\LookupBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that DNS cannot outlive the check budget.
 *
 * The regression these exist for: `dns_get_record()` takes no timeout, so a
 * stalled resolver ran a lookup to PHP's `max_execution_time` and ended the
 * request in a fatal — an HTTP 500 on the page holding the search box, with
 * nothing in `watchdog` because the process died before the logger ran. Every
 * other failure in this module degrades to `unknown`; that one did not degrade
 * at all.
 *
 * The assertions are about time and about the shape of the answer, because
 * those are the two things that were wrong: a query has to come back inside its
 * deadline, and "nothing answered" has to stay distinguishable from "answered,
 * there are none". Conflating them is what made a network failure cacheable as
 * though it were a fact about the name.
 *
 * No test here touches a real resolver — the blackhole addresses are from
 * RFC 5737 documentation ranges, which are guaranteed not to route.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\Service\BoundedDnsResolver
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversClass(BoundedDnsResolver::class)]
final class BoundedDnsResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'domain_availability'];

  /**
   * Addresses that silently drop packets, so a query can only time out.
   */
  private const BLACKHOLE = "nameserver 203.0.113.1\nnameserver 198.51.100.1\n";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);
  }

  /**
   * A stalled resolver returns inside the timeout instead of hanging.
   */
  public function testStalledResolverReturnsWithinItsTimeout(): void {
    $resolver = $this->resolver(new LookupBudget());

    $started = microtime(TRUE);
    $answer = $resolver->query('whois.example.com', BoundedDnsResolver::TYPE_A, 400);
    $elapsed = microtime(TRUE) - $started;

    self::assertNull($answer, 'A resolver that never answers must report "no answer", not "no records".');
    self::assertLessThan(
      2.0,
      $elapsed,
      'The query must be bounded by its own timeout; before the fix this ran to max_execution_time.',
    );
  }

  /**
   * An open check budget is the ceiling, even when the caller asks for more.
   *
   * This is the property `max_lookup_time` promises and DNS used to ignore.
   */
  public function testTheCheckBudgetOutranksTheRequestedTimeout(): void {
    $budget = new LookupBudget();
    $budget->start(0.4);

    $resolver = $this->resolver($budget);

    $started = microtime(TRUE);
    $resolver->query('whois.example.com', BoundedDnsResolver::TYPE_A, 30000);
    $elapsed = microtime(TRUE) - $started;

    self::assertLessThan(
      2.0,
      $elapsed,
      'A 30 second request must still be clamped to what is left of the budget.',
    );
  }

  /**
   * With no nameserver to ask, the resolver says so rather than guessing.
   */
  public function testNoNameserverIsUnusableRatherThanUnbounded(): void {
    $resolver = new BoundedDnsResolver(
      new LookupBudget(),
      $this->container->get('logger.channel.domain_availability'),
      $this->fixture(''),
    );

    self::assertFalse($resolver->isUsable());
    self::assertSame([], $resolver->nameservers());
    self::assertNull($resolver->query('whois.example.com', BoundedDnsResolver::TYPE_A, 500));
  }

  /**
   * Only routable nameserver lines are accepted.
   */
  public function testResolvConfIsParsedStrictly(): void {
    $resolver = new BoundedDnsResolver(
      new LookupBudget(),
      $this->container->get('logger.channel.domain_availability'),
      $this->fixture("# a comment\nsearch example.com\nnameserver 203.0.113.1\nnameserver not-an-address\nnameserver fe80::1%en0\n"),
    );

    self::assertSame(['203.0.113.1', 'fe80::1'], $resolver->nameservers());
  }

  /**
   * The DNS provider degrades instead of spending a spent budget.
   *
   * The larger of the two exposures: this loop runs up to five queries on names
   * that came from the public search box, and it had no budget check at all.
   */
  public function testDnsProviderRefusesToStartOnAnExhaustedBudget(): void {
    $budget = new LookupBudget();
    // Already over.
    $budget->start(0.0);

    $provider = new DnsProvider(
      $this->container->get('logger.channel.domain_availability'),
      $this->container->get('domain_availability.settings'),
      $this->resolver($budget),
      $budget,
    );

    $started = microtime(TRUE);
    $results = $provider->lookup(['a.example', 'b.example', 'c.example']);
    $elapsed = microtime(TRUE) - $started;

    self::assertCount(3, $results);

    foreach ($results as $domain => $result) {
      self::assertSame('unknown', $result->status->value, $domain . ' must degrade, never guess.');
      self::assertSame('budget_exhausted', $result->reason, $domain . ' must say why.');
    }

    self::assertLessThan(0.5, $elapsed, 'An exhausted budget must cost no network time at all.');
  }

  /**
   * A stalled resolver makes the provider inconclusive, not wrong.
   *
   * `dns_timeout` and `no_delegation_inconclusive` are both `unknown`, but they
   * are different facts: one is about the network and one is about the domain.
   */
  public function testDnsProviderReportsTimeoutAsItsOwnReason(): void {
    $budget = new LookupBudget();
    $budget->start(2.0);

    $provider = new DnsProvider(
      $this->container->get('logger.channel.domain_availability'),
      $this->container->get('domain_availability.settings'),
      $this->resolver($budget),
      $budget,
    );

    $results = $provider->lookup(['example.test']);
    $result = $results['example.test'];

    self::assertSame('unknown', $result->status->value);
    self::assertSame('dns_timeout', $result->reason);
  }

  /**
   * A resolver pointed at a blackhole.
   *
   * @param \Drupal\domain_availability\Service\LookupBudget $budget
   *   The budget to bind it to.
   *
   * @return \Drupal\domain_availability\Service\BoundedDnsResolver
   *   The resolver.
   */
  private function resolver(LookupBudget $budget): BoundedDnsResolver {
    return new BoundedDnsResolver(
      $budget,
      $this->container->get('logger.channel.domain_availability'),
      $this->fixture(self::BLACKHOLE),
    );
  }

  /**
   * Writes a resolver configuration and returns its path.
   *
   * @param string $contents
   *   The file contents.
   *
   * @return string
   *   The path.
   */
  private function fixture(string $contents): string {
    $path = 'public://resolv-' . md5($contents) . '.conf';
    $dir = 'public://';

    $this->container->get('file_system')->prepareDirectory($dir);
    file_put_contents($path, $contents);

    return (string) $this->container->get('file_system')->realpath($path);
  }

}
