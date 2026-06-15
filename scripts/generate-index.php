#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate the static AppPress registry index from package manifest entries.
 *
 * Usage:
 *   APPRESS_REGISTRY_PRIVATE_KEY=<hex> php scripts/generate-index.php [--featured]
 *
 * Package sources are declared in packages.json (full fleet catalog) or
 * featured-packages.json when --featured is passed.
 */

$root = dirname(__DIR__);
$useFeatured = in_array('--featured', $argv ?? [], true) || getenv('INDEX_SCOPE') === 'featured';
$packagesFile = $root.'/'.($useFeatured ? 'featured-packages.json' : 'packages.json');
$outputIndex = $root.'/index.json';
$outputPluginsDir = $root.'/plugins';

if (! is_file($packagesFile)) {
    fwrite(STDERR, "Missing packages.json\n");
    exit(1);
}

/** @var array<string, mixed> $catalog */
$catalog = json_decode((string) file_get_contents($packagesFile), true);
if (! is_array($catalog)) {
    fwrite(STDERR, "Invalid packages.json\n");
    exit(1);
}

$privateKeyHex = getenv('APPRESS_REGISTRY_PRIVATE_KEY') ?: '';
$githubToken = getenv('GITHUB_TOKEN') ?: '';

/** @var array<string, mixed> $packages */
$packages = [];

foreach ($catalog['packages'] as $entry) {
    if (! is_array($entry)) {
        continue;
    }

    $slug = (string) ($entry['slug'] ?? '');
    $githubRepo = (string) ($entry['github_repo'] ?? '');
    $manifestPath = (string) ($entry['manifest_path'] ?? '');

    if ($slug === '' || $githubRepo === '') {
        continue;
    }

    $manifest = resolveManifest($manifestPath, $githubRepo, $githubToken);
    if ($manifest === null) {
        fwrite(STDERR, "Skipping {$slug}: manifest unavailable\n");
        continue;
    }

    $version = (string) ($manifest['version'] ?? '');
    $shortSlug = basename($slug);
    $artifact = "{$shortSlug}-{$version}.zip";
    $tagPrefix = (string) ($entry['release_tag_prefix'] ?? '');
    $tag = $tagPrefix !== '' ? $tagPrefix.$version : "v{$version}";

    $releaseMeta = fetchReleaseAsset($githubRepo, $tag, $artifact, $githubToken);
    $downloadUrl = $releaseMeta['download_url'] ?? "https://github.com/{$githubRepo}/releases/download/{$tag}/{$artifact}";
    $checksum = $releaseMeta['checksum_sha256'] ?? '';
    $signature = $releaseMeta['signature'] ?? '';

    if ($checksum !== '' && $signature === '' && $privateKeyHex !== '') {
        $signature = signDigest($checksum, $privateKeyHex);
    }

    $minCore = (string) ($manifest['min_core_version'] ?? extractMinCore($manifest));
    $requiresPlugins = $manifest['requires_plugins'] ?? [];
    if (! is_array($requiresPlugins)) {
        $requiresPlugins = [];
    }

    $versionEntry = [
        'version' => $version,
        'download_url' => $downloadUrl,
        'checksum_sha256' => $checksum,
        'signature' => $signature,
        'min_core_version' => $minCore,
        'dependencies' => (object) ($manifest['dependencies'] ?? []),
        'requires_plugins' => array_is_list($requiresPlugins)
            ? array_values($requiresPlugins)
            : $requiresPlugins,
        'capabilities' => array_values((array) ($manifest['capabilities'] ?? [])),
        'yanked' => false,
        'published_at' => $releaseMeta['published_at'] ?? gmdate('c'),
    ];

    $packages[$slug] = [
        'slug' => $slug,
        'name' => (string) ($manifest['name'] ?? $slug),
        'type' => (string) ($entry['type'] ?? $manifest['type'] ?? 'plugin'),
        'github_repo' => $githubRepo,
        'tier' => (string) ($entry['tier'] ?? 'first_party'),
        'description' => (string) ($manifest['description'] ?? ''),
        'author' => (string) ($manifest['author'] ?? 'AppPress'),
        'categories' => array_values((array) ($entry['categories'] ?? [])),
        'versions' => [$versionEntry],
    ];

    if (! is_dir($outputPluginsDir)) {
        mkdir($outputPluginsDir, 0755, true);
    }

    $pluginDetail = buildPluginDetail($packages[$slug], $versionEntry);
    $pluginFile = $outputPluginsDir.'/'.slugToFilename($slug).'.json';
    file_put_contents($pluginFile, json_encode($pluginDetail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

$index = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('c'),
    'index_ref' => 'main',
    'packages' => $packages,
];

$index['signature'] = signIndex($index, $privateKeyHex);

file_put_contents($outputIndex, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Wrote {$outputIndex} with ".count($packages)." package(s)\n";

/**
 * @param  array<string, mixed>  $package
 * @param  array<string, mixed>  $version
 * @return array<string, mixed>
 */
function buildPluginDetail(array $package, array $version): array
{
    return [
        'slug' => $package['slug'],
        'name' => $package['name'],
        'version' => $version['version'],
        'author' => $package['author'],
        'description' => $package['description'],
        'categories' => $package['categories'],
        'downloads' => 0,
        'rating' => 0,
        'rating_count' => 0,
        'last_updated' => $version['published_at'],
        'requires' => ['core' => '>='.$version['min_core_version']],
        'is_verified' => ($package['tier'] ?? '') === 'first_party',
        'is_premium' => false,
        'license_type' => 'free',
        'download_url' => $version['download_url'],
        'checksum_sha256' => $version['checksum_sha256'],
        'signature' => $version['signature'],
        'min_core_version' => $version['min_core_version'],
        'dependencies' => $version['dependencies'],
        'requires_plugins' => $version['requires_plugins'],
        'capabilities' => $version['capabilities'],
        'yanked' => $version['yanked'],
        'versions' => [$version],
    ];
}

function slugToFilename(string $slug): string
{
    return str_replace('/', '--', $slug);
}

/**
 * @return array<string, mixed>|null
 */
function resolveManifest(string $manifestPath, string $githubRepo, string $githubToken): ?array
{
    if ($manifestPath !== '' && is_file($manifestPath)) {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        return is_array($decoded) ? $decoded : null;
    }

    $url = "https://raw.githubusercontent.com/{$githubRepo}/main/appress.json";
    $headers = $githubToken !== '' ? ["Authorization: Bearer {$githubToken}"] : [];
    $body = httpGet($url, $headers);
    if ($body === null) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array{download_url?: string, checksum_sha256?: string, signature?: string, published_at?: string}
 */
function fetchReleaseAsset(string $githubRepo, string $tag, string $artifact, string $githubToken): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: appress-registry-index-generator',
    ];
    if ($githubToken !== '') {
        $headers[] = "Authorization: Bearer {$githubToken}";
    }

    $releaseJson = httpGet("https://api.github.com/repos/{$githubRepo}/releases/tags/{$tag}", $headers);
    if ($releaseJson === null) {
        return [];
    }

    /** @var array<string, mixed> $release */
    $release = json_decode($releaseJson, true);
    if (! is_array($release)) {
        return [];
    }

    $assets = (array) ($release['assets'] ?? []);
    foreach ($assets as $asset) {
        if (! is_array($asset) || ($asset['name'] ?? '') !== $artifact) {
            continue;
        }

        $downloadUrl = (string) ($asset['browser_download_url'] ?? '');
        $checksum = '';
        $signature = '';

        foreach ($assets as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            if (($meta['name'] ?? '') === "{$artifact}.sha256") {
                $checksumBody = httpGet((string) ($meta['browser_download_url'] ?? ''), $headers);
                $checksum = $checksumBody !== null ? trim(strtok($checksumBody, ' ')) : '';
            }
            if (($meta['name'] ?? '') === "{$artifact}.sig") {
                $sigBody = httpGet((string) ($meta['browser_download_url'] ?? ''), $headers);
                $signature = $sigBody !== null ? trim($sigBody) : '';
            }
        }

        return [
            'download_url' => $downloadUrl,
            'checksum_sha256' => $checksum,
            'signature' => $signature,
            'published_at' => (string) ($release['published_at'] ?? gmdate('c')),
        ];
    }

    return [];
}

/**
 * @param  list<string>  $headers
 */
function httpGet(string $url, array $headers = []): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * @param  array<string, mixed>  $manifest
 */
function extractMinCore(array $manifest): string
{
    $requires = $manifest['requires'] ?? [];
    if (! is_array($requires)) {
        return '1.0.0';
    }

    $core = (string) ($requires['core'] ?? '>=1.0.0');

    return ltrim($core, '^~>=< ');
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

    return signDigest(json_encode($payload), $privateKeyHex);
}

function signDigest(string $message, string $privateKeyHex): string
{
    $secretKey = hex2bin($privateKeyHex);
    if ($secretKey === false) {
        return '';
    }

    return bin2hex(sodium_crypto_sign_detached($message, $secretKey));
}
