<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use Drush\Attributes\Command;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Container;
use YourVendor\DrushUpdbex\Drush\Commands\UpdbexDrushCommands;
use YourVendor\DrushUpdbex\Drush\Commands\UpdbexStatusDrushCommands;

final class CommandDiscoveryTest extends TestCase
{
    public function testComposerAutoloadableClassesUseDrushCommandAttributes(): void
    {
        foreach ([UpdbexDrushCommands::class, UpdbexStatusDrushCommands::class] as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isSubclassOf(\Drush\Commands\DrushCommands::class));
            self::assertNotEmpty($reflection->getMethods());
            self::assertStringEndsWith('DrushCommands', $reflection->getShortName());
        }

        $method = new \ReflectionMethod(UpdbexDrushCommands::class, 'updbex');
        self::assertSame('updbex', $method->getAttributes(Command::class)[0]->newInstance()->name);
        self::assertInstanceOf(UpdbexStatusDrushCommands::class, UpdbexStatusDrushCommands::create(new Container()));
    }
}