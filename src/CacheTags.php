<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

/**
 * How cache tags are written on a response, and asked for in a purge.
 *
 * The two have to agree, which is why they live together.
 *
 * **One string, matched with wildcards.** The CDN keeps a response's
 * `CDN-Tag` header as a single value rather than a list, so a page carrying
 * `node:1 node_list` is not found by a purge for `node:1`. It is found by
 * `* node:1 *` — so the header is written with a space before and after every
 * tag, and a colon at each end so no layer in between trims the outer ones.
 *
 * **Short, not readable.** A Drupal page carries dozens of tags and a busy one
 * hundreds, which is enough to overflow the buffer a web server keeps for a
 * response's headers: the page then fails with a bad gateway rather than being
 * cached. Each tag is written as eight hexadecimal characters instead. Two
 * tags sharing a hash means a purge of one also clears pages carrying the
 * other, which costs a cache miss and nothing more.
 */
final class CacheTags {

  /**
   * The value of the `CDN-Tag` header for a response carrying these tags.
   *
   * @param string[] $tags
   *   Drupal's cache tags.
   */
  public static function header(array $tags): string {
    $hashes = array_values(array_unique(array_map(self::hash(...), $tags)));

    return ': ' . implode(' ', $hashes) . ' :';
  }

  /**
   * What a purge asks for to clear every response carrying this tag.
   */
  public static function pattern(string $tag): string {
    return '* ' . self::hash($tag) . ' *';
  }

  /**
   * One tag, as the header carries it.
   */
  public static function hash(string $tag): string {
    return hash('crc32b', $tag);
  }

}
