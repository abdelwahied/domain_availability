<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\domain_availability\Exception\DomainAvailabilityException;
use Drupal\domain_availability\Exception\RateLimitException;
use Drupal\domain_availability\Service\DomainCheckService;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Service\RateLimiter;
use Drupal\domain_availability\Service\StatusReportService;
use Drupal\domain_availability\Utility\DomainSanitizer;
use Drupal\domain_availability\Validator\DomainValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for domain availability lookups.
 *
 * Deliberately thin: sanitize, validate, delegate, serialise. Every decision
 * that matters — which provider owns a TLD, what counts as available, what to
 * cache — lives in the services, so the same logic serves the API, the form,
 * the block and any other module that injects domain_availability.checker.
 *
 * The response shape is byte-for-byte the standalone application's contract.
 * Existing clients keep working; that is a hard requirement, not a nicety.
 *
 * @internal
 *   A controller; the route is the contract.
 */
final class DomainApiController extends ControllerBase {

  /**
   * Constructs a DomainApiController.
   *
   * @param \Drupal\domain_availability\Service\DomainCheckService $checker
   *   The domain check service.
   * @param \Drupal\domain_availability\Validator\DomainValidator $validator
   *   The domain validator.
   * @param \Drupal\domain_availability\Service\RateLimiter $rateLimiter
   *   The rate limiter.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   * @param \Drupal\domain_availability\Service\StatusReportService $statusReport
   *   The status report service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    private readonly DomainCheckService $checker,
    private readonly DomainValidator $validator,
    private readonly RateLimiter $rateLimiter,
    private readonly ModuleSettings $settings,
    private readonly StatusReportService $statusReport,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('domain_availability.checker'),
      $container->get('domain_availability.validator'),
      $container->get('domain_availability.rate_limiter'),
      $container->get('domain_availability.settings'),
      $container->get('domain_availability.status_report'),
      $container->get('logger.channel.domain_availability'),
    );
  }

  /**
   * Handles GET /domain-check?domain=neixora.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response, in the standalone application's contract shape.
   */
  public function check(Request $request): JsonResponse {
    try {
      $state = $this->enforceRateLimit($request);

      $label = $this->validator->validate(
        DomainSanitizer::sanitize((string) $request->query->get('domain', '')),
      );

      $report = $this->checker->check($label);

      return $this->json($report->toArray(), 200, [
        'X-RateLimit-Limit' => (string) $state['limit'],
        'X-RateLimit-Remaining' => (string) $state['remaining'],
        'X-RateLimit-Reset' => (string) $state['reset'],
      ]);
    }
    catch (DomainAvailabilityException $exception) {
      return $this->error($exception, $request);
    }
    catch (\Throwable $exception) {
      return $this->unexpected($exception, $request);
    }
  }

  /**
   * Handles GET /domain-check/health.
   *
   * Liveness for a load balancer, plus the WHOIS egress diagnostics. The same
   * data appears on Drupal's status report; this endpoint exists because a
   * load balancer cannot read /admin/reports/status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The health payload, 200 when healthy and 503 when degraded.
   */
  public function health(): JsonResponse {
    $report = $this->statusReport->build();

    return $this->json($report, $report['success'] === TRUE ? 200 : 503);
  }

  /**
   * Applies the per-IP quota.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array{allowed: bool, remaining: int, retry_after: int, limit: int, reset: int}
   *   The quota state for the allowed request.
   *
   * @throws \Drupal\domain_availability\Exception\RateLimitException
   *   When the client is over quota.
   */
  private function enforceRateLimit(Request $request): array {
    $state = $this->rateLimiter->hit((string) $request->getClientIp());

    if (!$state['allowed']) {
      $this->logger->info('Request rate limited for @ip.', ['@ip' => $request->getClientIp()]);

      throw new RateLimitException($state['retry_after']);
    }

    return $state;
  }

  /**
   * Renders a known exception.
   *
   * Clients get the error code and the public message only; class names, file
   * paths and registry internals stay in the log.
   *
   * @param \Drupal\domain_availability\Exception\DomainAvailabilityException $exception
   *   The exception to render.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON error response.
   */
  private function error(DomainAvailabilityException $exception, Request $request): JsonResponse {
    $status = $exception->statusCode();

    if ($status >= 500) {
      $this->logger->error('@code: @message', [
        '@code' => $exception->errorCode(),
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);
    }

    $payload = [
      'success' => FALSE,
      'error' => $exception->errorCode(),
      'message' => $exception->publicMessage(),
    ] + $exception->context();

    $headers = [];

    if ($exception instanceof RateLimitException) {
      $headers['Retry-After'] = (string) $exception->retryAfter();
    }

    return $this->json($payload, $status, $headers);
  }

  /**
   * Renders an unexpected throwable.
   *
   * @param \Throwable $exception
   *   The unexpected throwable.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The generic 500 response, with internals attached only in debug mode.
   */
  private function unexpected(\Throwable $exception, Request $request): JsonResponse {
    $this->logger->error('Unhandled error on @path: @message', [
      '@path' => $request->getPathInfo(),
      '@message' => $exception->getMessage(),
      'exception' => $exception,
    ]);

    $payload = [
      'success' => FALSE,
      'error' => 'internal_error',
      'message' => 'An unexpected error occurred. Please try again later.',
    ];

    // Debug mode is the only path that ever exposes internals, which is why the
    // settings form warns about it and it defaults to off.
    if ($this->settings->debug()) {
      $payload['debug'] = [
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile() . ':' . $exception->getLine(),
      ];
    }

    return $this->json($payload, 500);
  }

  /**
   * Builds a JSON response with the module's standard headers.
   *
   * @param array<string, mixed> $payload
   *   The payload.
   * @param int $status
   *   The HTTP status.
   * @param array<string, string> $headers
   *   Extra headers to send.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response, marked never to be browser-cached.
   */
  private function json(array $payload, int $status, array $headers = []): JsonResponse {
    $response = new JsonResponse($payload, $status, $headers);
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Lookups are cached server-side by the module, deliberately not by the
    // browser: a stale "available" is the one answer that must never be reused.
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

    return $response;
  }

}
