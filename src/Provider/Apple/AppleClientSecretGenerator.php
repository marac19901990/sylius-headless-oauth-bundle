<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider\Apple;

use Firebase\JWT\JWT;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidatorInterface;

use function sprintf;

/**
 * Generates the client_secret JWT required for Apple Sign-In.
 *
 * Apple doesn't use a static client_secret. Instead, you must generate a JWT
 * signed with your private key (.p8 file from Apple Developer Portal).
 *
 * The JWT is valid for up to 6 months, so it can be cached.
 */
final class AppleClientSecretGenerator implements AppleClientSecretGeneratorInterface
{
    private const APPLE_AUDIENCE = 'https://appleid.apple.com';
    private const MAX_EXPIRY_SECONDS = 15777000; // ~6 months

    public function __construct(
        private readonly CredentialValidatorInterface $credentialValidator,
        private readonly string $clientId,
        private readonly string $teamId,
        private readonly string $keyId,
        private readonly string $privateKeyPath,
    ) {
        $this->validateCredentials();
    }

    /**
     * Generate a client_secret JWT for Apple Sign-In.
     *
     * @param int $expirySeconds How long the token should be valid (max 6 months)
     */
    public function generate(int $expirySeconds = 3600): string
    {
        $privateKey = $this->loadPrivateKey();
        $now = time();

        $expirySeconds = min($expirySeconds, self::MAX_EXPIRY_SECONDS);

        $payload = [
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + $expirySeconds,
            'aud' => self::APPLE_AUDIENCE,
            'sub' => $this->clientId,
        ];

        return JWT::encode($payload, $privateKey, 'ES256', $this->keyId);
    }

    private function validateCredentials(): void
    {
        $this->credentialValidator->validateMany([
            ['value' => $this->clientId, 'env' => 'APPLE_CLIENT_ID', 'name' => 'client ID'],
            ['value' => $this->teamId, 'env' => 'APPLE_TEAM_ID', 'name' => 'team ID'],
            ['value' => $this->keyId, 'env' => 'APPLE_KEY_ID', 'name' => 'key ID'],
            ['value' => $this->privateKeyPath, 'env' => 'APPLE_PRIVATE_KEY_PATH', 'name' => 'private key path'],
        ], 'Apple');
    }

    private function loadPrivateKey(): string
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new OAuthException(sprintf(
                'Apple private key file not found at: %s',
                $this->privateKeyPath,
            ));
        }

        $privateKey = file_get_contents($this->privateKeyPath);

        if ($privateKey === false) {
            throw new OAuthException(sprintf(
                'Failed to read Apple private key file: %s',
                $this->privateKeyPath,
            ));
        }

        return $privateKey;
    }
}
