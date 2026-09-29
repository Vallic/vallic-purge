<?php

declare(strict_types=1);

namespace Drupal\vallic_purge;

/**
 * Where this environment's purges go, as Vallic Cloud tells it.
 *
 * Both values arrive as environment variables, written by the platform into
 * every environment whose project has the CDN on. Nothing is configured in
 * Drupal, and nothing ends up in exported configuration: the address is the
 * platform's, and the token only says which of the environments on a shared
 * machine is asking — it can clear this environment's own cache and nothing
 * else.
 */
final class Endpoint {

  /**
   * The address, the machine's local purge relay.
   */
  public const string URL = 'VALLIC_CDN_PURGE_URL';

  /**
   * The Unix socket the relay answers on, when there is one.
   *
   * The site runs in a container with a network of its own, so the machine's
   * loopback is not reachable from it. The platform mounts the relay's socket
   * into the container instead, and the request is sent through that; the
   * URL then supplies only the path.
   */
  public const string SOCKET = 'VALLIC_CDN_PURGE_SOCKET';

  /**
   * The token that says which environment is asking.
   */
  public const string TOKEN = 'VALLIC_CDN_PURGE_TOKEN';

  /**
   * Set in every environment on the platform, CDN or not.
   */
  public const string ENVIRONMENT = 'VALLIC_ENVIRONMENT';

  public function __construct(
    private readonly string $url,
    private readonly string $token,
    private readonly bool $onPlatform,
    private readonly string $socket = '',
  ) {}

  /**
   * The endpoint as this process's environment describes it.
   */
  public static function fromEnvironment(): self {
    return new self(
      trim((string) getenv(self::URL)),
      trim((string) getenv(self::TOKEN)),
      getenv(self::ENVIRONMENT) !== FALSE,
      trim((string) getenv(self::SOCKET)),
    );
  }

  /**
   * Whether there is a CDN to clear, and the means to ask.
   */
  public function isAvailable(): bool {
    return $this->url !== '' && $this->token !== '';
  }

  /**
   * Whether this site runs on Vallic Cloud at all.
   */
  public function isOnPlatform(): bool {
    return $this->onPlatform;
  }

  /**
   * Where a purge is sent.
   */
  public function url(): string {
    return $this->url;
  }

  /**
   * The Unix socket a purge is sent through, or '' for the URL's own host.
   */
  public function socket(): string {
    return $this->socket;
  }

  /**
   * The bearer token a purge carries.
   */
  public function token(): string {
    return $this->token;
  }

}
