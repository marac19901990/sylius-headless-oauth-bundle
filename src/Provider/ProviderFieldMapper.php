<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

use function sprintf;

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
    ];

    /**
     * Get the entity field name for a provider's ID.
     *
     * @param string $provider The provider name (e.g., 'google', 'apple')
     *
     * @throws OAuthException If the provider is unknown
     *
     * @return string The entity field name (e.g., 'googleId', 'appleId')
     */
    public function getFieldName(string $provider): string
    {
        return self::PROVIDER_FIELD_MAP[$provider]
            ?? throw new OAuthException(sprintf('Unknown provider: %s', $provider));
    }

    /**
     * Set the provider ID on an entity implementing OAuthIdentityInterface.
     *
     * @param OAuthIdentityInterface $entity The entity to update
     * @param string $provider The provider name
     * @param string $providerId The provider-specific user ID
     *
     * @throws OAuthException If the provider is unknown
     */
    public function setProviderId(
        OAuthIdentityInterface $entity,
        string $provider,
        string $providerId,
    ): void {
        match ($provider) {
            'google' => $entity->setGoogleId($providerId),
            'apple' => $entity->setAppleId($providerId),
            'facebook' => $entity->setFacebookId($providerId),
            default => throw new OAuthException(sprintf('Unknown provider: %s', $provider)),
        };
    }

    /**
     * Get all supported provider names.
     *
     * @return array<string>
     */
    public function getSupportedProviders(): array
    {
        return array_keys(self::PROVIDER_FIELD_MAP);
    }

    /**
     * Check if a provider is supported.
     */
    public function isSupported(string $provider): bool
    {
        return isset(self::PROVIDER_FIELD_MAP[$provider]);
    }
}
