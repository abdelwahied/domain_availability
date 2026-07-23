<?php

declare(strict_types=1);

namespace Drupal\domain_availability\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\domain_availability\Service\ModuleSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies CORS and hardening headers to this module's API responses.
 *
 * The standalone application ran these as middleware. In Drupal the kernel is
 * the middleware stack, so the same two jobs — answer preflight, decorate the
 * response — become a subscriber.
 *
 * It touches only this module's own routes. Core (and any reverse proxy) owns
 * the headers for the rest of the site, and a module that quietly rewrites
 * headers site-wide is a debugging nightmare nobody asked for.
 *
 * @internal
 *   Adds CORS headers to the endpoint's responses.
 */
final class ApiResponseSubscriber implements EventSubscriberInterface {

  /**
   * Routes this subscriber decorates.
   */
  private const API_ROUTES = [
    'domain_availability.api_check',
    'domain_availability.api_health',
  ];

  /**
   * Constructs an ApiResponseSubscriber.
   *
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    private readonly ModuleSettings $settings,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Before routing's own listeners would 405 an OPTIONS request.
      KernelEvents::REQUEST => ['onRequest', 40],
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  /**
   * Answers CORS preflight without letting it reach the controller.
   *
   * A preflight must never be charged against the client's rate limit, and it
   * carries no `domain` parameter to validate.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    if ($request->getMethod() !== 'OPTIONS' || !$this->isApiPath($request->getPathInfo())) {
      return;
    }

    if ($this->settings->corsAllowedOrigins() === []) {
      return;
    }

    $response = new Response('', 204);
    $this->applyCors($response, $request->headers->get('Origin'));
    $event->setResponse($response);
  }

  /**
   * Decorates the response of this module's API routes.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();

    if (!$this->isApiRoute() && !$this->isApiPath($request->getPathInfo())) {
      return;
    }

    $response = $event->getResponse();

    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

    if ($this->settings->corsAllowedOrigins() !== []) {
      $this->applyCors($response, $request->headers->get('Origin'));
    }
  }

  /**
   * Adds the CORS headers for an origin.
   *
   * The request Origin is echoed only when it is on the allow-list — never
   * reflected blindly, which would hand any site on the internet the right to
   * read these responses with the visitor's cookies attached.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to decorate.
   * @param string|null $origin
   *   The request Origin header.
   */
  private function applyCors(Response $response, ?string $origin): void {
    $allowed = $this->settings->corsAllowedOrigins();

    if (in_array('*', $allowed, TRUE)) {
      $response->headers->set('Access-Control-Allow-Origin', '*');
    }
    elseif ($origin !== NULL && in_array(rtrim($origin, '/'), $allowed, TRUE)) {
      $response->headers->set('Access-Control-Allow-Origin', $origin);
      // Without Vary, a shared cache can hand one origin's headers to another.
      $response->headers->set('Vary', 'Origin');
    }
    else {
      $response->headers->set('Vary', 'Origin');

      return;
    }

    $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With');
    $response->headers->set('Access-Control-Max-Age', '600');
  }

  /**
   * Whether the current route belongs to this module's API.
   */
  private function isApiRoute(): bool {
    return in_array($this->routeMatch->getRouteName(), self::API_ROUTES, TRUE);
  }

  /**
   * Whether a path belongs to this module's API.
   *
   * Used on preflight, where routing has not resolved a route name yet.
   *
   * @param string $path
   *   The request path.
   *
   * @return bool
   *   TRUE when the path is a domain-check endpoint.
   */
  private function isApiPath(string $path): bool {
    $path = '/' . trim($path, '/');

    return $path === '/domain-check' || str_starts_with($path, '/domain-check/');
  }

}
