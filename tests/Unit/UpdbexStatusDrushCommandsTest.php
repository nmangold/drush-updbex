<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Consolidation\AnnotatedCommand\CommandProcessor;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Consolidation\OutputFormatters\FormatterManager;
use Drupal\Core\Config\MemoryStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Container;
use YourVendor\DrushUpdbex\ConfigStatusResponse;
use YourVendor\DrushUpdbex\Drush\Commands\UpdbexStatusDrushCommands;

final class UpdbexStatusDrushCommandsTest extends TestCase
{
    public function testFormatJsonReturnsDecodableBooleanForChangedAndUnchangedConfiguration(): void
    {
        foreach ([true, false] as $hasChanges) {
            $sourceStorage = new MemoryStorage();
            $syncStorage = new MemoryStorage();
            $sourceStorage->write('system.site', ['name' => 'Site']);
            $syncStorage->write('system.site', ['name' => $hasChanges ? 'Other site' : 'Site']);

            $container = new Container();
            $container->set('config.storage.export', $sourceStorage);
            $container->set('config.storage.sync', $syncStorage);

            $formatterManager = new FormatterManager();
            $formatterManager->addDefaultFormatters();
            $commandProcessor = new CommandProcessor(new HookManager());
            $commandProcessor->setFormatterManager($formatterManager);

            $commandFactory = new AnnotatedCommandFactory();
            $commandFactory->setCommandProcessor($commandProcessor);
            $commandFile = new UpdbexStatusDrushCommands($container);
            $commandInfo = $commandFactory->createCommandInfo($commandFile, 'status');
            $command = $commandFactory->createCommand($commandInfo, $commandFile);
            $input = new ArrayInput(['--format' => 'json'], $command->getDefinition());
            $output = new BufferedOutput();
            $commandFile->setInput($input);
            $commandFile->setOutput($output);

            self::assertSame(0, $command->run($input, $output));
            self::assertSame($hasChanges, ConfigStatusResponse::decode($output->fetch()));
        }
    }
}