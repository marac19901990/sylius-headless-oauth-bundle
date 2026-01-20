<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Command;

use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthChecker;
use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthStatus;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function extension_loaded;
use function sprintf;

use const PHP_VERSION;

#[AsCommand(
    name: 'sylius:oauth:install',
    description: 'Install and configure SyliusHeadlessOAuthBundle',
)]
final class InstallCommand extends Command
{
    private const REQUIRED_PHP_VERSION = '8.2.0';
    private const REQUIRED_EXTENSIONS = ['json', 'openssl'];
    private const CONFIG_FILE_NAME = 'sylius_headless_oauth.yaml';

    private Filesystem $filesystem;

    public function __construct(
        private readonly ProviderHealthChecker $healthChecker,
        private readonly string $projectDir,
    ) {
        parent::__construct();
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing configuration without asking')
            ->addOption('skip-config', null, InputOption::VALUE_NONE, 'Skip configuration scaffolding')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command helps you set up the SyliusHeadlessOAuthBundle.

                It performs the following steps:
                  1. Checks PHP requirements (version and extensions)
                  2. Scaffolds the configuration file
                  3. Provides entity setup instructions
                  4. Shows database migration commands
                  5. Validates environment variables
                  6. Checks provider health
                  7. Displays next steps checklist

                <info>php %command.full_name%</info>

                Use --force to overwrite existing configuration:
                <info>php %command.full_name% --force</info>

                Use --skip-config to skip configuration scaffolding:
                <info>php %command.full_name% --skip-config</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('SyliusHeadlessOAuthBundle Installation');
        $io->text('This wizard will help you configure OAuth authentication for your Sylius headless application.');
        $io->newLine();

        // Step 1: Check Requirements
        if (!$this->checkRequirements($io)) {
            return Command::FAILURE;
        }

        // Step 2: Scaffold Configuration
        $force = (bool) $input->getOption('force');
        $skipConfig = (bool) $input->getOption('skip-config');

        if (!$skipConfig) {
            $this->scaffoldConfiguration($io, $force);
        } else {
            $io->note('Skipping configuration scaffolding (--skip-config)');
        }

        // Step 3: Entity Setup Instructions
        $this->showEntitySetupInstructions($io);

        // Step 4: Database Migration Instructions
        $this->showMigrationInstructions($io);

        // Step 5: Environment Variables Check
        $this->checkEnvironmentVariables($io);

        // Step 6: Provider Health Check
        $this->checkProviderHealth($io);

        // Step 7: Next Steps Checklist
        $this->showNextSteps($io);

        $io->success('Installation wizard completed!');

        return Command::SUCCESS;
    }

    private function checkRequirements(SymfonyStyle $io): bool
    {
        $io->section('Step 1: Checking Requirements');

        $issues = [];

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, self::REQUIRED_PHP_VERSION, '<')) {
            $issues[] = sprintf(
                'PHP %s or higher is required (current: %s)',
                self::REQUIRED_PHP_VERSION,
                $phpVersion,
            );
        }

        // Check required extensions
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $issues[] = sprintf('PHP extension "%s" is required but not loaded', $extension);
            }
        }

        // Display results
        $io->definitionList(
            ['PHP Version' => sprintf('%s (required: >=%s)', $phpVersion, self::REQUIRED_PHP_VERSION)],
            ['Extensions' => implode(', ', self::REQUIRED_EXTENSIONS)],
        );

        if (!empty($issues)) {
            foreach ($issues as $issue) {
                $io->error($issue);
            }

            return false;
        }

        $io->success('All requirements met');

        return true;
    }

    private function scaffoldConfiguration(SymfonyStyle $io, bool $force): void
    {
        $io->section('Step 2: Configuration Scaffolding');

        $configPath = $this->projectDir . '/config/packages/' . self::CONFIG_FILE_NAME;
        $templatePath = __DIR__ . '/Resources/' . self::CONFIG_FILE_NAME . '.dist';

        // Check if template exists
        if (!$this->filesystem->exists($templatePath)) {
            $io->warning('Configuration template not found. Skipping scaffolding.');

            return;
        }

        // Check if config already exists
        if ($this->filesystem->exists($configPath)) {
            if (!$force) {
                $io->note('Configuration file already exists: ' . $configPath);

                if (!$io->confirm('Overwrite existing configuration?', false)) {
                    $io->text('Skipping configuration scaffolding');

                    return;
                }
            }
        }

        // Create config directory if it doesn't exist
        $configDir = dirname($configPath);
        if (!$this->filesystem->exists($configDir)) {
            $this->filesystem->mkdir($configDir);
        }

        // Copy template to config
        $this->filesystem->copy($templatePath, $configPath, true);
        $io->success('Created config/packages/' . self::CONFIG_FILE_NAME);
        $io->text('Review and customize the configuration file for your needs.');
    }

    private function showEntitySetupInstructions(SymfonyStyle $io): void
    {
        $io->section('Step 3: Entity Setup');

        $io->text([
            'Your Customer entity must implement the OAuthIdentityInterface.',
            'Add the following to your <info>src/Entity/Customer/Customer.php</info>:',
        ]);

        $io->newLine();
        $io->writeln('<fg=cyan>use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;</>');
        $io->writeln('<fg=cyan>use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;</>');
        $io->newLine();
        $io->writeln('<fg=cyan>class Customer extends BaseCustomer implements OAuthIdentityInterface</>');
        $io->writeln('<fg=cyan>{</>');
        $io->writeln('<fg=cyan>    use OAuthIdentityTrait;</>');
        $io->writeln('<fg=cyan>}</>');
        $io->newLine();

        // Try to detect if Customer already implements the interface
        $customerClass = 'App\\Entity\\Customer\\Customer';
        if (class_exists($customerClass)) {
            if (is_subclass_of($customerClass, OAuthIdentityInterface::class)) {
                $io->success('Customer entity already implements OAuthIdentityInterface');
            } else {
                $io->warning('Customer entity does not yet implement OAuthIdentityInterface');
            }
        } else {
            $io->note('Could not auto-detect Customer entity. Ensure it implements OAuthIdentityInterface.');
        }
    }

    private function showMigrationInstructions(SymfonyStyle $io): void
    {
        $io->section('Step 4: Database Migration');

        $io->text('After modifying your Customer entity, run the following commands:');
        $io->newLine();

        $io->listing([
            '<info>bin/console doctrine:migrations:diff</info> - Generate migration',
            '<info>bin/console doctrine:migrations:migrate</info> - Apply migration',
        ]);

        $io->note('This will add OAuth identity columns (google_id, apple_id, facebook_id, oidc_id) to your customer table.');
    }

    private function checkEnvironmentVariables(SymfonyStyle $io): void
    {
        $io->section('Step 5: Environment Variables');

        $envVars = [
            'Google' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'Apple' => ['APPLE_CLIENT_ID', 'APPLE_TEAM_ID', 'APPLE_KEY_ID', 'APPLE_PRIVATE_KEY_PATH'],
            'Facebook' => ['FACEBOOK_CLIENT_ID', 'FACEBOOK_CLIENT_SECRET'],
            'GitHub' => ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET'],
        ];

        $rows = [];
        foreach ($envVars as $provider => $vars) {
            foreach ($vars as $var) {
                $value = getenv($var);
                $status = $value !== false && $value !== ''
                    ? '<info>Configured</info>'
                    : '<comment>Not set</comment>';
                $rows[] = [$provider, $var, $status];
                // Reset provider name for subsequent rows
                $provider = '';
            }
        }

        $io->table(['Provider', 'Environment Variable', 'Status'], $rows);

        $io->text([
            'Set environment variables in your <info>.env.local</info> file:',
            '',
            '  <fg=cyan>GOOGLE_CLIENT_ID=your-google-client-id</>',
            '  <fg=cyan>GOOGLE_CLIENT_SECRET=your-google-client-secret</>',
            '',
            'For OIDC providers (Keycloak, Auth0, Okta), add:',
            '',
            '  <fg=cyan>KEYCLOAK_ISSUER_URL=https://your-keycloak-server/realms/your-realm</>',
            '  <fg=cyan>KEYCLOAK_CLIENT_ID=your-client-id</>',
            '  <fg=cyan>KEYCLOAK_CLIENT_SECRET=your-client-secret</>',
        ]);
    }

    private function checkProviderHealth(SymfonyStyle $io): void
    {
        $io->section('Step 6: Provider Health Check');

        $statuses = $this->healthChecker->checkAll();

        if (empty($statuses)) {
            $io->note('No OAuth providers registered yet. Enable providers in your configuration.');

            return;
        }

        $this->renderProviderTable($io, $statuses);

        if ($this->healthChecker->isAllHealthy()) {
            $io->success('All enabled providers are properly configured');
        } else {
            $io->warning('Some providers have configuration issues. Review the table above.');
        }
    }

    /**
     * @param array<ProviderHealthStatus> $statuses
     */
    private function renderProviderTable(SymfonyStyle $io, array $statuses): void
    {
        $rows = [];

        foreach ($statuses as $status) {
            $statusText = $status->enabled
                ? '<info>Enabled</info>'
                : '<comment>Disabled</comment>';

            $healthText = $status->isHealthy()
                ? '<info>Healthy</info>'
                : '<error>Issues</error>';

            $issuesText = empty($status->issues)
                ? '-'
                : implode(', ', $status->issues);

            $rows[] = [
                ucfirst($status->name),
                $statusText,
                $healthText,
                $issuesText,
            ];
        }

        $io->table(['Provider', 'Status', 'Health', 'Issues'], $rows);
    }

    private function showNextSteps(SymfonyStyle $io): void
    {
        $io->section('Step 7: Next Steps Checklist');

        $io->listing([
            'Review and customize <info>config/packages/sylius_headless_oauth.yaml</info>',
            'Add OAuthIdentityTrait to your Customer entity',
            'Run database migrations',
            'Set up environment variables for your OAuth providers',
            'Configure allowed redirect URIs for your frontend applications',
            'Test OAuth flow: <info>POST /api/v2/auth/oauth/{provider}</info>',
            'Run <info>bin/console sylius:oauth:check-providers</info> to verify configuration',
        ]);

        $io->text([
            'Documentation: <fg=blue>https://github.com/your-org/sylius-headless-oauth-bundle</>',
            '',
            'For troubleshooting, see <info>docs/TROUBLESHOOTING.md</info>',
        ]);
    }
}
