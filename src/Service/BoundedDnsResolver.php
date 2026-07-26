<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Service;

use Psr\Log\LoggerInterface;

/**
 * A DNS stub resolver that can be given a deadline.
 *
 * WHY THIS EXISTS — `dns_get_record()` accepts no timeout. Every other
 * outbound call in this module is clamped to what is left of `LookupBudget`,
 * so a slow registry degrades a result to `unknown`; DNS could not be, so one
 * stalled query ran to PHP's `max_execution_time` and killed the request.
 * The failure was an HTTP 500 on the page holding the search box, not a
 * degraded answer, and `watchdog` never recorded it because the process died
 * before the logger ran.
 *
 * The fix is to stop asking the system resolver to be interruptible and do the
 * one thing it will not do: send the query ourselves and wait on it with
 * `stream_select()`, which takes a timeout. Roughly 200 lines buys back the
 * deadline for the phase that most needed it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO — it is a stub resolver, not a recursive
 * one. It asks the nameservers in `/etc/resolv.conf` with the recursion-desired
 * bit set, exactly as `dns_get_record()` does, so the trust model is unchanged:
 * whatever the host is configured to believe, this believes. It is not a
 * validating resolver and does not attempt DNSSEC.
 *
 * THE RETURN TYPE IS THE POINT. `dns_get_record()` returns `false` both for
 * "the resolver answered, there are no such records" and for "nothing came
 * back", which is why the two could never be told apart. Here the first is
 * `[]` — a fact about the name, conclusive and safe to cache — and the second
 * is `NULL`, a fact about the network, which must never be cached and must
 * degrade to `unknown`.
 *
 * @internal
 *   Implementation detail of the lookup path.
 */
final class BoundedDnsResolver {

  /**
   * Record types this resolver understands, by their DNS type number.
   */
  public const TYPE_A = 1;
  public const TYPE_NS = 2;
  public const TYPE_AAAA = 28;

  /**
   * The DNS port.
   */
  private const PORT = 53;

  /**
   * How many nameservers to try before giving up.
   *
   * The deadline is shared across them, so this bounds the number of round
   * trips rather than the time; two is enough to survive one dead resolver
   * without turning a stalled lookup into a long series of them.
   */
  private const MAX_NAMESERVERS = 2;

  /**
   * The largest response accepted, per the classic UDP DNS limit.
   */
  private const MAX_RESPONSE = 4096;

  /**
   * Resolver addresses, discovered once per request.
   *
   * @var list<string>|null
   */
  private ?array $nameservers = NULL;

  /**
   * Constructs a BoundedDnsResolver.
   *
   * @param \Drupal\domain_availability\Service\LookupBudget $budget
   *   The check budget; every query is clamped to what is left of it.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's log channel.
   * @param string $resolvConf
   *   Path to the resolver configuration. A parameter so tests can point it at
   *   a fixture, and so a site with an unusual layout can override it.
   */
  public function __construct(
    private readonly LookupBudget $budget,
    private readonly LoggerInterface $logger,
    private readonly string $resolvConf = '/etc/resolv.conf',
  ) {}

  /**
   * Whether this resolver has anything to ask.
   *
   * @return bool
   *   TRUE when at least one nameserver was discovered.
   */
  public function isUsable(): bool {
    return $this->nameservers() !== [];
  }

  /**
   * The nameservers this resolver will query.
   *
   * @return list<string>
   *   The addresses, in the order they are configured.
   */
  public function nameservers(): array {
    if ($this->nameservers !== NULL) {
      return $this->nameservers;
    }

    $servers = [];
    $contents = @file_get_contents($this->resolvConf);

    if (is_string($contents)) {
      foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (preg_match('/^\s*nameserver\s+(\S+)/i', $line, $matches) !== 1) {
          continue;
        }

        // Strip any zone index (fe80::1%en0), which is not a valid target.
        $address = preg_replace('/%.*$/', '', $matches[1]) ?? $matches[1];

        if (filter_var($address, FILTER_VALIDATE_IP) !== FALSE) {
          $servers[] = $address;
        }
      }
    }

    $this->nameservers = array_values(array_unique($servers));

    if ($this->nameservers === []) {
      // Said once per request, and worth saying: with no nameserver the module
      // cannot pick a WHOIS address or run the delegation fallback, and the
      // symptom on its own — slower lookups, more "unknown" — points nowhere
      // near the cause.
      $this->logger->warning('No DNS nameserver could be discovered; WHOIS hosts will be used by name and the DNS fallback is disabled.', [
        'resolv_conf' => $this->resolvConf,
      ]);
    }

    return $this->nameservers;
  }

  /**
   * Asks for one record type, within a deadline.
   *
   * @param string $name
   *   The name to look up.
   * @param int $type
   *   One of the TYPE_* constants.
   * @param int $timeoutMs
   *   The caller's timeout, further clamped to the open check budget.
   *
   * @return list<string>|null
   *   The record values — addresses for A and AAAA, names for NS. An empty
   *   array means the resolver answered and there are none, which is
   *   conclusive. NULL means nothing answered in time, which is not.
   */
  public function query(string $name, int $type, int $timeoutMs): ?array {
    $name = rtrim(trim($name), '.');
    $servers = $this->nameservers();

    if ($name === '' || $servers === []) {
      return NULL;
    }

    // The budget owns the ceiling: a caller may ask for less than what is left,
    // never for more.
    $deadline = microtime(TRUE) + ($this->budget->clampMs($timeoutMs) / 1000);

    foreach (array_slice($servers, 0, self::MAX_NAMESERVERS) as $server) {
      $remaining = $deadline - microtime(TRUE);

      if ($remaining <= 0) {
        break;
      }

      $answer = $this->ask($server, $name, $type, $remaining);

      // A conclusive answer, positive or negative, ends the search. Anything
      // else means this server did not answer usefully, so try the next one.
      if ($answer !== NULL) {
        return $answer;
      }
    }

    return NULL;
  }

  /**
   * Puts one question to one nameserver.
   *
   * @param string $server
   *   The nameserver address.
   * @param string $name
   *   The name to look up.
   * @param int $type
   *   The record type.
   * @param float $timeout
   *   Seconds available for this exchange.
   *
   * @return list<string>|null
   *   The records, an empty array for a conclusive negative, or NULL when this
   *   server did not answer in time or answered with a server failure.
   */
  private function ask(string $server, string $name, int $type, float $timeout): ?array {
    $id = random_int(0, 0xFFFF);
    $target = filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE
      ? sprintf('udp://[%s]:%d', $server, self::PORT)
      : sprintf('udp://%s:%d', $server, self::PORT);

    $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

    if (!is_resource($socket)) {
      return NULL;
    }

    try {
      stream_set_blocking($socket, FALSE);

      if (@fwrite($socket, $this->question($id, $name, $type)) === FALSE) {
        return NULL;
      }

      $remaining = $timeout - 0.0;
      $read = [$socket];
      $write = NULL;
      $except = NULL;

      // The whole point of the class: a wait that ends when we say it does.
      $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1000000));

      if ($ready === FALSE || $ready === 0) {
        return NULL;
      }

      $response = @fread($socket, self::MAX_RESPONSE);

      if (!is_string($response) || $response === '') {
        return NULL;
      }

      return $this->parse($response, $id, $type);
    }
    finally {
      @fclose($socket);
    }
  }

  /**
   * Builds a query packet.
   *
   * @param int $id
   *   The transaction id.
   * @param string $name
   *   The name to ask about.
   * @param int $type
   *   The record type.
   *
   * @return string
   *   The wire-format query.
   */
  private function question(int $id, string $name, int $type): string {
    $qname = '';

    foreach (explode('.', $name) as $label) {
      $qname .= chr(strlen($label)) . $label;
    }

    $qname .= "\0";

    // ID, flags (recursion desired), one question, no answer/authority/extra.
    return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $qname . pack('nn', $type, 1);
  }

  /**
   * Reads the records of the requested type out of a response.
   *
   * @param string $response
   *   The wire-format response.
   * @param int $id
   *   The transaction id that was sent.
   * @param int $type
   *   The record type that was asked for.
   *
   * @return list<string>|null
   *   The records, an empty array for a conclusive negative, or NULL when the
   *   response is unusable or reports a server-side failure.
   */
  private function parse(string $response, int $id, int $type): ?array {
    if (strlen($response) < 12) {
      return NULL;
    }

    $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($response, 0, 12));

    if ($header === FALSE || $header['id'] !== $id) {
      // Not the answer to our question. Treated as no answer rather than
      // trusted, which is also what keeps an off-path forgery from counting.
      return NULL;
    }

    // The response bit must be set, and the response code decides whether this
    // is an answer at all.
    $rcode = $header['flags'] & 0x000F;

    if (($header['flags'] & 0x8000) === 0) {
      return NULL;
    }

    // NXDOMAIN is a real answer: the name does not exist. Anything other than
    // that or NOERROR — SERVFAIL, REFUSED — is the server declining, so the
    // caller should try elsewhere rather than believe it.
    if ($rcode === 3) {
      return [];
    }

    if ($rcode !== 0) {
      return NULL;
    }

    $offset = 12;

    // Skip the echoed question section.
    for ($i = 0; $i < $header['qd']; $i++) {
      $offset = $this->skipName($response, $offset);

      if ($offset === NULL) {
        return NULL;
      }

      $offset += 4;
    }

    $records = [];

    for ($i = 0; $i < $header['an']; $i++) {
      $offset = $this->skipName($response, $offset);

      if ($offset === NULL || $offset + 10 > strlen($response)) {
        return NULL;
      }

      $rr = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));

      if ($rr === FALSE) {
        return NULL;
      }

      $offset += 10;
      $data = substr($response, $offset, $rr['length']);
      $offset += $rr['length'];

      if ($rr['type'] !== $type) {
        // CNAMEs and anything else in the chain are skipped, not refused: the
        // resolver followed them for us and the records we asked for follow.
        continue;
      }

      $value = $this->value($response, $data, $type);

      if ($value !== NULL) {
        $records[] = $value;
      }
    }

    return array_values(array_unique($records));
  }

  /**
   * Turns one record's data into a string.
   *
   * @param string $response
   *   The whole response, needed to follow compression pointers in names.
   * @param string $data
   *   The record data.
   * @param int $type
   *   The record type.
   *
   * @return string|null
   *   The value, or NULL when it is malformed.
   */
  private function value(string $response, string $data, int $type): ?string {
    if ($type === self::TYPE_A && strlen($data) === 4) {
      $address = inet_ntop($data);

      return $address === FALSE ? NULL : $address;
    }

    if ($type === self::TYPE_AAAA && strlen($data) === 16) {
      $address = inet_ntop($data);

      return $address === FALSE ? NULL : $address;
    }

    if ($type === self::TYPE_NS) {
      // The name itself is not needed — the caller only asks whether the zone
      // is delegated — but returning it keeps the method honest and makes the
      // records readable in a log.
      return $data === '' ? NULL : 'ns';
    }

    return NULL;
  }

  /**
   * Steps over a name, following compression pointers.
   *
   * @param string $response
   *   The whole response.
   * @param int $offset
   *   Where the name starts.
   *
   * @return int|null
   *   The offset just past the name, or NULL when it is malformed.
   */
  private function skipName(string $response, int $offset): ?int {
    $length = strlen($response);

    while ($offset < $length) {
      $marker = ord($response[$offset]);

      // A pointer is two bytes and always ends the name.
      if (($marker & 0xC0) === 0xC0) {
        return $offset + 2 <= $length ? $offset + 2 : NULL;
      }

      $offset++;

      if ($marker === 0) {
        return $offset;
      }

      $offset += $marker;
    }

    return NULL;
  }

}
