<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Dto\HttpResponse;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Service\ParallelHttpClient;

/**
 * Authoritative provider for TLDs that public protocols cannot serve.
 *
 * You do not need this for .sa. SaudiNIC publishes no RDAP, but its WHOIS host
 * does answer over IPv4 — see HostResolver for the dead AAAA record that once
 * made it look blocked. This provider is for the cases WHOIS genuinely cannot
 * serve: a registry that throttles you at scale, or a ccTLD that restricts
 * WHOIS outright.
 *
 * It is off until configured. With no endpoint and key, supports() returns
 * FALSE and the registry skips it entirely — behaviour is exactly as if the
 * class did not exist. Configure it at /admin/config/system/domain-availability
 * and it takes priority 5, ahead of RDAP and WHOIS, with both still behind it
 * as fallbacks.
 *
 * It is vendor agnostic. The request and response shapes are service
 * parameters, shipped mapped to WhoisFreaks' documented contract:
 *
 * @code
 * GET https://api.whoisfreaks.com/v2.0/domain/availability?domain=x.sa&apiKey=KEY
 * → [{"domain": "x.sa", "availability": "AVAILABLE" | "UNAVAILABLE"}]
 * @endcode
 *
 * Any vendor answering with a flat JSON verdict field fits by overriding those
 * parameters. A registrar's EPP <check> is the other authoritative route: it
 * speaks XML over a persistent TLS session rather than HTTP, so it belongs in
 * a sibling class implementing this same interface — not in a config switch
 * here.
 *
 * @internal
 *   One implementation of DomainProviderInterface.
 */
final class SaudiNicProvider implements DomainProviderInterface {

  /**
   * Ahead of RDAP and WHOIS: a licensed source outranks a public protocol.
   */
  private const PRIORITY = 5;

  /**
   * Constructs a SaudiNicProvider.
   *
   * @param \Drupal\domain_availability\Service\ParallelHttpClient $client
   *   The parallel HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings, which carry the endpoint and the key.
   * @param array{field: string, available: array<int, string>, registered: array<int, string>} $responseMap
   *   How to read the vendor's verdict out of its payload.
   * @param string $domainParam
   *   The query parameter carrying the domain.
   * @param string $keyParam
   *   The query parameter carrying the API key.
   * @param string|null $keyHeader
   *   Send the key as this header instead of a query parameter, when the
   *   vendor supports it. NULL puts the key in the query string.
   */
  public function __construct(
    private readonly ParallelHttpClient $client,
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
    private readonly array $responseMap,
    private readonly string $domainParam = 'domain',
    private readonly string $keyParam = 'apiKey',
    private readonly ?string $keyHeader = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'saudinic';
  }

  /**
   * {@inheritdoc}
   */
  public function priority(): int {
    return self::PRIORITY;
  }

  /**
   * {@inheritdoc}
   *
   * Claims a TLD only when this provider can actually answer for it: enabled,
   * with an endpoint and a credential. A half-configured provider must not
   * shadow WHOIS and turn working lookups into failures.
   */
  public function supports(string $tld): bool {
    return $this->settings->saudinicEnabled()
            && $this->settings->saudinicEndpoint() !== ''
            && $this->settings->saudinicApiKey() !== ''
            && in_array(Tld::normalise($tld), $this->settings->saudinicTlds(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $urls = [];

    foreach ($domains as $domain) {
      $urls[$domain] = $this->urlFor($domain);
    }

    $headers = ['Accept' => 'application/json'];

    if ($this->keyHeader !== NULL && $this->keyHeader !== '') {
      $headers[$this->keyHeader] = $this->settings->saudinicApiKey();
    }

    $results = [];

    foreach ($this->client->getMultiple($urls, $headers) as $domain => $response) {
      $results[$domain] = $this->interpret((string) $domain, $response);
    }

    return $results;
  }

  /**
   * Builds the request URL for one domain.
   *
   * The key travels as a query parameter because that is what the vendor's
   * contract requires. Set a key header in configuration to move it out of
   * the URL, and out of the vendor's access logs, when they support it.
   *
   * @param string $domain
   *   The fully qualified domain name.
   *
   * @return string
   *   The request URL.
   */
  private function urlFor(string $domain): string {
    $query = [$this->domainParam => $domain];

    if ($this->keyHeader === NULL || $this->keyHeader === '') {
      $query[$this->keyParam] = $this->settings->saudinicApiKey();
    }

    $endpoint = $this->settings->saudinicEndpoint();
    $separator = str_contains($endpoint, '?') ? '&' : '?';

    return $endpoint . $separator . http_build_query($query);
  }

  /**
   * Maps an upstream response onto a domain result.
   *
   * Every failure mode resolves to `unknown` — an auth failure or a quota
   * refusal says nothing about whether the domain is free.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The upstream response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The result.
   */
  private function interpret(string $domain, HttpResponse $response): DomainResult {
    $extension = Tld::withDot(Tld::fromDomain($domain));

    if ($response->hasError()) {
      $this->log($domain, 'Upstream request failed.', ['response' => $response->error]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'transport_error');
    }

    if (!$response->successful()) {
      $reason = match (TRUE) {
        $response->status === 401, $response->status === 403 => 'auth_failed',
                $response->status === 402 => 'quota_exhausted',
                $response->status === 429 => 'rate_limited',
                $response->status >= 500 => 'upstream_unavailable',
                default => 'unexpected_status_' . $response->status,
      };

      // Never log the response body verbatim here: a rejected request is
      // exactly the case where a vendor tends to echo the API key back.
      $this->log($domain, 'Upstream returned an error status.', ['status' => $response->status]);

      return DomainResult::unknown($domain, $extension, $this->name(), $reason);
    }

    return $this->classify($domain, $extension, $response);
  }

  /**
   * Turns a successful payload into a verdict.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The TLD with a leading dot.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The upstream response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The result.
   */
  private function classify(string $domain, string $extension, HttpResponse $response): DomainResult {
    $verdict = $this->extractVerdict($domain, $response);

    if ($verdict === NULL) {
      $this->log($domain, 'Upstream payload could not be classified.', []);

      return DomainResult::unknown($domain, $extension, $this->name(), 'unrecognised_payload');
    }

    $verdict = strtoupper(trim($verdict));

    if (in_array($verdict, $this->normalised('available'), TRUE)) {
      return DomainResult::available($domain, $extension, $this->name());
    }

    if (in_array($verdict, $this->normalised('registered'), TRUE)) {
      return DomainResult::registered($domain, $extension, $this->name());
    }

    // An unmapped verdict is a contract change, not an availability signal.
    $this->log($domain, 'Upstream returned an unmapped verdict.', ['verdict' => $verdict]);

    return DomainResult::unknown($domain, $extension, $this->name(), 'unmapped_verdict');
  }

  /**
   * Pulls the verdict out of the payload.
   *
   * Accepts both shapes vendors use: a bare object, or a list of results
   * (WhoisFreaks answers with a list even for a single domain). With a list,
   * only the entry naming the queried domain counts — suggestion APIs return
   * neighbours we never asked about.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The upstream response.
   *
   * @return string|null
   *   The raw verdict, or NULL when the payload cannot be read.
   */
  private function extractVerdict(string $domain, HttpResponse $response): ?string {
    $payload = $response->json();

    if ($payload === NULL) {
      return NULL;
    }

    $field = $this->responseMap['field'];

    if (isset($payload[$field]) && is_scalar($payload[$field])) {
      return (string) $payload[$field];
    }

    foreach ($payload as $entry) {
      if (!is_array($entry) || !isset($entry[$field]) || !is_scalar($entry[$field])) {
        continue;
      }

      // With several entries, only the one naming our domain counts —
      // suggestion APIs happily return neighbours we never asked about.
      $named = isset($entry['domain']) && is_scalar($entry['domain'])
                ? strtolower((string) $entry['domain'])
                : NULL;

      if ($named === NULL || $named === strtolower($domain)) {
        return (string) $entry[$field];
      }
    }

    return NULL;
  }

  /**
   * Returns the mapped verdicts for a side, upper-cased for comparison.
   *
   * @param string $key
   *   Either 'available' or 'registered'.
   *
   * @return array<int, string>
   *   The verdicts, upper-cased and trimmed.
   */
  private function normalised(string $key): array {
    return array_map(
          static fn (string $value): string => strtoupper(trim($value)),
          $this->responseMap[$key],
      );
  }

  /**
   * Logs a provider event, always stamped with the provider and domain.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $message
   *   The log message.
   * @param array<string, mixed> $context
   *   Extra, non-sensitive context. Never the raw response body.
   */
  private function log(string $domain, string $message, array $context): void {
    $this->logger->warning($message, [
      'provider' => $this->name(),
      'domain' => $domain,
    ] + $context);
  }

}
