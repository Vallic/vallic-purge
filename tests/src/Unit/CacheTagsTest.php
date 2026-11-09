<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Unit;

use Drupal\cache_purifier\OutboundTags;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\vallic_purge\CacheTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

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
    $cacheTags = $this->cacheTags();
    $header = $cacheTags->header($tags)->value;

    foreach ($cacheTags->purgeValues($tags) as $pattern) {
      $this->assertMatchesRegularExpression('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $header);
    }
    $this->assertCount(4, $cacheTags->purgeValues($tags));
  }

  /**
   * Cache Purifier's blocklist keeps a tag out of both.
   *
   * Every page carries `rendered`, so a purge for it would empty the CDN.
   */
  public function testBlocklistedTagsAreNeitherWrittenNorPurged(): void {
    $cacheTags = $this->cacheTags();
    $format = new HashedTagList(CacheTags::HASH_LENGTH);

    $this->assertSame([$format->purgeValue('node:1')], $cacheTags->purgeValues(['node:1', 'rendered', 'http_response']));
    $this->assertSame([$format->token('node:1')], $this->tokens($cacheTags->header(['rendered', 'node:1'])->value));
  }

  /**
   * A full header stops at the CDN's 1024 bytes, keeping lists and entities.
   *
   * The CDN would otherwise cut the header itself, at whatever tag the
   * 1024th byte fell in, and a page would no longer be cleared by the tags
   * past it.
   */
  public function testFullHeaderKeepsTheUsefulTags(): void {
    $tags = [];
    for ($i = 0; $i < 300; $i++) {
      $tags[] = 'config:block.block.b' . $i;
    }
    $tags[] = 'node:1';
    $tags[] = 'node_list';

    $header = $this->cacheTags()->header($tags);

    $this->assertLessThanOrEqual(1024, strlen($header->value));
    $this->assertNotSame([], $header->dropped);
    $this->assertContains('node_list', $header->included);
    $this->assertContains('node:1', $header->included);
  }

  /**
   * Without Cache Purifier, as before the update installs it, nothing goes.
   */
  public function testWithoutCachePurifierNothingIsWrittenOrPurged(): void {
    $cacheTags = new CacheTags();

    $this->assertTrue($cacheTags->header(['node:1'])->isEmpty());
    $this->assertSame([], $cacheTags->purgeValues(['node:1']));
  }

  /**
   * The tags with Cache Purifier's default outbound policy.
   */
  private function cacheTags(): CacheTags {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['outbound', [
        'blocklist' => ['http_response', 'rendered', 'library_info', 'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:*'],
        'aliases' => [],
        'buckets' => [],
      ],
      ],
    ]);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new CacheTags(new OutboundTags($configFactory));
  }

  /**
   * The tokens in a header, without its guards.
   *
   * @return list<string>
   *   The tokens.
   */
  private function tokens(string $header): array {
    return array_values(array_filter(explode(' ', $header), static fn (string $part): bool => $part !== ':' && $part !== ''));
  }

}
