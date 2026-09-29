<?php

declare(strict_types=1);

namespace Drupal\vallic_purge\Plugin\Purge\Purger;

use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvStatesInterface;
use Drupal\purge\Plugin\Purge\Purger\PurgerBase;
use Drupal\purge\Plugin\Purge\Purger\PurgerInterface;
use Drupal\vallic_purge\CacheTags;
use Drupal\vallic_purge\Endpoint;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clears this environment's Vallic Cloud CDN through the platform.
 *
 * The CDN's own API key never reaches the site: one key opens every
 * customer's cache, so the platform keeps it and the site asks the machine it
 * runs on instead. That request carries the environment's token and a list
 * of what to clear, and the platform decides which cache that is.
 *
 * @PurgePurger(
 *   id = "vallic",
 *   label = @Translation("Vallic Cloud CDN"),
 *   cooldown_time = 0.0,
 *   description = @Translation("Clears this environment's CDN on Vallic Cloud. Needs no configuration: the platform provides the address and the token."),
 *   multi_instance = FALSE,
 *   types = {"everything", "tag", "url"},
 * )
 */
final class VallicPurger extends PurgerBase implements PurgerInterface {

  /**
   * The most values one request carries.
   *
   * The platform clears each tag with its own call to the CDN, so a request
   * naming hundreds would outlast the time it is given. Twenty-five keeps one
   * request to a few seconds.
   */
  public const int CHUNK = 25;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * Where purges go; read from the environment when first needed.
   */
  protected ?Endpoint $endpoint = NULL;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The instance's configuration, with its id.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRuntimeMeasurement() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function routeTypeToMethod($type) {
    return match ($type) {
      'everything' => 'invalidateEverything',
      'tag' => 'invalidateTags',
      'url' => 'invalidateUrls',
      default => 'invalidate',
    };
  }

  /**
   * {@inheritdoc}
   *
   * Only reached for a type this purger does not declare, which purge does
   * not send it. Answered all the same: purge requires every invalidation it
   * hands over to come back in an outbound state, and one left untouched
   * breaks purging for every purger on the site.
   */
  public function invalidate(array $invalidations): void {
    $this->settle($invalidations, InvStatesInterface::NOT_SUPPORTED);
  }

  /**
   * Clears the whole cache.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidations; one request answers all of them.
   */
  public function invalidateEverything(array $invalidations): void {
    $this->send('all', [], $invalidations);
  }

  /**
   * Clears every response carrying these cache tags.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   Tag invalidations.
   */
  public function invalidateTags(array $invalidations): void {
    foreach (array_chunk($invalidations, self::CHUNK) as $chunk) {
      $this->send('tag', array_map(
        static fn (InvalidationInterface $invalidation): string => CacheTags::pattern((string) $invalidation->getExpression()),
        $chunk,
      ), $chunk);
    }
  }

  /**
   * Clears these addresses.
   *
   * Absolute, as the invalidation was made. The platform accepts only the
   * hostnames this environment answers on and skips the rest, so an address
   * for somewhere else costs nothing and clears nothing.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   URL invalidations.
   */
  public function invalidateUrls(array $invalidations): void {
    foreach (array_chunk($invalidations, self::CHUNK) as $chunk) {
      $this->send('url', array_map(
        static fn (InvalidationInterface $invalidation): string => (string) $invalidation->getExpression(),
        $chunk,
      ), $chunk);
    }
  }

  /**
   * Sets where purges go, instead of reading the environment.
   */
  public function setEndpoint(Endpoint $endpoint): void {
    $this->endpoint = $endpoint;
  }

  /**
   * Asks the platform to clear one batch, and records how it went.
   *
   * @param string $scope
   *   One of all, tag or url.
   * @param string[] $values
   *   What to clear; empty for everything.
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidations the request answers.
   */
  private function send(string $scope, array $values, array $invalidations): void {
    $endpoint = $this->endpoint ??= Endpoint::fromEnvironment();

    // No CDN — this environment has it off, or the site is not on the
    // platform at all. There is nothing cached anywhere to clear, so the
    // invalidation is done; failing it would only fill the queue with work
    // that can never succeed. The status report says which of the two.
    if (!$endpoint->isAvailable()) {
      $this->settle($invalidations, InvStatesInterface::SUCCEEDED);
      return;
    }

    $this->settle($invalidations, InvStatesInterface::PROCESSING);

    $body = ['scope' => $scope];
    if ($values !== []) {
      $body['values'] = array_values(array_unique($values));
    }

    $options = [
      RequestOptions::JSON => $body,
      RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $endpoint->token()],
      RequestOptions::CONNECT_TIMEOUT => 2,
      RequestOptions::TIMEOUT => 30,
    ];

    if ($endpoint->socket() !== '') {
      $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $endpoint->socket()];
    }

    try {
      $this->httpClient->request('POST', $endpoint->url(), $options);
    }
    catch (GuzzleException $e) {
      // Failed rather than dropped: purge offers it again on its next run,
      // which is right for a platform that was busy or a machine restarting.
      $this->logger()->error('Clearing the CDN failed (@scope): @message', [
        '@scope' => $scope,
        '@message' => $e->getMessage(),
      ]);
      $this->settle($invalidations, InvStatesInterface::FAILED);
      return;
    }

    $this->settle($invalidations, InvStatesInterface::SUCCEEDED);
  }

  /**
   * Puts every invalidation in one state.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidations.
   * @param int $state
   *   One of InvStatesInterface's states.
   */
  private function settle(array $invalidations, int $state): void {
    foreach ($invalidations as $invalidation) {
      $invalidation->setState($state);
    }
  }

}
