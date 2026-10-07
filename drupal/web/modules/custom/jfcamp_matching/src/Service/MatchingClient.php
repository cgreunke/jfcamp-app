<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class MatchingClient {

  public function __construct(
    private ClientInterface $http,
    private ConfigFactoryInterface $configFactory,
    private LoggerInterface $logger,
    private Settings $settings,
  ) {}

  private function baseUrl(): string {
    $cfg = $this->configFactory->get('jfcamp_matching.settings');
    $default = $this->settings->get('jfcamp_matching_default_endpoint', 'http://matching:5001');
    return rtrim($cfg->get('endpoint') ?: $default, '/');
  }

  /** GET /matching/stats */
  public function stats(): array {
    $url = $this->baseUrl() . '/matching/stats';
    try {
      $res = $this->http->request('GET', $url, [
        'timeout' => 60,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $data = json_decode((string) $res->getBody(), TRUE);
      if (!is_array($data)) {
        throw new \RuntimeException('Ungültige JSON-Antwort von /matching/stats.');
      }
      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('HTTP Fehler /matching/stats: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Matching-Service nicht erreichbar: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * POST /matching/dry-run.
   *
   * Produktiv werden keine Algorithmus-Overrides an den Matching-Service
   * übergeben. num_wishes und num_assign stammen aus matching_config.
   */
  public function dryRun(?int $runId = NULL): array {
    $headers = [];
    if ($runId !== NULL && $runId > 0) {
      $headers['X-JFCamp-Run-Id'] = (string) $runId;
    }
    return $this->post('/matching/dry-run', $headers);
  }

  /**
   * POST /matching/input-fingerprint.
   *
   * Lädt und normalisiert ausschließlich den aktuellen Matching-Eingabestand.
   * Es wird kein Matching berechnet und nichts in Drupal geschrieben.
   */
  public function inputFingerprint(): array {
    $data = $this->post('/matching/input-fingerprint');
    $fingerprint = strtolower(trim((string) ($data['input_fingerprint'] ?? '')));
    $version = (int) ($data['input_fingerprint_version'] ?? 0);

    if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
      throw new \RuntimeException('Matching-Service lieferte keinen gültigen aktuellen Input-Fingerprint.');
    }
    if ($version < 1) {
      throw new \RuntimeException('Matching-Service lieferte keine gültige aktuelle Fingerprint-Version.');
    }

    return [
      'input_fingerprint' => $fingerprint,
      'input_fingerprint_version' => $version,
    ];
  }

  /**
   * Liest den flüchtigen Fortschritt eines aktuell laufenden Solver-Requests.
   */
  public function progress(int $runId): array {
    $url = $this->baseUrl() . '/matching/progress/' . $runId;
    try {
      $res = $this->http->request('GET', $url, [
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $data = json_decode((string) $res->getBody(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (GuzzleException $e) {
      $this->logger->warning(
        'Fortschritt für Matching-Run #@run konnte nicht gelesen werden: @msg',
        ['@run' => $runId, '@msg' => $e->getMessage()],
      );
      return [];
    }
  }

  /**
   * Legacy-Kompatibilität für vorhandene Drush-/Altaufrufe.
   *
   * Auch dieser Aufruf berechnet lediglich einen Dry-Run. Er schreibt keine
   * Zuteilungen in Drupal. Die produktive Oberfläche nutzt diese Methode nicht.
   */
  public function run(array $params = []): array {
    $this->logger->notice('MatchingClient::run ist Legacy-Kompatibilität und nutzt /matching/dry-run.');
    return $this->post('/matching/dry-run');
  }

  /** Legacy-Kompatibilität für den derzeit noch vorhandenen ExportProxyController. */
  public function exportUrl(string $path): string {
    return $this->baseUrl() . $path;
  }

  private function post(string $path, array $extraHeaders = []): array {
    $url = $this->baseUrl() . $path;
    try {
      $res = $this->http->request('POST', $url, [
        // Der Aufruf kommt aus dem separaten Queue-Worker und darf deshalb
        // mehrere Minuten laufen, ohne einen Browser-Request zu blockieren.
        'timeout' => 900,
        'headers' => array_merge([
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ], $extraHeaders),
        'json' => new \stdClass(),
      ]);
      $data = json_decode((string) $res->getBody(), TRUE);
      if (!is_array($data)) {
        throw new \RuntimeException('Ungültige JSON-Antwort vom Matching-Service.');
      }
      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('HTTP Fehler @path: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
      throw new \RuntimeException('Matching-Service nicht erreichbar: ' . $e->getMessage(), 0, $e);
    }
  }

}
