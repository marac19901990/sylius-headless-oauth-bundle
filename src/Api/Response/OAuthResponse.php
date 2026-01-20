<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Response;

final readonly class OAuthResponse
{
    public function __construct(
        public string $token,
        public ?string $refreshToken = null,
        public ?int $customerId = null,
    ) {
    }
}
