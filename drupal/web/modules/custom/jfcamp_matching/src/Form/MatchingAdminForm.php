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
      '#default_value' => $cfg->get('endpoint') ?: 'http://matching:5001',
      '#description' => $this->t('Basis-URL des Matching-Services (z. B. http://matching:5001).'),
      '#required' => TRUE,
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
    // Speichern der Endpoint-URL.
    $this->configFactory->getEditable('jfcamp_matching.settings')
      ->set('endpoint', rtrim((string) $form_state->getValue('endpoint'), '/'))
      ->save();
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Einstellungen gespeichert.'));
  }

  public function submitDryRun(array &$form, FormStateInterface $form_state): void {
    // Erst speichern, damit der Client mit dem neuen Endpoint arbeitet.
    $this->submitForm($form, $form_state);
    try {
      $res = $this->client->dryRun();
      $sum = $res['summary'] ?? [];
      $this->messenger()->addStatus($this->t('Dry-Run OK: @p Teilnehmer, @a Zuteilungen, keine Wünsche: @nw. (Seed @seed)', [
        '@p' => $sum['participants_total'] ?? 0,
        '@a' => $sum['assignments_total'] ?? 0,
        '@nw' => $sum['participants_no_wishes'] ?? 0,
        '@seed' => $sum['seed'] ?? 'n/a',
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Dry-Run fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
  }

    public function submitRun(array &$form, FormStateInterface $form_state): void {
    // 1) Einstellungen speichern, damit evtl. geänderter Endpoint gilt.
    $this->submitForm($form, $form_state);

    try {
        // 2) ECHTER RUN: POST /matching/run
        $res = $this->client->run();

        // 3) Zusammenfassung zeigen
        $sum = $res['summary'] ?? [];
        $patched = (int) ($res['patched'] ?? 0);
        $msg = $this->t('Matching OK: @p Teilnehmer, @a Zuteilungen, keine Wünsche: @nw, geänderte Teilnehmer: @patched. (Seed @seed)', [
        '@p' => $sum['participants_total'] ?? 0,
        '@a' => $sum['assignments_total'] ?? 0,
        '@nw' => $sum['participants_no_wishes'] ?? 0,
        '@patched' => $patched,
        '@seed' => $sum['seed'] ?? 'n/a',
        ]);
        $this->messenger()->addStatus($msg);

        // 4) Patch-Fehler ggf. anzeigen + loggen (gekürzt)
        if (!empty($res['patch_errors'])) {
        $max = 5;
        $shown = array_slice($res['patch_errors'], 0, $max);
        $this->messenger()->addWarning($this->t('Einige PATCH-Fehler aufgetreten (zeige @n von @total). Details im Log.', [
            '@n' => count($shown), '@total' => count($res['patch_errors'])
        ]));
        foreach ($shown as $line) {
            $this->messenger()->addWarning(substr((string) $line, 0, 300));
        }
        $this->logger('jfcamp_matching')->warning('PATCH errors: @errs', ['@errs' => print_r($res['patch_errors'], TRUE)]);
        }
    }
    catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Matching fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
    }

}
