<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\domain_availability\Utility\DomainSanitizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that arbitrary user input is reduced to a bare domain label.
 */
#[Group('domain_availability')]
#[CoversMethod(DomainSanitizer::class, 'sanitize')]
final class DomainSanitizerTest extends UnitTestCase {

  /**
   * Tests sanitisation of every shape of input a user can paste.
   *
   * @param string $input
   *   The raw user input.
   * @param string $expected
   *   The label it must reduce to.
   */
  #[DataProvider('inputProvider')]
  public function testSanitize(string $input, string $expected): void {
    self::assertSame($expected, DomainSanitizer::sanitize($input));
  }

  /**
   * Input cases.
   *
   * @return array<string, array{string, string}>
   *   Case name => [input, expected].
   */
  public static function inputProvider(): array {
    return [
      'bare label' => ['neixora', 'neixora'],
      'domain with tld' => ['example.com', 'example'],
      'https url' => ['https://example.com', 'example'],
      'http url' => ['http://example.com', 'example'],
      'www prefix' => ['www.example.com', 'example'],
      'mixed case url with path' => ['HTTPS://WWW.Example.COM/pricing?a=1', 'example'],
      // Credentials must never survive into a lookup or a log line.
      'credentials stripped' => ['user:pass@evil.com', 'evil'],
      'port stripped' => ['example.com:8080', 'example'],
      'surrounding whitespace' => ['  spaced.com  ', 'spaced'],
      // "www" alone is a legitimate label; only the www. prefix is dropped.
      'literal www label' => ['www', 'www'],
      'only dots' => ['....', ''],
      'single character' => ['a', 'a'],
      'empty' => ['', ''],
      'subdomain takes first label' => ['shop.example.com', 'shop'],
      'trailing dot' => ['example.com.', 'example'],
      'control characters removed' => ["exa\x00mple.com", 'example'],
    ];
  }

}
