<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Entity;

interface OAuthIdentityInterface
{
    public function getGoogleId(): ?string;

    public function setGoogleId(?string $googleId): void;

    public function getAppleId(): ?string;

    public function setAppleId(?string $appleId): void;

    public function getFacebookId(): ?string;

    public function setFacebookId(?string $facebookId): void;

    public function getGithubId(): ?string;

    public function setGithubId(?string $githubId): void;

    /**
     * Get the OAuth provider ID for a generic OIDC provider.
     * This is used when configuring custom OIDC providers (Keycloak, Auth0, Okta, etc.)
     */
    public function getOidcId(): ?string;

    public function setOidcId(?string $oidcId): void;
}
