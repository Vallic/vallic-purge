<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\vallic_purge\CdnPurger;
use Drupal\vallic_purge\Endpoint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A site coming from 1.x gets Cache Purifier and one empty CDN.
 */
#[Group('vallic_purge')]
#[RunTestsInSeparateProcesses]
final class UpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'vallic_purge'];

  /**
   * The update installs Cache Purifier and clears everything once.
   *
   * The relay's socket is not there, so the clear waits for cron, which is
   * what happens on a deploy where the relay restarts at the same time.
   * Through the environment, as on the platform: installing a module builds
   * the container again, and anything set on the old one is gone.
   */
  public function testUpdateInstallsCachePurifierAndClearsOnce(): void {
    putenv(Endpoint::URL . '=http://localhost/purge');
    putenv(Endpoint::TOKEN . '=a-token');
    putenv(Endpoint::SOCKET . '=' . $this->siteDirectory . '/no-relay.sock');

    try {
      $this->assertFalse($this->container->get('module_handler')->moduleExists('cache_purifier'));

      $this->container->get('module_handler')->loadInclude('vallic_purge', 'install');
      vallic_purge_update_11001();

      $this->assertTrue($this->container->get('module_handler')->moduleExists('cache_purifier'));
      $queue = $this->container->get('queue')->get(CdnPurger::QUEUE);
      $this->assertSame(1, $queue->numberOfItems());
      $item = $queue->claimItem();
      $this->assertInstanceOf(\stdClass::class, $item);
      $this->assertSame(['scope' => 'all', 'values' => []], $item->data);
    }
    finally {
      putenv(Endpoint::URL);
      putenv(Endpoint::TOKEN);
      putenv(Endpoint::SOCKET);
    }
  }

}
