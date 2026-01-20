<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Psr\Cache\CacheItemPoolInterface;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Service for discovering OpenID Connect provider configuration.
 *
 * Fetches and caches the .well-known/openid-configuration document
 * which contains endpoints and capabilities of the OIDC provider.
 */
final class OidcDiscoveryService implements OidcDiscoveryServiceInterface
{
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * Discover OIDC configuration from the issuer URL.
     *
     * The returned array contains at minimum:
     * - issuer: string
     * - authorization_endpoint: string
     * - token_endpoint: string
     * - jwks_uri: string
     *
     * And optionally:
     * - userinfo_endpoint: string
     * - scopes_supported: array<string>
     * - response_types_supported: array<string>
     * - grant_types_supported: array<string>
     * - id_token_signing_alg_values_supported: array<string>
     *
     * @throws OAuthException
     *
     * @return array<string, mixed>
     */
    public function discover(string $issuerUrl): array
    {
        $cacheKey = $this->getCacheKey($issuerUrl);

        if ($this->cache !== null) {
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                /** @var array<string, mixed> $cached */
                $cached = $cacheItem->get();

                return $cached;
            }
        }

        $config = $this->fetchConfiguration($issuerUrl);

        if ($this->cache !== null) {
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($config);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
        }

        return $config;
    }

    /**
     * Get the token endpoint for the given issuer.
     */
    public function getTokenEndpoint(string $issuerUrl): string
    {
        $config = $this->discover($issuerUrl);

        return $config['token_endpoint'];
    }

    /**
     * Get the userinfo endpoint for the given issuer.
     */
    public function getUserinfoEndpoint(string $issuerUrl): ?string
    {
        $config = $this->discover($issuerUrl);

        return $config['userinfo_endpoint'] ?? null;
    }

    /**
     * Get the JWKS URI for the given issuer.
     */
    public function getJwksUri(string $issuerUrl): string
    {
        $config = $this->discover($issuerUrl);

        return $config['jwks_uri'];
    }

    /**
     * Check if the issuer supports a specific scope.
     */
    public function supportsScope(string $issuerUrl, string $scope): bool
    {
        $config = $this->discover($issuerUrl);
        $supportedScopes = $config['scopes_supported'] ?? ['openid'];

        return in_array($scope, $supportedScopes, true);
    }

    /**
     * Clear the cache for a specific issuer URL.
     */
    public function clearCache(string $issuerUrl): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->deleteItem($this->getCacheKey($issuerUrl));
    }

    /**
     * Fetch the OpenID configuration document.
     *
     * @throws OAuthException
     *
     * @return array<string, mixed>
     */
    private function fetchConfiguration(string $issuerUrl): array
    {
        $discoveryUrl = rtrim($issuerUrl, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            throw new OAuthException(
                sprintf('Failed to fetch OIDC configuration from "%s": %s', $discoveryUrl, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw new OAuthException(
                sprintf(
                    'OIDC discovery endpoint returned status %d for "%s"',
                    $response->getStatusCode(),
                    $discoveryUrl,
                ),
            );
        }

        $body = (string) $response->getBody();

        /** @var array<string, mixed>|null $config */
        $config = json_decode($body, true);

        if ($config === null) {
            throw new OAuthException(
                sprintf('Invalid JSON response from OIDC discovery endpoint "%s"', $discoveryUrl),
            );
        }

        $this->validateConfiguration($config, $discoveryUrl);

        return $config;
    }

    /**
     * Validate the required fields in the OIDC configuration.
     *
     * @param array<string, mixed> $config
     *
     * @throws OAuthException
     */
    private function validateConfiguration(array $config, string $discoveryUrl): void
    {
        $requiredFields = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || !is_string($config[$field])) {
                throw new OAuthException(
                    sprintf('OIDC configuration from "%s" is missing required field "%s"', $discoveryUrl, $field),
                );
            }
        }
    }

    /**
     * Generate a cache key for the given issuer URL.
     */
    private function getCacheKey(string $issuerUrl): string
    {
        return 'oidc_discovery_' . md5($issuerUrl);
    }
}
