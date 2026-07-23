<?php

declare(strict_types=1);

namespace Drupal\domain_availability;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for registration requests.
 *
 * The three permissions map to the three things an administrator does with a
 * request — read it, act on it, remove it — and nothing about a request is ever
 * public: these are back-office records containing a national ID and a
 * certificate, so every operation requires an explicit permission.
 *
 * Submitting a new request is NOT governed here; that is the visitor-facing
 * form, gated by its own route permission.
 *
 * @internal
 *   An entity handler.
 */
final class DomainRegistrationRequestAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view domain registration requests'),
      'update' => AccessResult::allowedIfHasPermission($account, 'manage domain registration requests'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete domain registration requests'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Entity-level create access is for administrative tooling. The public
    // submission form does its own gating and constructs the entity directly,
    // so it does not pass through here.
    return AccessResult::allowedIfHasPermission($account, 'manage domain registration requests');
  }

}
