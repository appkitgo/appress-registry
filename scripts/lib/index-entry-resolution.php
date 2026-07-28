<?php

declare(strict_types=1);

function resolveArtifactName(array $entry, string $shortSlug, string $version): string
{
    $prefix = (string) ($entry['artifact_prefix'] ?? $shortSlug);

    return "{$prefix}-{$version}.zip";
}

/**
 * The catalog field naming each embedded app-pack's `{pack}-v` release tag
 * prefix. Canonical name is `release_tag_prefix` (written by
 * sync-packages-catalog.php, used in packages.json + featured-packages.json).
 * A wrong key here silently falls back to /releases/latest on the shared
 * app-press repo and drops every embedded app-pack from the index.
 */
function entryReleaseTagPrefix(array $entry): string
{
    return (string) ($entry['release_tag_prefix'] ?? '');
}

/**
 * The manifest's path WITHIN its tagged repo, derived from the catalog's local
 * `manifest_path` (e.g. `../app-press/apps/api/modules/app-packs/booking-appointments/appress.json`)
 * by stripping the leading `../<checkout-dir>/` prefix. Standalone repos yield
 * `appress.json`; embedded app-packs yield their `apps/api/modules/app-packs/<pack>/appress.json`
 * subpath. Empty when the entry has no manifest_path (fall back to repo-root filenames).
 */
function repoManifestSubpath(array $entry): string
{
    $manifestPath = (string) ($entry['manifest_path'] ?? '');
    if ($manifestPath === '') {
        return '';
    }

    return (string) preg_replace('#^\.\./[^/]+/#', '', $manifestPath);
}

function matchesTagPrefix(string $tagName, string $prefix): bool
{
    if ($prefix === '') {
        return false;
    }

    return str_starts_with($tagName, $prefix);
}
