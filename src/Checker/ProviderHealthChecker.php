<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Checker;

use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;

/**
 * Service to check the health of all registered OAuth providers.
 */
final class ProviderHealthChecker
{
    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * Check all providers and return their health status.
     *
     * @return array<ProviderHealthStatus>
     */
    public function checkAll(): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            $results[] = $this->checkProvider($provider);
        }

        return $results;
    }

    /**
     * Check if all enabled providers are healthy.
     */
    public function isAllHealthy(): bool
    {
        foreach ($this->checkAll() as $status) {
            if (!$status->isHealthy()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check a single provider's health.
     */
    private function checkProvider(OAuthProviderInterface $provider): ProviderHealthStatus
    {
        if (!$provider instanceof ConfigurableOAuthProviderInterface) {
            // For providers that don't implement the configurable interface,
            // we can only provide limited information
            return new ProviderHealthStatus(
                name: $provider::class,
                enabled: true, // Assume enabled if it's registered
                credentials: [],
                issues: ['Provider does not implement ConfigurableOAuthProviderInterface'],
            );
        }

        $credentials = $provider->getCredentialStatus();
        $issues = [];

        if ($provider->isEnabled()) {
            $missingCredentials = [];
            foreach ($credentials as $name => $configured) {
                if (!$configured) {
                    $missingCredentials[] = $name;
                }
            }

            if (!empty($missingCredentials)) {
                $issues[] = 'Missing credentials: ' . implode(', ', $missingCredentials);
            }
        }

        return new ProviderHealthStatus(
            name: $provider->getName(),
            enabled: $provider->isEnabled(),
            credentials: $credentials,
            issues: $issues,
        );
    }
}
