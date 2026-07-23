<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an administrator change a request's status and record a note.
 *
 * One form covers approve, reject and cancel: they are the same action — set
 * the status — with different values, so a single audited path serves all
 * three rather than three near-identical handlers.
 *
 * @internal
 *   A form; the route is the contract.
 */
final class RegistrationStatusForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.domain_availability'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_registration_status_form';
  }

  /**
   * The page title.
   *
   * @param \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $domain_registration_request
   *   The request.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title.
   */
  public function title(DomainRegistrationRequestInterface $domain_registration_request): object {
    return $this->t('Change status of @ref', ['@ref' => $domain_registration_request->getReferenceNumber()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DomainRegistrationRequestInterface $domain_registration_request = NULL): array {
    $form_state->set('request_id', $domain_registration_request?->id());

    $form['domain'] = [
      '#type' => 'item',
      '#title' => $this->t('Requested domain'),
      '#markup' => '<strong dir="ltr">' . htmlspecialchars($domain_registration_request?->getDomain() ?? '', ENT_QUOTES, 'UTF-8') . '</strong>',
    ];

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#required' => TRUE,
      '#default_value' => $domain_registration_request?->getStatus(),
      '#options' => [
        DomainRegistrationRequestInterface::STATUS_PENDING => $this->t('Pending'),
        DomainRegistrationRequestInterface::STATUS_APPROVED => $this->t('Approved'),
        DomainRegistrationRequestInterface::STATUS_REJECTED => $this->t('Rejected'),
        DomainRegistrationRequestInterface::STATUS_CANCELLED => $this->t('Cancelled'),
      ],
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Administrative notes'),
      '#default_value' => $domain_registration_request?->get('notes')->value,
      '#rows' => 4,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->get('request_id');
    $storage = $this->entityTypeManager->getStorage('domain_registration_request');

    /** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface|null $request */
    $request = $id !== NULL ? $storage->load($id) : NULL;

    if ($request === NULL) {
      $this->messenger()->addError($this->t('The request no longer exists.'));

      return;
    }

    $status = (string) $form_state->getValue('status');
    $request->setStatus($status);
    $request->set('notes', trim((string) $form_state->getValue('notes')));
    $request->save();

    $this->logger->info('Registration request @ref set to @status.', [
      '@ref' => $request->getReferenceNumber(),
      '@status' => $status,
    ]);

    $this->messenger()->addStatus($this->t('Request @ref has been updated.', [
      '@ref' => $request->getReferenceNumber(),
    ]));

    $form_state->setRedirectUrl($request->toUrl('collection'));
  }

}
