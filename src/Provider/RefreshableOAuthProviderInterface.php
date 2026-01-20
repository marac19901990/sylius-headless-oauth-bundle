<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

/**
 * Interface for OAuth providers that support token refresh.
 */
interface RefreshableOAuthProviderInterface extends OAuthProviderInterface
{
    /**
     * Check if this provider supports refresh tokens.
     */
    public function supportsRefresh(): bool;

    /**
     * Refresh the OAuth tokens using a refresh token.
     *
     * Note: Some providers (like Apple) rotate refresh tokens on each use,
     * meaning the returned OAuthTokenData will contain a new refresh token.
     * Google allows the same refresh token to be used multiple times.
     *
     * @throws \Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException If refresh fails
     */
    public function refreshTokens(string $refreshToken): OAuthTokenData;

    /**
     * Fetch user data using an access token.
     *
     * Used during token refresh to identify the user.
     * Note: Some providers (like Apple) don't support this method
     * and will throw an exception. Use getUserDataFromTokenData instead.
     *
     * @throws \Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException If fetching user data fails
     */
    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData;

    /**
     * Extract user data from token data returned by refreshTokens().
     *
     * This method handles provider-specific differences:
     * - Google: Uses access_token to fetch from userinfo endpoint
     * - Apple: Decodes id_token JWT payload
     *
     * @throws \Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException If user data cannot be extracted
     */
    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData;
}
