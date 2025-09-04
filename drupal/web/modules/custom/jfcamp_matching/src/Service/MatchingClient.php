<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

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

  /** POST /matching/dry-run (mit optionalem Payload) */
  public function dryRun(array $params = []): array {
    return $this->post('/matching/dry-run', $params);
  }

  /**
   * "Run" = erneut /matching/dry-run mit denselben Parametern.
   * (Es gibt weiterhin kein echtes /matching/run im Service.)
   */
  public function run(array $params = []): array {
    $this->logger->notice('MatchingClient::run nutzt /matching/dry-run (kein /matching/run im Service vorhanden).');
    return $this->post('/matching/dry-run', $params);
  }

  /** Für ExportProxyController: zusammengesetzte URL zu CSV */
  public function exportUrl(string $path): string {
    return $this->baseUrl() . $path;
  }

  private function post(string $path, array $payload = []): array {
    $url = $this->baseUrl() . $path;
    try {
      $res = $this->http->request('POST', $url, [
        'timeout' => 120,
        'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        'json' => empty($payload) ? new \stdClass() : $payload,
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
