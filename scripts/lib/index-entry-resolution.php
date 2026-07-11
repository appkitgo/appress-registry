<?php

declare(strict_types=1);

function resolveArtifactName(array $entry, string $shortSlug, string $version): string
{
    $prefix = (string) ($entry['artifact_prefix'] ?? $shortSlug);

    return "{$prefix}-{$version}.zip";
}

function matchesTagPrefix(string $tagName, string $prefix): bool
{
    if ($prefix === '') {
        return false;
    }

    return str_starts_with($tagName, $prefix);
}
