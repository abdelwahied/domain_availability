<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Dto\WhoisResponse;

/**
 * Classifies a raw WHOIS payload as available / registered / unknown.
 *
 * WHOIS has no response schema — every registry words "no such domain"
 * differently — so classification is pattern based and deliberately
 * conservative: anything that is not clearly one of the two answers, or that
 * looks like throttling, resolves to `unknown` rather than a guess.
 *
 * @internal
 *   Implementation detail of the WHOIS path.
 */
final class WhoisResponseParser {

  /**
   * Constructs a WhoisResponseParser.
   *
   * @param list<string> $availablePatterns
   *   Markers meaning the domain is not registered.
   * @param list<string> $registeredPatterns
   *   Markers meaning the domain exists.
   * @param list<string> $rateLimitPatterns
   *   Markers meaning the registry refused to answer.
   */
  public function __construct(
    private readonly array $availablePatterns,
    private readonly array $registeredPatterns,
    private readonly array $rateLimitPatterns,
  ) {
  }

  /**
   * Classifies a WHOIS response as available, registered or unknown.
   *
   * @param \Drupal\domain_availability\Dto\WhoisResponse $response
   *   The WHOIS response to classify.
   *
   * @return \Drupal\domain_availability\Dto\DomainStatus
   *   The resolved domain status.
   */
  public function parse(WhoisResponse $response): DomainStatus {
    if ($response->hasError() || $response->isEmpty()) {
      return DomainStatus::Unknown;
    }

    $body = $this->normalise($response->body);

    // Throttling is checked first: a "query limit exceeded" body contains
    // none of the registration markers and would otherwise read as
    // "available", which is the one mistake this app must never make.
    if ($this->matches($body, $this->rateLimitPatterns)) {
      return DomainStatus::Unknown;
    }

    if ($this->matches($body, $this->availablePatterns)) {
      return DomainStatus::Available;
    }

    if ($this->matches($body, $this->registeredPatterns)) {
      return DomainStatus::Registered;
    }

    return DomainStatus::Unknown;
  }

  /**
   * Extracts the referral WHOIS server IANA points at, if any.
   *
   * @param \Drupal\domain_availability\Dto\WhoisResponse $response
   *   The WHOIS response to inspect.
   *
   * @return string|null
   *   The referral server hostname, or NULL when there is none.
   */
  public function extractReferral(WhoisResponse $response): ?string {
    if ($response->isEmpty()) {
      return NULL;
    }

    // Horizontal whitespace only: IANA emits a bare `whois:` line for
    // RDAP-only registries, and `\s*` would happily jump the newline and
    // capture the first token of the next line as the hostname.
    if (preg_match('/^[^\S\r\n]*(?:whois|refer):[^\S\r\n]*(\S+)[^\S\r\n]*$/mi', $response->body, $matches) !== 1) {
      return NULL;
    }

    $server = strtolower(trim($matches[1]));

    return $this->isValidHostname($server) ? $server : NULL;
  }

  /**
   * Strips the legal boilerplate registries append.
   *
   * The boilerplate frequently contains words such as "not found" and would
   * poison naive matching.
   *
   * @param string $body
   *   The raw WHOIS payload.
   *
   * @return string
   *   The normalised payload.
   */
  private function normalise(string $body): string {
    $body = strtolower($body);
    $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
    $kept = [];

    foreach ($lines as $line) {
      $trimmed = trim($line);

      if ($trimmed === '' || str_starts_with($trimmed, '%') || str_starts_with($trimmed, '#')) {
        continue;
      }

      if ($this->isBoilerplate($trimmed)) {
        continue;
      }

      $kept[] = $trimmed;
    }

    return implode("\n", $kept);
  }

  /**
   * Determines whether a line is legal boilerplate.
   *
   * Markers are matched against the line's content, never against its
   * decoration: several registries (.cloud among them) deliver the actual
   * verdict on a `>>> … <<<` line, so treating that prefix as boilerplate
   * would throw away the one line that answers the question.
   *
   * @param string $line
   *   The line to test.
   *
   * @return bool
   *   TRUE when the line is boilerplate.
   */
  private function isBoilerplate(string $line): bool {
    static $markers = [
      'terms of use',
      'by submitting',
      'by the following terms',
      'this data is provided',
      'the data in',
      'for more information',
      'notice:',
      'terms and conditions',
      'you agree that',
      'we reserve the right',
      'url of the icann',
      'complaint form',
      'last update of whois database',
      'please visit',
      'accredited registrars',
    ];

    foreach ($markers as $marker) {
      if (str_contains($line, $marker)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Tests whether any pattern is present in the payload.
   *
   * @param string $body
   *   The normalised payload.
   * @param list<string> $patterns
   *   The patterns to match.
   *
   * @return bool
   *   TRUE when at least one pattern matches.
   */
  private function matches(string $body, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if ($pattern !== '' && str_contains($body, strtolower($pattern))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether a string is a valid hostname.
   *
   * @param string $host
   *   The hostname to validate.
   *
   * @return bool
   *   TRUE when the hostname is valid.
   */
  private function isValidHostname(string $host): bool {
    return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1;
  }

}
