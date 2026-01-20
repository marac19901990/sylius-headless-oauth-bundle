<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;

/**
 * Interface for mapping OAuth provider names to entity field names.
 */
interface ProviderFieldMapperInterface
{
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
    public function getFieldName(string $provider): string;

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
    ): void;

    /**
     * Get the provider ID from an entity implementing OAuthIdentityInterface.
     *
     * @param OAuthIdentityInterface $entity The entity to read from
     * @param string $provider The provider name
     *
     * @return string|null The provider-specific user ID or null if not set
     */
    public function getProviderId(OAuthIdentityInterface $entity, string $provider): ?string;

    /**
     * Get all built-in provider names (excluding custom OIDC).
     *
     * @return array<string>
     */
    public function getBuiltInProviders(): array;

    /**
     * Check if a provider is a built-in provider.
     */
    public function isBuiltInProvider(string $provider): bool;

    /**
     * Check if a provider uses the generic OIDC field.
     */
    public function usesOidcField(string $provider): bool;
}
