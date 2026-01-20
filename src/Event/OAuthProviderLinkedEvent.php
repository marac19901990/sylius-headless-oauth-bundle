<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Event;

use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when an OAuth provider is linked to an existing customer account.
 *
 * This occurs when a user authenticates via OAuth but their email already
 * exists in the system from a previous registration (e.g., email/password signup).
 *
 * Use this event to:
 * - Send notifications about the linked provider
 * - Create audit log entries
 * - Update security settings
 */
final class OAuthProviderLinkedEvent extends Event
{
    public const NAME = 'sylius.headless_oauth.provider_linked';

    public function __construct(
        public readonly CustomerInterface $customer,
        public readonly string $provider,
        public readonly string $providerId,
    ) {
    }
}
