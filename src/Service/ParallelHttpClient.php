<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Dto\HttpResponse;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Concurrent HTTP client built on Drupal's injected Guzzle client.
 *
 * The standalone application drove curl_multi directly. This is the Drupal
 * translation of the same idea and keeps the property the whole design rests
 * on: every request in a batch is on the wire simultaneously, so a batch costs
 * roughly its slowest single request instead of the sum of all of them.
 * Guzzle's Pool is what preserves that. Sending requests one at a time through
 * $client->request() would turn a ~1 second parallel sweep into ~20 sequential
 * round trips, which is the single easiest way to ruin this module.
 * Handler-level connection pooling gives us the DNS and TLS reuse that the
 * standalone version had to ask curl_share for explicitly.
 * A transport failure is returned as HttpResponse with status 0 and an error,
 * never thrown: one dead registry must not abort a whole batch.
 *
 * @internal
 *   Implementation detail of the RDAP path.
 */
final class ParallelHttpClient {

  /**
   * Constructs a ParallelHttpClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal's http_client service.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ModuleSettings $settings,
  ) {}

  /**
   * Executes a batch of GET requests in parallel.
   *
   * @param array<string, string> $urls
   *   Caller key => URL.
   * @param array<string, string> $headers
   *   Headers sent with every request.
   * @param int|null $timeoutMs
   *   Total per-request budget, defaulting to the RDAP timeout setting.
   * @param int|null $connectTimeoutMs
   *   Connection phase budget, defaulting to the RDAP connect timeout setting.
   *
   * @return array<string, \Drupal\domain_availability\Dto\HttpResponse>
   *   Responses keyed exactly as $urls was.
   */
  public function getMultiple(
    array $urls,
    array $headers = [],
    ?int $timeoutMs = NULL,
    ?int $connectTimeoutMs = NULL,
  ): array {
    if ($urls === []) {
      return [];
    }

    $keys = array_keys($urls);
    $responses = [];
    $startedAt = microtime(TRUE);

    $options = [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::TIMEOUT => ($timeoutMs ?? $this->settings->rdapTimeoutMs()) / 1000,
      RequestOptions::CONNECT_TIMEOUT => ($connectTimeoutMs ?? $this->settings->rdapConnectTimeoutMs()) / 1000,
      RequestOptions::ALLOW_REDIRECTS => [
        'max' => 3,
        'strict' => TRUE,
        'referer' => FALSE,
        'protocols' => ['https'],
        'track_redirects' => FALSE,
      ],
      RequestOptions::VERIFY => TRUE,
      RequestOptions::DECODE_CONTENT => TRUE,
      RequestOptions::SYNCHRONOUS => FALSE,
    ];

    // A generator, not an array: Pool only materialises a request when a
    // concurrency slot frees up, so `parallel_requests` genuinely caps what is
    // in flight rather than merely how fast we hand promises over.
    $requests = static function () use ($urls, $headers): iterable {
      foreach ($urls as $url) {
        yield new Request('GET', $url, $headers);
      }
    };

    $pool = new Pool($this->httpClient, $requests(), [
      'concurrency' => $this->settings->parallelRequests(),
      'options' => $options,
      'fulfilled' => function (ResponseInterface $response, int $index) use (&$responses, $keys, $startedAt): void {
        $responses[$keys[$index]] = new HttpResponse(
          $response->getStatusCode(),
          (string) $response->getBody(),
          NULL,
          microtime(TRUE) - $startedAt,
        );
      },
      'rejected' => function (mixed $reason, int $index) use (&$responses, $keys, $startedAt): void {
        $responses[$keys[$index]] = HttpResponse::transportError(
          $this->describe($reason),
          microtime(TRUE) - $startedAt,
        );
      },
    ]);

    $pool->promise()->wait();

    // Restore the caller's key order, and account for any request the pool
    // never reported on so callers always get one response per URL.
    $ordered = [];

    foreach ($keys as $key) {
      $ordered[$key] = $responses[$key] ?? HttpResponse::transportError('no_response');
    }

    return $ordered;
  }

  /**
   * Executes a single GET request.
   *
   * Shares the batch configuration so timeout and TLS behaviour stay identical
   * across call sites.
   *
   * @param string $url
   *   The URL.
   * @param array<string, string> $headers
   *   The headers to send.
   * @param int|null $timeoutMs
   *   Total budget in milliseconds.
   * @param int|null $connectTimeoutMs
   *   Connect budget in milliseconds.
   *
   * @return \Drupal\domain_availability\Dto\HttpResponse
   *   The response.
   */
  public function get(
    string $url,
    array $headers = [],
    ?int $timeoutMs = NULL,
    ?int $connectTimeoutMs = NULL,
  ): HttpResponse {
    return $this->getMultiple(['default' => $url], $headers, $timeoutMs, $connectTimeoutMs)['default']
      ?? HttpResponse::transportError('no_response');
  }

  /**
   * Turns a rejection reason into a short, log-safe error string.
   *
   * A rejected promise may carry a response, a transport error, or anything a
   * middleware threw.
   *
   * @param mixed $reason
   *   The rejection reason.
   *
   * @return string
   *   A short, log-safe description.
   */
  private function describe(mixed $reason): string {
    if ($reason instanceof ConnectException) {
      return 'connect_error: ' . $reason->getMessage();
    }

    if ($reason instanceof RequestException || $reason instanceof TransferException) {
      return 'transfer_error: ' . $reason->getMessage();
    }

    if ($reason instanceof \Throwable) {
      return $reason::class . ': ' . $reason->getMessage();
    }

    return 'unknown_error';
  }

}
