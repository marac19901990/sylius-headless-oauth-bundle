<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Service;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

/**
 * Interface for discovering OpenID Connect provider configuration.
 *
 * Implementations fetch and cache the .well-known/openid-configuration document
 * which contains endpoints and capabilities of the OIDC provider.
 */
interface OidcDiscoveryServiceInterface
{
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
    public function discover(string $issuerUrl): array;

    /**
     * Get the token endpoint for the given issuer.
     *
     * @throws OAuthException
     */
    public function getTokenEndpoint(string $issuerUrl): string;

    /**
     * Get the userinfo endpoint for the given issuer.
     *
     * @throws OAuthException
     */
    public function getUserinfoEndpoint(string $issuerUrl): ?string;

    /**
     * Get the JWKS URI for the given issuer.
     *
     * @throws OAuthException
     */
    public function getJwksUri(string $issuerUrl): string;

    /**
     * Check if the issuer supports a specific scope.
     *
     * @throws OAuthException
     */
    public function supportsScope(string $issuerUrl, string $scope): bool;

    /**
     * Clear the cache for a specific issuer URL.
     */
    public function clearCache(string $issuerUrl): void;
}
