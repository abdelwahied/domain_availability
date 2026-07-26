<?php

declare(strict_types=1);

namespace Drupal\Tests\domain_availability\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\domain_availability\TwigExtension\DomainAvailabilityTwigExtension;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the Twig function bubbles what the component needs to work.
 *
 * The function used to render in isolation, which discards all bubbleable
 * metadata — and `#attached` shares that channel with `#cache`, so suppressing
 * the component's max-age also threw away its CSS, its JavaScript and its AJAX
 * binding, and left the page advertising a live form as permanently cacheable.
 *
 * @group domain_availability
 *
 * @covers \Drupal\domain_availability\TwigExtension\DomainAvailabilityTwigExtension::renderSearch
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('domain_availability')]
#[CoversMethod(DomainAvailabilityTwigExtension::class, 'renderSearch')]
final class TwigSearchFunctionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'domain_availability'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['domain_availability']);
    $this->installEntitySchema('user');
  }

  /**
   * The libraries and the max-age reach the caller's render context.
   */
  public function testMetadataBubblesToTheCaller(): void {
    $renderer = $this->container->get('renderer');
    $extension = new DomainAvailabilityTwigExtension($renderer);

    $context = new RenderContext();
    $markup = $renderer->executeInRenderContext($context, static fn (): mixed => $extension->renderSearch());

    self::assertStringContainsString('<form', (string) $markup);
    self::assertFalse($context->isEmpty(), 'The Twig function bubbled no metadata at all.');

    $metadata = $context->pop();

    self::assertContains('domain_availability/search', $metadata->getAttachments()['library'] ?? []);
    self::assertSame(0, $metadata->getCacheMaxAge(), 'A live form must not be advertised as cacheable.');
  }

}
