<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Resource;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Resource subscription implementation that manages MCP subscribe, unsubscribe, and notification element.
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
final class ResourceSubscription implements ResourceSubscriptionInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SessionStoreInterface $sessionStore = null,
        private readonly ?SessionFactoryInterface $sessionFactory = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function subscribe(SessionInterface $session, string $uri): void
    {
        $subscriptions = $session->get('resource_subscriptions', []);
        $subscriptions[$uri] = true;
        $session->set('resource_subscriptions', $subscriptions);
        $session->save();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function unsubscribe(SessionInterface $session, string $uri): void
    {
        $subscriptions = $session->get('resource_subscriptions', []);
        unset($subscriptions[$uri]);
        $session->set('resource_subscriptions', $subscriptions);
        $session->save();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function notifyResourceChanged(Protocol $protocol, string $uri): void
    {
        if (!$this->sessionStore || !$this->sessionFactory) {
            $this->logger->warning('Cannot send resource notifications: session store or factory not configured.');

            return;
        }

        foreach ($this->sessionStore->getAllSessionIds() as $sessionId) {
            try {
                $sessionData = $this->sessionStore->read($sessionId);
                if (!$sessionData) {
                    continue;
                }

                $sessionArray = json_decode($sessionData, true);
                if (!\is_array($sessionArray)) {
                    continue;
                }

                if (!isset($sessionArray['resource_subscriptions'][$uri])) {
                    continue;
                }

                $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);
                $protocol->sendNotification(new ResourceUpdatedNotification($uri), $session);
            } catch (InvalidArgumentException $e) {
                $this->logger->error('Error sending resource notification to session', [
                    'session_id' => $sessionId->toRfc4122(),
                    'uri' => $uri,
                    'exception' => $e,
                ]);
            }
        }
    }
}
