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
        'Matching OK: %d Teilnehmer, %d Zuteilungen, keine Wünsche: %d. (Seed %s)',
        $sum['participants_total'] ?? 0,
        $sum['assignments_total'] ?? 0,
        $sum['participants_no_wishes'] ?? 0,
        $sum['seed'] ?? 'n/a'
      );
      $this->messenger()->addStatus($msg);
      if (!empty($result['patch_errors'])) {
        $this->messenger()->addWarning('Einige PATCH-Fehler traten auf (siehe Logs).');
        $this->getLogger('jfcamp_matching')->warning('Patch-Fehler: @errs', ['@errs' => print_r($result['patch_errors'], true)]);
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError('Matching fehlgeschlagen: ' . $e->getMessage());
    }

    $url = Url::fromRoute('jfcamp_matching.admin_form')->toString();
    return new RedirectResponse($url);
  }

}
