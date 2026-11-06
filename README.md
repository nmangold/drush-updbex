# Drush updbex

Drush updbex is a small, standalone Drush extension for a common Drupal maintenance workflow. **updbex** means **Cache Rebuild, Updatedb, Configuration Export**.

It runs cache rebuild, applies pending database updates, compares the configuration that Drush would export with the site's configured synchronization storage, and exports only when those storages differ. It is a Composer-installed Drush command package, not a Drupal module: there is no module to enable or command file to register manually.

This is especially useful after updating Drupal core and contributed modules with `composer update`: database updates may change active configuration, and updbex exports any detected differences to the configured synchronization directory.

## Requirements

- PHP 8.2 or newer. Drupal 11 itself requires PHP 8.3 or newer.
- Drush 12.5.x or 13.x.
- A Drupal 10 or 11 site supported by the selected Drush release.

The PHP and Drupal constraints of the selected Drush and Drupal versions also apply. Composer will resolve compatible versions or report a dependency conflict.

### Drush command compatibility

Drush 12.5 does not support Drush's newer native Symfony Console command discovery, introduced in Drush 13.7. This package uses Drush's attribute-based commandfile API so one release works across Drush 12.5 and 13; Drush deprecated that method-based API in 13.7, but it remains available throughout Drush 13. The package excludes Drush 14, where the legacy API is not supported, and will need a native Console-command migration before adding Drush 14 compatibility.

## Installation

```bash
composer require nmangold/drush-updbex
```

Drush discovers the command through the package's PSR-4 autoload entry and `Drush\Commands` namespace. No Drupal module installation or `drush.services.yml` registration is needed.

## Usage

Run the workflow from the Drupal project using its normal Drush site context:

```bash
drush updbex
```

Standard Drush site aliases and URI selection are preserved. Standard Drush verbosity is also available, for example `drush -v updbex` or `drush -vvv updbex`.

To rebuild caches and run database updates without checking or exporting configuration:

```bash
drush updbex --no-config-export
```

## Execution order and safety

1. Rebuild Drupal caches with `cache:rebuild`.
2. Apply pending database updates with `updatedb --yes`.
3. Unless `--no-config-export` was selected, check whether exportable active configuration differs from sync storage.
4. If changes exist, display that the export will replace existing YAML files in the configured sync directory, then run `config:export --yes`. If no changes exist, skip the export.
5. Report completion only after all required operations have succeeded.

Each operation stops the workflow on failure. The failing operation is reported and the failure propagates as a non-zero Drush exit. The command does not manage maintenance mode. It intentionally rejects Drush `--simulate`: simulated database updates cannot provide a reliable post-update configuration comparison.

## Configuration change detection

After database updates, an internal full-bootstrap Drush command compares `config.storage.export` (falling back to `config.storage`, as Drush does) with `config.storage.sync`. That sync service resolves Drupal's configured synchronization directory; the package does not assume a `config/sync` path.

The comparison uses Drupal's `StorageComparer`, including its collection-aware changelist for additions, modifications, and deletions. The comparison mirrors Drush `config:export`'s storage pair. Drush `ConfigCommands::getChanges()` is not reused because it compares its own active storage and may apply an import transformer, which is not the same comparison that export performs.

The actual export remains Drush's `config:export` command. Drush checks the configuration again before writing, so any changes between detection and export are handled by Drush's normal export behavior.

## Example output

When changes are detected:

```text
Starting cache rebuild...
Cache rebuild completed.
Starting database updates...
Database updates completed.
Checking active configuration against the synchronization directory...
Configuration changes detected. Exporting active configuration to the configured synchronization directory; existing YAML files there will be replaced.
Starting configuration export...
Configuration export completed.
updbex workflow completed successfully.
```

When no changes are detected:

```text
Starting cache rebuild...
Cache rebuild completed.
Starting database updates...
Database updates completed.
Checking active configuration against the synchronization directory...
No configuration changes detected. Skipping configuration export.
updbex workflow completed successfully.
```

## Development and tests

```bash
composer install
vendor/bin/phpunit
```

The tests cover workflow order and failure handling, conditional/disabled exports, status response validation, configured sync storage selection, Drupal storage comparer change types and collections, and PSR-4 command class discovery. A full command smoke test requires a Drupal project with a configured database and Drush site context.

## Contributing

Issues and pull requests are welcome. Keep changes focused on the updbex workflow, add or update PHPUnit coverage for behavior changes, and run PHPUnit plus PHP syntax checks before submitting a pull request.

## License

This project is released under the MIT License. See `LICENSE`.