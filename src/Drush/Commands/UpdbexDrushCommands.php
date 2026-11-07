<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Consolidation\SiteProcess\ProcessBase;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use UnexpectedValueException;
use YourVendor\DrushUpdbex\ConfigStatusResponse;
use YourVendor\DrushUpdbex\UpdbexWorkflow;

#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class UpdbexDrushCommands extends DrushCommands
{
    use AutowireTrait;

    public function __construct(private readonly SiteAliasManagerInterface $siteAliasManager)
    {
        parent::__construct();
    }

    /**
     * Rebuild caches, apply database updates, and export resulting configuration changes.
     *
     * @throws \Throwable When an operation or change-detection step fails.
     */
    #[CLI\Command(name: 'updbex')]
    #[CLI\Option(name: 'no-config-export', description: 'Skip configuration change detection and export after database updates.')]
    #[CLI\Usage(name: 'drush updbex', description: 'Rebuild caches, run database updates, and export changed configuration.')]
    public function updbex(array $options = ['no-config-export' => false]): void
    {
        if ($this->getConfig()->simulate()) {
            throw new UnexpectedValueException('updbex cannot run with --simulate because its configuration check requires completed database updates.');
        }

        $workflow = new UpdbexWorkflow();
        $redispatchOptions = Drush::redispatchOptions();
        unset($redispatchOptions['no-config-export']);

        $runCommand = function (string $command, array $commandOptions) use ($redispatchOptions): void {
            $options = array_merge($redispatchOptions, $commandOptions);
            $process = $this->processManager()->drush(
                $this->siteAliasManager->getSelf(),
                $command,
                [],
                $options,
            );
            $this->mustRun($process);
        };

        $detectChanges = function () use ($redispatchOptions): bool {
            $process = $this->processManager()->drush(
                $this->siteAliasManager->getSelf(),
                'updbex:config-status',
                [],
                array_merge($redispatchOptions, ['format' => 'json']),
            );
            $this->mustRun($process, true);

            return ConfigStatusResponse::decode($process->getOutput());
        };

        $workflow->execute(
            $runCommand,
            $detectChanges,
            fn (string $message) => $this->io()->writeln($message),
            (bool) ($options['no-config-export'] ?? false),
        );
    }

    private function mustRun(ProcessBase $process, bool $hideStdout = false): void
    {
        $this->output()->writeln('Running: ' . $process->getCommandLine(), OutputInterface::OUTPUT_RAW);
        try {
            $realtimeOutput = $process->showRealtime();
            if ($hideStdout) {
                $realtimeOutput->hideStdout();
            }
            $process->mustRun($realtimeOutput);
        } catch (ProcessFailedException $exception) {
            $exitCode = $exception->getProcess()->getExitCode() ?: 1;
            throw new RuntimeException(sprintf('Command failed with exit code %d.', $exitCode), $exitCode, $exception);
        }
    }
}
