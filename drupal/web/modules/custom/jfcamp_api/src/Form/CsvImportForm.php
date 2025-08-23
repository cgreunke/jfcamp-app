<?php

namespace Drupal\jfcamp_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Admin-Formular zum CSV-Import (nur UI, kein Drush).
 *
 * Unterstützte Typen:
 *  - workshops:  title,max,ext_id (optional),room (optional),kurzbeschreibung (optional)
 *  - teilnehmer: code,vorname,nachname,regionalverband
 *  - wuensche:   code,w1,w2,w3,...  (Werte = Workshop-UUID ODER exakter Workshop-Titel)
 *
 * Hinweise:
 *  - UTF-8, Trennzeichen Komma ODER Semikolon (auswählbar)
 *  - Erste Zeile = Header (Spaltennamen wie oben)
 *  - Idempotent (Upsert), d.h. vorhandene Einträge werden aktualisiert
 *  - Wünsche werden auf die in matching_config hinterlegte Anzahl begrenzt
 */
class CsvImportForm extends FormBase {

  public function getFormId(): string {
    return 'jfcamp_api_csv_import_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Import-Typ'),
      '#required' => TRUE,
      '#options' => [
        'workshops' => $this->t('Workshops'),
        'teilnehmer' => $this->t('Teilnehmer'),
        'wuensche' => $this->t('Wünsche'),
      ],
    ];

    $form['csv'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV-Datei'),
      '#description' => $this->t('UTF-8, erste Zeile = Header.'),
      '#required' => TRUE,
    ];

    $form['delimiter'] = [
      '#type' => 'select',
      '#title' => $this->t('Trennzeichen'),
      '#options' => [',' => ',', ';' => ';'],
      '#default_value' => ';',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import starten'),
      '#button_type' => 'primary',
    ];

    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('CSV-Formate (Beispiele)'),
      '#open' => FALSE,
      '#markup' => '<pre>'.
        "workshops:  title,max,ext_id,room,kurzbeschreibung\n".
        "teilnehmer: code,vorname,nachname,regionalverband\n".
        "wuensche:   code,w1,w2,w3\n".
        "</pre>",
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $path = $this->getUploadedFilePath();
    if (!$path) {
      $form_state->setErrorByName('csv', $this->t('Bitte eine CSV-Datei auswählen.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $type = (string) $form_state->getValue('type');
    $delim = (string) $form_state->getValue('delimiter');
    $path = $this->getUploadedFilePath();

    if (!$path) {
      $this->messenger()->addError($this->t('Keine Datei gefunden.'));
      return;
    }

    [$ok, $fail] = $this->processCsv($type, $path, $delim);
    $this->messenger()->addStatus($this->t('Import abgeschlossen: @ok Zeilen OK, @fail Fehler.', ['@ok' => $ok, '@fail' => $fail]));
  }

  private function getUploadedFilePath(): ?string {
    // Standard PHP-Upload; Datei wird nur gelesen (nicht gespeichert).
    if (!empty($_FILES['files']['name']['csv']) && is_uploaded_file($_FILES['files']['tmp_name']['csv'])) {
      return $_FILES['files']['tmp_name']['csv'];
    }
    return NULL;
    // Hinweis: Für sehr große Dateien könnte man auf das File-Entity umstellen und im privaten FS speichern.
  }

  /**
   * @return array{0:int,1:int} [ok, fail]
   */
  private function processCsv(string $type, string $path, string $delimiter): array {
    $ok = 0; $fail = 0;

    if (($h = fopen($path, 'r')) === FALSE) {
      $this->messenger()->addError($this->t('Kann CSV nicht öffnen.'));
      return [0, 1];
    }

    $header = fgetcsv($h, 0, $delimiter);
    if ($header === FALSE) {
      fclose($h);
      $this->messenger()->addError($this->t('Leere CSV.'));
      return [0, 1];
    }
    $header = $this->normalizeHeader($header);

    $rownum = 1;
    while (($row = fgetcsv($h, 0, $delimiter)) !== FALSE) {
      $rownum++;
      $row = array_map('trim', $row);
      $data = array_combine($header, $row);
      if ($data === FALSE) { $fail++; continue; }

      try {
        switch ($type) {
          case 'workshops':
            $this->upsertWorkshop($data);
            break;
          case 'teilnehmer':
            $this->upsertTeilnehmer($data);
            break;
          case 'wuensche':
            $this->upsertWuensche($data);
            break;
          default:
            throw new \RuntimeException("Unbekannter Typ: $type");
        }
        $ok++;
      } catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Zeile @n: @msg', ['@n' => $rownum, '@msg' => $e->getMessage()]));
        $fail++;
      }
    }
    fclose($h);
    return [$ok, $fail];
  }

  /**
   * Workshops upsert inkl. optionaler Felder:
   * - field_maximale_plaetze
   * - field_room
   * - field_ext_id
   * - field_kurzbeschreibung
   */
  private function upsertWorkshop(array $data): void {
    // Pflichtfeld (Synonyme erlaubt)
    $title = $this->pick($data, ['title', 'titel', 'name']);
    if ($title === null || $title === '') {
      throw new \InvalidArgumentException('Spalte "title" (oder "titel"/"name") fehlt/leer.');
    }

    // Optionale Felder (mehrere mögliche Header-Bezeichnungen)
    $max  = $this->pick($data, ['max', 'kapazitaet', 'capacity']);
    $room = $this->pick($data, ['room', 'raum', 'ort', 'location']);
    $ext  = $this->pick($data, ['ext_id', 'ext id', 'extid', 'external_id', 'external id']);
    $kurz = $this->pick($data, ['kurzbeschreibung', 'beschreibung', 'short', 'short_description']);

    // vorhandenen Workshop per exakt gleichem Titel finden (Upsert)
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'workshop')
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();

    $n = $ids
      ? Node::load(reset($ids))
      : Node::create(['type' => 'workshop', 'title' => $title, 'status' => 1]);

    // Max (Ganzzahl oder NULL)
    if ($max !== null && $max !== '') {
      $n->set('field_maximale_plaetze', (int) $max);
    } else {
      $n->set('field_maximale_plaetze', NULL);
    }

    // Raum/Ort (Text)
    if ($room !== null) {
      $n->set('field_room', $room);
    }

    // Externe ID (Text)
    if ($ext !== null) {
      $n->set('field_ext_id', $ext);
    }

    // Kurzbeschreibung (Text (unformatiert, lang))
    if ($kurz !== null) {
      // Für "Text (unformatiert, lang)" reicht ein String:
      $n->set('field_kurzbeschreibung', $kurz);
      // Falls zukünftig ein Text-mit-Format-Feld genutzt wird:
      // $n->set('field_kurzbeschreibung', ['value' => $kurz, 'format' => 'plain_text']);
    }

    $n->save();
  }

  private function upsertTeilnehmer(array $data): void {
    $code = (string)($data['code'] ?? '');
    if ($code === '') throw new \InvalidArgumentException('Spalte "code" fehlt/leer.');
    $vor = (string)($data['vorname'] ?? '');
    $nach = (string)($data['nachname'] ?? '');
    $rv = (string)($data['regionalverband'] ?? '');

    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type','teilnehmer')->condition('field_code',$code)
      ->range(0,1)->execute();

    $n = $ids ? Node::load(reset($ids)) : Node::create(['type'=>'teilnehmer','title'=> trim("$vor $nach"), 'status'=>1]);
    $n->set('field_code', $code);
    if ($vor !== '') $n->set('field_vorname', $vor);
    if ($nach !== '') $n->set('field_name', $nach);
    if ($rv !== '') $n->set('field_regionalverband', $rv);
    $n->save();
  }

  private function upsertWuensche(array $data): void {
    $code = (string)($data['code'] ?? '');
    if ($code === '') throw new \InvalidArgumentException('Spalte "code" fehlt/leer.');

    // Teilnehmer laden
    $teilnehmerIds = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type','teilnehmer')->condition('field_code',$code)
      ->range(0,1)->execute();
    if (!$teilnehmerIds) throw new \RuntimeException("Teilnehmer-Code unbekannt: $code");
    $teilnehmer = Node::load(reset($teilnehmerIds));

    // matching_config (neueste, published)
    $cfgIds = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type','matching_config')->condition('status',1)
      ->sort('created','DESC')->range(0,1)->execute();
    if (!$cfgIds) throw new \RuntimeException('Keine matching_config veröffentlicht.');
    $cfg = Node::load(reset($cfgIds));
    $maxWishes = (int)($cfg->get('field_num_wuensche')->value ?? 0) ?: 5;

    // alle Spalten außer "code" → Wunschwerte
    $wishVals = [];
    foreach ($data as $k=>$v) {
      if ($k === 'code') continue;
      if ($v === '' || $v === NULL) continue;
      $wishVals[] = (string)$v;
    }

    // Auflösen: zuerst als UUID, sonst exakter Titel
    $wishUuids = [];
    foreach ($wishVals as $val) {
      $uuid = NULL;
      $q = \Drupal::entityQuery('node')->accessCheck(FALSE)
        ->condition('type','workshop')->condition('uuid',$val)
        ->range(0,1)->execute();
      if ($q) {
        $uuid = Node::load(reset($q))->uuid();
      } else {
        $q = \Drupal::entityQuery('node')->accessCheck(FALSE)
          ->condition('type','workshop')->condition('title',$val)
          ->range(0,1)->execute();
        if ($q) $uuid = Node::load(reset($q))->uuid();
      }
      if ($uuid && !in_array($uuid, $wishUuids, TRUE)) $wishUuids[] = $uuid;
    }

    $wishUuids = array_slice($wishUuids, 0, $maxWishes);
    if (empty($wishUuids)) throw new \RuntimeException('Keine gültigen Workshops in dieser Zeile.');

    // Wunsch-Node upsert (1 pro Teilnehmer)
    $wunschIds = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type','wunsch')->condition('field_teilnehmer',$teilnehmer->id())
      ->range(0,1)->execute();
    $wunsch = $wunschIds ? Node::load(reset($wunschIds)) : Node::create(['type'=>'wunsch','title'=>'Wunsch: '.$teilnehmer->label(),'status'=>1]);

    $wunsch->set('field_teilnehmer', $teilnehmer);

    // Refs in Reihenfolge setzen
    $items = [];
    foreach ($wishUuids as $uuid) {
      $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
        ->condition('type','workshop')->condition('uuid',$uuid)
        ->range(0,1)->execute();
      if ($ids) $items[] = ['target_id' => reset($ids)];
    }
    if (!$items) throw new \RuntimeException('Interner Fehler beim Setzen der Referenzen.');

    $wunsch->set('field_wuensche', $items);
    $wunsch->save();
  }

  /* -------------------- Helpers -------------------- */

  /**
   * Header vereinheitlichen (lowercase, trim).
   */
  private function normalizeHeader(array $header): array {
    return array_map(fn($h) => mb_strtolower(trim((string)$h)), $header);
  }

  /**
   * Gibt den ersten nicht-leeren Wert aus $data für die angegebenen Keys zurück
   * (case-insensitiv), sonst NULL.
   */
  private function pick(array $data, array $keys): ?string {
    foreach ($keys as $k) {
      $k = mb_strtolower($k);
      if (array_key_exists($k, $data)) {
        $v = trim((string)$data[$k]);
        if ($v !== '') return $v;
      }
    }
    return null;
  }

}
