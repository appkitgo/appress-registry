#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../lib/index-entry-resolution.php';

$failures = 0;

function check(bool $cond, string $label): void
{
    global $failures;
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

check(resolveArtifactName([], 'apppress--widgets', '1.0.0') === 'apppress--widgets-1.0.0.zip', 'default artifact name from short slug');
check(resolveArtifactName(['artifact_prefix' => 'app-press-core'], 'appress-core', '1.2.0') === 'app-press-core-1.2.0.zip', 'artifact_prefix override wins');

check(matchesTagPrefix('core-v1.2.0', 'core-v') === true, 'matches core-v prefix');
check(matchesTagPrefix('billing-v1.2.0', 'core-v') === false, 'rejects non-matching prefix');
check(matchesTagPrefix('core-v1.2.0', '') === false, 'empty prefix never matches (use fetchLatestRelease instead)');

$schema = json_decode((string) file_get_contents(__DIR__.'/../../schema/index.schema.json'), true);
check(in_array('core', $schema['$defs']['package']['properties']['type']['enum'], true), 'schema type enum includes core');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "All index-entry-resolution tests passed\n";
