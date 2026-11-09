<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\vallic_purge\CdnPurger;
use Drupal\vallic_purge\Endpoint;
use Drupal\vallic_purge\EventSubscriber\CdnTagSubscriber;
use Drupal\vallic_purge\Hook\RequirementsHooks;
use Drupal\vallic_purge\Plugin\QueueWorker\PurgeQueueWorker;
use Drupal\vallic_purge\PurgeCollector;
use Drupal\vallic_purge\PurgeOutcome;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

/**
 * Invalidated cache tags reach the platform once, or wait for cron.
 */
#[Group('vallic_purge')]
#[CoversClass(CdnPurger::class)]
#[CoversClass(PurgeCollector::class)]
#[CoversClass(PurgeQueueWorker::class)]
#[CoversClass(CdnTagSubscriber::class)]
#[CoversClass(RequirementsHooks::class)]
#[RunTestsInSeparateProcesses]
final class PurgeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'cache_purifier', 'vallic_purge'];

  /**
   * The requests made, with their options, as Guzzle records them.
   *
   * @var \ArrayObject<int, array<string, mixed>>
   */
  private \ArrayObject $sent;

  /**
   * What the platform answers, one per request.
   */
  private MockHandler $answers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['cache_purifier']);

    $this->sent = new \ArrayObject();
    $this->answers = new MockHandler();
    $stack = HandlerStack::create($this->answers);
    // Through a local: Guzzle takes it by reference, typed loosely, and the
    // object is the same either way.
    $history = $this->sent;
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $this->purger()->setEndpoint(new Endpoint('http://localhost/purge', 'a-token', TRUE, '/run/vallic-cdn/purge.sock'));
  }

  /**
   * Each tag goes once, at the end of the request, in the header's format.
   */
  public function testInvalidatedTagsArePurgedOnceAtTheEnd(): void {
    Cache::invalidateTags(['node:1', 'node_list']);
    Cache::invalidateTags(['node:1', 'rendered']);
    $this->assertCount(0, $this->sent, 'Nothing is sent before the request ends.');

    $this->answers->append(new Response(200));
    $this->container->get('vallic_purge.cache_tags_invalidator')->destruct();

    $format = new HashedTagList();
    $this->assertCount(1, $this->sent);
    $request = $this->request(0);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('http://localhost/purge', (string) $request->getUri());
    $this->assertSame('Bearer a-token', $request->getHeaderLine('Authorization'));
    $this->assertSame([CURLOPT_UNIX_SOCKET_PATH => '/run/vallic-cdn/purge.sock'], $this->options(0)['curl'] ?? NULL);
    $this->assertSame([
      'scope' => 'tag',
      'values' => [$format->purgeValue('node:1'), $format->purgeValue('node_list')],
    ], $this->body(0), 'rendered is on the blocklist.');
  }

  /**
   * A Cache Purifier rule naming this invalidator keeps tags from it.
   */
  public function testCachePurifierRulesApply(): void {
    $this->config('cache_purifier.settings')->set('rules', [
      'private' => [
        'label' => 'Contact messages',
        'tags' => ['contact_message:*'],
        'targets' => ['vallic_purge.cache_tags_invalidator'],
      ],
    ])->save();
    // Saving the rule invalidates the config's own tag, which does go.
    $this->answers->append(new Response(200));
    $this->container->get('vallic_purge.cache_tags_invalidator')->destruct();
    $this->sent->exchangeArray([]);

    Cache::invalidateTags(['contact_message:3']);
    $this->container->get('vallic_purge.cache_tags_invalidator')->destruct();

    $this->assertCount(0, $this->sent);
  }

  /**
   * A long list goes in several requests, each a size the platform finishes.
   */
  public function testLongListIsSentInChunks(): void {
    $tags = [];
    for ($i = 0; $i < CdnPurger::CHUNK * 2 + 1; $i++) {
      $tags[] = 'node:' . $i;
    }
    $this->answers->append(new Response(200), new Response(200), new Response(200));

    $this->assertSame(PurgeOutcome::Sent, $this->purger()->invalidateTags($tags));

    $this->assertCount(3, $this->sent);
    $this->assertCount(1, $this->body(2)['values']);
  }

  /**
   * Addresses go as given; everything goes with no values at all.
   */
  public function testUrlsAndEverything(): void {
    $this->answers->append(new Response(200), new Response(200));

    $this->purger()->purgeUrls(['https://www.example.com/news', '']);
    $this->purger()->purgeAll();

    $this->assertSame(['scope' => 'url', 'values' => ['https://www.example.com/news']], $this->body(0));
    $this->assertSame(['scope' => 'all'], $this->body(1));
  }

  /**
   * An unreachable platform: this chunk and the rest wait, and cron sends them.
   */
  public function testWhatCannotGoNowWaitsForCron(): void {
    $tags = [];
    for ($i = 0; $i < CdnPurger::CHUNK + 1; $i++) {
      $tags[] = 'node:' . $i;
    }
    $this->answers->append(new Response(200, [], ''));
    $this->answers->append(new ConnectException('Connection refused', new Request('POST', 'http://localhost/purge')));

    $this->assertSame(PurgeOutcome::Queued, $this->purger()->invalidateTags($tags));
    $queue = $this->container->get('queue')->get(CdnPurger::QUEUE);
    $this->assertSame(1, $queue->numberOfItems(), 'The first chunk went; the second waits.');

    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(CdnPurger::QUEUE);
    $item = $queue->claimItem();
    $this->assertInstanceOf(\stdClass::class, $item);

    $this->answers->append(new Response(502, [], '{"error":"The CDN refused the purge."}'));
    try {
      $worker->processItem($item->data);
      $this->fail('A failing platform suspends the queue.');
    }
    catch (SuspendQueueException) {
    }

    $this->answers->append(new Response(200));
    $worker->processItem($item->data);
    $this->assertSame(['scope' => 'tag', 'values' => [(new HashedTagList())->purgeValue('node:' . CdnPurger::CHUNK)]], $this->body(3));
  }

  /**
   * What the platform refuses is logged and not sent again.
   */
  public function testRefusalIsNotQueued(): void {
    $this->answers->append(new Response(409, [], '{"error":"This environment is not served through a CDN."}'));

    $this->assertSame(PurgeOutcome::Refused, $this->purger()->purgeAll());
    $this->assertSame(0, $this->container->get('queue')->get(CdnPurger::QUEUE)->numberOfItems());
  }

  /**
   * With no CDN there is nothing to clear: nothing sent, nothing queued.
   */
  public function testNoCdnSendsNothing(): void {
    $this->purger()->setEndpoint(new Endpoint('', '', TRUE));

    $this->assertSame(PurgeOutcome::Nothing, $this->purger()->invalidateTags(['node:1']));
    $this->assertSame(PurgeOutcome::Nothing, $this->purger()->purgeAll());
    $this->assertCount(0, $this->sent);
    $this->assertSame(0, $this->container->get('queue')->get(CdnPurger::QUEUE)->numberOfItems());
  }

  /**
   * Public responses carry the header where there is a CDN; nothing else does.
   */
  public function testHeaderIsOnlyOnPublicResponsesWithCdn(): void {
    $public = $this->respond(TRUE);
    $tokens = explode(' ', (string) $public->headers->get('CDN-Tag'));
    $this->assertSame(':', $tokens[0]);
    $this->assertContains((new HashedTagList())->token('node:1'), $tokens);
    $this->assertNotContains((new HashedTagList())->token('rendered'), $tokens);

    $this->assertFalse($this->respond(FALSE)->headers->has('CDN-Tag'), 'A private response is never cached.');

    $this->purger()->setEndpoint(new Endpoint('', '', FALSE));
    $this->assertFalse($this->respond(TRUE)->headers->has('CDN-Tag'), 'Nothing would read it.');
  }

  /**
   * The status report says which of the three it is.
   */
  public function testTheStatusReport(): void {
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(RequirementsHooks::class);
    $this->assertSame(RequirementSeverity::OK, $hooks->runtime()['vallic_purge']['severity']);

    $this->container->get('queue')->get(CdnPurger::QUEUE)->createItem(['scope' => 'all', 'values' => []]);
    $this->assertSame(RequirementSeverity::Warning, $hooks->runtime()['vallic_purge']['severity'], 'Purges waiting are worth a look.');

    $this->purger()->setEndpoint(new Endpoint('', '', TRUE));
    $this->assertSame(RequirementSeverity::Warning, $hooks->runtime()['vallic_purge']['severity']);

    $this->purger()->setEndpoint(new Endpoint('', '', FALSE));
    $this->assertSame(RequirementSeverity::Info, $hooks->runtime()['vallic_purge']['severity']);
  }

  /**
   * The purger service.
   */
  private function purger(): CdnPurger {
    return $this->container->get('vallic_purge.purger');
  }

  /**
   * One request made.
   */
  private function request(int $index): Request {
    $request = $this->sent[$index]['request'] ?? NULL;
    $this->assertInstanceOf(Request::class, $request);
    return $request;
  }

  /**
   * The options one request was made with.
   *
   * @return array<string, mixed>
   *   The Guzzle options.
   */
  private function options(int $index): array {
    $options = $this->sent[$index]['options'] ?? NULL;
    $this->assertIsArray($options);
    return $options;
  }

  /**
   * The JSON body of one request made.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private function body(int $index): array {
    return json_decode((string) $this->request($index)->getBody(), TRUE);
  }

  /**
   * A response tagged node:1 and rendered, through the subscriber.
   */
  private function respond(bool $public): CacheableResponse {
    $response = new CacheableResponse('ok');
    $response->getCacheableMetadata()->setCacheTags(['node:1', 'rendered']);
    if ($public) {
      $response->setPublic();
    }
    else {
      $response->setPrivate();
    }
    $event = new ResponseEvent($this->container->get('http_kernel'), new HttpRequest(), HttpKernelInterface::MAIN_REQUEST, $response);
    $this->container->get('vallic_purge.cdn_tag_subscriber')->onResponse($event);
    return $response;
  }

}
