<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex;

use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use LogicException;
use Psr\Container\ContainerInterface;

final class ConfigChangeDetector
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function hasChanges(): bool
    {
        return $this->getChanges() !== [];
    }

    /**
     * Return the collection-aware change list using the same storages as Drush config:export.
     */
    public function getChanges(): array
    {
        $sourceId = $this->container->has('config.storage.export')
            ? 'config.storage.export'
            : 'config.storage';

        if (!$this->container->has($sourceId)) {
            throw new LogicException(sprintf('The required configuration storage service "%s" is unavailable.', $sourceId));
        }
        if (!$this->container->has('config.storage.sync')) {
            throw new LogicException('The configured synchronization storage service "config.storage.sync" is unavailable.');
        }

        $sourceStorage = $this->container->get($sourceId);
        $syncStorage = $this->container->get('config.storage.sync');
        if (!$sourceStorage instanceof StorageInterface || !$syncStorage instanceof StorageInterface) {
            throw new LogicException('Drush configuration export and synchronization services must implement Drupal configuration storage.');
        }

        $comparer = new StorageComparer($sourceStorage, $syncStorage);
        if (!$comparer->createChangelist()->hasChanges()) {
            return [];
        }

        $changes = [];
        foreach ($comparer->getAllCollectionNames() as $collection) {
            $collectionChanges = $comparer->getChangelist(null, $collection);
            if ($collectionChanges !== []) {
                $changes[$collection] = $collectionChanges;
            }
        }

        return $changes;
    }
}