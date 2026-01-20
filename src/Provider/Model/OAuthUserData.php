<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider\Model;

final readonly class OAuthUserData
{
    public function __construct(
        public string $provider,
        public string $providerId,
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
    ) {
    }
}
