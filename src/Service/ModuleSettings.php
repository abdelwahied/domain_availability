<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\domain_availability\Utility\Tld;

/**
 * Typed, read-only access to domain_availability.settings.
 *
 * Config values arrive from YAML as mixed, and every consumer would otherwise
 * repeat the same cast-and-default dance — which is exactly where a silent
 * NULL turns into a 0 second timeout. This is the single place that knows the
 * shape of the config, so PHPStan can see real types everywhere else.
 * It reads through ConfigFactory on every call rather than caching values in a
 * property: config can change mid-request (a settings form save is a request
 * like any other), and a stale timeout is a bug that only shows up in
 * production.
 *
 * @api
 *   Public and stable since 1.0.0. Typed, read-only access to
 *   `domain_availability.settings`. The config keys it reads are part of the
 *   contract too.
 */
final class ModuleSettings {

  public const CONFIG_NAME = 'domain_availability.settings';

  /**
   * Constructs a ModuleSettings.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * The immutable settings object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The module's immutable configuration.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

  /**
   * The configured TLDs, normalised and de-duplicated.
   *
   * @return array<int, string>
   *   Dot-less TLDs, in configured order.
   */
  public function tlds(): array {
    return Tld::normaliseList($this->list('tlds'));
  }

  /**
   * Whether lookup results are cached.
   *
   * @return bool
   *   TRUE when lookup results are cached.
   */
  public function cacheEnabled(): bool {
    return $this->bool('cache_enabled', TRUE);
  }

  /**
   * Lifetime of a cached result, in seconds.
   *
   * @return int
   *   The cache lifetime in seconds.
   */
  public function cacheTtl(): int {
    return $this->int('cache_ttl', 600);
  }

  /**
   * Whether the RDAP provider may claim TLDs.
   *
   * @return bool
   *   TRUE when the RDAP provider may claim TLDs.
   */
  public function rdapEnabled(): bool {
    return $this->bool('rdap_enabled', TRUE);
  }

  /**
   * Whether the WHOIS provider may claim TLDs.
   *
   * @return bool
   *   TRUE when the WHOIS provider may claim TLDs.
   */
  public function whoisEnabled(): bool {
    return $this->bool('whois_enabled', TRUE);
  }

  /**
   * Whether the DNS delegation fallback may answer.
   *
   * @return bool
   *   TRUE when the DNS delegation fallback may answer.
   */
  public function dnsFallbackEnabled(): bool {
    return $this->bool('dns_fallback_enabled', TRUE);
  }

  /**
   * The total budget for one check, in seconds.
   *
   * @return int
   *   The total budget for one check, in seconds.
   */
  public function maxLookupTime(): int {
    return $this->int('max_lookup_time', 15);
  }

  /**
   * How many requests may be in flight at once within a batch.
   *
   * @return int
   *   The number of concurrent requests allowed within a batch.
   */
  public function parallelRequests(): int {
    return $this->int('parallel_requests', 20);
  }

  /**
   * RDAP total timeout, in milliseconds.
   *
   * @return int
   *   The RDAP total timeout, in milliseconds.
   */
  public function rdapTimeoutMs(): int {
    return $this->int('rdap_timeout_ms', 3000);
  }

  /**
   * RDAP connect timeout, in milliseconds.
   *
   * @return int
   *   The RDAP connect timeout, in milliseconds.
   */
  public function rdapConnectTimeoutMs(): int {
    return $this->int('rdap_connect_timeout_ms', 2000);
  }

  /**
   * WHOIS total timeout, in milliseconds.
   *
   * @return int
   *   The WHOIS total timeout, in milliseconds.
   */
  public function whoisTimeoutMs(): int {
    return $this->int('whois_timeout_ms', 2500);
  }

  /**
   * WHOIS per-address connect timeout, in milliseconds.
   *
   * @return int
   *   The WHOIS per-address connect timeout, in milliseconds.
   */
  public function whoisConnectTimeoutMs(): int {
    return $this->int('whois_connect_timeout_ms', 1500);
  }

  /**
   * Which address family WHOIS tries first: ipv4, ipv6 or system.
   *
   * @return string
   *   The preferred address family: ipv4, ipv6 or system.
   */
  public function whoisAddressFamily(): string {
    $value = $this->string('whois_address_family', HostResolver::PREFER_IPV4);

    return in_array($value, [
      HostResolver::PREFER_IPV4,
      HostResolver::PREFER_IPV6,
      HostResolver::PREFER_SYSTEM,
    ], TRUE) ? $value : HostResolver::PREFER_IPV4;
  }

  /**
   * How long resolved WHOIS host addresses stay cached, in seconds.
   *
   * @return int
   *   The WHOIS host address cache lifetime, in seconds.
   */
  public function whoisDnsTtl(): int {
    return $this->int('whois_dns_ttl', 300);
  }

  /**
   * Whether per-IP rate limiting is enforced.
   *
   * @return bool
   *   TRUE when per-IP rate limiting is enforced.
   */
  public function rateLimitEnabled(): bool {
    return $this->bool('rate_limit_enabled', TRUE);
  }

  /**
   * Requests allowed per window, per IP.
   *
   * @return int
   *   The number of requests allowed per window, per IP.
   */
  public function rateLimitMaxRequests(): int {
    return $this->int('rate_limit_max_requests', 30);
  }

  /**
   * The rate limit window, in seconds.
   *
   * @return int
   *   The rate limit window, in seconds.
   */
  public function rateLimitWindow(): int {
    return $this->int('rate_limit_window', 60);
  }

  /**
   * Minimum seconds between two requests from the same IP.
   *
   * @return int
   *   The minimum seconds between two requests from the same IP.
   */
  public function rateLimitMinInterval(): int {
    // Unlike every other integer here, 0 is a real choice: it means "no
    // throttle, only the window quota". So this one reader accepts it.
    return $this->intAllowingZero('rate_limit_min_interval', 1);
  }

  /**
   * Whether the module writes to its logger channel at all.
   *
   * @return bool
   *   TRUE when the module writes to its logger channel.
   */
  public function loggingEnabled(): bool {
    return $this->bool('logging_enabled', TRUE);
  }

  /**
   * The minimum severity written to the log, as an RFC 5424 level name.
   *
   * @return string
   *   The minimum severity as an RFC 5424 level name.
   */
  public function logLevel(): string {
    return $this->string('log_level', 'warning');
  }

  /**
   * Whether debug mode is on.
   *
   * Debug mode attaches exception details to 500 responses, so it must never
   * be enabled on a public site.
   *
   * @return bool
   *   TRUE when debug mode is on.
   */
  public function debug(): bool {
    return $this->bool('debug', FALSE);
  }

  /**
   * Allowed CORS origins.
   *
   * Exact origins, or ['*'] for any. An empty array disables CORS headers.
   *
   * @return array<int, string>
   *   The allowed CORS origins.
   */
  public function corsAllowedOrigins(): array {
    $raw = trim($this->string('cors_allowed_origins', ''));

    if ($raw === '') {
      return [];
    }

    $origins = array_values(array_filter(array_map(
      static fn (string $origin): string => rtrim(trim($origin), '/'),
      explode(',', $raw),
    )));

    return $origins;
  }

  /**
   * Whether the authoritative (licensed API) provider is enabled.
   *
   * @return bool
   *   TRUE when the authoritative provider is enabled.
   */
  public function saudinicEnabled(): bool {
    return $this->bool('saudinic_enabled', FALSE);
  }

  /**
   * The authoritative provider endpoint.
   *
   * @return string
   *   The authoritative provider endpoint.
   */
  public function saudinicEndpoint(): string {
    return trim($this->string('saudinic_endpoint', ''));
  }

  /**
   * The authoritative provider API key.
   *
   * @return string
   *   The authoritative provider API key.
   */
  public function saudinicApiKey(): string {
    return trim($this->string('saudinic_api_key', ''));
  }

  /**
   * Whether an authoritative provider API key is stored.
   *
   * Lets the settings form report that a key exists without ever putting the
   * key itself back into an HTML response.
   *
   * @return bool
   *   TRUE when a key is stored.
   */
  public function hasSaudinicApiKey(): bool {
    return $this->saudinicApiKey() !== '';
  }

  /**
   * TLDs the authoritative provider answers for.
   *
   * @return array<int, string>
   *   Normalised, dot-less TLDs.
   */
  public function saudinicTlds(): array {
    return Tld::normaliseList($this->list('saudinic_tlds'));
  }

  /**
   * TLDs whose WHOIS egress is probed on the status report.
   *
   * @return array<int, string>
   *   Normalised, dot-less TLDs.
   */
  public function healthProbeTlds(): array {
    return Tld::normaliseList($this->list('health_probe_tlds'));
  }

  /**
   * Reads a boolean.
   *
   * @param string $key
   *   The config key.
   * @param bool $default
   *   Returned when the key is missing.
   *
   * @return bool
   *   The boolean value.
   */
  private function bool(string $key, bool $default): bool {
    $value = $this->config()->get($key);

    return is_bool($value) ? $value : $default;
  }

  /**
   * Reads an integer.
   *
   * A non-positive value falls back to the default rather than being honoured.
   * Config is typed, so a junk value is cast to 0 on save rather than rejected
   * — and a 0 ms timeout or a 0 second TTL is not a configuration choice, it is
   * a silently broken lookup. Every integer this module reads is a duration, a
   * count or a limit, and none of them are meaningful at zero.
   *
   * @param string $key
   *   The config key.
   * @param int $default
   *   Returned when the key is missing.
   *
   * @return int
   *   The integer value.
   */
  private function int(string $key, int $default): int {
    $value = $this->config()->get($key);

    if (!is_numeric($value)) {
      return $default;
    }

    $value = (int) $value;

    return $value > 0 ? $value : $default;
  }

  /**
   * Reads a non-negative integer, where zero is a meaningful value.
   *
   * @param string $key
   *   The config key.
   * @param int $default
   *   Returned when the key is missing.
   *
   * @return int
   *   The non-negative integer value.
   */
  private function intAllowingZero(string $key, int $default): int {
    $value = $this->config()->get($key);

    if (!is_numeric($value)) {
      return $default;
    }

    return max(0, (int) $value);
  }

  /**
   * Reads a string.
   *
   * @param string $key
   *   The config key.
   * @param string $default
   *   Returned when the key is missing.
   *
   * @return string
   *   The string value.
   */
  private function string(string $key, string $default): string {
    $value = $this->config()->get($key);

    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * Reads a list of strings.
   *
   * @param string $key
   *   The config key.
   *
   * @return array<int, string>
   *   The list of non-empty string values.
   */
  private function list(string $key): array {
    $value = $this->config()->get($key);

    if (!is_array($value)) {
      return [];
    }

    return array_values(array_filter(
      array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value),
      static fn (string $item): bool => $item !== '',
    ));
  }

}
