<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client für den externen Matching-Service (Flask).
 * Der Service hat KEIN /matching/run → wir verwenden für "Run" den Dry-Run.
 */
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
      $res = $this->http->request('GET', $url, ['timeout' => 60, 'headers' => ['Accept' => 'application/json']]);
      $data = json_decode((string) $res->getBody(), true);
      if (!is_array($data)) {
        throw new \RuntimeException('Ungültige JSON-Antwort von /matching/stats.');
      }
      return $data;
    } catch (GuzzleException $e) {
      $this->logger->error('HTTP Fehler /matching/stats: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Matching-Service nicht erreichbar: ' . $e->getMessage(), 0, $e);
    }
  }

  /** POST /matching/dry-run */
  public function dryRun(): array {
    return $this->post('/matching/dry-run');
  }

  /**
   * "Run" = Dry-Run (Service hat keinen echten /matching/run).
   * Wir behalten die Methode bei, damit Form/Drush weiter funktionieren.
   */
  public function run(): array {
    $this->logger->notice('MatchingClient::run nutzt /matching/dry-run (kein /matching/run im Service vorhanden).');
    return $this->post('/matching/dry-run');
  }

  private function post(string $path): array {
    $url = $this->baseUrl() . $path;
    try {
      $res = $this->http->request('POST', $url, [
        'timeout' => 60,
        'headers' => ['Accept' => 'application/json'],
        'json' => new \stdClass(),
      ]);
      $data = json_decode((string) $res->getBody(), true);
      if (!is_array($data)) {
        throw new \RuntimeException('Ungültige JSON-Antwort vom Matching-Service.');
      }
      return $data;
    } catch (GuzzleException $e) {
      $this->logger->error('HTTP Fehler @path: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
      throw new \RuntimeException('Matching-Service nicht erreichbar: ' . $e->getMessage(), 0, $e);
    }
  }
}
