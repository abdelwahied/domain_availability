<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\domain_availability\Exception\ValidationException;
use Drupal\domain_availability\Service\DomainCheckService;
use Drupal\domain_availability\Utility\DomainSanitizer;
use Drupal\domain_availability\Validator\DomainValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The domain search form.
 *
 * Server-rendered through the Form API rather than the standalone's fetch()
 * call, which buys CSRF protection, validation, translation and accessible
 * markup from core instead of from hand-written JavaScript. The AJAX submit
 * keeps the "no page reload" behaviour, and — unlike the original — the form
 * still works with JavaScript disabled.
 * The form knows nothing about RDAP, WHOIS or caching: it sanitizes, validates
 * and hands the label to the checker service, exactly as the API controller
 * does. Both surfaces therefore always agree.
 *
 * @internal
 *   A form; the route and the render element are the contract.
 */
final class DomainSearchForm extends FormBase {

  /**
   * Constructs a DomainSearchForm.
   *
   * @param \Drupal\domain_availability\Service\DomainCheckService $checker
   *   The domain check service.
   * @param \Drupal\domain_availability\Validator\DomainValidator $validator
   *   The domain validator.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    protected DomainCheckService $checker,
    protected DomainValidator $validator,
    protected RendererInterface $renderer,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('domain_availability.checker'),
      $container->get('domain_availability.validator'),
      $container->get('renderer'),
      $container->get('logger.channel.domain_availability'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'domain_availability/search';
    $form['#attributes']['class'][] = 'domain-availability-form';

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Type the name only, without an extension — we check every TLD at once.'),
      '#attributes' => ['class' => ['domain-availability-form__intro']],
    ];

    $form['domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Domain name'),
      '#description' => $this->t('Allowed: letters, numbers and hyphen (-) — up to 63 characters. A full URL is reduced to its name automatically.'),
      '#required' => TRUE,
      '#maxlength' => 253,
      '#size' => 40,
      '#default_value' => '',
      '#attributes' => [
        'placeholder' => 'domain',
        'autocapitalize' => 'none',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        // The label is always an ASCII domain label, so it stays LTR even when
        // the surrounding interface is Arabic.
        'dir' => 'ltr',
        'class' => ['domain-availability-form__input'],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'domain-availability-results',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Checking every extension…'),
        ],
      ],
    ];

    $form['results'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'domain-availability-results',
        // Screen readers announce results as they land without stealing focus.
        'aria-live' => 'polite',
        'aria-atomic' => 'false',
      ],
      'content' => $form_state->get('results') ?? [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Sanitize first: the same input rules as the API, so a URL pasted into the
    // form behaves exactly as a URL passed to the endpoint.
    $label = DomainSanitizer::sanitize((string) $form_state->getValue('domain'));

    try {
      $this->validator->validate($label);
    }
    catch (ValidationException $exception) {
      $errors = $exception->errors();
      $form_state->setErrorByName('domain', $errors['domain'] ?? $exception->publicMessage());

      return;
    }

    // Hand the cleaned label to the submit handler and show the user exactly
    // what is being checked.
    $form_state->setValue('domain', $label);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('results', $this->buildResults((string) $form_state->getValue('domain')));
    $form_state->setRebuild(TRUE);
  }

  /**
   * The AJAX callback: swaps the results wrapper in place.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response that replaces the results wrapper.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // With validation errors the wrapper still has to be replaced, otherwise
    // stale results sit under a fresh error message.
    $response->addCommand(new ReplaceCommand('#domain-availability-results', $form['results']));

    return $response;
  }

  /**
   * Runs the lookup and builds its render array.
   *
   * @param string $label
   *   The validated label.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function buildResults(string $label): array {
    try {
      $report = $this->checker->check($label);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Search failed for @label: @message', [
        '@label' => $label,
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);

      return [
        '#theme' => 'domain_availability_error',
        '#message' => $this->t('The lookup service is temporarily unavailable. Please try again.'),
      ];
    }

    return [
      '#theme' => 'domain_availability_results',
      '#report' => $report,
      '#cache' => ['max-age' => 0],
    ];
  }

}
