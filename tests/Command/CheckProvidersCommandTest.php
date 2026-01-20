<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Command;

use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthChecker;
use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthStatus;
use Marac\SyliusHeadlessOAuthBundle\Command\CheckProvidersCommand;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CheckProvidersCommandTest extends TestCase
{
    private function createCommandTester(array $providers): CommandTester
    {
        $healthChecker = new ProviderHealthChecker($providers);
        $command = new CheckProvidersCommand($healthChecker);

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('sylius:oauth:check-providers'));
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

    public function testCommandSuccessWithHealthyProviders(): void
    {
        $googleProvider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => true]
        );

        $appleProvider = $this->createConfigurableProvider(
            name: 'apple',
            enabled: false,
            credentials: ['client_id' => true]
        );

        $commandTester = $this->createCommandTester([$googleProvider, $appleProvider]);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('OAuth Provider Health Check', $output);
        $this->assertStringContainsString('Google', $output);
        $this->assertStringContainsString('Apple', $output);
        $this->assertStringContainsString('Enabled', $output);
        $this->assertStringContainsString('Disabled', $output);
        $this->assertStringContainsString('All enabled OAuth providers are properly configured', $output);
    }

    public function testCommandFailureWithUnhealthyProvider(): void
    {
        $provider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => false]
        );

        $commandTester = $this->createCommandTester([$provider]);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Missing', $output);
        $this->assertStringContainsString('Some OAuth providers have configuration issues', $output);
    }

    public function testCommandWithNoProviders(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('No OAuth providers are registered', $output);
    }

    public function testCommandDisplaysCredentialStatus(): void
    {
        $provider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => true]
        );

        $commandTester = $this->createCommandTester([$provider]);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('client_id', $output);
        $this->assertStringContainsString('client_secret', $output);
        $this->assertStringContainsString('OK', $output);
    }
}
