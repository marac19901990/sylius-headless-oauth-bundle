<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

/**
 * Interface for OAuth providers that expose configuration status.
 *
 * This allows health checking and diagnostic tools to inspect
 * provider configuration without attempting actual OAuth flows.
 */
interface ConfigurableOAuthProviderInterface extends OAuthProviderInterface
{
    /**
     * Get the provider name (e.g., 'google', 'apple').
     */
    public function getName(): string;

    /**
     * Check if the provider is enabled in configuration.
     */
    public function isEnabled(): bool;

    /**
     * Get the status of each credential.
     *
     * Returns an associative array where keys are credential names
     * and values are booleans indicating if the credential is configured.
     *
     * Example:
     * [
     *     'client_id' => true,
     *     'client_secret' => false,
     * ]
     *
     * @return array<string, bool>
     */
    public function getCredentialStatus(): array;
}
