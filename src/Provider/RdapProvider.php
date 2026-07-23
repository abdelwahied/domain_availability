<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Dto\HttpResponse;
use Drupal\domain_availability\Utility\Tld;
use Psr\Log\LoggerInterface;
use Drupal\domain_availability\Service\ParallelHttpClient;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Service\RdapRegistryResolver;

/**
 * Preferred provider: RDAP (RFC 7482 / RFC 9082).
 *
 * RDAP is the modern replacement for WHOIS and answers with machine readable
 * JSON over HTTPS, which makes classification exact rather than pattern based:
 *   404 → the registry has no such object → available
 *   200 → the object exists              → registered
 *   429 / 5xx / transport error          → unknown (never a guess)
 *
 * @internal
 *   One implementation of DomainProviderInterface.
 */
final class RdapProvider implements DomainProviderInterface {
  private const PRIORITY = 10;

  public function __construct(
    private readonly ParallelHttpClient $client,
    private readonly RdapRegistryResolver $registry,
    private readonly LoggerInterface $logger,
    private readonly ModuleSettings $settings,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'rdap';
  }

  /**
   * {@inheritdoc}
   */
  public function priority(): int {
    return self::PRIORITY;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $tld): bool {
    // The administrative switch is checked before the registry: turning RDAP
    // off must take effect immediately, without a bootstrap fetch.
    return $this->settings->rdapEnabled() && $this->registry->supports($tld);
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(array $domains): array {
    $urls = [];
    $results = [];

    foreach ($domains as $domain) {
      $url = $this->urlFor($domain);

      if ($url === NULL) {
        $results[$domain] = DomainResult::unknown(
              $domain,
              Tld::withDot(Tld::fromDomain($domain)),
              $this->name(),
              'no_rdap_endpoint',
          );

        continue;
      }

      $urls[$domain] = $url;
    }

    $responses = $this->client->getMultiple($urls, [
      'Accept' => 'application/rdap+json, application/json',
    ]);

    foreach ($responses as $domain => $response) {
      $results[$domain] = $this->interpret((string) $domain, $response);
    }

    return $results;
  }

  /**
   * Builds the RDAP query URL for a domain.
   *
   * @param string $domain
   *   The fully qualified domain name.
   *
   * @return string|null
   *   The RDAP query URL, or NULL when the registry has no endpoint.
   */
  private function urlFor(string $domain): ?string {
    $base = $this->registry->resolve(Tld::fromDomain($domain));

    return $base === NULL ? NULL : $base . 'domain/' . rawurlencode($domain);
  }

  /**
   * Maps an RDAP response onto a domain result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The interpreted domain result.
   */
  private function interpret(string $domain, HttpResponse $response): DomainResult {
    $extension = Tld::withDot(Tld::fromDomain($domain));

    if ($response->hasError()) {
      $this->logger->warning('RDAP request failed.', [
        'provider' => $this->name(),
        'domain' => $domain,
        'response' => $response->error,
      ]);

      return DomainResult::unknown($domain, $extension, $this->name(), 'transport_error');
    }

    return match (TRUE) {
      $response->status === 404 => DomainResult::available($domain, $extension, $this->name()),
            $response->status === 200 => $this->interpretBody($domain, $extension, $response),
            default => $this->unexpectedStatus($domain, $extension, $response),
    };
  }

  /**
   * Interprets a 200 RDAP body.
   *
   * A 200 normally means the domain exists. Some registries answer 200 with
   * an errorCode body instead of the correct status, so the payload decides.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The extension.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The interpreted domain result.
   */
  private function interpretBody(string $domain, string $extension, HttpResponse $response): DomainResult {
    $payload = $response->json();

    if ($payload === NULL) {
      return DomainResult::unknown($domain, $extension, $this->name(), 'invalid_payload');
    }

    if (isset($payload['errorCode'])) {
      $errorCode = (int) $payload['errorCode'];

      return $errorCode === 404
                ? DomainResult::available($domain, $extension, $this->name())
                : DomainResult::unknown($domain, $extension, $this->name(), 'registry_error_' . $errorCode);
    }

    // An RDAP object for a registered domain always carries its identity.
    if (isset($payload['ldhName']) || isset($payload['handle']) || isset($payload['objectClassName'])) {
      return DomainResult::registered($domain, $extension, $this->name());
    }

    return DomainResult::unknown($domain, $extension, $this->name(), 'unrecognised_payload');
  }

  /**
   * Maps an unexpected RDAP status onto an unknown result.
   *
   * @param string $domain
   *   The fully qualified domain name.
   * @param string $extension
   *   The extension.
   * @param \Drupal\domain_availability\Dto\HttpResponse $response
   *   The response.
   *
   * @return \Drupal\domain_availability\Dto\DomainResult
   *   The interpreted domain result.
   */
  private function unexpectedStatus(string $domain, string $extension, HttpResponse $response): DomainResult {
    $reason = match (TRUE) {
      $response->status === 429 => 'rate_limited',
            $response->status === 403 => 'forbidden',
            $response->status >= 500 => 'registry_unavailable',
            default => 'unexpected_status_' . $response->status,
    };

    $this->logger->warning('RDAP returned an unexpected status.', [
      'provider' => $this->name(),
      'domain' => $domain,
      'status' => $response->status,
      'response' => substr($response->body, 0, 500),
    ]);

    return DomainResult::unknown($domain, $extension, $this->name(), $reason);
  }

}
