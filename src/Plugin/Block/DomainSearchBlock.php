<?php

declare(strict_types=1);

namespace Drupal\domain_availability\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Places the domain search anywhere through Block Layout.
 *
 * @internal
 *   A block plugin; the plugin id `domain_availability_search` is the contract.
 */
#[Block(
  id: 'domain_availability_search',
  admin_label: new TranslatableMarkup('Domain availability search'),
  category: new TranslatableMarkup('Forms'),
)]
final class DomainSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#type' => 'domain_availability_search',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The block is a second door onto the same lookups the search page runs, so
   * it answers to the same permission. Placing it in a region must not become a
   * way around access control.
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'access domain availability search');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
