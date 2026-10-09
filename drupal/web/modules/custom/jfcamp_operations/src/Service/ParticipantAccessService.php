<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

/**
 * Resolves the Regionalverband scope for the current backend user.
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
   * An empty array intentionally means "no participant access within the
   * overview". This fail-closed behaviour prevents a Teamer account without a
   * configured Regionalverband from accidentally seeing all participants.
   *
   * @return string[]|null
   */
  public function getAllowedRegions(): ?array {
    if ($this->currentUser->hasPermission(self::PERMISSION_VIEW_ALL)) {
      return NULL;
    }

    if (!$this->currentUser->isAuthenticated()) {
      return [];
    }

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());

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
