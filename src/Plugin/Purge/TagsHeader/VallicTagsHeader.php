<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Plugin\Purge\TagsHeader;

use Drupal\purge\Plugin\Purge\TagsHeader\TagsHeaderBase;
use Drupal\vallic_purge\CacheTags;
use Drupal\vallic_purge\Endpoint;

/**
 * Puts a response's cache tags where the CDN reads them.
 *
 * Only while the Vallic purger is configured, and only where there is a CDN
 * to read it: off the platform, or with the CDN off, a response carries no
 * header nothing would ever read. See CacheTags for its shape.
 *
 * @PurgeTagsHeader(
 *   id = "vallic",
 *   header_name = "CDN-Tag",
 *   dependent_purger_plugins = {"vallic"},
 * )
 */
final class VallicTagsHeader extends TagsHeaderBase {

  /**
   * {@inheritdoc}
   */
  public function getValue(array $tags) {
    return CacheTags::header($tags);
  }

  /**
   * {@inheritdoc}
   *
   * Asked on every response, and answered from the environment, which is two
   * lookups in memory.
   */
  public function isEnabled() {
    return Endpoint::fromEnvironment()->isAvailable();
  }

}
