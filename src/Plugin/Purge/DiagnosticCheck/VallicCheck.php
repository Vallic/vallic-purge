<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Plugin\Purge\DiagnosticCheck;

use Drupal\purge\Plugin\Purge\DiagnosticCheck\DiagnosticCheckBase;
use Drupal\purge\Plugin\Purge\DiagnosticCheck\DiagnosticCheckInterface;
use Drupal\vallic_purge\Endpoint;

/**
 * Says whether purges are going anywhere, on the status report.
 *
 * With no CDN the purger marks invalidations done without sending them — the
 * right answer, and a silent one. This is where it is said out loud, and why:
 * on the platform with the CDN off, or not on the platform at all.
 *
 * Never an error. An error stops purge for every purger on the site, and
 * having no CDN is not a reason to stop anybody else's.
 *
 * @PurgeDiagnosticCheck(
 *   id = "vallic",
 *   title = @Translation("Vallic Cloud CDN"),
 *   description = @Translation("Whether this environment has a CDN to clear."),
 *   dependent_queue_plugins = {},
 *   dependent_purger_plugins = {"vallic"}
 * )
 */
final class VallicCheck extends DiagnosticCheckBase implements DiagnosticCheckInterface {

  /**
   * {@inheritdoc}
   */
  public function run() {
    $endpoint = Endpoint::fromEnvironment();

    if ($endpoint->isAvailable()) {
      $this->value = $endpoint->url();
      $this->recommendation = $this->t('Purges go to the platform, which clears this environment’s CDN.');
      return self::SEVERITY_OK;
    }

    if ($endpoint->isOnPlatform()) {
      $this->recommendation = $this->t('This environment has no CDN, so there is nothing to clear: invalidations are marked done without being sent. Turn the CDN on for the project in the Vallic Cloud console.');
      return self::SEVERITY_WARNING;
    }

    $this->recommendation = $this->t('Not running on Vallic Cloud. Invalidations are marked done without being sent.');
    return self::SEVERITY_INFO;
  }

}
