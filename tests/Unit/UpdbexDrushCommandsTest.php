<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use ArrayObject;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Consolidation\SiteProcess\ProcessBase;
use Drush\Config\DrushConfig;
use Drush\Drush;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Process\InputStream;
use YourVendor\DrushUpdbex\Drush\Commands\UpdbexDrushCommands;
use YourVendor\DrushUpdbex\WorkflowException;

final class UpdbexDrushCommandsTest extends TestCase
{
    private ?ContainerInterface $previousContainer = null;

    protected function setUp(): void
    {
        $this->previousContainer = Drush::hasContainer() ? Drush::getContainer() : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousContainer !== null) {
            Drush::setContainer($this->previousContainer);
        } else {
            Drush::unsetContainer();
        }
    }

    public function testStreamsBothChannelsBeforeCompletionAndExportsChanges(): void
    {
        [$command, $stdout, $stderr, $calls] = $this->createCommand('changed');
        $command->updbex();

        self::assertSame(['cache:rebuild', 'updatedb', 'updbex:config-status', 'config:export'], array_column($calls->getArrayCopy(), 0));
        self::assertSame(['yes' => null], $calls[1][1]);
        self::assertSame(['format' => 'json'], $calls[2][1]);
        self::assertSame(['yes' => null], $calls[3][1]);

        $output = $stdout->fetch();
        foreach ($calls as $call) {
            self::assertStringContainsString('Running: ' . $call[2], $output);
        }
        self::assertLessThan(strpos($output, 'cache stdout ready'), strpos($output, 'Running: ' . $calls[0][2]));
        self::assertLessThan(strpos($output, 'cache completed'), strpos($output, 'cache stdout ready'));
        self::assertStringContainsString('update output', $output);
        self::assertStringNotContainsString('{"has_changes":true}', $output);
        self::assertStringContainsString('export output', $output);
        self::assertStringContainsString('updbex workflow completed successfully.', $output);
        $errors = $stderr->fetch();
        self::assertStringContainsString('cache stderr ready', $errors);
        self::assertStringContainsString('update diagnostic', $errors);
        self::assertStringContainsString('status diagnostic', $errors);
    }

    public function testUnchangedStatusSkipsExportAndNoConfigExportSkipsStatus(): void
    {
        [$command, $stdout, , $calls] = $this->createCommand('unchanged');
        $command->updbex();
        self::assertSame(['cache:rebuild', 'updatedb', 'updbex:config-status'], array_column($calls->getArrayCopy(), 0));
        self::assertStringNotContainsString('{"has_changes":false}', $stdout->fetch());

        [$command, $stdout, , $calls] = $this->createCommand('changed');
        $command->updbex(['no-config-export' => true]);
        self::assertSame(['cache:rebuild', 'updatedb'], array_column($calls->getArrayCopy(), 0));
        self::assertStringContainsString('Configuration export disabled', $stdout->fetch());
    }

    public function testFailureStreamsOutputOnceAndPreservesExitCode(): void
    {
        [$command, $stdout, $stderr, $calls] = $this->createCommand('fail-update');
        try {
            $command->updbex();
            self::fail('Expected database update failure.');
        } catch (WorkflowException $exception) {
            self::assertSame('Database updates', $exception->operation);
            self::assertSame(23, $exception->getCode());
            self::assertStringContainsString('exit code 23', $exception->getMessage());
            self::assertStringNotContainsString('update output', $exception->getMessage());
            self::assertStringNotContainsString('update diagnostic', $exception->getMessage());
        }
        self::assertSame(['cache:rebuild', 'updatedb'], array_column($calls->getArrayCopy(), 0));
        self::assertSame(1, substr_count($stdout->fetch(), 'update output'));
        self::assertSame(1, substr_count($stderr->fetch(), 'update diagnostic'));
    }

    public function testStatusFailuresAndMalformedJsonStopWorkflow(): void
    {
        foreach (['fail-status' => 17, 'malformed' => 1] as $scenario => $exitCode) {
            [$command, $stdout, $stderr, $calls] = $this->createCommand($scenario);
            try {
                $command->updbex();
                self::fail('Expected configuration detection failure.');
            } catch (WorkflowException $exception) {
                self::assertSame('Configuration change detection', $exception->operation);
                self::assertSame($exitCode, $exception->getCode());
            }
            self::assertSame(['cache:rebuild', 'updatedb', 'updbex:config-status'], array_column($calls->getArrayCopy(), 0));
            self::assertStringContainsString('status diagnostic', $stderr->fetch());
            $output = $stdout->fetch();
            self::assertStringNotContainsString('not json', $output);
            self::assertStringNotContainsString('{"has_changes":', $output);
            self::assertStringNotContainsString('updbex workflow completed successfully.', $output);
        }
    }

    private function createCommand(string $scenario): array
    {
        $calls = new ArrayObject();
        $inputStream = new InputStream();
        $seen = '';
        $onOutput = static function (string $message) use (&$seen, $inputStream): void {
            $seen .= $message;
            if (!$inputStream->isClosed() && str_contains($seen, 'cache stdout ready') && str_contains($seen, 'cache stderr ready')) {
                $inputStream->write("continue\n");
                $inputStream->close();
            }
        };
        $stdout = new ObservedOutput($onOutput);
        $stderr = new ObservedOutput($onOutput);
        $input = new ArrayInput([]);
        $config = new DrushConfig();
        $config->set('runtime.options', []);
        $config->set('runtime.contxt', []);
        $container = new Container();
        $container->set('config', $config);
        $container->set('input', $input);
        Drush::setContainer($container);

        $alias = new SiteAlias();
        $aliases = $this->createMock(SiteAliasManagerInterface::class);
        $aliases->method('getSelf')->willReturn($alias);
        $manager = $this->createMock(ProcessManager::class);
        $manager->method('drush')->willReturnCallback(
            static function (SiteAliasInterface $siteAlias, string $name, array $args, array $options) use ($calls, $alias, $scenario, $inputStream, $input, $stdout, $stderr): ProcessBase {
                self::assertSame($alias, $siteAlias);
                self::assertSame([], $args);
                $process = new ProcessBase([PHP_BINARY, __DIR__ . '/../Fixtures/updbex-subprocess.php', $name, $scenario]);
                $process->setTimeout(5);
                $process->setRealtimeOutput(new SymfonyStyle($input, $stdout), $stderr);
                if ($name === 'cache:rebuild') {
                    $process->setInput($inputStream);
                }
                $calls[] = [$name, $options, $process->getCommandLine()];
                return $process;
            },
        );
        $command = new UpdbexDrushCommands($aliases);
        $command->setInput($input);
        $command->setOutput($stdout);
        $command->setConfig($config);
        $command->setProcessManager($manager);

        return [$command, $stdout, $stderr, $calls];
    }
}

final class ObservedOutput extends BufferedOutput
{
    public function __construct(private readonly \Closure $onOutput)
    {
        parent::__construct();
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite($message, $newline);
        ($this->onOutput)($message);
    }
}
