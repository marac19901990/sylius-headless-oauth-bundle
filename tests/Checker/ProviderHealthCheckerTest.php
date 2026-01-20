<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Checker;

use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthChecker;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use PHPUnit\Framework\TestCase;

class ProviderHealthCheckerTest extends TestCase
{
    public function testCheckAllWithConfigurableProviders(): void
    {
        $googleProvider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => true],
        );

        $appleProvider = $this->createConfigurableProvider(
            name: 'apple',
            enabled: false,
            credentials: ['client_id' => true],
        );

        $checker = new ProviderHealthChecker([$googleProvider, $appleProvider]);
        $results = $checker->checkAll();

        $this->assertCount(2, $results);

        $this->assertSame('google', $results[0]->name);
        $this->assertTrue($results[0]->enabled);
        $this->assertTrue($results[0]->isHealthy());

        $this->assertSame('apple', $results[1]->name);
        $this->assertFalse($results[1]->enabled);
        $this->assertTrue($results[1]->isHealthy());
    }

    public function testCheckAllWithNonConfigurableProvider(): void
    {
        $nonConfigurableProvider = $this->createMock(OAuthProviderInterface::class);

        $checker = new ProviderHealthChecker([$nonConfigurableProvider]);
        $results = $checker->checkAll();

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isHealthy());
        $this->assertNotEmpty($results[0]->issues);
        $this->assertStringContainsString('ConfigurableOAuthProviderInterface', $results[0]->issues[0]);
    }

    public function testCheckAllWithMissingCredentials(): void
    {
        $provider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => false],
        );

        $checker = new ProviderHealthChecker([$provider]);
        $results = $checker->checkAll();

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isHealthy());
        $this->assertNotEmpty($results[0]->issues);
        $this->assertStringContainsString('client_secret', $results[0]->issues[0]);
    }

    public function testIsAllHealthyWithHealthyProviders(): void
    {
        $provider1 = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => true],
        );

        $provider2 = $this->createConfigurableProvider(
            name: 'apple',
            enabled: false,
            credentials: ['client_id' => false],
        );

        $checker = new ProviderHealthChecker([$provider1, $provider2]);

        $this->assertTrue($checker->isAllHealthy());
    }

    public function testIsAllHealthyWithUnhealthyProvider(): void
    {
        $provider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => false],
        );

        $checker = new ProviderHealthChecker([$provider]);

        $this->assertFalse($checker->isAllHealthy());
    }

    public function testCheckAllWithNoProviders(): void
    {
        $checker = new ProviderHealthChecker([]);
        $results = $checker->checkAll();

        $this->assertEmpty($results);
        $this->assertTrue($checker->isAllHealthy());
    }

    /**
     * @param array<string, bool> $credentials
     */
    private function createConfigurableProvider(
        string $name,
        bool $enabled,
        array $credentials,
    ): ConfigurableOAuthProviderInterface {
        $provider = $this->createMock(ConfigurableOAuthProviderInterface::class);

        $provider->method('getName')->willReturn($name);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getCredentialStatus')->willReturn($credentials);

        return $provider;
    }
}
