<?php

namespace Drupal\jfcamp_matching\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\jfcamp_matching\Service\MatchingRunProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[QueueWorker(
  id: 'jfcamp_matching_runs',
  title: new TranslatableMarkup('JFCamp Matching runs'),
  cron: ['time' => 900],
)]
final class MatchingRunQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private MatchingRunProcessor $processor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('jfcamp_matching.run_processor'),
    );
  }

  public function processItem($data): void {
    $runId = (int) ($data['run_id'] ?? 0);
    if ($runId <= 0) {
      throw new \InvalidArgumentException('Queue-Item enthält keine gültige run_id.');
    }

    $this->processor->process($runId);
  }

}
