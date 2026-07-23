<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Utility;

/**
 * TLD string helpers.
 *
 * Configuration accepts `.com` or `com`; internals need a predictable form on
 * both sides of that boundary.
 *
 * @api
 *   Public and stable since 1.0.0. Pure, static TLD normalisation. Provider
 *   authors implementing supports() will want it.
 */
final class Tld {

  /**
   * Not instantiable — a bag of static helpers.
   */
  private function __construct() {}

  /**
   * Normalises a TLD to its dot-less, lower-case form: `.COM` => `com`.
   *
   * @param string $tld
   *   The TLD, with or without a leading dot.
   *
   * @return string
   *   The normalised TLD.
   */
  public static function normalise(string $tld): string {
    return strtolower(trim($tld, ". \t\n\r\0\x0B"));
  }

  /**
   * Adds a leading dot: `com` => `.com`.
   *
   * @param string $tld
   *   The TLD, with or without a leading dot.
   *
   * @return string
   *   The TLD with a leading dot.
   */
  public static function withDot(string $tld): string {
    return '.' . self::normalise($tld);
  }

  /**
   * Normalises a configured list, dropping empties and duplicates.
   *
   * The configured order is preserved.
   *
   * @param array<int, string> $tlds
   *   The configured TLDs.
   *
   * @return array<int, string>
   *   Normalised, dot-less, de-duplicated TLDs.
   */
  public static function normaliseList(array $tlds): array {
    $normalised = [];

    foreach ($tlds as $tld) {
      $value = self::normalise($tld);

      if ($value !== '' && !in_array($value, $normalised, TRUE)) {
        $normalised[] = $value;
      }
    }

    return $normalised;
  }

  /**
   * Extracts the TLD from a fully qualified domain: `neixora.com` => `com`.
   *
   * @param string $domain
   *   The fully qualified domain name.
   *
   * @return string
   *   The normalised TLD, or an empty string when there is no dot.
   */
  public static function fromDomain(string $domain): string {
    $position = strpos($domain, '.');

    return $position === FALSE ? '' : self::normalise(substr($domain, $position + 1));
  }

}
