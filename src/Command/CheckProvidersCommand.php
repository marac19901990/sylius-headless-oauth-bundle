<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Command;

use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthChecker;
use Marac\SyliusHeadlessOAuthBundle\Checker\ProviderHealthStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'sylius:oauth:check-providers',
    description: 'Check the health and configuration of OAuth providers',
)]
final class CheckProvidersCommand extends Command
{
    public function __construct(
        private readonly ProviderHealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('OAuth Provider Health Check');

        $statuses = $this->healthChecker->checkAll();

        if (empty($statuses)) {
            $io->warning('No OAuth providers are registered.');

            return Command::SUCCESS;
        }

        $this->renderTable($io, $statuses);

        $allHealthy = $this->healthChecker->isAllHealthy();

        if ($allHealthy) {
            $io->success('All enabled OAuth providers are properly configured.');

            return Command::SUCCESS;
        }

        $io->error('Some OAuth providers have configuration issues.');

        return Command::FAILURE;
    }

    /**
     * @param array<ProviderHealthStatus> $statuses
     */
    private function renderTable(SymfonyStyle $io, array $statuses): void
    {
        $rows = [];

        foreach ($statuses as $status) {
            $statusText = $status->enabled ? '<info>Enabled</info>' : '<comment>Disabled</comment>';
            $credentialsText = $this->formatCredentials($status);
            $issuesText = empty($status->issues) ? '<info>None</info>' : '<error>' . implode(', ', $status->issues) . '</error>';

            $rows[] = [
                ucfirst($status->name),
                $statusText,
                $credentialsText,
                $issuesText,
            ];
        }

        $io->table(
            ['Provider', 'Status', 'Credentials', 'Issues'],
            $rows,
        );
    }

    private function formatCredentials(ProviderHealthStatus $status): string
    {
        if (empty($status->credentials)) {
            return '<comment>-</comment>';
        }

        if (!$status->enabled) {
            return '<comment>-</comment>';
        }

        $lines = [];
        foreach ($status->credentials as $name => $configured) {
            $statusIcon = $configured ? '<info>OK</info>' : '<error>Missing</error>';
            $lines[] = sprintf('%s: %s', $name, $statusIcon);
        }

        return implode("\n", $lines);
    }
}
