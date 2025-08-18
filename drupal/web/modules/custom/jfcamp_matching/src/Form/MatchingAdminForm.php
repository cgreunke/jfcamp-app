<?php

namespace Drupal\jfcamp_matching\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
      '#description' => $this->t('Basis-URL des Matching-Services (z. B. http://matching:5001). Leer lassen für Default aus settings.php'),
    ];

    $form['actions']['dry'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dry-Run (Simulation)'),
      '#submit' => ['::submitDryRun'],
      '#button_type' => 'secondary',
    ];

    $form['actions']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Matching jetzt ausführen'),
      '#submit' => ['::submitRun'],
      '#button_type' => 'primary',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('jfcamp_matching.settings')
      ->set('endpoint', rtrim((string) $form_state->getValue('endpoint'), '/'))
      ->save();
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Einstellungen gespeichert.'));
  }

  public function submitDryRun(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    try {
      $res = $this->client->dryRun();
      $sum = $res['summary'] ?? [];
      $this->messenger()->addStatus($this->t('Dry-Run OK: @p TN, @a Zuteilungen, ohne Wünsche: @nw, alle gefüllt: @af, Restplätze gesamt: @rem', [
        '@p' => $sum['participants_total'] ?? 0,
        '@a' => $sum['assignments_total'] ?? 0,
        '@nw' => $sum['participants_no_wishes'] ?? 0,
        '@af' => !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein',
        '@rem' => $sum['capacity_remaining_total'] ?? 0,
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Dry-Run fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
  }

  public function submitRun(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    try {
      $res = $this->client->run();
      $sum = $res['summary'] ?? [];
      $patched = (int) ($res['patched'] ?? 0);
      $this->messenger()->addStatus($this->t('Matching OK: @p TN, @a Zuteilungen, geändert: @patched, alle gefüllt: @af', [
        '@p' => $sum['participants_total'] ?? 0,
        '@a' => $sum['assignments_total'] ?? 0,
        '@patched' => $patched,
        '@af' => !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein',
      ]));
      if (!empty($res['patch_errors'])) {
        $max = 5;
        $shown = array_slice($res['patch_errors'], 0, $max);
        $this->messenger()->addWarning($this->t('PATCH-Fehler (zeige @n von @t). Details im Log.', ['@n' => count($shown), '@t' => count($res['patch_errors'])]));
        foreach ($shown as $line) {
          $this->messenger()->addWarning(substr((string) $line, 0, 300));
        }
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Matching fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
  }

}
