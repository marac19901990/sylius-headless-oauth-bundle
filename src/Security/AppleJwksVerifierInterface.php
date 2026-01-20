<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

/**
 * Interface for verifying Apple id_token JWT signatures.
 */
interface AppleJwksVerifierInterface
{
    /**
     * Verify and decode an Apple id_token.
     *
     * @throws OAuthException If verification fails
     *
     * @return array{sub: string, email: string, email_verified?: bool, iss: string, aud: string, exp: int}
     */
    public function verify(string $idToken): array;

    /**
     * Clear the cached JWKS keys.
     *
     * Useful if keys have rotated and verification is failing.
     */
    public function clearCache(): void;
}
