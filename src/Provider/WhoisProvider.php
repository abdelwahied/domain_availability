<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Dto\DomainStatus;
use Drupal\domain_availability\Dto\WhoisResponse;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Service\WhoisClient;
use Drupal\domain_availability\Service\WhoisResponseParser;
use Drupal\domain_availability\Service\WhoisServerResolver;

/**
 * WHOIS provider (RFC 3912): the fallback for registries without RDAP.
 *
 * It is also the only option for ccTLDs such as .sa and .ai. WHOIS output is
 * free-form text, so answers are classified by the parser and anything
 * ambiguous (or throttled) resolves to `unknown`.
 *
 * @internal
 *   One implementation of DomainProviderInterface.
 */
final class WhoisProvider implements DomainProviderInterface {
  private const PRIORITY = 20;

  /**
   * Constructs a WhoisProvider.
   *
   * @param \Drupal\domain_availability\Service\WhoisClient $client
   *   The client.
   * @param \Drupal\domain_availability\Service\WhoisServerResolver $resolver
   *   The resolver.
   * @param \Drupal\domain_availability\Service\WhoisResponseParser $parser
   *   The parser.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The settings.
   * @param array<string, string> $queryFormats
   *   WHOIS host => sprintf format.
   */
  public function __construct(
    private readonly WhoisClient $client,
    private readonly WhoisServerResolver $resolver,
    private readonly WhoisResponseParser $parser,
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
    private readonly array $queryFormats = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'whois';
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
   * Only configured TLDs are claimed up front: discovering an unknown TLD's
   * host costs a network round trip, and `supports()` must stay cheap.
   * Unlisted TLDs are still handled — `lookup()` discovers their host — so
   * the registry can route to this provider whenever RDAP declines.
   */
  public function supports(string $tld): bool {
    return $this->settings->whoisEnabled() && $this->resolver->isConfigured($tld);
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $results = [];
    $queries = [];

    $servers = $this->resolver->resolveMany(array_map(
          static fn (string $domain): string => Tld::fromDomain($domain),
          $domains,
      ));

    foreach ($domains as $domain) {
      $tld = Tld::fromDomain($domain);
      $server = $servers[$tld] ?? NULL;

      if ($server === NULL) {
        $results[$domain] = DomainResult::unknown(
              $domain,
              Tld::withDot($tld),
              $this->name(),
              'no_whois_server',
          );

        continue;
      }

      $queries[$domain] = [
        'server' => $server,
        'query' => $this->formatQuery($server, $domain),
      ];
    }

    foreach ($this->client->queryMany($queries) as $domain => $response) {
      $results[$domain] = $this->interpret((string) $domain, $response);
    }

    return $results;
  }

  /**
   * Builds the query line.
   *
   * Verisign needs an explicit `domain ` keyword to return an exact match
   * instead of a fuzzy search; everything else takes the bare name
   * terminated by CRLF, per RFC 3912.
   *
   * @param string $server
   *   The WHOIS host.
   * @param string $domain
   *   The fully qualified domain name.
   *
   * @return string
   *   The formatted query line.
   */
  private function formatQuery(string $server, string $domain): string {
    $format = $this->queryFormats[$server] ?? "%s\r\n";

    return sprintf($format, $domain);
  }

  /**
   * Interprets a WHOIS response as a domain result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param \Drupal\domain_availability\Dto\WhoisResponse $response
   *   The response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The interpreted domain result.
   */
  private function interpret(string $domain, WhoisResponse $response): DomainResult {
    $extension = Tld::withDot(Tld::fromDomain($domain));

    if ($response->hasError()) {
      $this->logger->warning('WHOIS request failed.', [
        'provider' => $this->name(),
        'domain' => $domain,
        'server' => $response->server,
        'response' => $response->error,
      ]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'transport_error');
    }

    $status = $this->parser->parse($response);

    if ($status === DomainStatus::Unknown) {
      $this->logger->info('WHOIS response could not be classified.', [
        'provider' => $this->name(),
        'domain' => $domain,
        'server' => $response->server,
        'response' => substr($response->body, 0, 800),
      ]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'inconclusive_response');
    }

    return new DomainResult($domain, $extension, $status, $this->name());
  }

}
