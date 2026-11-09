<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Drush\Commands;

use Drupal\vallic_purge\CdnPurger;
use Drupal\vallic_purge\PurgeOutcome;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clears the CDN by hand, or as a deploy's last step.
 */
final class VallicPurgeCommands extends DrushCommands {

  public function __construct(
    private readonly CdnPurger $purger,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('vallic_purge.purger'));
  }

  /**
   * Empties this environment's CDN cache.
   *
   * For the end of a deploy that changed templates, stylesheets or scripts:
   * every page looks different and no cache tag says so.
   */
  #[CLI\Command(name: 'vallic-purge:all', aliases: ['vp-all'])]
  #[CLI\Usage(name: 'drush vallic-purge:all', description: 'Start a release from an empty CDN cache.')]
  public function all(): int {
    return $this->report($this->purger->purgeAll());
  }

  /**
   * Clears every page carrying these cache tags.
   *
   * @param string $tags
   *   Drupal cache tags, separated by commas.
   */
  #[CLI\Command(name: 'vallic-purge:tags', aliases: ['vp-tags'])]
  #[CLI\Argument(name: 'tags', description: 'Drupal cache tags, separated by commas.')]
  #[CLI\Usage(name: 'drush vallic-purge:tags node:12,node_list', description: 'Clear the pages showing node 12, and every node listing.')]
  public function tags(string $tags): int {
    return $this->report($this->purger->invalidateTags(self::split($tags)));
  }

  /**
   * Clears these addresses.
   *
   * @param string $urls
   *   Absolute addresses, separated by commas.
   */
  #[CLI\Command(name: 'vallic-purge:urls', aliases: ['vp-urls'])]
  #[CLI\Argument(name: 'urls', description: 'Absolute addresses, separated by commas.')]
  #[CLI\Usage(name: 'drush vallic-purge:urls https://www.example.com/news', description: 'Clear one page.')]
  public function urls(string $urls): int {
    return $this->report($this->purger->purgeUrls(self::split($urls)));
  }

  /**
   * Says what became of a purge, and turns it into an exit code.
   *
   * Waiting for cron is not a failure: a deploy should not be rolled back
   * because the CDN will be cleared a few minutes late.
   */
  private function report(PurgeOutcome $outcome): int {
    match ($outcome) {
      PurgeOutcome::Sent => $this->io()->success(dt('The CDN was cleared.')),
      PurgeOutcome::Queued => $this->io()->warning(dt('The platform could not be reached. The purge waits in the queue, and cron sends it.')),
      PurgeOutcome::Refused => $this->io()->error(dt('The platform refused the purge. See the log.')),
      PurgeOutcome::Nothing => $this->io()->note(dt('Nothing to clear: this environment has no CDN, or nothing given reaches it.')),
    };

    return $outcome === PurgeOutcome::Refused ? Command::FAILURE : Command::SUCCESS;
  }

  /**
   * A comma-separated argument as a list.
   *
   * @return list<string>
   *   The items, trimmed, without empty ones.
   */
  private static function split(string $list): array {
    return array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $item): bool => $item !== ''));
  }

}
