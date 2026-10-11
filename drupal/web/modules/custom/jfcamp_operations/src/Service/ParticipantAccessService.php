<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

/**
 * Resolves the Regionalverband scope for backend users.
 */
final class ParticipantAccessService {

  public const FIELD_REGIONS = 'field_jfcamp_regionalverbaende';
  public const PERMISSION_VIEW_ALL = 'view all jfcamp participant regions';

  public function __construct(
    private AccountProxyInterface $currentUser,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns NULL for unrestricted access, otherwise the allowed region codes.
   *
   * @return string[]|null
   *   NULL means unrestricted access. An empty array intentionally means no
   *   regional participant access.
   */
  public function getAllowedRegions(): ?array {
    return $this->getAllowedRegionsFor($this->currentUser);
  }

  /**
   * Resolves the Regionalverband scope for an arbitrary account.
   *
   * This variant is used by Drupal node-access checks, where the account being
   * evaluated is passed explicitly and must not be inferred from the request.
   *
   * @return string[]|null
   *   NULL means unrestricted access. An empty array intentionally means no
   *   regional participant access.
   */
  public function getAllowedRegionsFor(AccountInterface $account): ?array {
    if ($account->hasPermission(self::PERMISSION_VIEW_ALL)) {
      return NULL;
    }

    if (!$account->isAuthenticated()) {
      return [];
    }

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $account->id());

    if (!$user instanceof UserInterface || !$user->hasField(self::FIELD_REGIONS)) {
      return [];
    }

    $regions = [];
    foreach ($user->get(self::FIELD_REGIONS) as $item) {
      $value = strtoupper(trim((string) ($item->value ?? '')));
      if ($value !== '') {
        $regions[$value] = $value;
      }
    }

    ksort($regions, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($regions);
  }

  public function isRestricted(): bool {
    return $this->getAllowedRegions() !== NULL;
  }

}
