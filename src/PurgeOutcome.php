<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

/**
 * What became of a purge.
 */
enum PurgeOutcome {

  // The platform took all of it.
  case Sent;

  // The platform could not be reached, or failed: the rest waits for cron.
  case Queued;

  // The platform refused it. Sending it again would be refused again.
  case Refused;

  // No CDN to clear, or nothing that reaches it: nothing was sent.
  case Nothing;

}
