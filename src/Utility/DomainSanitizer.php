<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Utility;

/**
 * Reduces whatever the user typed to a bare domain label.
 *
 * `https://www.example.com/path?x=1` and `example.com` and `EXAMPLE` all
 * collapse to `example`, which is the only thing the checker needs.
 *
 * @internal
 *   Input handling for this module's own entry points.
 */
final class DomainSanitizer {

  /**
   * The longest input accepted before truncation, per RFC 1035.
   */
  private const MAX_INPUT_LENGTH = 253;

  /**
   * Not instantiable — a bag of static helpers.
   */
  private function __construct() {}

  /**
   * Extracts the registrable label from arbitrary user input.
   *
   * @param string $input
   *   The raw user input.
   *
   * @return string
   *   The bare label, or an empty string when nothing usable remains.
   */
  public static function sanitize(string $input): string {
    $value = self::normalise($input);

    if ($value === '') {
      return '';
    }

    $value = self::stripScheme($value);
    $value = self::stripCredentials($value);
    $value = self::stripPathAndQuery($value);
    $value = self::stripPort($value);
    $value = self::stripLeadingWww($value);

    return self::firstLabel($value);
  }

  /**
   * Lower-cases, trims whitespace and dots, and drops control characters.
   *
   * Unicode input is converted to its ASCII (punycode) form when the intl
   * extension is available, so IDN searches still resolve to a valid label.
   *
   * @param string $input
   *   The raw user input.
   *
   * @return string
   *   The normalised value.
   */
  private static function normalise(string $input): string {
    $value = trim($input);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = mb_substr($value, 0, self::MAX_INPUT_LENGTH);
    $value = mb_strtolower($value, 'UTF-8');

    if ($value !== '' && !preg_match('/^[\x20-\x7E]*$/', $value) && function_exists('idn_to_ascii')) {
      $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

      if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
      }
    }

    return trim($value, ". \t\n\r\0\x0B");
  }

  /**
   * Strips a URL scheme.
   *
   * @param string $value
   *   The value to strip.
   *
   * @return string
   *   The value without a leading scheme.
   */
  private static function stripScheme(string $value): string {
    return preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $value) ?? $value;
  }

  /**
   * Drops `user:pass@` so credentials never reach the lookup or the logs.
   *
   * @param string $value
   *   The value to strip.
   *
   * @return string
   *   The value without credentials.
   */
  private static function stripCredentials(string $value): string {
    $position = strrpos($value, '@');

    return $position === FALSE ? $value : substr($value, $position + 1);
  }

  /**
   * Strips a path, query string or fragment.
   *
   * @param string $value
   *   The value to strip.
   *
   * @return string
   *   The value up to the first path, query or fragment separator.
   */
  private static function stripPathAndQuery(string $value): string {
    return (string) preg_split('#[/?\#\s]#', $value, 2)[0];
  }

  /**
   * Strips a trailing port.
   *
   * @param string $value
   *   The value to strip.
   *
   * @return string
   *   The value without a trailing `:port`.
   */
  private static function stripPort(string $value): string {
    return preg_replace('/:\d+$/', '', $value) ?? $value;
  }

  /**
   * Strips a leading `www.`.
   *
   * @param string $value
   *   The value to strip.
   *
   * @return string
   *   The value without a leading `www.`, unless that is all there is.
   */
  private static function stripLeadingWww(string $value): string {
    // Only strip `www.` when something follows it, so a search for the literal
    // label "www" still works.
    if (str_starts_with($value, 'www.') && strlen($value) > 4) {
      return substr($value, 4);
    }

    return $value;
  }

  /**
   * Takes the first dot-separated label.
   *
   * @param string $value
   *   The value to reduce.
   *
   * @return string
   *   The first label.
   */
  private static function firstLabel(string $value): string {
    $label = explode('.', $value)[0];

    return trim($label);
  }

}
