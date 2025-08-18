<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

final class MatchingRunController extends ControllerBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_matching.client'));
  }

  public function run() {
    try {
      $result = $this->client->run();
      $sum = $result['summary'] ?? [];
      $msg = sprintf(
        'Matching OK: %d TN, %d Zuteilungen, alle gefüllt: %s.',
        $sum['participants_total'] ?? 0,
        $sum['assignments_total'] ?? 0,
        !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein'
      );
      $this->messenger()->addStatus($msg);
      if (!empty($result['patch_errors'])) {
        $this->messenger()->addWarning('PATCH-Fehler aufgetreten (siehe Logs).');
        $this->getLogger('jfcamp_matching')->warning('Patch-Fehler: @errs', ['@errs' => print_r($result['patch_errors'], TRUE)]);
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError('Matching fehlgeschlagen: ' . $e->getMessage());
    }

    $url = Url::fromRoute('jfcamp_matching.admin_form')->toString();
    return new RedirectResponse($url);
  }

}
