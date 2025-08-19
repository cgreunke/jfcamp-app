<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportProxyController extends ControllerBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_matching.client'));
  }

  /**
   * Streamt generische CSVs vom Matching-Service:
   * - slots.csv, regions.csv, overview.csv, pending.csv
   */
  public function streamGeneric(string $type): StreamedResponse {
    $map = [
      'slots'   => '/export/slots.csv',
      'regions' => '/export/regions.csv',
      'overview'=> '/export/overview.csv',
      'pending' => '/export/pending.csv',
    ];
    if (!isset($map[$type])) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $path = $map[$type];
    $filename = 'matching-' . $type . '.csv';

    return $this->proxyCsv($path, $filename);
  }

  /**
   * Streamt CSV für einen Slot: /export/slot/{slot}.csv
   */
  public function streamSlot(int $slot): StreamedResponse {
    $slot = max(1, (int) $slot);
    $path = '/export/slot/' . $slot . '.csv';
    $filename = 'matching-slot-' . $slot . '.csv';

    return $this->proxyCsv($path, $filename);
  }

  private function proxyCsv(string $path, string $filename): StreamedResponse {
    $url = $this->client->exportUrl($path);
    $response = new StreamedResponse(function() use ($url) {
      try {
        $client = \Drupal::httpClient();
        $res = $client->request('GET', $url, [
          'timeout' => 60,
          'headers' => ['Accept' => 'text/csv,application/octet-stream,*/*'],
        ]);
        // Direkt ausgeben:
        echo (string) $res->getBody();
      } catch (\Throwable $e) {
        // Fallback: menschenlesbare Fehlermeldung als CSV-Zeile
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        echo $bom . "error\n" . str_replace(["\r","\n"], ' ', $e->getMessage()) . "\n";
      }
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->setPrivate(); // nicht cachen
    return $response;
  }
}
