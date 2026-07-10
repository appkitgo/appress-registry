#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate the static AppPress registry index from package manifest entries.
 *
 * Usage:
 *   GITHUB_TOKEN=<token> APPRESS_REGISTRY_PRIVATE_KEY=<hex> php scripts/generate-index.php [--featured]
 *
 * Package sources are declared in packages.json (full fleet catalog) or
 * featured-packages.json when --featured is passed.
 *
 * H1/H2 hardening: every package's manifest is read from its actual tagged
 * GitHub release (not `main`) and its checksum/signature assets MUST exist —
 * a package that can't be verified from a real release is a hard failure of
 * this run (non-zero exit, package named), not a silently-skipped or
 * false/""-checksummed entry. H4: prior versions[] history is preserved
 * across regenerations (including yanked flags) rather than being
 * regenerated from scratch with only the latest version.
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

if ($githubToken === '') {
    fwrite(STDERR, "WARNING: GITHUB_TOKEN not set — GitHub API calls will be rate-limited and may fail.\n");
}

// Load the previously-published index (if any) so version history — and
// every prior version's yanked flag — survives this regeneration.
/** @var array<string, array<string, mixed>> $previousPackages */
$previousPackages = [];
if (is_file($outputIndex)) {
    $previous = json_decode((string) file_get_contents($outputIndex), true);
    if (is_array($previous) && is_array($previous['packages'] ?? null)) {
        $previousPackages = $previous['packages'];
    }
}

/** @var array<string, mixed> $packages */
$packages = [];
$failures = [];

foreach ($catalog['packages'] as $entry) {
    if (! is_array($entry)) {
        continue;
    }

    $slug = (string) ($entry['slug'] ?? '');
    $githubRepo = (string) ($entry['github_repo'] ?? '');

    if ($slug === '' || $githubRepo === '') {
        continue;
    }

    // Entries not yet expected to have a real release (e.g. archived
    // connectors pending security audit) opt out explicitly via
    // skip_reason — this is a soft skip (logged, doesn't fail the run),
    // distinct from a certified/conditional package silently missing its
    // release, which IS a hard failure below.
    $skipReason = (string) ($entry['skip_reason'] ?? '');
    if ($skipReason !== '') {
        fwrite(STDERR, "Skipping {$slug}: {$skipReason}\n");

        continue;
    }

    fwrite(STDERR, "Resolving {$slug} ({$githubRepo})...\n");

    $release = fetchLatestRelease($githubRepo, $githubToken);
    if ($release === null) {
        $failures[] = "{$slug}: no GitHub release found for {$githubRepo}";

        continue;
    }

    $tag = (string) $release['tag_name'];
    $manifest = fetchManifestAtRef($githubRepo, $tag, $githubToken);
    if ($manifest === null) {
        $failures[] = "{$slug}: could not fetch appress.json at tag {$tag} for {$githubRepo}";

        continue;
    }

    $version = (string) ($manifest['version'] ?? '');
    if ($version === '') {
        $failures[] = "{$slug}: manifest at tag {$tag} has no version";

        continue;
    }

    $shortSlug = basename($slug);
    $artifact = "{$shortSlug}-{$version}.zip";
    $assetMeta = extractReleaseAssets($release, $artifact, $githubToken);

    if ($assetMeta === null) {
        $failures[] = "{$slug}: release {$tag} is missing {$artifact}, {$artifact}.sha256, or {$artifact}.sig";

        continue;
    }

    $minCore = (string) ($manifest['min_core_version'] ?? extractMinCore($manifest));
    $requiresPlugins = $manifest['requires_plugins'] ?? [];
    if (! is_array($requiresPlugins)) {
        $requiresPlugins = [];
    }

    $versionEntry = [
        'version' => $version,
        'download_url' => $assetMeta['download_url'],
        'checksum_sha256' => $assetMeta['checksum_sha256'],
        'signature' => $assetMeta['signature'],
        'min_core_version' => $minCore,
        'dependencies' => (object) ($manifest['dependencies'] ?? []),
        'requires_plugins' => array_is_list($requiresPlugins)
            ? array_values($requiresPlugins)
            : $requiresPlugins,
        'capabilities' => array_values((array) ($manifest['capabilities'] ?? [])),
        'yanked' => false,
        'published_at' => (string) ($release['published_at'] ?? gmdate('c')),
    ];

    $priorVersions = [];
    $priorEntry = $previousPackages[$slug] ?? null;
    if (is_array($priorEntry) && is_array($priorEntry['versions'] ?? null)) {
        $priorVersions = $priorEntry['versions'];
    }

    $versions = mergeVersionHistory($priorVersions, $versionEntry);

    $packages[$slug] = [
        'slug' => $slug,
        'name' => (string) ($manifest['name'] ?? $slug),
        'type' => (string) ($entry['type'] ?? $manifest['type'] ?? 'plugin'),
        'github_repo' => $githubRepo,
        'tier' => (string) ($entry['tier'] ?? 'first_party'),
        'description' => (string) ($manifest['description'] ?? ''),
        'author' => (string) ($manifest['author'] ?? 'AppPress'),
        'categories' => array_values((array) ($entry['categories'] ?? [])),
        'versions' => $versions,
    ];

    if (! is_dir($outputPluginsDir)) {
        mkdir($outputPluginsDir, 0755, true);
    }

    $latestVersion = $versions[array_key_last($versions)];
    $pluginDetail = buildPluginDetail($packages[$slug], $latestVersion);
    $pluginFile = $outputPluginsDir.'/'.slugToFilename($slug).'.json';
    file_put_contents($pluginFile, json_encode($pluginDetail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

if ($failures !== []) {
    fwrite(STDERR, "\nIndex generation FAILED — every catalog package must resolve to a verified release:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

// Carry forward any previously-indexed package this run's catalog didn't
// touch (e.g. --featured runs only cover a subset) so it isn't dropped.
foreach ($previousPackages as $slug => $priorEntry) {
    if (! isset($packages[$slug]) && is_array($priorEntry)) {
        $packages[$slug] = $priorEntry;
    }
}

$index = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('c'),
    'index_ref' => 'tags',
    'packages' => $packages,
];

$index['signature'] = signIndex($index, $privateKeyHex);

file_put_contents($outputIndex, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Wrote {$outputIndex} with ".count($packages)." package(s)\n";

/**
 * Merge a newly-resolved version entry into the prior version history,
 * replacing an entry for the same version number (idempotent re-runs) while
 * preserving every other entry — crucially including its yanked flag —
 * untouched. Never regenerates history from scratch (H4).
 *
 * @param  list<array<string, mixed>>  $priorVersions
 * @param  array<string, mixed>  $newVersion
 * @return list<array<string, mixed>>
 */
function mergeVersionHistory(array $priorVersions, array $newVersion): array
{
    $merged = [];
    $replaced = false;

    foreach ($priorVersions as $existing) {
        if (! is_array($existing)) {
            continue;
        }

        if (($existing['version'] ?? null) === $newVersion['version']) {
            $merged[] = $newVersion;
            $replaced = true;

            continue;
        }

        $merged[] = $existing;
    }

    if (! $replaced) {
        $merged[] = $newVersion;
    }

    usort($merged, static fn (array $a, array $b): int => version_compare(
        (string) ($a['version'] ?? '0.0.0'),
        (string) ($b['version'] ?? '0.0.0'),
    ));

    return $merged;
}

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
        'versions' => $package['versions'],
    ];
}

function slugToFilename(string $slug): string
{
    return str_replace('/', '--', $slug);
}

/**
 * Fetch the latest GitHub release (tag, assets, published_at) for a repo.
 *
 * @return array<string, mixed>|null
 */
function fetchLatestRelease(string $githubRepo, string $githubToken): ?array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: appress-registry-index-generator',
    ];
    if ($githubToken !== '') {
        $headers[] = "Authorization: Bearer {$githubToken}";
    }

    $url = "https://api.github.com/repos/{$githubRepo}/releases/latest";
    $body = httpGet($url, $headers);
    if ($body === null) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) && isset($decoded['tag_name']) ? $decoded : null;
}

/**
 * Fetch appress.json (falling back to the legacy apppress.json name) from a
 * specific tag ref — never from `main` (H1).
 *
 * @return array<string, mixed>|null
 */
function fetchManifestAtRef(string $githubRepo, string $tag, string $githubToken): ?array
{
    $headers = $githubToken !== '' ? ["Authorization: Bearer {$githubToken}"] : [];

    foreach (['appress.json', 'apppress.json'] as $filename) {
        $url = "https://raw.githubusercontent.com/{$githubRepo}/{$tag}/{$filename}";
        $body = httpGet($url, $headers);
        if ($body === null) {
            continue;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Verify a release carries the artifact zip plus its .sha256 and .sig
 * sidecar assets, and return their resolved values. Returns null (hard
 * failure) if any of the three is missing — a release with a zip but no
 * checksum/signature is exactly the "installable but unverifiable" gap this
 * closes.
 *
 * @param  array<string, mixed>  $release
 * @return array{download_url: string, checksum_sha256: string, signature: string}|null
 */
function extractReleaseAssets(array $release, string $artifact, string $githubToken): ?array
{
    $headers = [
        'Accept: application/octet-stream',
        'User-Agent: appress-registry-index-generator',
    ];
    if ($githubToken !== '') {
        $headers[] = "Authorization: Bearer {$githubToken}";
    }

    $assets = (array) ($release['assets'] ?? []);
    $byName = [];
    foreach ($assets as $asset) {
        if (is_array($asset) && isset($asset['name'])) {
            $byName[(string) $asset['name']] = $asset;
        }
    }

    if (! isset($byName[$artifact], $byName["{$artifact}.sha256"], $byName["{$artifact}.sig"])) {
        return null;
    }

    $downloadUrl = (string) ($byName[$artifact]['browser_download_url'] ?? '');

    $checksumBody = httpGet((string) ($byName["{$artifact}.sha256"]['browser_download_url'] ?? ''), $headers);
    $checksum = $checksumBody !== null ? trim(strtok(trim($checksumBody), ' ')) : '';

    $sigBody = httpGet((string) ($byName["{$artifact}.sig"]['browser_download_url'] ?? ''), $headers);
    $signature = $sigBody !== null ? trim($sigBody) : '';

    if ($downloadUrl === '' || ! preg_match('/^[0-9a-f]{64}$/', $checksum) || ! preg_match('/^[0-9a-f]{128}$/', $signature)) {
        return null;
    }

    return [
        'download_url' => $downloadUrl,
        'checksum_sha256' => $checksum,
        'signature' => $signature,
    ];
}

/**
 * @param  list<string>  $headers
 */
function httpGet(string $url, array $headers = []): ?string
{
    if ($url === '') {
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = file_get_contents($url, false, $context);

    $status = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $headerLine, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    if ($body === false || $status >= 400 || $status === 0) {
        fwrite(STDERR, "  fetch failed ({$status}): {$url}\n");

        return null;
    }

    fwrite(STDERR, "  fetched ({$status}): {$url}\n");

    return $body !== '' ? $body : null;
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
