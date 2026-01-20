<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider\Model;

/**
 * Token data returned from OAuth token exchange or refresh.
 */
final readonly class OAuthTokenData
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?int $expiresIn = null,
        public ?string $tokenType = null,
        public ?string $idToken = null,
    ) {
    }
}
