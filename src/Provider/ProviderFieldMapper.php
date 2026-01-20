<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;

/**
 * Maps OAuth provider names to their corresponding entity field names.
 *
 * This service centralizes the mapping logic that was previously
 * duplicated across UserResolver methods.
 */
final class ProviderFieldMapper
{
    private const PROVIDER_FIELD_MAP = [
        'google' => 'googleId',
        'apple' => 'appleId',
        'facebook' => 'facebookId',
        'github' => 'githubId',
        'oidc' => 'oidcId',
    ];

    /**
     * Get the entity field name for a provider's ID.
     *
     * For known providers (google, apple, facebook), returns their specific field.
     * For OIDC providers (or any unknown provider), returns 'oidcId' as the default.
     *
     * @param string $provider The provider name (e.g., 'google', 'apple', 'keycloak')
     *
     * @return string The entity field name (e.g., 'googleId', 'appleId', 'oidcId')
     */
    public function getFieldName(string $provider): string
    {
        // Known providers use their specific fields
        if (isset(self::PROVIDER_FIELD_MAP[$provider])) {
            return self::PROVIDER_FIELD_MAP[$provider];
        }

        // Unknown providers (custom OIDC) use the generic oidcId field
        return 'oidcId';
    }

    /**
     * Set the provider ID on an entity implementing OAuthIdentityInterface.
     *
     * @param OAuthIdentityInterface $entity The entity to update
     * @param string $provider The provider name
     * @param string|null $providerId The provider-specific user ID, or null to unlink
     */
    public function setProviderId(
        OAuthIdentityInterface $entity,
        string $provider,
        ?string $providerId,
    ): void {
        match ($provider) {
            'google' => $entity->setGoogleId($providerId),
            'apple' => $entity->setAppleId($providerId),
            'facebook' => $entity->setFacebookId($providerId),
            'github' => $entity->setGithubId($providerId),
            // Any OIDC or custom provider uses the generic oidcId field
            default => $entity->setOidcId($providerId),
        };
    }

    /**
     * Get the provider ID from an entity implementing OAuthIdentityInterface.
     *
     * @param OAuthIdentityInterface $entity The entity to read from
     * @param string $provider The provider name
     *
     * @return string|null The provider-specific user ID or null if not set
     */
    public function getProviderId(OAuthIdentityInterface $entity, string $provider): ?string
    {
        return match ($provider) {
            'google' => $entity->getGoogleId(),
            'apple' => $entity->getAppleId(),
            'facebook' => $entity->getFacebookId(),
            'github' => $entity->getGithubId(),
            // Any OIDC or custom provider uses the generic oidcId field
            default => $entity->getOidcId(),
        };
    }

    /**
     * Get all built-in provider names (excluding custom OIDC).
     *
     * @return array<string>
     */
    public function getBuiltInProviders(): array
    {
        return array_keys(self::PROVIDER_FIELD_MAP);
    }

    /**
     * Check if a provider is a built-in provider.
     */
    public function isBuiltInProvider(string $provider): bool
    {
        return isset(self::PROVIDER_FIELD_MAP[$provider]);
    }

    /**
     * Check if a provider uses the generic OIDC field.
     */
    public function usesOidcField(string $provider): bool
    {
        return !$this->isBuiltInProvider($provider) || $provider === 'oidc';
    }
}
