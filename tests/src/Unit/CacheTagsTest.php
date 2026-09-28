<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vallic_purge\CacheTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The header a response carries and the pattern a purge sends agree.
 */
#[Group('vallic_purge')]
#[CoversClass(CacheTags::class)]
final class CacheTagsTest extends UnitTestCase {

  /**
   * A purge for any one tag matches the header of a page carrying several.
   *
   * The CDN keeps the header as one string and matches `*` as anything, so
   * this is the whole contract: the pattern, with its stars as wildcards,
   * found in the header.
   */
  public function testThePatternFindsTheTagInTheHeader(): void {
    $tags = ['node:1', 'node_list', 'config:system.site', 'user:7'];
    $header = CacheTags::header($tags);

    foreach ($tags as $tag) {
      $pattern = '/^' . str_replace('\*', '.*', preg_quote(CacheTags::pattern($tag), '/')) . '$/';
      $this->assertMatchesRegularExpression($pattern, $header, $tag);
    }

    $this->assertDoesNotMatchRegularExpression(
      '/^' . str_replace('\*', '.*', preg_quote(CacheTags::pattern('node:2'), '/')) . '$/',
      $header,
      'A tag the page does not carry is not found in it.',
    );
  }

  /**
   * A tag that is a prefix of another does not match it.
   *
   * `node:1` against a page carrying only `node:12`: the spaces around every
   * hash are what keep a purge from clearing pages it is not about.
   */
  public function testTagDoesNotMatchAnotherItIsPrefixOf(): void {
    $header = CacheTags::header(['node:12']);
    $pattern = '/^' . str_replace('\*', '.*', preg_quote(CacheTags::pattern('node:1'), '/')) . '$/';

    $this->assertDoesNotMatchRegularExpression($pattern, $header);
  }

  /**
   * The header stays short however long the tags are.
   */
  public function testTheHeaderIsShort(): void {
    $tags = [];
    for ($i = 0; $i < 300; $i++) {
      $tags[] = 'config:block.block.a_rather_long_block_machine_name_' . $i;
    }

    $this->assertLessThan(3000, strlen(CacheTags::header($tags)));
    $this->assertStringStartsWith(': ', CacheTags::header($tags));
    $this->assertStringEndsWith(' :', CacheTags::header($tags));
  }

  /**
   * A tag listed twice is written once.
   */
  public function testDuplicatesAreWrittenOnce(): void {
    $this->assertSame(CacheTags::header(['node:1']), CacheTags::header(['node:1', 'node:1']));
  }

}
