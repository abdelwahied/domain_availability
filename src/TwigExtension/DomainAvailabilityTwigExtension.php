<?php

declare(strict_types=1);

namespace Drupal\domain_availability\TwigExtension;

use Drupal\Core\Render\RendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the search component to Twig templates.
 *
 * For theme developers who want the component inside a template without
 * touching PHP or placing a block. It renders the same element as everything
 * else, so it inherits the same access checks and markup.
 *
 * @code
 *   {{ domain_availability_search() }}
 *
 * @endcode
 *
 * @internal
 *   The Twig function `domain_availability_search()` is the contract.
 */
final class DomainAvailabilityTwigExtension extends AbstractExtension {

  /**
   * Constructs a DomainAvailabilityTwigExtension.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(private readonly RendererInterface $renderer) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('domain_availability_search', [$this, 'renderSearch'], [
        'is_safe' => ['html'],
      ]),
    ];
  }

  /**
   * Renders the search component.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rendered markup.
   */
  public function renderSearch(): mixed {
    $build = ['#type' => 'domain_availability_search'];

    // renderInIsolation(): the component carries max-age 0, and bubbling that
    // up would make the whole page uncacheable just because a template happens
    // to call this function. Available on every supported core (Drupal 10.3+).
    return $this->renderer->renderInIsolation($build);
  }

}
