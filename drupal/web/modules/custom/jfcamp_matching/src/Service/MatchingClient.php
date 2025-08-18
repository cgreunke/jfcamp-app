<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class MatchingClient {

  private ClientInterface $http;
  private string $baseUrl;
  private LoggerInterface $logger;

  public function __construct(ClientInterface $http, ConfigFactoryInterface $configFactory, LoggerInterface $logger) {
    $this->http = $http;
    $cfg = $configFactory->get('jfcamp_matching.settings');
    $default = \Drupal::service('settings')->get('jfcamp_matching_default_endpoint', 'http://matching:5001');
    $this->baseUrl = rtrim($cfg->get('endpoint') ?: $default, '/');
    $this->logger = $logger;
  }

  public function run(): array {
    return $this->post('/matching/run');
  }

  public function dryRun(): array {
    return $this->post('/matching/dry-run');
  }

  private function post(string $path): array {
    $url = $this->baseUrl . $path;
    try {
      $res = $this->http->request('POST', $url, [
        'timeout' => 30,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);
      $body = (string) $res->getBody();
      $data = json_decode($body, true);
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
