<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Checker;

/**
 * Represents the health status of an OAuth provider.
 */
final readonly class ProviderHealthStatus
{
    /**
     * @param string $name Provider name (e.g., 'google', 'apple')
     * @param bool $enabled Whether the provider is enabled
     * @param array<string, bool> $credentials Credential status (name => configured)
     * @param array<string> $issues List of configuration issues
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public array $credentials,
        public array $issues = [],
    ) {
    }

    /**
     * Check if the provider is healthy (enabled and all credentials configured).
     */
    public function isHealthy(): bool
    {
        if (!$this->enabled) {
            return true; // Disabled providers are considered healthy
        }

        return empty($this->issues) && !in_array(false, $this->credentials, true);
    }

    /**
     * Get list of missing credentials.
     *
     * @return array<string>
     */
    public function getMissingCredentials(): array
    {
        $missing = [];

        foreach ($this->credentials as $name => $configured) {
            if (!$configured) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
