# AppPress Registry

Static, signed package index for production installs.

## Files

| File | Purpose |
|------|---------|
| `featured-packages.json` | Curated index source (billing, documents, quote-request-portal) |
| `packages.json` | Full fleet catalog synced from local `plugin-*` manifests |
| `index.json` | Committed featured index (unsigned in dev; CI signs for production) |
| `fixtures/` | Local signed release artifacts for air-gapped / offline installs |

## Regenerate the featured index

```bash
# Dev index (unsigned — verification skipped outside production)
php scripts/generate-index.php --featured

# Signed index (requires APPRESS_REGISTRY_PRIVATE_KEY)
APPRESS_REGISTRY_PRIVATE_KEY=<hex> php scripts/generate-index.php --featured
```

## Local billing fixture (air-gapped path)

```bash
export APPRESS_REGISTRY_PRIVATE_KEY=<hex>
export APPRESS_REGISTRY_PUBLIC_KEY=<matching-public-hex>
bash ../appress-ci/scripts/build-dev-fixture.sh
```

Then install without network:

```bash
php artisan appress:package:install apppress/billing \
  --file=../../appress-registry/fixtures/billing-1.0.0.zip
```

## Sync full fleet catalog

```bash
php scripts/sync-packages-catalog.php > packages.json
```
