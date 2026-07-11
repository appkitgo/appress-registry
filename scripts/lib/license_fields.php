<?php

declare(strict_types=1);

/**
 * @param  array<string, mixed>  $manifest
 */
function resolveLicenseType(array $manifest): string
{
    $type = (string) ($manifest['license_type'] ?? 'free');

    return in_array($type, ['free', 'premium'], true) ? $type : 'free';
}

/**
 * @param  array<string, mixed>  $manifest
 */
function resolveProductSlug(array $manifest, string $slug): string
{
    $productSlug = (string) ($manifest['product_slug'] ?? '');

    return $productSlug !== '' ? $productSlug : $slug;
}
