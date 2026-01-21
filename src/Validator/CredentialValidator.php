<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Validator;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

use function sprintf;

/**
 * Validates OAuth provider credentials configuration.
 *
 * Centralizes credential validation logic to ensure DRY principle
 * and consistent error messages across providers.
 */
final class CredentialValidator implements CredentialValidatorInterface
{
    /**
     * Validate that a credential is configured and not using placeholder env var.
     *
     * @param string $value The credential value to validate
     * @param string $envVarName The expected environment variable name (e.g., 'GOOGLE_CLIENT_ID')
     * @param string $providerName The provider name for error messages (e.g., 'Google', 'Apple')
     * @param string $credentialName Human-readable credential name (e.g., 'client ID', 'team ID')
     *
     * @throws OAuthException If the credential is not configured
     */
    public function validate(
        string $value,
        string $envVarName,
        string $providerName,
        string $credentialName,
    ): void {
        $placeholder = sprintf('%%env(%s)%%', $envVarName);
        $providerLower = strtolower($providerName);

        if (empty($value) || $value === $placeholder) {
            throw new OAuthException(sprintf(
                '%s OAuth is enabled but %s is not configured. ' .
                'Set the environment variable or disable %s: sylius_headless_oauth.providers.%s.enabled: false',
                $providerName,
                $envVarName,
                $providerName,
                $providerLower,
            ));
        }
    }

    /**
     * Validate multiple credentials at once.
     *
     * @param list<array{value: string, env: string, name: string}> $credentials
     * @param string $providerName The provider name for error messages
     *
     * @throws OAuthException If any credential is not configured
     */
    public function validateMany(array $credentials, string $providerName): void
    {
        foreach ($credentials as $config) {
            $this->validate(
                $config['value'],
                $config['env'],
                $providerName,
                $config['name'],
            );
        }
    }
}
