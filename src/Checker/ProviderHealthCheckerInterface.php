<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Checker;

/**
 * Interface for checking the health of OAuth providers.
 */
interface ProviderHealthCheckerInterface
{
    /**
     * Check all providers and return their health status.
     *
     * @return array<ProviderHealthStatus>
     */
    public function checkAll(): array;

    /**
     * Check if all enabled providers are healthy.
     */
    public function isAllHealthy(): bool;
}
