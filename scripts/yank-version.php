#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Flip a package version's `yanked` flag in index.json and re-sign the
 * index — the recall mechanism (H3): platform installs already filter
 * yanked versions from resolution (PackageSemverResolver) and Phase 1.5
 * closed the pinned/lockfile bypass, so this completes recall end-to-end.
 *
 * Usage:
 *   APPRESS_REGISTRY_PRIVATE_KEY=<hex> php scripts/yank-version.php \
 *     --slug=apppress/billing --version=1.2.0 [--reason="security issue"] [--unyank] [--index=path/to/index.json]
 */

$root = dirname(__DIR__);

$options = parseOptions($argv);
$slug = $options['slug'] ?? null;
$version = $options['version'] ?? null;
$reason = $options['reason'] ?? null;
$unyank = array_key_exists('unyank', $options);
$indexPath = $options['index'] ?? $root.'/index.json';

if ($slug === null || $version === null) {
    fwrite(STDERR, "Usage: php scripts/yank-version.php --slug=<slug> --version=<version> [--reason=\"...\"] [--unyank]\n");
    exit(1);
}

if (! is_file($indexPath)) {
    fwrite(STDERR, "index.json not found at {$indexPath}\n");
    exit(1);
}

$index = json_decode((string) file_get_contents($indexPath), true);
if (! is_array($index) || ! is_array($index['packages'] ?? null)) {
    fwrite(STDERR, "index.json is invalid\n");
    exit(1);
}

if (! isset($index['packages'][$slug]) || ! is_array($index['packages'][$slug])) {
    fwrite(STDERR, "Package not found in index: {$slug}\n");
    exit(1);
}

$versions = $index['packages'][$slug]['versions'] ?? [];
if (! is_array($versions)) {
    fwrite(STDERR, "Package {$slug} has no versions array\n");
    exit(1);
}

$found = false;
foreach ($versions as $i => $entry) {
    if (! is_array($entry) || ($entry['version'] ?? null) !== $version) {
        continue;
    }

    $versions[$i]['yanked'] = ! $unyank;
    if (! $unyank && $reason !== null) {
        $versions[$i]['yank_reason'] = $reason;
    } elseif ($unyank) {
        unset($versions[$i]['yank_reason']);
    }
    $found = true;
    break;
}

if (! $found) {
    fwrite(STDERR, "Version {$version} not found for package {$slug}\n");
    exit(1);
}

$index['packages'][$slug]['versions'] = $versions;
$index['generated_at'] = gmdate('c');

$privateKeyHex = getenv('APPRESS_REGISTRY_PRIVATE_KEY') ?: '';
$index['signature'] = signIndex($index, $privateKeyHex);

file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$pluginFile = dirname($indexPath).'/plugins/'.str_replace('/', '--', $slug).'.json';
if (is_file($pluginFile)) {
    $detail = json_decode((string) file_get_contents($pluginFile), true);
    if (is_array($detail)) {
        $detail['versions'] = $versions;
        $latest = $versions[array_key_last($versions)];
        $detail['yanked'] = $latest['yanked'] ?? false;
        file_put_contents($pluginFile, json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}

echo ($unyank ? 'Un-yanked' : 'Yanked')." {$slug} v{$version}\n";
if (! $unyank && $reason !== null) {
    echo "Reason: {$reason}\n";
}

/**
 * @param  list<string>  $argv
 * @return array<string, string|null>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = null;
        }
    }

    return $options;
}

/**
 * @param  array<string, mixed>  $index
 */
function signIndex(array $index, string $privateKeyHex): string
{
    if ($privateKeyHex === '' || strlen($privateKeyHex) !== 128) {
        return str_repeat('0', 128);
    }

    $payload = $index;
    unset($payload['signature']);
    ksort($payload);

    $secretKey = hex2bin($privateKeyHex);
    if ($secretKey === false) {
        return '';
    }

    return bin2hex(sodium_crypto_sign_detached(json_encode($payload), $secretKey));
}
