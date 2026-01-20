<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

interface OAuthProviderInterface
{
    /**
     * Check if this provider supports the given provider name.
     */
    public function supports(string $provider): bool;

    /**
     * Exchange the authorization code for user data.
     *
     * @param string $code The authorization code from the OAuth callback
     * @param string $redirectUri The redirect URI used in the authorization request
     *
     * @return OAuthUserData The user data from the OAuth provider
     */
    public function getUserData(string $code, string $redirectUri): OAuthUserData;
}
