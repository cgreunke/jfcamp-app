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

  public function run(): array {
    return $this->post('/matching/run');
  }

  public function dryRun(): array {
    return $this->post('/matching/dry-run');
  }

  public function exportUrl(string $path): string {
    return $this->baseUrl() . $path;
  }

  private function post(string $path): array {
    $url = $this->baseUrl() . $path;
    try {
      $res = $this->http->request('POST', $url, [
        'timeout' => 45,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $data = json_decode((string) $res->getBody(), true);
      if (!is_array($data)) {
        throw new \RuntimeException('Ungültige JSON-Antwort vom Matching-Service.');
      }
      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('HTTP Fehler beim Matching-Service: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Matching-Service nicht erreichbar: ' . $e->getMessage(), 0, $e);
    }
  }

}
