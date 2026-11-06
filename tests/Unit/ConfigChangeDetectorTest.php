<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use YourVendor\DrushUpdbex\ConfigChangeDetector;

final class ConfigChangeDetectorTest extends TestCase
{
    private string $syncDirectory;

    private ?array $fileCacheConfiguration;

    private ?string $fileCachePrefix;

    protected function setUp(): void
    {
        $this->fileCacheConfiguration = FileCacheFactory::getConfiguration();
        $this->fileCachePrefix = FileCacheFactory::getPrefix();
        FileCacheFactory::setConfiguration([FileCacheFactory::DISABLE_CACHE => true]);
        $this->syncDirectory = sys_get_temp_dir() . '/updbex-sync-' . bin2hex(random_bytes(6));
        mkdir($this->syncDirectory);
    }

    protected function tearDown(): void
    {
        $files = array_merge(
            glob($this->syncDirectory . '/*') ?: [],
            glob($this->syncDirectory . '/.*') ?: [],
        );
        foreach ($files as $file) {
            if (basename($file) === '.' || basename($file) === '..') {
                continue;
            }
            unlink($file);
        }
        rmdir($this->syncDirectory);
        FileCacheFactory::setConfiguration($this->fileCacheConfiguration);
        FileCacheFactory::setPrefix($this->fileCachePrefix);
    }

    public function testDetectsAdditionsModificationsAndDeletions(): void
    {
        $active = new MemoryStorage();
        $sync = new MemoryStorage();
        $active->write('test.created', ['value' => 'created']);
        $active->write('test.modified', ['value' => 'new']);
        $sync->write('test.modified', ['value' => 'old']);
        $sync->write('test.deleted', ['value' => 'deleted']);

        $changes = $this->detector($active, $sync)->getChanges();
        $defaultCollection = '';

        self::assertSame(['test.created'], $changes[$defaultCollection]['create']);
        self::assertSame(['test.modified'], $changes[$defaultCollection]['update']);
        self::assertSame(['test.deleted'], $changes[$defaultCollection]['delete']);
    }

    public function testDetectsChangesInNonDefaultCollections(): void
    {
        $active = new MemoryStorage();
        $sync = new MemoryStorage();
        $active->createCollection('language.fr')->write('test.translation', ['value' => 'Bonjour']);

        $changes = $this->detector($active, $sync)->getChanges();

        self::assertSame(['test.translation'], $changes['language.fr']['create']);
    }

    public function testUsesConfiguredSynchronizationStorage(): void
    {
        $active = new MemoryStorage();
        $active->write('test.config', ['value' => 'same']);
        $configuredSync = new FileStorage($this->syncDirectory);
        $configuredSync->write('test.config', ['value' => 'same']);

        $container = new Container();
        $container->set('config.storage.export', $active);
        $container->set('config.storage', new MemoryStorage());
        $container->set('config.storage.sync', $configuredSync);

        self::assertFalse((new ConfigChangeDetector($container))->hasChanges());

        $configuredSync->write('test.config', ['value' => 'changed in the configured sync directory']);
        self::assertTrue((new ConfigChangeDetector($container))->hasChanges());
    }

    public function testFallsBackToActiveStorageWhenExportStorageIsUnavailable(): void
    {
        $active = new MemoryStorage();
        $sync = new MemoryStorage();
        $active->write('test.config', ['value' => 'active']);

        $container = new Container();
        $container->set('config.storage', $active);
        $container->set('config.storage.sync', $sync);

        self::assertTrue((new ConfigChangeDetector($container))->hasChanges());
    }

    public function testFailsClosedWhenConfiguredSyncStorageIsUnavailable(): void
    {
        $container = new Container();
        $container->set('config.storage', new MemoryStorage());

        $this->expectException(LogicException::class);
        (new ConfigChangeDetector($container))->hasChanges();
    }

    private function detector(MemoryStorage $active, MemoryStorage $sync): ConfigChangeDetector
    {
        $container = new Container();
        $container->set('config.storage.export', $active);
        $container->set('config.storage.sync', $sync);

        return new ConfigChangeDetector($container);
    }
}