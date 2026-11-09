<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

use Drupal\Core\Queue\QueueFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Clears this environment's Vallic Cloud CDN through the platform.
 *
 * The CDN's own API key never reaches the site: one key opens every
 * customer's cache, so the platform keeps it and the site asks the machine it
 * runs on instead. That request carries the environment's token and a list
 * of what to clear, and the platform decides which cache that is. The
 * platform also keeps to the CDN's rate limits and retries what they defer,
 * so nothing here counts them.
 *
 * What cannot be sent now, because the platform is busy or the machine is
 * restarting, waits in a queue cron empties. What the platform refuses is
 * logged and dropped: it would be refused again.
 */
final class CdnPurger {

  /**
   * The queue that holds purges to send again.
   */
  public const string QUEUE = 'vallic_purge';

  /**
   * The most values one request carries.
   *
   * The platform clears each tag with its own call to the CDN, so a request
   * naming hundreds would outlast the time it is given. Twenty-five keeps one
   * request to a few seconds.
   */
  public const int CHUNK = 25;

  /**
   * Where purges go; read from the environment when first needed.
   */
  private ?Endpoint $endpoint = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheTags $cacheTags,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Where purges go, as this process's environment describes it.
   */
  public function endpoint(): Endpoint {
    return $this->endpoint ??= Endpoint::fromEnvironment();
  }

  /**
   * Sets where purges go, instead of reading the environment.
   */
  public function setEndpoint(Endpoint $endpoint): void {
    $this->endpoint = $endpoint;
  }

  /**
   * Whether there is a CDN to clear, and the means to ask.
   */
  public function isAvailable(): bool {
    return $this->endpoint()->isAvailable();
  }

  /**
   * Clears every response carrying these cache tags.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags. Cache Purifier's outbound policy decides which
   *   of them reach the CDN.
   */
  public function invalidateTags(iterable $tags): PurgeOutcome {
    if (!$this->isAvailable()) {
      return PurgeOutcome::Nothing;
    }

    return $this->dispatch('tag', $this->cacheTags->purgeValues($tags));
  }

  /**
   * Clears these addresses.
   *
   * Absolute. The platform accepts only the hostnames this environment
   * answers on and skips the rest, so an address for somewhere else costs
   * nothing and clears nothing.
   *
   * @param list<string> $urls
   *   The addresses.
   */
  public function purgeUrls(array $urls): PurgeOutcome {
    if (!$this->isAvailable()) {
      return PurgeOutcome::Nothing;
    }

    return $this->dispatch('url', array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== ''))));
  }

  /**
   * Empties this environment's CDN cache.
   */
  public function purgeAll(): PurgeOutcome {
    if (!$this->isAvailable()) {
      return PurgeOutcome::Nothing;
    }

    $this->logger->notice('Clearing everything in the CDN.');

    return $this->dispatch('all', []);
  }

  /**
   * Sends a purge cron took off the queue.
   *
   * Throws when it should be offered again, so the queue keeps it.
   *
   * @param array{scope: 'all'|'tag'|'url', values: list<string>} $item
   *   The queue item.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the platform could not be reached or failed.
   */
  public function processQueued(array $item): void {
    if (!$this->isAvailable()) {
      // The CDN was turned off since: nothing cached to clear.
      return;
    }

    try {
      $this->send($item['scope'], $item['values']);
    }
    catch (ClientException $e) {
      $this->refused($item['scope'], $e);
    }
  }

  /**
   * Sends one kind of purge in chunks; what can't go now is queued.
   *
   * @param 'all'|'tag'|'url' $scope
   *   What the values are.
   * @param list<string> $values
   *   What to clear: tag patterns or addresses. Empty for everything.
   */
  private function dispatch(string $scope, array $values): PurgeOutcome {
    if ($scope !== 'all' && $values === []) {
      return PurgeOutcome::Nothing;
    }

    $chunks = $scope === 'all' ? [[]] : array_chunk($values, self::CHUNK);

    foreach ($chunks as $index => $chunk) {
      try {
        $this->send($scope, $chunk);
      }
      catch (ClientException $e) {
        $this->refused($scope, $e);
        return PurgeOutcome::Refused;
      }
      catch (GuzzleException $e) {
        // This chunk and every one after it: the platform is unreachable or
        // failing, so the next would fail the same way, after its timeout.
        $this->logger->warning('Clearing the CDN failed (@scope), so it waits for cron: @message', [
          '@scope' => $scope,
          '@message' => $e->getMessage(),
        ]);
        $queue = $this->queueFactory->get(self::QUEUE);
        foreach (array_slice($chunks, $index) as $rest) {
          $queue->createItem(['scope' => $scope, 'values' => $rest]);
        }
        return PurgeOutcome::Queued;
      }
    }

    return PurgeOutcome::Sent;
  }

  /**
   * Asks the platform to clear one batch.
   *
   * @param 'all'|'tag'|'url' $scope
   *   What the values are.
   * @param list<string> $values
   *   What to clear; empty for everything.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the platform could not be reached, failed, or refused.
   */
  private function send(string $scope, array $values): void {
    $endpoint = $this->endpoint();

    $body = ['scope' => $scope];
    if ($values !== []) {
      $body['values'] = $values;
    }

    $options = [
      RequestOptions::JSON => $body,
      RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $endpoint->token()],
      RequestOptions::CONNECT_TIMEOUT => 2,
      RequestOptions::TIMEOUT => 30,
    ];

    // The site's container has a network of its own, so the machine's
    // loopback in the URL would be the container's. The platform mounts the
    // relay's socket instead, and the URL supplies only the path.
    if ($endpoint->socket() !== '') {
      $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $endpoint->socket()];
    }

    $this->httpClient->request('POST', $endpoint->url(), $options);
  }

  /**
   * Logs a purge the platform refused, which is not sent again.
   */
  private function refused(string $scope, ClientException $e): void {
    $this->logger->error('The platform refused to clear the CDN (@scope): @message', [
      '@scope' => $scope,
      '@message' => $e->getMessage(),
    ]);
  }

}
