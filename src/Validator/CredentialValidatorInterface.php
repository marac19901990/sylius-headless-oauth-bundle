<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Validator;

use InvalidArgumentException;

/**
 * Interface for OAuth provider credential validation.
 *
 * Centralizes credential validation logic to ensure DRY principle
 * and consistent error messages across providers.
 */
interface CredentialValidatorInterface
{
    /**
     * Validate that a credential is configured and not using placeholder env var.
     *
     * @param string $value The credential value to validate
     * @param string $envVarName The expected environment variable name (e.g., 'GOOGLE_CLIENT_ID')
     * @param string $providerName The provider name for error messages (e.g., 'Google', 'Apple')
     * @param string $credentialName Human-readable credential name (e.g., 'client ID', 'team ID')
     *
     * @throws InvalidArgumentException If the credential is not configured
     */
    public function validate(
        string $value,
        string $envVarName,
        string $providerName,
        string $credentialName,
    ): void;

    /**
     * Validate multiple credentials at once.
     *
     * @param list<array{value: string, env: string, name: string}> $credentials
     * @param string $providerName The provider name for error messages
     *
     * @throws InvalidArgumentException If any credential is not configured
     */
    public function validateMany(array $credentials, string $providerName): void;
}
