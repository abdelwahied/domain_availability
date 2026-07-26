<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Pricing;

use Drupal\Core\Form\FormStateInterface;

/**
 * A pricing strategy that has settings of its own.
 *
 * Split from PricingStrategyInterface rather than merged into it: a strategy
 * that reads its prices from a registrar API or from a promotion entity has
 * nothing to put on the settings form, and should not be made to implement
 * three empty methods to say so. The settings form asks `instanceof` and skips
 * the ones that do not answer.
 *
 * A strategy owns its whole vertical slice — the fields, their validation and
 * the shape it stores — so adding a mode never edits SettingsForm.
 *
 * @api
 *   Public and stable since 1.1.0. Implement alongside PricingStrategyInterface
 *   when a strategy needs administrative settings.
 */
interface ConfigurablePricingStrategyInterface extends PricingStrategyInterface {

  /**
   * Builds this strategy's settings fields.
   *
   * The returned elements are placed inside a container that is shown only
   * while this strategy's mode is selected, so they must not carry `#states`
   * or `#required` of their own — a hidden required field blocks the form.
   *
   * @return array<string, mixed>
   *   The form elements, keyed by the configuration key they populate.
   */
  public function buildConfigurationForm(): array;

  /**
   * Validates this strategy's submitted values.
   *
   * Only called for the strategy the administrator actually selected: the
   * others are hidden, and hidden fields must never block a save.
   *
   * @param array<string, mixed> $values
   *   The submitted values of this strategy's subform.
   * @param array<int, string> $parents
   *   The subform's form parents, for building error element names.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state, to report errors on.
   */
  public function validateConfigurationForm(array $values, array $parents, FormStateInterface $formState): void;

  /**
   * Turns this strategy's submitted values into what should be stored.
   *
   * @param array<string, mixed> $values
   *   The submitted values of this strategy's subform.
   *
   * @return array<string, mixed>
   *   Keys to write into the `pricing` configuration mapping.
   */
  public function submitConfigurationForm(array $values): array;

}
