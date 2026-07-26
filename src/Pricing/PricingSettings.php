<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\domain_availability\Service\ModuleSettings;

/**
 * Typed, read-only access to the `pricing` mapping.
 *
 * Everything pricing-related lives in a single `pricing` mapping inside
 * `domain_availability.settings`, and this is the only class that knows that.
 * Templates, controllers and the check service never read those keys, so the
 * storage shape stays changeable.
 *
 * Reads through the config factory on every call, like ModuleSettings does: a
 * settings save is an ordinary request, and a price cached in a property would
 * be the wrong price for the rest of it.
 *
 * **A strategy reads its own keys and nobody else's.** Two strategies sharing a
 * key are two strategies that cannot be configured independently, and the one
 * that ships second inherits a bug it cannot see.
 *
 * @api
 *   Public and stable since 1.1.0. Injected into every pricing strategy —
 *   PricingStrategyBase takes it as its first constructor argument — so it is
 *   part of the extension contract, not an implementation detail. Consumers
 *   that only want a price call PricingManager instead.
 */
final class PricingSettings {

  /**
   * The key holding the whole pricing mapping.
   */
  public const CONFIG_KEY = 'pricing';

  /**
   * The key naming the active strategy.
   */
  public const MODE_KEY = 'mode';

  /**
   * Constructs a PricingSettings.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * The active pricing mode.
   *
   * @return string
   *   The configured strategy id, or an empty string when pricing is unset.
   */
  public function mode(): string {
    $mode = $this->all()[self::MODE_KEY] ?? '';

    return is_string($mode) ? trim($mode) : '';
  }

  /**
   * The whole pricing mapping.
   *
   * @return array<string, mixed>
   *   The stored pricing values, empty when nothing is configured.
   */
  public function all(): array {
    $values = $this->configFactory->get(ModuleSettings::CONFIG_NAME)->get(self::CONFIG_KEY);

    return is_array($values) ? $values : [];
  }

  /**
   * One pricing value.
   *
   * @param string $key
   *   The key within the pricing mapping, e.g. `fixed_price`.
   * @param mixed $default
   *   What to return when the key is unset.
   *
   * @return mixed
   *   The stored value, or the default.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->all()[$key] ?? $default;
  }

  /**
   * One pricing value as a mapping.
   *
   * @param string $key
   *   The key within the pricing mapping, e.g. `extension_prices`.
   *
   * @return array<string, mixed>
   *   The stored mapping, empty when unset or stored as something else.
   */
  public function getMapping(string $key): array {
    $value = $this->get($key);

    return is_array($value) ? $value : [];
  }

  /**
   * The cache tag every stored price depends on.
   *
   * @return array<int, string>
   *   The cache tags.
   */
  public function cacheTags(): array {
    return ['config:' . ModuleSettings::CONFIG_NAME];
  }

}
