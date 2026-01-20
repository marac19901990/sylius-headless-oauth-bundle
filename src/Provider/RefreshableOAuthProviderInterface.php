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
     *
     * @throws \Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException If fetching user data fails
     */
    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData;
}
