<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Event;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before creating a new user during OAuth authentication.
 *
 * Use this event to:
 * - Modify user data before creation
 * - Add custom fields or metadata
 * - Perform validation checks
 * - Block user creation if needed
 */
final class OAuthPreUserCreateEvent extends Event
{
    public const NAME = 'sylius.headless_oauth.pre_user_create';

    /** @var array<string, mixed> */
    private array $additionalData = [];

    public function __construct(
        public readonly OAuthUserData $userData,
    ) {
    }

    /** @return array<string, mixed> */
    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    /** @param array<string, mixed> $data */
    public function setAdditionalData(array $data): void
    {
        $this->additionalData = $data;
    }
}
