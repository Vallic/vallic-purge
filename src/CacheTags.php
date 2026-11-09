<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

use Drupal\cache_purifier\OutboundTags;
use Valicm\BunnyToolkit\CacheTag\CdnTagHeader;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\CacheTag\TagFormat;

/**
 * Which cache tags reach the CDN, and how they are written and purged.
 *
 * Cache Purifier's outbound policy decides the tags: its blocklist, aliases,
 * buckets and order. The response header and the purge both go through it
 * and through the same format, so a purged tag always matches the header it
 * was written into.
 *
 * The format is bunny-toolkit's, the one the bunny.net module uses. The CDN
 * keeps a response's `CDN-Tag` header as one string of at most 1024 bytes,
 * so each tag is hashed to five characters, the tokens go between guards
 * (`: k3XqA 7m-2d :`) and a purge matches one with wildcards (`* k3XqA *`).
 * A full header stops at 1024 bytes rather than being cut by the CDN, and
 * Cache Purifier puts list and entity tags first, so what is left out is
 * the least useful.
 *
 * The platform passes purge values to the CDN unchanged, so this format is
 * the site's choice alone.
 *
 * Until Cache Purifier is installed, as on a site updating from 1.x, nothing
 * is written or purged.
 */
final class CacheTags {

  /**
   * Characters per hashed tag.
   *
   * 170 tags fit, and a purge of one also clears a full page it is not on
   * about once in six million.
   */
  public const int HASH_LENGTH = 5;

  public function __construct(
    private readonly ?OutboundTags $outbound = NULL,
  ) {}

  /**
   * The format the header is written in and tags are purged with.
   */
  public function format(): TagFormat {
    return new HashedTagList(self::HASH_LENGTH);
  }

  /**
   * The CDN-Tag header for a response the CDN may cache.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   */
  public function header(iterable $tags): CdnTagHeader {
    return $this->format()->header($this->outbound?->forHeader($tags) ?? []);
  }

  /**
   * What a purge asks for to clear every response carrying these tags.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   *
   * @return list<string>
   *   One wildcard pattern per tag that reaches the CDN, without duplicates.
   */
  public function purgeValues(iterable $tags): array {
    $format = $this->format();
    $values = array_map($format->purgeValue(...), $this->outbound?->forPurge($tags) ?? []);

    return array_values(array_unique($values));
  }

}
