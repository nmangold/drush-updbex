<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;
use YourVendor\DrushUpdbex\ConfigChangeDetector;

#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class UpdbexStatusDrushCommands extends DrushCommands
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): self
    {
        return new self($container);
    }

    /**
     * Internal full-bootstrap status command used by updbex.
     */
    #[CLI\Command(name: 'updbex:config-status')]
    #[CLI\FieldLabels(labels: ['has_changes' => 'has_changes'])]
    public function status(array $options = ['format' => 'json']): array
    {
        return ['has_changes' => (new ConfigChangeDetector($this->container))->hasChanges()];
    }
}