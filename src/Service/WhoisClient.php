<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Drupal\domain_availability\Dto\WhoisResponse;

/**
 * Concurrent WHOIS (RFC 3912) client.
 *
 * WHOIS has no HTTP client to lean on, so this drives raw non-blocking sockets
 * directly: every connection is opened at once and driven with stream_select(),
 * which gives the same "batch costs the slowest query" property curl_multi
 * gives the RDAP path.
 * A hostname may resolve to several addresses, and the first one is not
 * necessarily alive — see HostResolver for the .sa case that proved it. Each
 * session therefore carries a candidate list and moves to the next address
 * when one refuses or stalls, instead of reporting the whole registry as
 * unreachable.
 *
 * @phpstan-type WhoisSession array{
 *   socket: resource,
 *   server: string,
 *   address: string,
 *   candidates: list<string>,
 *   attempt: int,
 *   payload: string,
 *   written: int,
 *   body: string,
 *   done: bool,
 *   connected: bool,
 *   started_at: float,
 *   error: string|null
 *   }
 *
 * @internal
 *   Implementation detail of the WHOIS path.
 */
final class WhoisClient {
  private const READ_CHUNK = 8192;
  private const MAX_RESPONSE_BYTES = 262144;
  private const SELECT_TIMEOUT_US = 50000;

  /**
   * Constructs a WhoisClient.
   *
   * @param \Drupal\domain_availability\Service\HostResolver $resolver
   *   The host resolver.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   * @param int $port
   *   The WHOIS port; 43 per RFC 3912.
   */
  public function __construct(
    private readonly HostResolver $resolver,
    private readonly ModuleSettings $settings,
    private readonly int $port = 43,
  ) {
  }

  /**
   * Runs a batch of WHOIS queries in parallel.
   *
   * @param array<string, array<string, string>> $queries
   *   The query specs, keyed by caller key; each has 'server' and 'query'.
   *
   * @return array<string, \Drupal\domain_availability\Dto\WhoisResponse>
   *   The responses, keyed the same as $queries.
   */
  public function queryMany(array $queries): array {
    if ($queries === []) {
      return [];
    }

    $sessions = [];
    $responses = [];
    $now = microtime(TRUE);

    foreach ($queries as $key => $query) {
      $candidates = $this->resolver->resolve($query['server']);

      if ($candidates === []) {
        $responses[$key] = WhoisResponse::transportError($query['server'], 'dns_resolution_failed');

        continue;
      }

      $socket = $this->openSocket($candidates[0]);

      if (is_string($socket)) {
        $responses[$key] = WhoisResponse::transportError($query['server'], $socket);

        continue;
      }

      $sessions[$key] = [
        'socket' => $socket,
        'server' => $query['server'],
        'address' => $candidates[0],
        'candidates' => $candidates,
        'attempt' => 0,
        'payload' => $query['query'],
        'written' => 0,
        'body' => '',
        'done' => FALSE,
        'connected' => FALSE,
        'started_at' => $now,
        'error' => NULL,
      ];
    }

    $deadline = microtime(TRUE) + ($this->settings->whoisTimeoutMs() / 1000);

    while ($this->pending($sessions) !== [] && microtime(TRUE) < $deadline) {
      $this->tick($sessions, $deadline);
      $this->advanceStalled($sessions);
    }

    foreach ($sessions as $key => $session) {
      $this->closeSocket($session['socket']);

      $responses[$key] = $session['body'] !== ''
                ? new WhoisResponse($session['server'], $session['body'])
                : WhoisResponse::transportError($session['server'], $session['error'] ?? 'timeout');
    }

    // Preserve the caller's key order for deterministic output.
    $ordered = [];

    foreach (array_keys($queries) as $key) {
      $ordered[$key] = $responses[$key]
                ?? WhoisResponse::transportError($queries[$key]['server'], 'no_response');
    }

    return $ordered;
  }

  /**
   * Opens a non-blocking connection to one address.
   *
   * The timeout argument is nearly moot here: with STREAM_CLIENT_ASYNC_CONNECT
   * the TCP handshake continues in the background, which is what makes the
   * batch concurrent. The handshake itself is bounded by advanceStalled().
   *
   * @param string $address
   *   The IP address.
   *
   * @return resource|string
   *   The socket, or an error string on failure.
   */
  private function openSocket(string $address) {
    $errorCode = 0;
    $errorMessage = '';

    $socket = @stream_socket_client(
          HostResolver::toSocketAddress($address, $this->port),
          $errorCode,
          $errorMessage,
          $this->settings->whoisConnectTimeoutMs() / 1000,
          STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
      );

    if ($socket === FALSE) {
      return $errorMessage !== '' ? $errorMessage : 'connect_failed_' . $errorCode;
    }

    stream_set_blocking($socket, FALSE);
    stream_set_timeout($socket, 0, self::SELECT_TIMEOUT_US);

    return $socket;
  }

  /**
   * Moves a stalled session onto the host's next address, failing it alone.
   *
   * This is what rescues a host whose first address is dead: SaudiNIC's AAAA
   * record accepts no connections, so the .sa session stalls here, retries on
   * the A record, and answers in milliseconds. Without the retry the registry
   * looks unreachable when it is merely half-broken.
   *
   * @param array<string, mixed> $sessions
   *   The in-flight sessions, keyed by caller key; mutated in place.
   */
  private function advanceStalled(array &$sessions): void {
    $limit = $this->settings->whoisConnectTimeoutMs() / 1000;
    $now = microtime(TRUE);

    foreach ($sessions as $key => $session) {
      if ($session['done'] || $session['connected']) {
        continue;
      }

      if ($now - $session['started_at'] < $limit) {
        continue;
      }

      $next = $session['candidates'][$session['attempt'] + 1] ?? NULL;

      if ($next === NULL) {
        $sessions[$key]['done'] = TRUE;
        $sessions[$key]['error'] = 'connect_timeout';

        continue;
      }

      $this->closeSocket($session['socket']);
      $socket = $this->openSocket($next);

      if (is_string($socket)) {
        $sessions[$key]['done'] = TRUE;
        $sessions[$key]['error'] = $socket;

        continue;
      }

      $sessions[$key]['socket'] = $socket;
      $sessions[$key]['address'] = $next;
      $sessions[$key]['attempt']++;
      $sessions[$key]['started_at'] = $now;
    }
  }

  /**
   * Advances every unfinished session by one select() round.
   *
   * @param array<string, mixed> $sessions
   *   The in-flight sessions, keyed by caller key; mutated in place.
   * @param float $deadline
   *   The absolute deadline, as a UNIX timestamp with microseconds.
   */
  private function tick(array &$sessions, float $deadline): void {
    $read = [];
    $write = [];

    foreach ($this->pending($sessions) as $key => $session) {
      if ($session['written'] < strlen($session['payload'])) {
        $write[$key] = $session['socket'];
      }
      else {
        $read[$key] = $session['socket'];
      }
    }

    if ($read === [] && $write === []) {
      return;
    }

    $except = NULL;
    $remaining = max(0.0, $deadline - microtime(TRUE));
    $timeoutUs = (int) min(self::SELECT_TIMEOUT_US, $remaining * 1000000);

    $readable = $read;
    $writable = $write;

    $ready = @stream_select($readable, $writable, $except, 0, $timeoutUs);

    if ($ready === FALSE || $ready === 0) {
      return;
    }

    foreach ($writable as $socket) {
      $key = $this->keyFor($write, $socket);

      if ($key !== NULL) {
        // Writability is the completion signal for an async connect.
        $sessions[$key]['connected'] = TRUE;
        $this->send($sessions[$key]);
      }
    }

    foreach ($readable as $socket) {
      $key = $this->keyFor($read, $socket);

      if ($key !== NULL) {
        $this->receive($sessions[$key]);
      }
    }
  }

  /**
   * Writes as much of the query as the socket accepts.
   *
   * Partial writes resume on the next round.
   *
   * @param array<string, mixed> $session
   *   The session, mutated in place.
   */
  private function send(array &$session): void {
    $remaining = substr($session['payload'], $session['written']);
    $written = @fwrite($session['socket'], $remaining);

    if ($written === FALSE) {
      $session['done'] = TRUE;
      $session['error'] = 'write_failed';

      return;
    }

    $session['written'] += $written;
  }

  /**
   * Reads available response bytes into the session buffer.
   *
   * @param array<string, mixed> $session
   *   The session, mutated in place.
   */
  private function receive(array &$session): void {
    $chunk = @fread($session['socket'], self::READ_CHUNK);

    if ($chunk === FALSE || $chunk === '') {
      if (feof($session['socket'])) {
        $session['done'] = TRUE;
      }

      return;
    }

    $session['body'] .= $chunk;

    if (strlen($session['body']) >= self::MAX_RESPONSE_BYTES || feof($session['socket'])) {
      $session['body'] = substr($session['body'], 0, self::MAX_RESPONSE_BYTES);
      $session['done'] = TRUE;
    }
  }

  /**
   * Filters the sessions down to those still in flight.
   *
   * @param array<string, mixed> $sessions
   *   The sessions, keyed by caller key.
   *
   * @return array<string, mixed>
   *   The sessions that are not yet done.
   */
  private function pending(array $sessions): array {
    return array_filter($sessions, static fn (array $session): bool => !$session['done']);
  }

  /**
   * Resolves the session key for a socket returned by stream_select().
   *
   * @param array<string, resource> $sockets
   *   The sockets, keyed by session key.
   * @param resource $needle
   *   The socket to find.
   *
   * @return string|null
   *   The matching session key, or NULL when not found.
   */
  private function keyFor(array $sockets, $needle): ?string {
    foreach ($sockets as $key => $socket) {
      if ($socket === $needle) {
        return (string) $key;
      }
    }

    return NULL;
  }

  /**
   * Closes a socket if it is still an open resource.
   *
   * @param resource $socket
   *   The socket to close.
   */
  private function closeSocket($socket): void {
    if (is_resource($socket)) {
      @fclose($socket);
    }
  }

}
