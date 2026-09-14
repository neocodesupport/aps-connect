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

### 1. Install

```bash
composer require neocode/aps-connect
```

There is nothing to publish: no config file, migrations, views, translations,
or assets. Every credential is resolved exclusively from two project files —
see below.

### 2. Provide `appstation.conf.json` / `appstation.conf.local.json` at the project root

All credentials are resolved by `Neocode\ApsConnect\Support\ProjectConfigReader`
from two files at the application's base path, both written by the `aps` CLI
(e.g. `aps init`):

```json
// appstation.conf.json (versioned, publisher-controlled)
{
  "environment": "production",
  "api": { "baseUrl": "https://registra.neocode.ci/api" },
  "appstation": { "baseUrl": "https://app-station.neocode.ci" }
}
```

```json
// appstation.conf.local.json (gitignored, local/deployment-controlled)
{
  "auth": { "apiKey": "the-product-api-key" }
}
```

`appstation.baseUrl` is the bare App Station domain — no `/api/v1` suffix.
`AppStationClient` adds that prefix to every request path itself, the same way the
`aps` CLI's own App Station client does. `api.baseUrl` (Registra), on the other
hand, already includes `/api`.

- `environment`, `api.baseUrl`, and `appstation.baseUrl` are read **only** from
  `appstation.conf.json` — there is no Laravel config/env override or fallback
  for any of the three. Which environment licence checks run against, and
  which server every call is sent to, are trust decisions, not convenience
  defaults: a missing file or key throws `MissingCredentialsException` instead
  of silently falling back to something a deployer could edit.
- The API key is read **only** from `appstation.conf.local.json`'s
  `auth.apiKey` — same rule, no Laravel config/env fallback. That file always
  holds whichever key is correct for the current `environment` (never both at
  once), so there's no separate dev/prod key selection to configure.
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
$update = ApsConnect::checkForUpdate($instanceApiKey, $currentVersion, $platform, $minStability, $currentChannel);

if ($update->updateAvailable) {
    // $update->release, $update->url, $update->checksum, $update->signature
}
```

`$minStability` (nightly < alpha < beta < rc < stable, default `stable`) is the
least stable channel App Station will consider — pass `'beta'` to receive beta
releases as updates, for example. `$currentChannel` (default `stable`) is the
channel the caller is currently on; it only ever lets App Station offer a
same-version upgrade to a *more* stable channel (e.g. `1.0.0-rc` -> `1.0.0-stable`),
never the reverse.

Marketplace catalogue (App Station) — public, unauthenticated reads; safe to
call anywhere, including outside a licensed context:

```php
$softwares = ApsConnect::listSoftwares(['category_id' => $categoryId, 'featured' => true, 'q' => $search, 'sort' => 'recent']);
$software = ApsConnect::getSoftware($softwareSlug);
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
foreach ($packages->items as $item) {
    // $item is a Package DTO
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
  `appstation.conf.local.json` resolve into credentials, and why none of the
  three values (environment, both base urls, the api key) has a config/env
  override or fallback
- `src/Http/RegistraClient.php`, `src/Http/AppStationClient.php` — request/response
  and error-mapping behavior per service

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

- Do not try to set `api.baseUrl`/`appstation.baseUrl`/`environment`/the api key
  through Laravel config or an env var — none of them has a config/env
  fallback; the first three are only ever read from `appstation.conf.json`,
  and the api key only from `appstation.conf.local.json`'s `auth.apiKey`, by
  design.
- Do not call `registerSoftwareInstance()` without a stable `$clientReference` (e.g.
  regenerating one per request) — this creates a new App Station instance and API
  key on every call instead of reusing the existing one.
- Do not expect this package to persist instance API keys for you — it is a
  stateless HTTP client; store what `registerSoftwareInstance()` returns in your own
  application's data model.
- Do not document package internals here; keep the skill focused on adoption in
  Laravel apps.
