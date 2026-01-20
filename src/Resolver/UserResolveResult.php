<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use Sylius\Component\Core\Model\ShopUserInterface;

/**
 * Result of user resolution during OAuth authentication.
 *
 * Contains the resolved ShopUser and metadata about the resolution,
 * such as whether this is a new user or an existing one.
 */
final class UserResolveResult
{
    public function __construct(
        public readonly ShopUserInterface $shopUser,
        public readonly bool $isNewUser,
    ) {
    }
}
