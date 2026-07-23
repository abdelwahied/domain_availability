<?php

declare(strict_types=1);

namespace Drupal\domain_availability;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Url;
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the administrative listing of registration requests.
 *
 * The listing is itself a form, so it carries bulk operations — approve,
 * reject, cancel or delete several requests at once — alongside the per-row
 * operations. Newest first, because an administrator reviewing requests works
 * from the top of the queue.
 *
 * Every data cell is rendered as plain text, so a company name or address a
 * visitor typed can never become markup here; every bulk action is
 * access-checked per entity, so the action select can never do more than the
 * acting user is permitted.
 *
 * @internal
 *   An entity handler.
 */
final class DomainRegistrationRequestListBuilder extends EntityListBuilder implements FormInterface {

  use MessengerTrait;

  /**
   * Maps a bulk action to the status it applies.
   */
  private const STATUS_ACTIONS = [
    'approve' => DomainRegistrationRequestInterface::STATUS_APPROVED,
    'reject' => DomainRegistrationRequestInterface::STATUS_REJECTED,
    'cancel' => DomainRegistrationRequestInterface::STATUS_CANCELLED,
  ];

  /**
   * Constructs the list builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
    protected FormBuilderInterface $formBuilder,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('form_builder'),
      $container->get('logger.channel.domain_availability'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'domain_availability_registration_requests';
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return $this->formBuilder->getForm($this);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'domain' => $this->t('Requested domain'),
      'company' => $this->t('Company'),
      'commercial_registration' => $this->t('Commercial registration'),
      'mobile' => $this->t('Mobile'),
      'status' => $this->t('Status'),
      'created' => $this->t('Created'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof DomainRegistrationRequestInterface);

    $row['id'] = $entity->getReferenceNumber();
    $row['domain'] = $entity->getDomain();
    $row['company'] = $entity->get('company_name_en')->value ?: $entity->get('company_name_ar')->value;
    $row['commercial_registration'] = $entity->get('commercial_registration')->value;
    $row['mobile'] = $entity->get('mobile')->value;
    $row['status'] = $this->statusLabel($entity->getStatus());
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    // The optional $cacheability parameter matches the signature Drupal 11.3
    // added; it is not forwarded to the parent so the override stays compatible
    // with the single-argument parent on Drupal 10.3.
    $operations = parent::getDefaultOperations($entity);

    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => -10,
        'url' => $entity->toUrl('canonical'),
      ];
    }

    if ($entity->access('update')) {
      $operations['edit_status'] = [
        'title' => $this->t('Edit status'),
        'weight' => 0,
        'url' => Url::fromRoute('domain_availability.registration_request.status', [
          'domain_registration_request' => $entity->id(),
        ]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['bulk'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['domain-registration-bulk']],
    ];
    $form['bulk']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#title_display' => 'invisible',
      '#empty_option' => $this->t('- Bulk action -'),
      '#options' => [
        'approve' => $this->t('Approve'),
        'reject' => $this->t('Reject'),
        'cancel' => $this->t('Cancel'),
        'delete' => $this->t('Delete'),
      ],
    ];
    $form['bulk']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to selected'),
      '#button_type' => 'primary',
    ];

    $form['requests'] = [
      '#type' => 'table',
      '#header' => ['select' => $this->t('Select')] + $this->buildHeader(),
      '#empty' => $this->t('No registration requests have been submitted yet.'),
    ];

    foreach ($this->load() as $id => $entity) {
      assert($entity instanceof DomainRegistrationRequestInterface);
      $form['requests'][$id]['select'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select request @ref', ['@ref' => $entity->getReferenceNumber()]),
        '#title_display' => 'invisible',
      ];

      foreach ($this->buildRow($entity) as $key => $cell) {
        // Operations arrive as a render array; every other cell is untrusted
        // text and is rendered as plain text so it can never become markup.
        $form['requests'][$id][$key] = ($key === 'operations')
          ? ($cell['data'] ?? [])
          : ['#plain_text' => (string) $cell];
      }
    }

    $form['pager'] = ['#type' => 'pager'];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->selectedIds($form_state) === []) {
      $form_state->setErrorByName('requests', $this->t('Select at least one request.'));
    }

    if ((string) $form_state->getValue('action') === '') {
      $form_state->setErrorByName('action', $this->t('Choose a bulk action.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action');
    $entities = $this->storage->loadMultiple($this->selectedIds($form_state));
    $applied = 0;

    foreach ($entities as $entity) {
      assert($entity instanceof DomainRegistrationRequestInterface);

      // Each entity is access-checked for the action, so the bulk select can
      // never exceed the acting user's own permissions.
      if ($action === 'delete') {
        if ($entity->access('delete')) {
          $entity->delete();
          $applied++;
        }
      }
      elseif ($entity->access('update')) {
        $entity->setStatus(self::STATUS_ACTIONS[$action])->save();
        $applied++;
      }
    }

    $this->logger->info('Bulk action @action applied to @count registration request(s).', [
      '@action' => $action,
      '@count' => $applied,
    ]);

    $this->messenger()->addStatus($this->formatPlural(
      $applied,
      'The action was applied to 1 request.',
      'The action was applied to @count requests.',
    ));

    $form_state->setRedirect('entity.domain_registration_request.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC');

    $query->pager(50);

    return $this->storage->loadMultiple($query->execute());
  }

  /**
   * The ids of the checked rows.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<int, int|string>
   *   The selected entity ids.
   */
  private function selectedIds(FormStateInterface $form_state): array {
    $rows = $form_state->getValue('requests');

    if (!is_array($rows)) {
      return [];
    }

    return array_keys(array_filter(
      $rows,
      static fn ($row): bool => is_array($row) && !empty($row['select']),
    ));
  }

  /**
   * A human, translated label for a status value.
   *
   * @param string $status
   *   The status machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  private function statusLabel(string $status): object {
    return match ($status) {
      DomainRegistrationRequestInterface::STATUS_APPROVED => $this->t('Approved'),
      DomainRegistrationRequestInterface::STATUS_REJECTED => $this->t('Rejected'),
      DomainRegistrationRequestInterface::STATUS_CANCELLED => $this->t('Cancelled'),
      default => $this->t('Pending'),
    };
  }

}
