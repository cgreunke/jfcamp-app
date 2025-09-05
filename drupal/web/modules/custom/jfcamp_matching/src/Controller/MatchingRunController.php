<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Drupal\jfcamp_matching\Batch\ApplyAssignmentsBatch;
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
      // 0) letzte Parameter aus State nehmen (Reproduzierbarkeit)
      $params = \Drupal::state()->get('jfcamp_matching.last_params') ?: [];

      // 1) Dry-Run ausführen (als "Run"-Quelle)
      $result = $this->client->dryRun(is_array($params) ? $params : []);
      $sum = $result['summary'] ?? [];
      $byParticipant = $result['by_participant'] ?? [];
      if (!is_array($byParticipant) || empty($byParticipant)) {
        $this->messenger()->addWarning($this->t('Keine Zuweisungen im Ergebnis.'));
        $url = Url::fromRoute('jfcamp_matching.admin_form')->toString();
        return new RedirectResponse($url);
      }

      // 2) Konfiguration fürs Schreiben
      $cfg = \Drupal::config('jfcamp_matching.settings');
      $config = [
        'participant_bundle' => $cfg->get('participant_bundle') ?? 'teilnehmer',
        'workshop_bundle' => $cfg->get('workshop_bundle') ?? 'workshop',
        'assignment_mode' => $cfg->get('assignment_mode') ?? 'auto',
        'slot_fields_prefix' => $cfg->get('slot_fields_prefix') ?? 'field_slot_',
        'num_slots' => (int) ($cfg->get('num_slots') ?? 3),
        'assigned_field' => $cfg->get('assigned_field') ?? 'field_zugewiesen',
      ];

      // 3) Batch anwenden
      $batch = ApplyAssignmentsBatch::build($config, $byParticipant);
      batch_set($batch);

      $this->messenger()->addStatus($this->t(
        'Matching OK: @p TN, @a Zuteilungen. Wende Zuweisungen in Drupal an …',
        ['@p' => $sum['participants_total'] ?? 0, '@a' => $sum['assignments_total'] ?? 0]
      ));

      return batch_process(Url::fromRoute('jfcamp_matching.admin_form'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError('Matching fehlgeschlagen: ' . $e->getMessage());
      $url = Url::fromRoute('jfcamp_matching.admin_form')->toString();
      return new RedirectResponse($url);
    }
  }

}
