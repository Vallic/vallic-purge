<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvStatesInterface;
use Drupal\vallic_purge\CacheTags;
use Drupal\vallic_purge\Endpoint;
use Drupal\vallic_purge\Plugin\Purge\Purger\VallicPurger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What the purger sends, and the state it leaves every invalidation in.
 *
 * Every path ends with the invalidation's context set back to NULL, which is
 * where purge checks the state a purger left: one left FRESH throws, and that
 * breaks purging for every purger on the site.
 */
#[Group('vallic_purge')]
#[CoversClass(VallicPurger::class)]
#[RunTestsInSeparateProcesses]
final class VallicPurgerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'purge', 'vallic_purge'];

  /**
   * The requests the purger made.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $sent = [];

  /**
   * What the endpoint answers, one per request.
   */
  private MockHandler $answers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['purge']);
    // Configured, as `drush p:purger-add vallic` would: purge only makes
    // invalidations of a type some configured purger takes.
    $this->container->get('purge.purgers')->setPluginsEnabled(['vallic' => 'vallic']);

    $this->answers = new MockHandler();
    $stack = HandlerStack::create($this->answers);
    $stack->push(Middleware::history($this->sent));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Tags go as the patterns the header is written for, with the token.
   */
  public function testTagsAreSentAsPatterns(): void {
    $this->answers->append(new Response(200, [], '{"purged":2,"scope":"tag"}'));
    $invalidations = [$this->invalidation('tag', 'node:1'), $this->invalidation('tag', 'node_list')];

    $this->purger()->invalidateTags($invalidations);

    $this->assertCount(1, $this->sent);
    $request = $this->sent[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('http://127.0.0.1:9339/purge', (string) $request->getUri());
    $this->assertSame('Bearer a-token', $request->getHeaderLine('Authorization'));
    $this->assertSame([
      'scope' => 'tag',
      'values' => [CacheTags::pattern('node:1'), CacheTags::pattern('node_list')],
    ], json_decode((string) $request->getBody(), TRUE));
    $this->assertStates(InvStatesInterface::SUCCEEDED, $invalidations);
  }

  /**
   * A long list goes in several requests, each a size the platform finishes.
   */
  public function testLongListIsSentInChunks(): void {
    $invalidations = [];
    for ($i = 0; $i < VallicPurger::CHUNK * 2 + 1; $i++) {
      $invalidations[] = $this->invalidation('tag', 'node:' . $i);
    }
    for ($i = 0; $i < 3; $i++) {
      $this->answers->append(new Response(200));
    }

    $this->purger()->invalidateTags($invalidations);

    $this->assertCount(3, $this->sent);
    $this->assertStates(InvStatesInterface::SUCCEEDED, $invalidations);
  }

  /**
   * URLs go as given; everything goes with no values at all.
   */
  public function testUrlsAndEverything(): void {
    $this->answers->append(new Response(200), new Response(200));
    $url = $this->invalidation('url', 'https://www.example.com/news');
    $everything = $this->invalidation('everything');

    $this->purger()->invalidateUrls([$url]);
    $this->purger()->invalidateEverything([$everything]);

    $this->assertSame(['scope' => 'url', 'values' => ['https://www.example.com/news']], json_decode((string) $this->sent[0]['request']->getBody(), TRUE));
    $this->assertSame(['scope' => 'all'], json_decode((string) $this->sent[1]['request']->getBody(), TRUE));
    $this->assertStates(InvStatesInterface::SUCCEEDED, [$url, $everything]);
  }

  /**
   * A refusal fails the batch, so purge offers it again.
   */
  public function testRefusalFailsTheBatch(): void {
    $this->answers->append(new Response(502, [], '{"error":"the purge was refused"}'));
    $invalidations = [$this->invalidation('tag', 'node:1')];

    $this->purger()->invalidateTags($invalidations);

    $this->assertStates(InvStatesInterface::FAILED, $invalidations);
  }

  /**
   * With no CDN there is nothing to clear: done, and nothing sent.
   */
  public function testNoCdnSendsNothingAndCompletes(): void {
    $invalidations = [$this->invalidation('tag', 'node:1')];

    $this->purger(new Endpoint('', '', TRUE))->invalidateTags($invalidations);

    $this->assertSame([], $this->sent);
    $this->assertStates(InvStatesInterface::SUCCEEDED, $invalidations);
  }

  /**
   * With the purger configured, purge sends the header and runs the check.
   *
   * Both are enabled by the purger they depend on, so nothing else has to be
   * switched on for a response to carry its tags.
   */
  public function testTheHeaderAndTheCheckComeWithThePurger(): void {
    $headers = $this->container->get('purge.tagsheaders');
    $this->assertContains('vallic', $headers->getPluginsEnabled());

    foreach ($headers as $header) {
      if ($header->getPluginId() === 'vallic') {
        $this->assertSame('CDN-Tag', $header->getHeaderName());
        $this->assertSame(CacheTags::header(['node:1']), $header->getValue(['node:1']));
      }
    }

    $this->assertContains('vallic', $this->container->get('purge.diagnostics')->getPluginsEnabled());
  }

  /**
   * With no CDN the header is not sent: nothing would read it.
   */
  public function testTheHeaderIsOnlySentWhereTheCdnIsOn(): void {
    $header = NULL;
    foreach ($this->container->get('purge.tagsheaders') as $candidate) {
      if ($candidate->getPluginId() === 'vallic') {
        $header = $candidate;
      }
    }
    $this->assertNotNull($header);

    $this->assertFalse($header->isEnabled(), 'No variables: no header.');

    putenv(Endpoint::URL . '=http://127.0.0.1:9339/purge');
    putenv(Endpoint::TOKEN . '=a-token');
    try {
      $this->assertTrue($header->isEnabled());
    }
    finally {
      putenv(Endpoint::URL);
      putenv(Endpoint::TOKEN);
    }
  }

  /**
   * The purger, pointed at a platform with the CDN on.
   */
  private function purger(?Endpoint $endpoint = NULL): VallicPurger {
    $definition = [
      'id' => 'vallic',
      'label' => 'Vallic Cloud CDN',
      'cooldown_time' => 0.0,
      'multi_instance' => FALSE,
      'types' => ['everything', 'tag', 'url'],
    ];
    $purger = VallicPurger::create($this->container, ['id' => 'vallic'], 'vallic', $definition);
    // What purge gives a purger it creates.
    $purger->setLogger($this->container->get('purge.logger')->get('purger_vallic'));
    $purger->setEndpoint($endpoint ?? new Endpoint('http://127.0.0.1:9339/purge', 'a-token', TRUE));
    return $purger;
  }

  /**
   * An invalidation, handed to this purger the way purge hands it over.
   */
  private function invalidation(string $type, ?string $expression = NULL): InvalidationInterface {
    $invalidation = $this->container->get('purge.invalidation.factory')->get($type, $expression);
    $invalidation->setStateContext('vallic');
    return $invalidation;
  }

  /**
   * Every invalidation is in this state, and it is one purge accepts back.
   *
   * @param int $state
   *   The state expected.
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidations.
   */
  private function assertStates(int $state, array $invalidations): void {
    foreach ($invalidations as $invalidation) {
      $this->assertSame($state, $invalidation->getState());
      // Where purge checks what a purger left: it throws on anything else.
      $invalidation->setStateContext(NULL);
    }
  }

}
