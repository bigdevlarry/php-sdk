<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Resource\ResourceSubscription;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ResourceSubscriptionTest extends TestCase
{
    private ResourceSubscription $resourceSubscription;
    private LoggerInterface&MockObject $logger;
    private SessionStoreInterface&MockObject $sessionStore;
    private SessionFactoryInterface&MockObject $sessionFactory;
    private Protocol&MockObject $protocol;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sessionStore = $this->createMock(SessionStoreInterface::class);
        $this->sessionFactory = $this->createMock(SessionFactoryInterface::class);
        $this->protocol = $this->createMock(Protocol::class);
        $this->resourceSubscription = new ResourceSubscription($this->logger, $this->sessionStore, $this->sessionFactory);
    }

    #[TestDox('Subscribing to a resource sends update notifications')]
    public function testSubscribeAndSendsNotification(): void
    {
        // Arrange
        $session1 = $this->createMock(SessionInterface::class);
        $session2 = $this->createMock(SessionInterface::class);

        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();

        $session1->method('getId')->willReturn($uuid1);
        $session2->method('getId')->willReturn($uuid2);

        $uri = 'test://resource1';

        $session1->expects($this->once())->method('get')->with('resource_subscriptions', [])->willReturn([]);
        $session1->expects($this->once())->method('set')->with('resource_subscriptions', [$uri => true]);
        $session1->expects($this->once())->method('save');

        $session2->expects($this->once())->method('get')->with('resource_subscriptions', [])->willReturn([]);
        $session2->expects($this->once())->method('set')->with('resource_subscriptions', [$uri => true]);
        $session2->expects($this->once())->method('save');

        // Subscribe both sessions
        $this->resourceSubscription->subscribe($session1, $uri);
        $this->resourceSubscription->subscribe($session2, $uri);

        $this->sessionStore->expects($this->once())
            ->method('getAllSessionIds')
            ->willReturn([$uuid1, $uuid2]);

        $sessionData1 = json_encode(['resource_subscriptions' => [$uri => true]]);
        $sessionData2 = json_encode(['resource_subscriptions' => [$uri => true]]);

        $this->sessionStore->expects($this->exactly(2))
            ->method('read')
            ->willReturnCallback(function ($id) use ($uuid1, $uuid2, $sessionData1, $sessionData2) {
                if ($id === $uuid1) {
                    return $sessionData1;
                }
                if ($id === $uuid2) {
                    return $sessionData2;
                }

                return false;
            });

        $this->sessionFactory->expects($this->exactly(2))
            ->method('createWithId')
            ->willReturnCallback(function ($id) use ($uuid1, $uuid2, $session1, $session2) {
                if ($id === $uuid1) {
                    return $session1;
                }
                if ($id === $uuid2) {
                    return $session2;
                }

                return null;
            });

        $this->protocol->expects($this->exactly(2))
            ->method('sendNotification')
            ->with($this->callback(function ($notification) use ($uri) {
                return $notification instanceof ResourceUpdatedNotification
                    && $notification->uri === $uri;
            }));

        // Act & Assert
        $this->resourceSubscription->notifyResourceChanged($this->protocol, $uri);
    }

    #[TestDox('Unsubscribing from a resource removes only the target session')]
    public function testUnsubscribeRemovesOnlyTargetSession(): void
    {
        // Arrange
        $session1 = $this->createMock(SessionInterface::class);
        $uuid1 = Uuid::v4();
        $session1->method('getId')->willReturn($uuid1);

        $uri = 'test://resource';

        $callCount = 0;
        $session1->expects($this->exactly(2))->method('get')->with('resource_subscriptions', [])
            ->willReturnCallback(function () use (&$callCount, $uri) {
                return 0 === $callCount++ ? [] : [$uri => true];
            });
        $session1->expects($this->exactly(2))->method('set')
            ->willReturnCallback(function ($key, $value) use ($uri) {
                // First call sets subscription, second call removes it
                static $callNum = 0;
                if (0 === $callNum++) {
                    $this->assertEquals('resource_subscriptions', $key);
                    $this->assertEquals([$uri => true], $value);
                } else {
                    $this->assertEquals('resource_subscriptions', $key);
                    $this->assertEquals([], $value);
                }
            });
        $session1->expects($this->exactly(2))->method('save');

        $this->resourceSubscription->subscribe($session1, $uri);

        $this->sessionStore->expects($this->once())
            ->method('getAllSessionIds')
            ->willReturn([$uuid1]);

        $sessionData = json_encode(['resource_subscriptions' => [$uri => true]]);
        $this->sessionStore->expects($this->once())
            ->method('read')
            ->with($uuid1)
            ->willReturn($sessionData);

        $this->sessionFactory->expects($this->once())
            ->method('createWithId')
            ->with($uuid1)
            ->willReturn($session1);

        $this->protocol->expects($this->exactly(1))
            ->method('sendNotification')
            ->with($this->callback(fn ($notification) => $notification instanceof ResourceUpdatedNotification && $notification->uri === $uri
            ));

        $this->resourceSubscription->notifyResourceChanged($this->protocol, $uri);

        // Act & Assert
        $this->resourceSubscription->unsubscribe($session1, $uri);
    }

    #[TestDox('Unsubscribing from a resource verifies that no notification is sent')]
    public function testUnsubscribeDoesNotSendNotifications(): void
    {
        // Arrange
        $protocol = $this->createMock(Protocol::class);
        $session = $this->createMock(SessionInterface::class);
        $uuid = Uuid::v4();
        $session->method('getId')->willReturn($uuid);
        $uri = 'test://resource';

        $callCount = 0;
        $session->expects($this->exactly(2))->method('get')->with('resource_subscriptions', [])
            ->willReturnCallback(function () use (&$callCount, $uri) {
                return 0 === $callCount++ ? [] : [$uri => true];
            });
        $session->expects($this->exactly(2))->method('set')
            ->willReturnCallback(function ($key, $value) use ($uri) {
                static $callNum = 0;
                $this->assertEquals('resource_subscriptions', $key);
                if (0 === $callNum++) {
                    $this->assertEquals([$uri => true], $value);
                } else {
                    $this->assertEquals([], $value);
                }
            });
        $session->expects($this->exactly(2))->method('save');

        // Act & Assert
        $this->resourceSubscription->subscribe($session, $uri);
        $this->resourceSubscription->unsubscribe($session, $uri);

        $this->sessionStore->expects($this->once())
            ->method('getAllSessionIds')
            ->willReturn([$uuid]);

        $sessionData = json_encode(['resource_subscriptions' => []]);
        $this->sessionStore->expects($this->once())
            ->method('read')
            ->with($uuid)
            ->willReturn($sessionData);

        $protocol->expects($this->never())->method('sendNotification');

        $this->resourceSubscription->notifyResourceChanged($protocol, $uri);
    }
}
