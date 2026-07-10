# AppPress Registry

Static, signed package index for production installs.

## Production serving path

**GitHub Pages, served from this repo, is the one production registry.**
`rebuild-index.yml` regenerates and signs `index.json` on every package
release and deploys it via `actions/deploy-pages`. Platform instances fetch
it over HTTPS via `apppress:registry:refresh` (see app-press), verify its
Ed25519 signature, then cache it locally — see `PackageDistributionIndex`.

`appress-registry-api` (a sibling repo) is a **dev-only stub** for local
license-validation testing — it is never the production package registry and
must never be pointed at from a staging or production instance.

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

## Local fixture (air-gapped path)

Nothing under `fixtures/` is produced or updated by the release pipeline —
it is a purely local scratch area (fully gitignored). To build an offline
artifact, use the same script CI uses, with a throwaway dev keypair:

```bash
cd ../plugin-billing
APPRESS_REGISTRY_PRIVATE_KEY=<dev-hex> \
  bash ../appress-ci/scripts/build-package.sh .
mv billing-*.zip* ../appress-registry/fixtures/
```

Then install without network (the instance must trust the matching dev
public key via `APPRESS_REGISTRY_PUBLIC_KEY`):

```bash
php artisan appress:package:install apppress/billing \
  --file=../../appress-registry/fixtures/billing-<version>.zip
```

## Sync full fleet catalog

```bash
php scripts/sync-packages-catalog.php > packages.json
```
