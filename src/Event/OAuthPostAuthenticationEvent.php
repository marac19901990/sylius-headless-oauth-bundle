<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Event;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after successful OAuth authentication.
 *
 * Use this event to:
 * - Merge anonymous cart with user's cart
 * - Send welcome emails for new users
 * - Track analytics events
 * - Update user preferences
 */
final class OAuthPostAuthenticationEvent extends Event
{
    public const NAME = 'sylius.headless_oauth.post_authentication';

    public function __construct(
        public readonly ShopUserInterface $shopUser,
        public readonly OAuthUserData $userData,
        public readonly bool $isNewUser,
    ) {
    }
}
