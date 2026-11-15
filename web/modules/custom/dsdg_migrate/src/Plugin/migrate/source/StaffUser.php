<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\user\Plugin\migrate\source\d7\User;

/**
 * D7 DSDG staff accounts.
 *
 * Only YG staff accounts (@yukon.ca or @gov.yk.ca) are returned, and of those
 * only accounts that are active or own content:
 * - Active: enabled and signed in on or after active_since. Migrated as active.
 * - Inactive but owning a node: migrated as blocked, to keep authorship.
 * - Inactive with no nodes: not returned.
 * Vendor accounts own no D7 content, so none is reassigned.
 *
 * Adds two source properties:
 * - dsdg_status: 1 for active accounts, 0 otherwise.
 * - dsdg_roles: D11 role machine names, from the D7 role names.
 *
 * Configuration:
 * - active_since: date (Y-m-d) from which a sign-in counts as active.
 *
 * @MigrateSource(
 *   id = "dsdg_staff_user",
 *   source_module = "user"
 * )
 */
class StaffUser extends User {

  /**
   * D11 roles, keyed by D7 role name.
   */
  const ROLES = [
    'administrator' => 'administrator',
    'editor' => 'editor',
    'publisher' => 'publisher',
    'translator' => 'translator',
  ];

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $domains = $query->orConditionGroup()
      ->condition('u.mail', '%@yukon.ca', 'LIKE')
      ->condition('u.mail', '%@gov.yk.ca', 'LIKE');
    $owns_content = $this->select('node', 'n')
      ->fields('n', ['uid']);
    $active_or_owner = $query->orConditionGroup()
      ->condition($query->andConditionGroup()
        ->condition('u.status', 1)
        ->condition('u.login', $this->activeSince(), '>='))
      ->condition('u.uid', $owns_content, 'IN');
    return $query
      ->condition('u.uid', 1, '>')
      ->condition($domains)
      ->condition($active_or_owner);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // The parent adds the "roles" property (D7 role IDs).
    $result = parent::prepareRow($row);

    $active =$row->getSourceProperty('status') == 1 && $row->getSourceProperty('login') >= $this->activeSince();
    $row->setSourceProperty('dsdg_status', (int) $active);

    $names = [];
    if ($rids = $row->getSourceProperty('roles')) {
      $names = $this->select('role', 'r')
        ->fields('r', ['name'])
        ->condition('r.rid', $rids, 'IN')
        ->execute()
        ->fetchCol();
    }
    $row->setSourceProperty('dsdg_roles', array_values(array_intersect_key(static::ROLES, array_flip($names))));

    return $result;
  }

  /**
   * Returns the active_since cutoff as a Unix timestamp.
   */
  protected function activeSince(): int {
    return strtotime($this->configuration['active_since'] ?? '2025-01-01');
  }

}
