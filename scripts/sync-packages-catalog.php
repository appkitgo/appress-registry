#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate appress-registry/packages.json from local workspace manifests.
 *
 * Includes:
 * - plugin-* repos (appress.json)
 * - apppack-* and theme-* repos (appress.json or apppress.json)
 * - embedded app packs under app-press/apps/api/modules/app-packs/
 *
 * Usage: php scripts/sync-packages-catalog.php > packages.json
 */

$root = dirname(__DIR__, 2);
$entries = [];

foreach (glob($root.'/plugin-*/appress.json') ?: [] as $manifestPath) {
    addEntry($entries, $manifestPath, basename(dirname($manifestPath)), 'appkitgo/'.basename(dirname($manifestPath)));
}

foreach (glob($root.'/{apppack-*,theme-*}/*press.json', GLOB_BRACE) ?: [] as $manifestPath) {
    $repoDir = basename(dirname($manifestPath));
    addEntry($entries, $manifestPath, $repoDir, 'appkitgo/'.$repoDir);
}

$embeddedRoot = $root.'/app-press/apps/api/modules/app-packs';
foreach (glob($embeddedRoot.'/*/appress.json') ?: [] as $manifestPath) {
    $packDir = basename(dirname($manifestPath));
    addEntry(
        $entries,
        $manifestPath,
        'app-press:'.$packDir,
        'appkitgo/app-press',
        $packDir.'-v',
        'app-pack',
    );
}

usort($entries, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

echo json_encode(['packages' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

/**
 * @param  list<array<string, mixed>>  $entries
 */
function addEntry(
    array &$entries,
    string $manifestPath,
    string $label,
    string $githubRepo,
    string $releaseTagPrefix = '',
    ?string $typeOverride = null,
): void {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (! is_array($manifest)) {
        return;
    }

    $slug = (string) ($manifest['id'] ?? $manifest['slug'] ?? '');
    if ($slug === '') {
        return;
    }

    $type = $typeOverride ?? (string) ($manifest['type'] ?? 'plugin');
    $entry = [
        'slug' => $slug,
        'github_repo' => $githubRepo,
        'tier' => str_starts_with($slug, 'apppress/') ? 'first_party' : 'community',
        'type' => $type,
        'manifest_path' => relativeManifestPath($manifestPath),
    ];

    if ($releaseTagPrefix !== '') {
        $entry['release_tag_prefix'] = $releaseTagPrefix;
    }

    $entries[] = $entry;
}

function relativeManifestPath(string $manifestPath): string
{
    $root = dirname(__DIR__, 2);

    return '../'.ltrim(str_replace($root.'/', '', $manifestPath), '/');
}
