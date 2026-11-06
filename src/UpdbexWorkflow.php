<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex;

use Throwable;
use UnexpectedValueException;

final class UpdbexWorkflow
{
    public function execute(callable $runCommand, callable $detectChanges, callable $write, bool $noConfigExport = false): void
    {
        $this->runOperation('Cache rebuild', 'cache:rebuild', [], $runCommand, $write);
        $this->runOperation('Database updates', 'updatedb', ['yes' => null], $runCommand, $write);

        if ($noConfigExport) {
            $write('Configuration export disabled by --no-config-export. Skipping configuration export.');
            $write('updbex workflow completed successfully.');
            return;
        }

        $write('Checking active configuration against the synchronization directory...');
        try {
            $hasChanges = $detectChanges();
        } catch (Throwable $exception) {
            throw new WorkflowException('Configuration change detection', $exception);
        }

        if (!is_bool($hasChanges)) {
            throw new WorkflowException(
                'Configuration change detection',
                new UnexpectedValueException('The status command did not return a boolean change result.'),
            );
        }

        if (!$hasChanges) {
            $write('No configuration changes detected. Skipping configuration export.');
            $write('updbex workflow completed successfully.');
            return;
        }

        $write('Configuration changes detected. Exporting active configuration to the configured synchronization directory; existing YAML files there will be replaced.');
        $this->runOperation('Configuration export', 'config:export', ['yes' => null], $runCommand, $write);
        $write('updbex workflow completed successfully.');
    }

    private function runOperation(string $label, string $command, array $options, callable $runCommand, callable $write): void
    {
        $write(sprintf('Starting %s...', strtolower($label)));
        try {
            $runCommand($command, $options);
        } catch (Throwable $exception) {
            $write(sprintf('%s failed.', $label));
            throw new WorkflowException($label, $exception);
        }
        $write(sprintf('%s completed.', $label));
    }
}