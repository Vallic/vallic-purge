<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vallic_purge\CdnPurger;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends the purges the platform could not take when they were made.
 */
#[QueueWorker(
  id: CdnPurger::QUEUE,
  title: new TranslatableMarkup('Vallic Cloud CDN purges'),
  cron: ['time' => 30],
)]
final class PurgeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The purger.
   */
  protected CdnPurger $purger;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->purger = $container->get('vallic_purge.purger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !in_array($data['scope'] ?? NULL, ['all', 'tag', 'url'], TRUE) || !is_array($data['values'] ?? NULL)) {
      return;
    }

    try {
      $this->purger->processQueued([
        'scope' => $data['scope'],
        'values' => array_values(array_map(strval(...), $data['values'])),
      ]);
    }
    catch (GuzzleException $e) {
      // Still unreachable: the item stays, and the rest would fail the same
      // way, so stop until the next cron run.
      throw new SuspendQueueException('The platform could not clear the CDN: ' . $e->getMessage(), 0, $e);
    }
  }

}
