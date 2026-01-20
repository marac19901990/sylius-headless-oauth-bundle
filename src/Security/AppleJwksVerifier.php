<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;
use UnexpectedValueException;

use function count;
use function in_array;
use function is_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies Apple id_token JWT signatures using Apple's JWKS endpoint.
 *
 * Apple's JWKS (JSON Web Key Set) contains the public keys used to sign id_tokens.
 * This service fetches these keys, caches them, and uses them to verify JWT signatures.
 */
final class AppleJwksVerifier
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';
    private const CACHE_KEY = 'apple_jwks_keys';
    private const CACHE_TTL = 86400; // 24 hours - Apple keys rotate infrequently

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ?CacheInterface $cache,
        private readonly string $clientId,
    ) {
    }

    /**
     * Verify and decode an Apple id_token.
     *
     * @throws OAuthException If verification fails
     *
     * @return array{sub: string, email: string, email_verified?: bool, iss: string, aud: string, exp: int}
     */
    public function verify(string $idToken): array
    {
        $keys = $this->getKeys();

        try {
            // firebase/php-jwt handles signature verification and standard claim validation
            $decoded = JWT::decode($idToken, $keys);
            $payload = (array) $decoded;

            // Verify issuer
            if (!isset($payload['iss']) || $payload['iss'] !== self::ISSUER) {
                throw new OAuthException(
                    'Apple id_token has invalid issuer',
                    401,
                );
            }

            // Verify audience (client_id)
            $aud = $payload['aud'] ?? null;
            if (!$this->isValidAudience($aud)) {
                throw new OAuthException(
                    'Apple id_token has invalid audience',
                    401,
                );
            }

            // Ensure required claims are present
            if (!isset($payload['sub']) || !is_string($payload['sub'])) {
                throw new OAuthException(
                    'Apple id_token missing required claim: sub',
                    401,
                );
            }

            if (!isset($payload['email']) || !is_string($payload['email'])) {
                throw new OAuthException(
                    'Apple id_token missing required claim: email',
                    401,
                );
            }

            /** @var array{sub: string, email: string, email_verified?: bool, iss: string, aud: string, exp: int} $payload */
            return $payload;
        } catch (UnexpectedValueException $e) {
            // JWT library throws this for invalid/expired tokens or signature mismatch
            throw new OAuthException(
                'Apple id_token verification failed: ' . $e->getMessage(),
                401,
                $e,
            );
        } catch (OAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OAuthException(
                'Apple id_token verification failed: ' . $e->getMessage(),
                401,
                $e,
            );
        }
    }

    /**
     * Clear the cached JWKS keys.
     *
     * Useful if keys have rotated and verification is failing.
     */
    public function clearCache(): void
    {
        $this->cache?->delete(self::CACHE_KEY);
    }

    /**
     * Validate the audience claim.
     * Audience can be a string or an array of strings.
     */
    private function isValidAudience(mixed $aud): bool
    {
        if ($aud === $this->clientId) {
            return true;
        }

        if (is_array($aud) && in_array($this->clientId, $aud, true)) {
            return true;
        }

        return false;
    }

    /**
     * Get the JWKS keys, using cache if available.
     *
     * @return array<string, Key>
     */
    private function getKeys(): array
    {
        if ($this->cache !== null) {
            /** @var array{keys: array<int, array<string, string>>} $jwks */
            $jwks = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchJwks();
            });

            return $this->parseKeys($jwks);
        }

        // No cache available - fetch directly
        return $this->parseKeys($this->fetchJwks());
    }

    /**
     * Parse JWKS data into Key objects.
     *
     * @param array<string, mixed> $jwks
     *
     * @return array<string, Key>
     */
    private function parseKeys(array $jwks): array
    {
        try {
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable $e) {
            throw new OAuthException(
                'Failed to parse Apple JWKS: ' . $e->getMessage(),
                500,
                $e,
            );
        }
    }

    /**
     * Fetch the JWKS from Apple's endpoint.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    private function fetchJwks(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::JWKS_URL, [
                'timeout' => 10,
                'http_errors' => true,
            ]);

            $body = $response->getBody()->getContents();
            $jwks = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($jwks['keys']) || !is_array($jwks['keys']) || count($jwks['keys']) === 0) {
                throw new OAuthException(
                    'Apple JWKS response missing keys',
                    500,
                );
            }

            return $jwks;
        } catch (GuzzleException $e) {
            throw new OAuthException(
                'Failed to fetch Apple JWKS: ' . $e->getMessage(),
                500,
                $e,
            );
        } catch (JsonException $e) {
            throw new OAuthException(
                'Failed to parse Apple JWKS response: ' . $e->getMessage(),
                500,
                $e,
            );
        }
    }
}
