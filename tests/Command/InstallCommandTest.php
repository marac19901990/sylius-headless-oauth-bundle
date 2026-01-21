<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthChecker;
use Marac\SyliusHeadlessOAuthBundle\Command\InstallCommand;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class InstallCommandTest extends TestCase
{
    private string $tempDir;
    private Filesystem $filesystem;
    private ParameterBagInterface $parameterBag;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/sylius_oauth_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
        $this->filesystem->mkdir($this->tempDir . '/config/packages');

        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->parameterBag->method('has')->willReturn(false);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getClassMetadata')
            ->willThrowException(new Exception('Not mapped'));
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }

    public function testCommandExecutesSuccessfully(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('SyliusHeadlessOAuthBundle Installation', $commandTester->getDisplay());
        $this->assertStringContainsString('Installation wizard completed!', $commandTester->getDisplay());
    }

    public function testCommandDisplaysRequirementCheck(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 1: Checking Requirements', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('Extensions', $output);
        $this->assertStringContainsString('All requirements met', $output);
    }

    public function testCreatesConfigFileWhenMissing(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->setInputs(['yes']);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Step 2: Configuration Scaffolding', $output);
        $this->assertStringContainsString('sylius_headless_oauth.yaml', $output);
    }

    public function testSkipsConfigWhenAlreadyExistsAndUserDeclines(): void
    {
        // Create an existing config file
        $configPath = $this->tempDir . '/config/packages/sylius_headless_oauth.yaml';
        $this->filesystem->dumpFile($configPath, 'existing: config');

        $commandTester = $this->createCommandTester([]);
        $commandTester->setInputs(['no']);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Skipping configuration scaffolding', $output);

        // Verify original content is preserved
        $this->assertSame('existing: config', file_get_contents($configPath));
    }

    public function testOverwritesConfigWithForceOption(): void
    {
        // Create an existing config file
        $configPath = $this->tempDir . '/config/packages/sylius_headless_oauth.yaml';
        $this->filesystem->dumpFile($configPath, 'existing: config');

        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--force' => true]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());

        // If the template exists, it should be overwritten
        if (file_exists(__DIR__ . '/../../src/Command/Resources/sylius_headless_oauth.yaml.dist')) {
            $newContent = file_get_contents($configPath);
            $this->assertNotSame('existing: config', $newContent);
        }
    }

    public function testSkipsConfigWithSkipConfigOption(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Skipping configuration scaffolding (--skip-config)', $output);
    }

    public function testDisplaysEntitySetupInstructions(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 3: Entity Setup', $output);
        $this->assertStringContainsString('OAuthIdentityInterface', $output);
        $this->assertStringContainsString('OAuthIdentityTrait', $output);
    }

    public function testDisplaysMigrationInstructions(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 4: Database Migration', $output);
        $this->assertStringContainsString('doctrine:migrations:diff', $output);
        $this->assertStringContainsString('doctrine:migrations:migrate', $output);
    }

    public function testDisplaysEnvironmentVariablesCheck(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 5: Environment Variables', $output);
        $this->assertStringContainsString('GOOGLE_CLIENT_ID', $output);
        $this->assertStringContainsString('APPLE_CLIENT_ID', $output);
        $this->assertStringContainsString('FACEBOOK_CLIENT_ID', $output);
    }

    public function testDisplaysProviderHealthCheck(): void
    {
        $googleProvider = $this->createConfigurableProvider(
            name: 'google',
            enabled: true,
            credentials: ['client_id' => true, 'client_secret' => true],
        );

        $commandTester = $this->createCommandTester([$googleProvider]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 6: Provider Health Check', $output);
        $this->assertStringContainsString('Google', $output);
        $this->assertStringContainsString('Enabled', $output);
    }

    public function testDisplaysNoProvidersMessage(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('No OAuth providers registered yet', $output);
    }

    public function testDisplaysNextSteps(): void
    {
        $commandTester = $this->createCommandTester([]);
        $commandTester->execute(['--skip-config' => true]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Step 7: Next Steps Checklist', $output);
        $this->assertStringContainsString('sylius:oauth:check-providers', $output);
    }

    public function testCommandHasHelpText(): void
    {
        $healthChecker = new ProviderHealthChecker([]);
        $command = new InstallCommand(
            $healthChecker,
            $this->tempDir,
            $this->filesystem,
            $this->parameterBag,
            $this->entityManager,
        );

        $help = $command->getHelp();
        $description = $command->getDescription();

        $this->assertStringContainsString('--force', $help);
        $this->assertStringContainsString('--skip-config', $help);
        $this->assertSame('Install and configure SyliusHeadlessOAuthBundle', $description);
    }

    public function testCommandHasExpectedOptions(): void
    {
        $healthChecker = new ProviderHealthChecker([]);
        $command = new InstallCommand(
            $healthChecker,
            $this->tempDir,
            $this->filesystem,
            $this->parameterBag,
            $this->entityManager,
        );
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasOption('skip-config'));
        $this->assertSame('f', $definition->getOption('force')->getShortcut());
    }

    /**
     * @param array<int, ConfigurableOAuthProviderInterface> $providers
     */
    private function createCommandTester(array $providers): CommandTester
    {
        $healthChecker = new ProviderHealthChecker($providers);
        $command = new InstallCommand(
            $healthChecker,
            $this->tempDir,
            $this->filesystem,
            $this->parameterBag,
            $this->entityManager,
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('sylius:oauth:install'));
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
