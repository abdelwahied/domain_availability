<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The module's help page, as Drupal 11.1 and later want it declared.
 *
 * The module still ships the procedural hook_help() in its .module file,
 * because it supports Drupal 10.3 where this class is never discovered — core's
 * own Hook attribute there says it "will not have an effect until Drupal
 * 11.1.0". That function carries #[LegacyHook] and delegates straight back
 * here, so there is one implementation and the text can never drift between the
 * two Drupal versions.
 *
 * @internal
 *   A hook implementation.
 */
final class DomainAvailabilityHelp {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   *
   * @param string $route_name
   *   The route the help was requested for.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return string
   *   The help markup, or an empty string for a route this module has nothing
   *   to say about.
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    switch ($route_name) {
      case 'help.page.domain_availability':
        $output = '<h2>' . $this->t('About') . '</h2>';
        $output .= '<p>' . $this->t('Checks a domain name across every configured TLD in one parallel sweep, using RDAP where the registry supports it and WHOIS everywhere else.') . '</p>';
        $output .= '<h3>' . $this->t('Reading the results') . '</h3>';
        $output .= '<p>' . $this->t('A result is <em>available</em>, <em>registered</em>, or <em>unknown</em>. Unknown means no provider could answer — a throttled registry, an unreachable host, an unparseable reply. It is never a hint that the domain might be free: availability is never guessed, because "available" is the answer a visitor acts on.') . '</p>';
        $output .= '<h3>' . $this->t('Uses') . '</h3>';
        $output .= '<dl>';
        $output .= '<dt>' . $this->t('Search page') . '</dt>';
        $output .= '<dd>' . $this->t('Visit <code>/domain-search</code>.') . '</dd>';
        $output .= '<dt>' . $this->t('Block') . '</dt>';
        $output .= '<dd>' . $this->t('Place "Domain availability search" from Block Layout.') . '</dd>';
        $output .= '<dt>' . $this->t('Template') . '</dt>';
        $output .= '<dd>' . $this->t('Call <code>{{ domain_availability_search() }}</code> in any Twig template.') . '</dd>';
        $output .= '<dt>' . $this->t('API') . '</dt>';
        $output .= '<dd>' . $this->t('<code>GET /domain-check?domain=neixora</code> returns JSON.') . '</dd>';
        $output .= '</dl>';

        return $output;

      default:
        return '';
    }
  }

}
