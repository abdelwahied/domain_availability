<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\domain_availability\Cache\DomainCacheInterface;
use Drupal\domain_availability\Service\HostResolver;
use Drupal\domain_availability\Service\ModuleSettings;
use Drupal\domain_availability\Service\RateLimiter;
use Drupal\domain_availability\Utility\Tld;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative settings for Domain Availability.
 *
 * Everything here is plain configuration, so it exports with `drush cex` and
 * deploys like any other config — no database-only settings, no surprises
 * between environments.
 *
 * @internal
 *   A form; the route and the configuration are the contract.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs a SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\domain_availability\Cache\DomainCacheInterface $cache
   *   The lookup cache.
   * @param \Drupal\domain_availability\Service\RateLimiter $rateLimiter
   *   The rate limiter.
   * @param \Drupal\domain_availability\Service\ModuleSettings $settings
   *   The module settings.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected DomainCacheInterface $cache,
    protected RateLimiter $rateLimiter,
    protected ModuleSettings $settings,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('domain_availability.cache'),
      $container->get('domain_availability.rate_limiter'),
      $container->get('domain_availability.settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ModuleSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ModuleSettings::CONFIG_NAME);

    $form['tlds'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Enabled TLDs'),
      '#description' => $this->t('One per line, with or without the leading dot. Order is preserved in the results. Unlisted TLDs are discovered through IANA automatically.'),
      '#default_value' => implode("\n", array_map(
        static fn (string $tld): string => Tld::withDot($tld),
        $this->settings->tlds(),
      )),
      '#rows' => 8,
      '#required' => TRUE,
    ];

    $form['providers'] = [
      '#type' => 'details',
      '#title' => $this->t('Lookup providers'),
      '#open' => TRUE,
      '#description' => $this->t('Providers are tried in priority order, and any that answers "unknown" hands the domain to the next one. Turning them all off leaves every lookup unknown — which is honest, but useless.'),
    ];
    $form['providers']['rdap_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable RDAP (recommended)'),
      '#description' => $this->t('RDAP answers with machine-readable JSON, so classification is exact. Preferred wherever the registry supports it.'),
      '#default_value' => $config->get('rdap_enabled'),
    ];
    $form['providers']['whois_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WHOIS (recommended)'),
      '#description' => $this->t('Required for ccTLDs with no RDAP service, such as .sa, .io, .ai, .co and .me. Needs outbound TCP port 43.'),
      '#default_value' => $config->get('whois_enabled'),
    ];
    $form['providers']['dns_fallback_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the DNS delegation fallback'),
      '#description' => $this->t('Last resort. It can only ever prove that a domain is <em>registered</em>: an undelegated domain may still be taken, so absence of DNS proves nothing and stays "unknown".'),
      '#default_value' => $config->get('dns_fallback_enabled'),
    ];

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance'),
      '#open' => TRUE,
    ];
    $form['performance']['cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache lookup results'),
      '#description' => $this->t('Strongly recommended. Registries throttle heavy callers, and the cache is what keeps repeat searches instant.'),
      '#default_value' => $config->get('cache_enabled'),
    ];
    $form['performance']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache duration'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 30,
      '#max' => 86400,
      '#default_value' => $config->get('cache_ttl'),
      '#states' => [
        'visible' => [':input[name="cache_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['performance']['parallel_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Parallel requests'),
      '#description' => $this->t('How many HTTP lookups may be in flight at once. Lowering this makes a sweep slower but gentler on registries.'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $config->get('parallel_requests'),
    ];
    $form['performance']['max_lookup_time'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum lookup time'),
      '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('Total budget for one check across every provider round. Anything unresolved when the budget runs out is reported as "unknown" rather than holding the request open.'),
      '#min' => 1,
      '#max' => 120,
      '#default_value' => $config->get('max_lookup_time'),
    ];

    $form['timeouts'] = [
      '#type' => 'details',
      '#title' => $this->t('Timeouts'),
      '#description' => $this->t('The dial between speed and how many TLDs come back conclusive. Tighter timeouts return faster with more "unknown" results.'),
    ];
    $form['timeouts']['rdap_timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('RDAP total timeout'),
      '#field_suffix' => $this->t('ms'),
      '#min' => 200,
      '#max' => 30000,
      '#default_value' => $config->get('rdap_timeout_ms'),
    ];
    $form['timeouts']['rdap_connect_timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('RDAP connect timeout'),
      '#field_suffix' => $this->t('ms'),
      '#min' => 200,
      '#max' => 30000,
      '#default_value' => $config->get('rdap_connect_timeout_ms'),
    ];
    $form['timeouts']['whois_timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('WHOIS total timeout'),
      '#field_suffix' => $this->t('ms'),
      '#min' => 200,
      '#max' => 30000,
      '#default_value' => $config->get('whois_timeout_ms'),
    ];
    $form['timeouts']['whois_connect_timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('WHOIS connect timeout (per address)'),
      '#field_suffix' => $this->t('ms'),
      '#description' => $this->t('Applied per address. A host with several addresses gets this budget for each, because a stalled address is retried on the next one.'),
      '#min' => 200,
      '#max' => 30000,
      '#default_value' => $config->get('whois_connect_timeout_ms'),
    ];
    $form['timeouts']['whois_address_family'] = [
      '#type' => 'select',
      '#title' => $this->t('WHOIS address family preference'),
      '#options' => [
        HostResolver::PREFER_IPV4 => $this->t('IPv4 first (recommended)'),
        HostResolver::PREFER_IPV6 => $this->t('IPv6 first'),
        HostResolver::PREFER_SYSTEM => $this->t('Leave it to the operating system'),
      ],
      '#description' => $this->t('Registry WHOIS is an IPv4-first estate, and several registries publish AAAA records that accept no connections — SaudiNIC among them. "Leave it to the operating system" reproduces that hang.'),
      '#default_value' => $this->settings->whoisAddressFamily(),
    ];

    $form['rate_limit'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate limiting'),
      '#open' => TRUE,
      '#description' => $this->t('Every lookup fans out to roughly twenty registries. Without a limit this module becomes an amplifier and your server IP gets blocked by the registries.'),
    ];
    $form['rate_limit']['rate_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable per-IP rate limiting'),
      '#default_value' => $config->get('rate_limit_enabled'),
    ];
    $form['rate_limit']['rate_limit_max_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per window'),
      '#min' => 1,
      '#max' => 1000,
      '#default_value' => $config->get('rate_limit_max_requests'),
      '#states' => [
        'visible' => [':input[name="rate_limit_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['rate_limit']['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Window length'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#max' => 3600,
      '#default_value' => $config->get('rate_limit_window'),
      '#states' => [
        'visible' => [':input[name="rate_limit_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['rate_limit']['rate_limit_min_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum interval between requests'),
      '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('Set to 0 to allow bursts within the window.'),
      '#min' => 0,
      '#max' => 60,
      '#default_value' => $config->get('rate_limit_min_interval'),
      '#states' => [
        'visible' => [':input[name="rate_limit_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['authoritative'] = [
      '#type' => 'details',
      '#title' => $this->t('Authoritative provider (optional)'),
      '#description' => $this->t('For TLDs public protocols cannot serve — a registry that throttles you at scale, or a ccTLD that restricts WHOIS. Disabled by default, and inert without a key: the module then behaves exactly as if it were not here. Confirm your vendor actually covers the TLDs you list before buying.'),
    ];
    $form['authoritative']['saudinic_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the authoritative provider'),
      '#default_value' => $config->get('saudinic_enabled'),
    ];
    $form['authoritative']['saudinic_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint'),
      '#description' => $this->t('Defaults to the WhoisFreaks availability API contract. Any vendor answering with a flat JSON verdict field fits by overriding the response map service parameters.'),
      '#default_value' => $config->get('saudinic_endpoint'),
      '#states' => [
        'visible' => [':input[name="saudinic_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['authoritative']['saudinic_api_key'] = [
      // Write-only: the stored key is never rendered back into the page, so it
      // cannot be read from the settings form or from View Source. The field is
      // always empty on load; an empty submission keeps whatever is stored.
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->settings->hasSaudinicApiKey()
        ? $this->t('A key is currently stored; leave this blank to keep it. Stored in configuration — exclude it from exported config or override it in settings.php if your config is committed to Git.')
        : $this->t('Stored in configuration. Exclude it from exported config or override it in settings.php if your config is committed to Git.'),
      '#attributes' => ['autocomplete' => 'off'],
      '#states' => [
        'visible' => [':input[name="saudinic_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['authoritative']['saudinic_tlds'] = [
      '#type' => 'textfield',
      '#title' => $this->t('TLDs it answers for'),
      '#description' => $this->t('Comma separated. This provider takes priority over RDAP and WHOIS, so list only TLDs the vendor is genuinely authoritative on.'),
      '#default_value' => implode(', ', array_map(
        static fn (string $tld): string => Tld::withDot($tld),
        $this->settings->saudinicTlds(),
      )),
      '#states' => [
        'visible' => [':input[name="saudinic_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['diagnostics'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging and diagnostics'),
    ];
    $form['diagnostics']['logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable logging'),
      '#description' => $this->t('Writes to the <em>domain_availability</em> logger channel.'),
      '#default_value' => $config->get('logging_enabled'),
    ];
    $form['diagnostics']['log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum log level'),
      '#options' => [
        'error' => $this->t('Error'),
        'warning' => $this->t('Warning (recommended)'),
        'info' => $this->t('Info'),
        'debug' => $this->t('Debug'),
      ],
      '#default_value' => $this->settings->logLevel(),
      '#states' => [
        'visible' => [':input[name="logging_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['diagnostics']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#description' => $this->t('Attaches exception details to API 500 responses. <strong>Never enable this on a public site.</strong>'),
      '#default_value' => $config->get('debug'),
    ];
    $form['diagnostics']['health_probe_tlds'] = [
      '#type' => 'textfield',
      '#title' => $this->t('TLDs probed for WHOIS egress'),
      '#description' => $this->t('Comma separated. Their reachability is reported on the <a href=":url">status report</a> and at /domain-check/health. A blocked port 43 is the usual reason a WHOIS-only TLD returns "unknown".', [
        ':url' => '/admin/reports/status',
      ]),
      '#default_value' => implode(', ', array_map(
        static fn (string $tld): string => Tld::withDot($tld),
        $this->settings->healthProbeTlds(),
      )),
    ];
    $form['diagnostics']['cors_allowed_origins'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CORS allowed origins'),
      '#description' => $this->t('Comma separated exact origins (https://example.com), or * for any. Leave empty to send no CORS headers, which is right when the API is only called from this site.'),
      '#default_value' => $config->get('cors_allowed_origins'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $tlds = $this->parseTlds((string) $form_state->getValue('tlds'));

    if ($tlds === []) {
      $form_state->setErrorByName('tlds', $this->t('Enter at least one TLD.'));
    }

    foreach ($tlds as $tld) {
      // A TLD is a DNS label like any other, so it obeys the same rule the
      // searched name does. Catching it here beats discovering it as a failed
      // lookup on every request.
      if (preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $tld) !== 1) {
        $form_state->setErrorByName('tlds', $this->t('"@tld" is not a valid TLD. Use letters, numbers and hyphens only.', ['@tld' => $tld]));
      }
    }

    if ((bool) $form_state->getValue('saudinic_enabled')) {
      if (trim((string) $form_state->getValue('saudinic_endpoint')) === '') {
        $form_state->setErrorByName('saudinic_endpoint', $this->t('An endpoint is required when the authoritative provider is enabled.'));
      }

      // The field is write-only, so a blank submission is fine when a key is
      // already stored; it is only missing when none was ever set.
      if (trim((string) $form_state->getValue('saudinic_api_key')) === '' && !$this->settings->hasSaudinicApiKey()) {
        $form_state->setErrorByName('saudinic_api_key', $this->t('An API key is required when the authoritative provider is enabled. Without one the provider stays inert and every lookup falls through to RDAP and WHOIS.'));
      }
    }

    if ((int) $form_state->getValue('rdap_connect_timeout_ms') > (int) $form_state->getValue('rdap_timeout_ms')) {
      $form_state->setErrorByName('rdap_connect_timeout_ms', $this->t('The RDAP connect timeout cannot exceed the total RDAP timeout.'));
    }

    if ((int) $form_state->getValue('whois_connect_timeout_ms') > (int) $form_state->getValue('whois_timeout_ms')) {
      $form_state->setErrorByName('whois_connect_timeout_ms', $this->t('The WHOIS connect timeout cannot exceed the total WHOIS timeout.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ModuleSettings::CONFIG_NAME);
    $config
      ->set('tlds', $this->parseTlds((string) $form_state->getValue('tlds')))
      ->set('rdap_enabled', (bool) $form_state->getValue('rdap_enabled'))
      ->set('whois_enabled', (bool) $form_state->getValue('whois_enabled'))
      ->set('dns_fallback_enabled', (bool) $form_state->getValue('dns_fallback_enabled'))
      ->set('cache_enabled', (bool) $form_state->getValue('cache_enabled'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('parallel_requests', (int) $form_state->getValue('parallel_requests'))
      ->set('max_lookup_time', (int) $form_state->getValue('max_lookup_time'))
      ->set('rdap_timeout_ms', (int) $form_state->getValue('rdap_timeout_ms'))
      ->set('rdap_connect_timeout_ms', (int) $form_state->getValue('rdap_connect_timeout_ms'))
      ->set('whois_timeout_ms', (int) $form_state->getValue('whois_timeout_ms'))
      ->set('whois_connect_timeout_ms', (int) $form_state->getValue('whois_connect_timeout_ms'))
      ->set('whois_address_family', (string) $form_state->getValue('whois_address_family'))
      ->set('rate_limit_enabled', (bool) $form_state->getValue('rate_limit_enabled'))
      ->set('rate_limit_max_requests', (int) $form_state->getValue('rate_limit_max_requests'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->set('rate_limit_min_interval', (int) $form_state->getValue('rate_limit_min_interval'))
      ->set('logging_enabled', (bool) $form_state->getValue('logging_enabled'))
      ->set('log_level', (string) $form_state->getValue('log_level'))
      ->set('debug', (bool) $form_state->getValue('debug'))
      ->set('cors_allowed_origins', trim((string) $form_state->getValue('cors_allowed_origins')))
      ->set('saudinic_enabled', (bool) $form_state->getValue('saudinic_enabled'))
      ->set('saudinic_endpoint', trim((string) $form_state->getValue('saudinic_endpoint')))
      ->set('saudinic_tlds', $this->parseTlds((string) $form_state->getValue('saudinic_tlds')))
      ->set('health_probe_tlds', $this->parseTlds((string) $form_state->getValue('health_probe_tlds')));

    // Write-only key: only overwrite the stored value when a new key was
    // actually entered. A blank field means "keep the existing key", so saving
    // the settings can never silently wipe it.
    $newKey = trim((string) $form_state->getValue('saudinic_api_key'));
    if ($newKey !== '') {
      $config->set('saudinic_api_key', $newKey);
    }

    $config->save();

    // Cached results were produced under the old settings: a changed TLD list,
    // a disabled provider or a tighter timeout would otherwise keep serving
    // answers the new configuration would never produce.
    $this->cache->invalidateAll();

    // Same for the counters: a tightened limit should bite now, not after the
    // old window expires.
    $this->rateLimiter->reset();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses a TLD list from a textarea or comma separated field.
   *
   * @param string $value
   *   The raw value.
   *
   * @return array<int, string>
   *   Normalised, dot-less, de-duplicated TLDs.
   */
  private function parseTlds(string $value): array {
    $parts = preg_split('/[\s,]+/', $value) ?: [];

    return Tld::normaliseList(array_values(array_filter($parts, static fn (string $item): bool => trim($item) !== '')));
  }

}
