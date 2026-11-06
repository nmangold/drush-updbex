<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use YourVendor\DrushUpdbex\UpdbexWorkflow;
use YourVendor\DrushUpdbex\WorkflowException;

final class UpdbexWorkflowTest extends TestCase
{
    public function testRunsWorkflowInOrderAndExportsDetectedChanges(): void
    {
        $workflow = new UpdbexWorkflow();
        $commands = [];
        $messages = [];

        $workflow->execute(
            static function (string $command, array $options) use (&$commands): void {
                $commands[] = [$command, $options];
            },
            static fn (): bool => true,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        self::assertSame([
            ['cache:rebuild', []],
            ['updatedb', ['yes' => null]],
            ['config:export', ['yes' => null]],
        ], $commands);
        self::assertStringContainsString('existing YAML files there will be replaced.', implode("\n", $messages));
        self::assertSame('updbex workflow completed successfully.', end($messages));
    }

    public function testCacheRebuildFailureStopsWorkflow(): void
    {
        $this->assertFailureStopsAt('cache:rebuild', 'Cache rebuild', ['cache:rebuild']);
    }

    public function testDatabaseUpdateFailureStopsWorkflow(): void
    {
        $this->assertFailureStopsAt('updatedb', 'Database updates', ['cache:rebuild', 'updatedb']);
    }

    public function testFailureExitCodeIsPreserved(): void
    {
        $workflow = new UpdbexWorkflow();

        try {
            $workflow->execute(
                static function (): void {
                    throw new RuntimeException('operation failed', 23);
                },
                static fn (): bool => true,
                static function (): void {
                },
            );
            self::fail('Expected cache rebuild to fail.');
        } catch (WorkflowException $exception) {
            self::assertSame(23, $exception->getCode());
        }
    }

    public function testNoChangesSkipConfigurationExport(): void
    {
        $commands = [];
        $messages = [];
        $workflow = new UpdbexWorkflow();

        $workflow->execute(
            static function (string $command) use (&$commands): void {
                $commands[] = $command;
            },
            static fn (): bool => false,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        self::assertSame(['cache:rebuild', 'updatedb'], $commands);
        self::assertContains('No configuration changes detected. Skipping configuration export.', $messages);
        self::assertSame('updbex workflow completed successfully.', end($messages));
    }

    public function testConfigurationExportFailureStopsBeforeSuccess(): void
    {
        $commands = [];
        $messages = [];
        $workflow = new UpdbexWorkflow();

        try {
            $workflow->execute(
                static function (string $command) use (&$commands): void {
                    $commands[] = $command;
                    if ($command === 'config:export') {
                        throw new RuntimeException('export failed');
                    }
                },
                static fn (): bool => true,
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                },
            );
            self::fail('Expected configuration export to fail.');
        } catch (WorkflowException $exception) {
            self::assertSame('Configuration export', $exception->operation);
            self::assertStringContainsString('Configuration export failed', $exception->getMessage());
        }

        self::assertSame(['cache:rebuild', 'updatedb', 'config:export'], $commands);
        self::assertNotContains('updbex workflow completed successfully.', $messages);
    }

    public function testNoConfigExportSkipsDetectionAndExport(): void
    {
        $commands = [];
        $messages = [];
        $detectionCount = 0;
        $workflow = new UpdbexWorkflow();

        $workflow->execute(
            static function (string $command) use (&$commands): void {
                $commands[] = $command;
            },
            static function () use (&$detectionCount): bool {
                ++$detectionCount;
                return true;
            },
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            true,
        );

        self::assertSame(['cache:rebuild', 'updatedb'], $commands);
        self::assertSame(0, $detectionCount);
        self::assertContains('Configuration export disabled by --no-config-export. Skipping configuration export.', $messages);
        self::assertSame('updbex workflow completed successfully.', end($messages));
    }

    public function testDetectionExceptionsFailClosed(): void
    {
        $commands = [];
        $messages = [];
        $workflow = new UpdbexWorkflow();

        try {
            $workflow->execute(
                static function (string $command) use (&$commands): void {
                    $commands[] = $command;
                },
                static function (): bool {
                    throw new RuntimeException('status failed');
                },
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                },
            );
            self::fail('Expected configuration detection to fail.');
        } catch (WorkflowException $exception) {
            self::assertSame('Configuration change detection', $exception->operation);
        }

        self::assertSame(['cache:rebuild', 'updatedb'], $commands);
        self::assertNotContains('updbex workflow completed successfully.', $messages);
    }

    public function testNonBooleanDetectionResultFailsClosed(): void
    {
        $workflow = new UpdbexWorkflow();

        try {
            $workflow->execute(
                static function (): void {
                },
                static fn (): string => 'unknown',
                static function (): void {
                },
            );
            self::fail('Expected malformed status result to fail.');
        } catch (WorkflowException $exception) {
            self::assertSame('Configuration change detection', $exception->operation);
        }
    }

    private function assertFailureStopsAt(string $failingCommand, string $operation, array $expectedCommands): void
    {
        $commands = [];
        $messages = [];
        $workflow = new UpdbexWorkflow();

        try {
            $workflow->execute(
                static function (string $command) use (&$commands, $failingCommand): void {
                    $commands[] = $command;
                    if ($command === $failingCommand) {
                        throw new RuntimeException('operation failed');
                    }
                },
                static fn (): bool => true,
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                },
            );
            self::fail(sprintf('Expected %s to fail.', $failingCommand));
        } catch (WorkflowException $exception) {
            self::assertSame($operation, $exception->operation);
            self::assertStringContainsString($operation . ' failed', $exception->getMessage());
        }

        self::assertSame($expectedCommands, $commands);
        self::assertNotContains('updbex workflow completed successfully.', $messages);
    }
}