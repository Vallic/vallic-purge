<?php

declare(strict_types=1);

namespace Drupal\Tests\vallic_purge\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vallic_purge\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Where purges go is read from the environment, and only there.
 */
#[Group('vallic_purge')]
#[CoversClass(Endpoint::class)]
final class EndpointTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(Endpoint::URL);
    putenv(Endpoint::TOKEN);
    putenv(Endpoint::ENVIRONMENT);
    putenv(Endpoint::SOCKET);
    parent::tearDown();
  }

  /**
   * Both variables set: there is a CDN to clear.
   */
  public function testAnEnvironmentWithTheCdnOn(): void {
    putenv(Endpoint::URL . '=http://127.0.0.1:9339/purge');
    putenv(Endpoint::TOKEN . '=abc');
    putenv(Endpoint::ENVIRONMENT . '=acme-production');

    $endpoint = Endpoint::fromEnvironment();

    $this->assertTrue($endpoint->isAvailable());
    $this->assertTrue($endpoint->isOnPlatform());
    $this->assertSame('http://127.0.0.1:9339/purge', $endpoint->url());
    $this->assertSame('abc', $endpoint->token());
    $this->assertSame('', $endpoint->socket());
  }

  /**
   * The socket, where the platform gives one, is read beside the rest.
   */
  public function testTheSocketIsRead(): void {
    putenv(Endpoint::URL . '=http://localhost/purge');
    putenv(Endpoint::TOKEN . '=abc');
    putenv(Endpoint::SOCKET . '=/run/vallic-cdn/purge.sock');

    $this->assertSame('/run/vallic-cdn/purge.sock', Endpoint::fromEnvironment()->socket());
  }

  /**
   * On the platform with the CDN off, the two are told apart.
   */
  public function testAnEnvironmentWithTheCdnOff(): void {
    putenv(Endpoint::ENVIRONMENT . '=acme-production');

    $endpoint = Endpoint::fromEnvironment();

    $this->assertFalse($endpoint->isAvailable());
    $this->assertTrue($endpoint->isOnPlatform());
  }

  /**
   * An address without its token is not enough to ask.
   */
  public function testTheAddressAloneIsNotEnough(): void {
    putenv(Endpoint::URL . '=http://127.0.0.1:9339/purge');

    $this->assertFalse(Endpoint::fromEnvironment()->isAvailable());
  }

}
