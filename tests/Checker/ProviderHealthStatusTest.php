<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Checker;

use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthStatus;
use PHPUnit\Framework\TestCase;

class ProviderHealthStatusTest extends TestCase
{
    public function testHealthyEnabledProvider(): void
    {
        $status = new ProviderHealthStatus(
            name: 'google',
            enabled: true,
            credentials: [
                'client_id' => true,
                'client_secret' => true,
            ],
            issues: [],
        );

        $this->assertTrue($status->isHealthy());
        $this->assertEmpty($status->getMissingCredentials());
    }

    public function testHealthyDisabledProvider(): void
    {
        $status = new ProviderHealthStatus(
            name: 'apple',
            enabled: false,
            credentials: [
                'client_id' => false,
            ],
            issues: [],
        );

        // Disabled providers are considered healthy
        $this->assertTrue($status->isHealthy());
    }

    public function testUnhealthyProviderWithMissingCredentials(): void
    {
        $status = new ProviderHealthStatus(
            name: 'google',
            enabled: true,
            credentials: [
                'client_id' => true,
                'client_secret' => false,
            ],
            issues: [],
        );

        $this->assertFalse($status->isHealthy());
        $this->assertSame(['client_secret'], $status->getMissingCredentials());
    }

    public function testUnhealthyProviderWithIssues(): void
    {
        $status = new ProviderHealthStatus(
            name: 'google',
            enabled: true,
            credentials: [
                'client_id' => true,
                'client_secret' => true,
            ],
            issues: ['Some issue'],
        );

        $this->assertFalse($status->isHealthy());
    }

    public function testGetMissingCredentials(): void
    {
        $status = new ProviderHealthStatus(
            name: 'google',
            enabled: true,
            credentials: [
                'client_id' => false,
                'client_secret' => false,
                'redirect_uri' => true,
            ],
            issues: [],
        );

        $missing = $status->getMissingCredentials();

        $this->assertCount(2, $missing);
        $this->assertContains('client_id', $missing);
        $this->assertContains('client_secret', $missing);
        $this->assertNotContains('redirect_uri', $missing);
    }
}
