<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;

/**
 * Collects the cache tags a request invalidates and purges them at its end.
 *
 * Saving one entity invalidates the same tags several times, so they are
 * gathered and sent once, after the response has gone to the browser.
 *
 * Registered as a cache tags invalidator, so Cache Purifier's rules can name
 * it as their target: `vallic_purge.cache_tags_invalidator`.
 */
final class PurgeCollector implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * Tags invalidated in this request, as keys.
   *
   * @var array<string, true>
   */
  private array $tags = [];

  /**
   * Constructs the collector.
   *
   * @param \Closure(): \Drupal\vallic_purge\CdnPurger $purger
   *   Returns the purger.
   */
  public function __construct(
    private readonly \Closure $purger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    foreach ($tags as $tag) {
      $this->tags[$tag] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if ($this->tags === []) {
      return;
    }
    $tags = array_map(strval(...), array_keys($this->tags));
    $this->tags = [];

    ($this->purger)()->invalidateTags($tags);
  }

}
