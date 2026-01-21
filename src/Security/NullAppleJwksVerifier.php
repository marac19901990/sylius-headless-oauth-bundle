<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

use function count;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Null Object implementation of AppleJwksVerifierInterface.
 *
 * Used when JWT verification is disabled or when the real verifier cannot be configured.
 * Decodes the token without signature verification - should only be used in testing
 * or when security requirements explicitly allow unverified tokens.
 *
 * WARNING: This implementation does NOT verify JWT signatures. Use the real
 * AppleJwksVerifier in production environments where security is required.
 */
final class NullAppleJwksVerifier implements AppleJwksVerifierInterface
{
    /**
     * Decode an Apple id_token without verification.
     *
     * @return array{sub: string, email: string, email_verified?: bool, iss: string, aud: string, exp: int}
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new OAuthException('Invalid Apple id_token format');
        }

        $payload = $this->base64UrlDecode($parts[1]);
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['sub'], $data['email'])) {
            throw new OAuthException('Apple id_token missing required claims (sub, email)');
        }

        return $data;
    }

    public function clearCache(): void
    {
        // No cache to clear in null implementation
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new OAuthException('Failed to decode Apple id_token payload');
        }

        return $decoded;
    }
}
