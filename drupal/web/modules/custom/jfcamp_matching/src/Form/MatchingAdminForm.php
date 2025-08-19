<?php

namespace Drupal\jfcamp_matching\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Drupal\jfcamp_matching\Batch\ApplyAssignmentsBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

final class MatchingAdminForm extends ConfigFormBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    $instance = new self($container->get('jfcamp_matching.client'));
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  protected function getEditableConfigNames(): array {
    return ['jfcamp_matching.settings'];
  }

  public function getFormId(): string {
    return 'jfcamp_matching_admin_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cfg = $this->config('jfcamp_matching.settings');

    $form['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Matching Base URL'),
      '#default_value' => $cfg->get('endpoint') ?: '',
      '#description' => $this->t('z. B. http://matching:5001'),
      '#required' => FALSE,
    ];

    $form['info'] = [
      '#type' => 'item',
      '#markup' => '<em>Hinweis: Der Service bietet kein <code>/matching/run</code>. Beide Buttons rufen intern <code>/matching/dry-run</code> auf; der „Echtlauf“ schreibt zusätzlich die Zuteilungen in Drupal.</em>',
    ];

    // Mapping & Modus für das Schreiben nach Drupal
    $form['mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Feld‑Mapping & Zuweisungsmodus'),
      '#open' => TRUE,
    ];
    $form['mapping']['participant_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle Teilnehmer'),
      '#default_value' => $cfg->get('participant_bundle') ?: 'teilnehmer',
      '#required' => TRUE,
    ];
    $form['mapping']['workshop_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle Workshop'),
      '#default_value' => $cfg->get('workshop_bundle') ?: 'workshop',
      '#required' => TRUE,
    ];
    $form['mapping']['assignment_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Zuweisungsmodus'),
      '#options' => [
        'auto' => $this->t('Automatisch erkennen'),
        'per_slot' => $this->t('Pro Slot (field_slot_1..N)'),
        'multi' => $this->t('Ein Mehrfach‑Referenzfeld (z. B. field_zugewiesen)'),
      ],
      '#default_value' => $cfg->get('assignment_mode') ?: 'auto',
    ];
    $form['mapping']['slot_fields_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slot‑Feldpräfix'),
      '#default_value' => $cfg->get('slot_fields_prefix') ?: 'field_slot_',
      '#states' => ['visible' => [':input[name="assignment_mode"]' => ['value' => 'per_slot']]],
    ];
    $form['mapping']['num_slots'] = [
      '#type' => 'number',
      '#title' => $this->t('Anzahl Slots'),
      '#default_value' => (int) ($cfg->get('num_slots') ?? 3),
      '#min' => 1,
      '#states' => ['visible' => [':input[name="assignment_mode"]' => ['value' => 'per_slot']]],
    ];
    $form['mapping']['assigned_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mehrfach‑Referenzfeld'),
      '#default_value' => $cfg->get('assigned_field') ?: 'field_zugewiesen',
      '#states' => ['visible' => [':input[name="assignment_mode"]' => ['value' => 'multi']]],
    ];

    $form['actions']['dry'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dry‑Run (Simulation)'),
      '#submit' => ['::submitDryRun'],
      '#button_type' => 'secondary',
    ];

    $form['actions']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Echtlauf: in Drupal schreiben'),
      '#submit' => ['::submitRun'],
      '#button_type' => 'primary',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('jfcamp_matching.settings')
      ->set('endpoint', rtrim((string) $form_state->getValue('endpoint'), '/'))
      ->set('participant_bundle', (string) $form_state->getValue('participant_bundle'))
      ->set('workshop_bundle', (string) $form_state->getValue('workshop_bundle'))
      ->set('assignment_mode', (string) $form_state->getValue('assignment_mode'))
      ->set('slot_fields_prefix', (string) $form_state->getValue('slot_fields_prefix'))
      ->set('num_slots', (int) $form_state->getValue('num_slots'))
      ->set('assigned_field', (string) $form_state->getValue('assigned_field'))
      ->save();
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Einstellungen gespeichert.'));
  }

  public function submitDryRun(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    try {
      $res = $this->client->dryRun();
      $sum = $res['summary'] ?? [];
      $this->messenger()->addStatus($this->formatSummary('Dry‑Run OK', $sum));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Dry‑Run fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
  }

  /**
   * Startet den Batch direkt aus dem Submit-Handler.
   * Danach zeigt Drupal automatisch die Batch-Progress-Seite an.
   */
  public function submitRun(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);

    try {
      // 1) Ergebnis vom Service (Dry-Run = unsere Quelle)
      $result = $this->client->dryRun();
      $sum = $result['summary'] ?? [];
      $byParticipant = $result['by_participant'] ?? [];

      if (!is_array($byParticipant) || empty($byParticipant)) {
        $this->messenger()->addWarning($this->t('Keine Zuweisungen im Ergebnis.'));
        return;
      }

      // 2) Konfiguration für Schreiblogik
      $cfg = \Drupal::config('jfcamp_matching.settings');
      $config = [
        'participant_bundle' => $cfg->get('participant_bundle') ?? 'teilnehmer',
        'workshop_bundle' => $cfg->get('workshop_bundle') ?? 'workshop',
        'assignment_mode' => $cfg->get('assignment_mode') ?? 'auto',
        'slot_fields_prefix' => $cfg->get('slot_fields_prefix') ?? 'field_slot_',
        'num_slots' => (int) ($cfg->get('num_slots') ?? 3),
        'assigned_field' => $cfg->get('assigned_field') ?? 'field_zugewiesen',
      ];

      // 3) Batch setzen — KEIN batch_process() hier!
      // Im Form-Submit reicht batch_set(); Drupal zeigt automatisch die Progress-Seite.
      $batch = ApplyAssignmentsBatch::build($config, $byParticipant);
      batch_set($batch);

      $this->messenger()->addStatus($this->t(
        'Matching OK: @p TN, @a Zuteilungen. Schreibe in Drupal …',
        ['@p' => $sum['participants_total'] ?? 0, '@a' => $sum['assignments_total'] ?? 0]
      ));

      // optionaler Redirect-Zielort nach Batch-Ende:
      $form_state->setRedirectUrl(Url::fromRoute('jfcamp_matching.admin_form'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Matching fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
  }

  private function formatSummary(string $prefix, array $sum): string {
    return $this->t('@p: @tn TN, @as Zuteilungen, ohne Wünsche: @nw, alle gefüllt: @af, Restplätze: @rem, Happy: @hp', [
      '@p' => $prefix,
      '@tn' => $sum['participants_total'] ?? 0,
      '@as' => $sum['assignments_total'] ?? 0,
      '@nw' => $sum['participants_no_wishes'] ?? 0,
      '@af' => !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein',
      '@rem' => $sum['capacity_remaining_total'] ?? 0,
      '@hp' => $sum['happy_index'] ?? 'n/a',
    ]);
  }

}
