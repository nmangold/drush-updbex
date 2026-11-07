<?php

declare(strict_types=1);

$command = $argv[1];
$scenario = $argv[2];

switch ($command) {
    case 'cache:rebuild':
        fwrite(STDOUT, "cache stdout ready\n");
        fflush(STDOUT);
        fwrite(STDERR, "cache stderr ready\n");
        fflush(STDERR);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            exit(9);
        }
        fwrite(STDOUT, "cache completed\n");
        break;

    case 'updatedb':
        fwrite(STDOUT, "update output\n");
        fwrite(STDERR, "update diagnostic\n");
        if ($scenario === 'fail-update') {
            exit(23);
        }
        break;

    case 'updbex:config-status':
        fwrite(STDERR, "status diagnostic\n");
        if ($scenario === 'fail-status') {
            exit(17);
        }
        fwrite(STDOUT, $scenario === 'malformed' ? "not json\n" : json_encode(['has_changes' => $scenario === 'changed']) . "\n");
        break;

    case 'config:export':
        fwrite(STDOUT, "export output\n");
        break;
}
