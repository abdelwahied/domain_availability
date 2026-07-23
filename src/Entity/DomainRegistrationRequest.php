<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Entity;

use Drupal\file\FileInterface;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\domain_availability\DomainRegistrationRequestAccessControlHandler;
use Drupal\domain_availability\DomainRegistrationRequestListBuilder;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the domain registration request content entity.
 *
 * @internal
 *   The implementation; type-hint DomainRegistrationRequestInterface.
 */
#[ContentEntityType(
  id: 'domain_registration_request',
  label: new TranslatableMarkup('Domain registration request'),
  label_collection: new TranslatableMarkup('Registration requests'),
  label_singular: new TranslatableMarkup('registration request'),
  label_plural: new TranslatableMarkup('registration requests'),
  label_count: [
    'singular' => '@count registration request',
    'plural' => '@count registration requests',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => DomainRegistrationRequestListBuilder::class,
    'access' => DomainRegistrationRequestAccessControlHandler::class,
    'form' => [
      'delete' => ContentEntityDeleteForm::class,
    ],
  ],
  base_table: 'domain_registration_request',
  admin_permission: 'manage domain registration requests',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'domain',
    'owner' => 'uid',
  ],
  links: [
    'collection' => '/admin/config/system/domain-availability/registration-requests',
    'canonical' => '/admin/config/system/domain-availability/registration-requests/{domain_registration_request}',
    'delete-form' => '/admin/config/system/domain-availability/registration-requests/{domain_registration_request}/delete',
  ],
)]
final class DomainRegistrationRequest extends ContentEntityBase implements DomainRegistrationRequestInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getDomain(): string {
    return (string) $this->get('domain')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isIndividual(): bool {
    return (string) $this->get('applicant_type')->value === self::APPLICANT_INDIVIDUAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    $value = (string) $this->get('status')->value;

    return $value !== '' ? $value : self::STATUS_PENDING;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCertificateFile(): ?object {
    $entity = $this->get('certificate')->entity;

    return $entity instanceof FileInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceNumber(): string {
    return sprintf('DRR-%06d', (int) $this->id());
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // A request always has an owner and a status: default them here so no
    // caller can persist a half-formed row, whatever path created it.
    if ($this->getStatus() === '') {
      $this->setStatus(self::STATUS_PENDING);
    }

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }

    if ((string) $this->get('applicant_type')->value === '') {
      $this->set('applicant_type', self::APPLICANT_COMPANY);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);

    // Keep the uploaded certificate permanent and record its usage, so cron's
    // temporary-file cleanup can never delete a document tied to a live
    // request.
    $file = $this->getCertificateFile();

    if ($file !== NULL && !$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['domain'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Requested domain'))
      ->setDescription(t('The fully qualified domain the request is for.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['applicant_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Applicant type'))
      ->setDescription(t('Whether the request is made by an individual or a company.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::APPLICANT_INDIVIDUAL => t('Individual')->render(),
        self::APPLICANT_COMPANY => t('Company')->render(),
      ])
      ->setDefaultValue(self::APPLICANT_COMPANY);

    $fields['registration_years'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Registration period (years)'))
      ->setDescription(t('How many years the domain should be reserved for.'))
      ->setRequired(TRUE)
      ->setSetting('min', 1)
      ->setSetting('max', 10)
      ->setDefaultValue(1);

    // The company documents below are mandatory for a company applicant only,
    // so the requirement lives in the form, which knows the applicant type,
    // rather than in the storage definition, which would reject every
    // individual's request.
    $fields['company_name_ar'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Company name (Arabic)'))
      ->setSetting('max_length', 255);

    $fields['company_name_en'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Company name (English)'))
      ->setSetting('max_length', 255);

    $fields['national_address'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('National address'));

    $fields['commercial_registration'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Commercial registration number'))
      ->setSetting('max_length', 64);

    $fields['mobile'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Mobile number'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    // The constraint means the rule holds however the request was created —
    // the form, a migration, an API write — and not only where a form ran.
    $fields['national_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('National ID / Iqama number'))
      ->setSetting('max_length', 32)
      ->addConstraint('SaudiId');

    $fields['certificate'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Commercial registration certificate'))
      ->setDescription(t('The uploaded PDF certificate.'))
      ->setSetting('file_extensions', 'pdf')
      ->setSetting('handler', 'default:file');

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::STATUS_PENDING => t('Pending')->render(),
        self::STATUS_APPROVED => t('Approved')->render(),
        self::STATUS_REJECTED => t('Rejected')->render(),
        self::STATUS_CANCELLED => t('Cancelled')->render(),
      ])
      ->setDefaultValue(self::STATUS_PENDING);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Administrative notes'))
      ->setDescription(t('Internal notes recorded while reviewing the request.'));

    $fields['ip_address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IP address'))
      ->setSetting('max_length', 128);

    $fields['user_agent'] = BaseFieldDefinition::create('string')
      ->setLabel(t('User agent'))
      ->setSetting('max_length', 512);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Updated'));

    return $fields;
  }

}
