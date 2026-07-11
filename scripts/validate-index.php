#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate index.json against schema/index.schema.json (H2) — hand-rolled
 * rather than pulling in a JSON Schema library dependency, since the schema
 * this repo actually uses is small, stable, and entirely covered by the
 * checks below (required fields, enums, and three regex patterns).
 *
 * Usage: php scripts/validate-index.php [path/to/index.json]
 * Exit code non-zero on any violation — wire this into rebuild-index.yml
 * before commit/deploy so a schema-violating index can never be published.
 */

$root = dirname(__DIR__);
$indexPath = $argv[1] ?? $root.'/index.json';

if (! is_file($indexPath)) {
    fwrite(STDERR, "Index not found: {$indexPath}\n");
    exit(1);
}

$index = json_decode((string) file_get_contents($indexPath), true);
if (! is_array($index)) {
    fwrite(STDERR, "Index is not valid JSON: {$indexPath}\n");
    exit(1);
}

$errors = [];

foreach (['schema_version', 'generated_at', 'packages', 'signature'] as $key) {
    if (! array_key_exists($key, $index)) {
        $errors[] = "Missing top-level key: {$key}";
    }
}

if (($index['schema_version'] ?? null) !== '1.0.0') {
    $errors[] = "schema_version must be exactly \"1.0.0\", got: ".json_encode($index['schema_version'] ?? null);
}

if (isset($index['generated_at']) && ! isValidDateTime((string) $index['generated_at'])) {
    $errors[] = 'generated_at is not a valid ISO-8601 date-time';
}

$signature = (string) ($index['signature'] ?? '');
if (! preg_match('/^[0-9a-f]{128}$/', $signature)) {
    $errors[] = 'signature must be a 128-character hex string, got: '.var_export($index['signature'] ?? null, true);
}

$packages = $index['packages'] ?? null;
if (! is_array($packages)) {
    $errors[] = 'packages must be an object';
    $packages = [];
}

foreach ($packages as $slug => $package) {
    $prefix = "packages[{$slug}]";

    if (! is_array($package)) {
        $errors[] = "{$prefix} must be an object";

        continue;
    }

    foreach (['slug', 'name', 'type', 'github_repo', 'tier', 'versions'] as $key) {
        if (! array_key_exists($key, $package)) {
            $errors[] = "{$prefix}: missing required key '{$key}'";
        }
    }

    if (isset($package['type']) && ! in_array($package['type'], ['plugin', 'theme', 'app-pack', 'core'], true)) {
        $errors[] = "{$prefix}.type must be one of plugin/theme/app-pack/core, got: ".json_encode($package['type']);
    }

    if (isset($package['tier']) && ! in_array($package['tier'], ['first_party', 'certified', 'community'], true)) {
        $errors[] = "{$prefix}.tier must be one of first_party/certified/community, got: ".json_encode($package['tier']);
    }

    $versions = $package['versions'] ?? null;
    if (! is_array($versions)) {
        $errors[] = "{$prefix}.versions must be an array";

        continue;
    }

    foreach ($versions as $i => $version) {
        $vPrefix = "{$prefix}.versions[{$i}]";

        if (! is_array($version)) {
            $errors[] = "{$vPrefix} must be an object";

            continue;
        }

        foreach (['version', 'download_url', 'checksum_sha256', 'signature'] as $key) {
            if (! array_key_exists($key, $version)) {
                $errors[] = "{$vPrefix}: missing required key '{$key}'";
            }
        }

        $checksum = $version['checksum_sha256'] ?? null;
        if ($checksum !== null && ! preg_match('/^[0-9a-f]{64}$/', (string) $checksum)) {
            $errors[] = "{$vPrefix}.checksum_sha256 must be a 64-character hex string, got: ".json_encode($checksum);
        }

        $versionSignature = $version['signature'] ?? null;
        if ($versionSignature !== null && ! preg_match('/^[0-9a-f]{128}$/', (string) $versionSignature)) {
            $errors[] = "{$vPrefix}.signature must be a 128-character hex string, got: ".json_encode($versionSignature);
        }

        $downloadUrl = (string) ($version['download_url'] ?? '');
        if ($downloadUrl !== '' && filter_var($downloadUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = "{$vPrefix}.download_url is not a valid URI: {$downloadUrl}";
        }

        if (isset($version['yanked']) && ! is_bool($version['yanked'])) {
            $errors[] = "{$vPrefix}.yanked must be a boolean, got: ".json_encode($version['yanked']);
        }

        if (isset($version['published_at']) && ! isValidDateTime((string) $version['published_at'])) {
            $errors[] = "{$vPrefix}.published_at is not a valid ISO-8601 date-time";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Index validation FAILED (".count($errors)." error(s)):\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

echo "Index valid: {$indexPath} (".count($packages)." package(s))\n";

function isValidDateTime(string $value): bool
{
    if ($value === '') {
        return false;
    }

    try {
        new DateTimeImmutable($value);

        return true;
    } catch (\Exception) {
        return false;
    }
}
