<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Unit;

use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Dto\WhoisResponse;
use Drupal\domain_availability\Service\WhoisResponseParser;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests WHOIS classification against real registry payloads.
 *
 * WHOIS has no response schema, so this is where the module's central promise
 * is enforced: a throttled or unreadable reply must resolve to `unknown`, never
 * to `available`.
 */
#[Group('domain_availability')]
#[CoversMethod(WhoisResponseParser::class, 'parse')]
#[CoversMethod(WhoisResponseParser::class, 'extractReferral')]
final class WhoisResponseParserTest extends UnitTestCase {

  /**
   * The parser, built from the module's own service parameters.
   */
  private WhoisResponseParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Read the patterns from services.yml rather than duplicating them: a test
    // that hardcodes its own patterns stops testing the shipped ones.
    //
    // PARSE_CUSTOM_TAGS: the file uses Symfony's !tagged_iterator, which the
    // parser rejects unless custom tags are enabled. Only the parameters are
    // read here, but the whole document still has to parse.
    $services = Yaml::parseFile(
      __DIR__ . '/../../../domain_availability.services.yml',
      Yaml::PARSE_CUSTOM_TAGS,
    );
    $parameters = $services['parameters'];

    $this->parser = new WhoisResponseParser(
      $parameters['domain_availability.whois_available_patterns'],
      $parameters['domain_availability.whois_registered_patterns'],
      $parameters['domain_availability.whois_rate_limit_patterns'],
    );
  }

  /**
   * Tests classification of real registry payloads.
   *
   * @param string $body
   *   The raw WHOIS payload.
   * @param \Drupal\domain_availability\Dto\DomainStatus $expected
   *   The status it must classify to.
   */
  #[DataProvider('payloadProvider')]
  public function testParse(string $body, DomainStatus $expected): void {
    self::assertSame($expected, $this->parser->parse(new WhoisResponse('test.server', $body)));
  }

  /**
   * Real payloads, verbatim from the registries.
   *
   * @return array<string, array{string, \Drupal\domain_availability\Dto\DomainStatus}>
   *   The cases.
   */
  public static function payloadProvider(): array {
    return [
      // .co answers with wording no other registry uses.
      'co free' => [
        "The queried object does not exist: DOMAIN NOT FOUND\n\n>>> Last update of WHOIS database: 2026-07-16T20:21:08.0Z <<<",
        DomainStatus::Available,
      ],
      // .cloud delivers the verdict on a >>> line — the exact case that once
      // made the boilerplate filter throw the answer away.
      'cloud free' => [
        ">>> Domain x.cloud is available for registration\n\n>>> Please visit https://rdap.registry.cloud/registrars/ for a list of\naccredited registrars\n\n>>> Last update of WHOIS database: 2026 <<<",
        DomainStatus::Available,
      ],
      'verisign free' => [
        "No match for \"ZZZQQQ.COM\".\n>>> Last update of whois database: 2026-07-16T20:00:00Z <<<",
        DomainStatus::Available,
      ],
      'verisign taken' => [
        "Domain Name: GOOGLE.COM\nRegistrar: MarkMonitor Inc.\nCreation Date: 1997-09-15T04:00:00Z\nName Server: NS1.GOOGLE.COM",
        DomainStatus::Registered,
      ],
      'sa taken' => [
        "Domain Name: stc.sa\nRegistrant:\n  Saudi Telecom Company\nName Servers:\n  ns1.stc.com.sa",
        DomainStatus::Registered,
      ],
      // Throttling must never read as availability.
      'throttled' => [
        'WHOIS LIMIT EXCEEDED - SEE WWW.PIR.ORG/WHOIS FOR DETAILS',
        DomainStatus::Unknown,
      ],
      'rate limited' => [
        'Your connection limit exceeded. Please slow down and try again later.',
        DomainStatus::Unknown,
      ],
      'banned' => [
        'Access denied: you have been banned for excessive queries.',
        DomainStatus::Unknown,
      ],
      'boilerplate only' => [
        "% This query returned 0 objects.\n% Terms of use: the data in this WHOIS database is provided for information purposes.",
        DomainStatus::Unknown,
      ],
      'empty' => ['', DomainStatus::Unknown],
    ];
  }

  /**
   * A transport error is never a verdict.
   */
  public function testTransportErrorIsUnknown(): void {
    $response = WhoisResponse::transportError('test.server', 'connect_timeout');
    self::assertSame(DomainStatus::Unknown, $this->parser->parse($response));
  }

  /**
   * IANA referrals are read from the whois: line only.
   */
  public function testExtractReferral(): void {
    $body = "domain:       CO\norganisation: MinTIC\nwhois:        whois.registry.co\nstatus:       ACTIVE";
    self::assertSame('whois.registry.co', $this->parser->extractReferral(new WhoisResponse('whois.iana.org', $body)));
  }

  /**
   * An empty whois: line must not capture the next line.
   *
   * RDAP-only registries publish a bare `whois:` line; a greedy pattern would
   * read the following line's first token as a hostname.
   */
  public function testExtractReferralIgnoresEmptyWhoisLine(): void {
    $body = "domain:       APP\nwhois:\nstatus:       ACTIVE\nremarks:      RDAP only";
    self::assertNull($this->parser->extractReferral(new WhoisResponse('whois.iana.org', $body)));
  }

}
