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

function matchesTagPrefix(string $tagName, string $prefix): bool
{
    if ($prefix === '') {
        return false;
    }

    return str_starts_with($tagName, $prefix);
}
