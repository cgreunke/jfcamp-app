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
  public function dryRun(): array {
    return $this->post('/matching/dry-run');
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

  private function post(string $path): array {
    $url = $this->baseUrl() . $path;
    try {
      $res = $this->http->request('POST', $url, [
        // Der Aufruf kommt aus dem separaten Queue-Worker und darf deshalb
        // mehrere Minuten laufen, ohne einen Browser-Request zu blockieren.
        'timeout' => 900,
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
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
