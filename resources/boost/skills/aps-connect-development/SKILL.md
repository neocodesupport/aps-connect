---
name: aps-connect-development
description: >
  Configure and apply the Aps Connect package (Registra licence checks and
  App Station distribution/auto-update) in Laravel applications.
license: MIT
metadata:
  author: Armel Meledje
---

# Aps Connect

Use this skill when a Laravel application needs to integrate the Aps Connect package.

## Primary Goal

- apply the `neocode/aps-connect` package's public API in the smallest correct way

## Workflow

### 1. Install and publish config

```bash
composer require neocode/aps-connect
php artisan vendor:publish --tag="aps-connect-config"
```

This publishes `config/aps-connect.php`, which holds only the two optional API key
overrides (`api_key`, `dev_api_key`). There are no other publish tags — the package
ships no migrations, views, translations, or assets.

### 2. Provide `appstation.conf.json` at the project root

All credentials are resolved by `Neocode\ApsConnect\Support\ProjectConfigReader`
from an `appstation.conf.json` file at the application's base path (written by the
`aps` CLI, e.g. `aps init`):

```json
{
  "environment": "production",
  "api": { "baseUrl": "https://registra.example.com/api" },
  "appstation": { "baseUrl": "https://app-station.example.com" }
}
```

`appstation.baseUrl` is the bare App Station domain — no `/api/v1` suffix.
`AppStationClient` adds that prefix to every request path itself, the same way the
`aps` CLI's own App Station client does. `api.baseUrl` (Registra), on the other
hand, already includes `/api`.

- `environment` is read **only** from this file — there is no Laravel config/env
  override. Which environment ("development" vs "production") licence checks run
  against is a trust decision, not a convenience default: no project file (or no
  `environment` key) always means "production".
- `api.baseUrl` and `appstation.baseUrl` both point at the one real, shared
  production instance of each service, so when the file omits either,
  `ProjectConfigReader` falls back to a config default:
  `config('aps-connect.registra_base_url')` (`https://registra.neocode.ci/api`) and
  `config('aps-connect.appstation_base_url')` (`https://app-station.neocode.ci`).
  Both are plain literals in `config/aps-connect.php` — not `env()`-backed, so a
  deployer's `.env` can't redirect them. An explicit value in the file always wins
  over these defaults; they only fill in when the file is silent.
- The API key is the one value with a config/env fallback (because
  `appstation.conf.local.json`, the gitignored local file, may not exist in CI):
  set `config('aps-connect.api_key')` / `REGISTRA_API_KEY` for production, or
  `config('aps-connect.dev_api_key')` / `REGISTRA_DEV_API_KEY` for the development
  environment declared in `appstation.conf.json`.
- The same key authenticates both Registra licence calls and the App Station
  `instances/register` call — there is no separate "App Station" credential to set up.

### 3. Use the `ApsConnect` facade or inject `ApsConnect::class`

Licensing (Registra):

```php
use Neocode\ApsConnect\Facades\ApsConnect;

$status = ApsConnect::verifyLicence($licenceKey);
$verification = ApsConnect::verifyModuleLicence($licenceKey, $moduleSlug);
$result = ApsConnect::subscribe($licenceKey, ['email' => $email], ['uuid' => $deviceUuid]);
$trial = ApsConnect::issueTrial(['email' => $email]);
$moduleTrial = ApsConnect::issueModuleTrial($moduleSlug, ['email' => $email]);
$identity = ApsConnect::me();
```

Distribution/auto-update (App Station), once a customer has an active licence:

```php
// Register once per tenant/device and persist $registration->apiKey and
// $registration->instance->id yourself — the package is stateless and does not
// store it. ALWAYS pass a stable $clientReference (tenant id, device id, ...):
// App Station uses it as the idempotency key. Omitting it (or varying it) creates
// a brand-new instance and a brand-new API key on every call.
$registration = ApsConnect::registerSoftwareInstance($licenceKey, $clientReference, $label);

// Later, using the persisted instance api key:
$download = ApsConnect::downloadPackage($instanceApiKey, $packageReleaseId, $moduleLicenceKey);
$update = ApsConnect::checkForUpdate($instanceApiKey, $currentVersion, $platform, $channel);

if ($update->updateAvailable) {
    // $update->release, $update->url, $update->checksum, $update->signature
}
```

Marketplace catalogue (App Station) — public, unauthenticated reads scoped to
*this* software's own packages and releases; safe to call anywhere, including
outside a licensed context. There is no "list every software" or "get an
arbitrary software" method — your integration already knows which software
it is:

```php
$releases = ApsConnect::getSoftwareReleases($slug);
$packages = ApsConnect::getSoftwarePackages($slug);
$otherPackages = ApsConnect::listPackages(['software_id' => $softwareId]);
$package = ApsConnect::getPackage($softwareSlug, $packageSlug);
$releases = ApsConnect::getPackageReleases($softwareSlug, $packageSlug);
$categories = ApsConnect::listCategories(); // Category[], nested `children`, not paginated
$tags = ApsConnect::listTags();
$results = ApsConnect::search($query, type: 'all'); // 'software' | 'package' | 'all'

// listSoftwares/getSoftwareReleases/getSoftwarePackages/listPackages/
// getPackageReleases/listTags all return a Paginated: ->items, ->currentPage,
// ->lastPage, ->perPage, ->total. Pass ['page' => N] / $page again yourself.
foreach ($softwares->items as $item) {
    // $item is a Software DTO
}
```

### 4. Catch the right exception hierarchy

Registra calls (`verifyLicence`, `subscribe`, `me`, ...) throw subclasses of
`Neocode\ApsConnect\Exceptions\RegistraRequestException`:
`InvalidApiKeyException` (401), `LicenceInactiveException` (403),
`LicenceNotFoundException` (404), `LicenceConflictException` (409),
`RegistraValidationException` (422, has `$errors`), `RegistraUnavailableException`
(429/503, has `$retryAfter`).

App Station calls (`registerSoftwareInstance`, `downloadPackage`, `checkForUpdate`,
and the marketplace catalogue methods) throw a **separate** hierarchy under
`Neocode\ApsConnect\Exceptions\AppStationRequestException`:
`InvalidAppStationApiKeyException` (401), `AppStationLicenceRejectedException` (403),
`PackageReleaseNotFoundException` (404, from `downloadPackage`),
`AppStationResourceNotFoundException` (404, from an unknown slug in the catalogue
methods), `AppStationValidationException` (422, has `$errors`),
`AppStationUnavailableException` (429/503, has `$retryAfter`). Do not expect a
Registra exception class from an App Station call, or vice versa.

### 5. Diagnose the connection

```bash
php artisan aps-connect:doctor
```

Resolves Registra credentials and confirms the configured API key is accepted. This
command only checks the Registra connection, not App Station.

## Rules, References, and Templates

Read before executing:

- `src/ApsConnect.php` — the full public API surface
- `src/Facades/ApsConnect.php` — facade accessor
- `src/Support/ProjectConfigReader.php` — how `appstation.conf.json` /
  `appstation.conf.local.json` / config resolve into credentials, and why the
  environment has no config/env override at all while both base urls do (as a
  literal fallback default only, never an override of an explicit file value)
- `src/Http/RegistraClient.php`, `src/Http/AppStationClient.php` — request/response
  and error-mapping behavior per service
- `config/aps-connect.php` — the four configurable values

## Examples

- A SaaS app calls `ApsConnect::verifyLicence()` on each request to gate a feature,
  catching `LicenceNotFoundException`/`LicenceInactiveException` to show an upgrade
  prompt.
- A desktop/on-prem app calls `ApsConnect::registerSoftwareInstance()` once per
  install (passing a persisted device UUID as `$clientReference`), stores the
  returned `apiKey` in its own local storage, then calls `ApsConnect::checkForUpdate()`
  on startup and `ApsConnect::downloadPackage()` when the user accepts an update.
- An in-app "browse add-ons" screen calls `ApsConnect::getSoftwarePackages($slug)`
  and `ApsConnect::search($query)` directly — no licence, instance, or API key
  gating needed, since the catalogue is public.

## Anti-patterns

- Do not try to override an explicit `api.baseUrl`/`appstation.baseUrl` already set
  in `appstation.conf.json` through Laravel config or an env var — `registra_base_url`
  / `appstation_base_url` in `config/aps-connect.php` are fallback defaults used only
  when the file is silent, never overrides, and neither is `env()`-backed.
- Do not try to set `environment` through Laravel config or an env var — it has no
  fallback at all and is only ever read from `appstation.conf.json`, by design.
- Do not call `registerSoftwareInstance()` without a stable `$clientReference` (e.g.
  regenerating one per request) — this creates a new App Station instance and API
  key on every call instead of reusing the existing one.
- Do not expect this package to persist instance API keys for you — it is a
  stateless HTTP client; store what `registerSoftwareInstance()` returns in your own
  application's data model.
- Do not document package internals here; keep the skill focused on adoption in
  Laravel apps.
