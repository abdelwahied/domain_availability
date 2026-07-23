<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for a domain registration request.
 *
 * A request is what a visitor submits after a search shows a domain as
 * available: it captures who wants the domain and their supporting documents,
 * for an administrator to review. It is deliberately separate from the lookup
 * engine — a request records intent, it does not register anything.
 *
 * @api
 *   Public and stable since 1.0.0. The entity contract, including the STATUS_*
 *   and APPLICANT_* constants.
 */
interface DomainRegistrationRequestInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * The applicant is a private individual.
   */
  public const APPLICANT_INDIVIDUAL = 'individual';

  /**
   * The applicant is a registered company.
   */
  public const APPLICANT_COMPANY = 'company';

  /**
   * The request is awaiting review.
   */
  public const STATUS_PENDING = 'pending';

  /**
   * The request has been approved.
   */
  public const STATUS_APPROVED = 'approved';

  /**
   * The request has been rejected.
   */
  public const STATUS_REJECTED = 'rejected';

  /**
   * The request has been cancelled.
   */
  public const STATUS_CANCELLED = 'cancelled';

  /**
   * The requested domain, e.g. `neixora.sa`.
   *
   * @return string
   *   The domain.
   */
  public function getDomain(): string;

  /**
   * Whether the applicant is a private individual rather than a company.
   *
   * The company documents are only mandatory for a company applicant, so both
   * the form and the review screens branch on this.
   *
   * @return bool
   *   TRUE when the applicant is an individual.
   */
  public function isIndividual(): bool;

  /**
   * The current workflow status.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function getStatus(): string;

  /**
   * Sets the workflow status.
   *
   * @param string $status
   *   One of the STATUS_* constants.
   *
   * @return $this
   *   The entity, for chaining.
   */
  public function setStatus(string $status): static;

  /**
   * The uploaded commercial-registration certificate, if any.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity, or NULL when none is attached.
   */
  public function getCertificateFile(): ?object;

  /**
   * The creation timestamp.
   *
   * @return int
   *   A UNIX timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * The human-facing reference number for this request.
   *
   * Derived from the id so it is stable and needs no extra storage: the same
   * number appears in the confirmation email and on the admin detail page.
   *
   * @return string
   *   The reference number, e.g. `DRR-000042`.
   */
  public function getReferenceNumber(): string;

}
