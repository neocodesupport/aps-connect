<div align="center">
    <h1>Aps Connect</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/neocode/aps-connect"><img src="https://img.shields.io/packagist/v/neocode/aps-connect.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/neocode/aps-connect"><img src="https://img.shields.io/packagist/php-v/neocode/aps-connect.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/neocode/aps-connect"><img src="https://badge.laravel.cloud/badge/neocode/aps-connect?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/neocodesupport/aps-connect/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/neocodesupport/aps-connect/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/neocode/aps-connect"><img src="https://img.shields.io/packagist/dt/neocode/aps-connect.svg?style=flat-square" alt="Total Downloads"></a>
</p>

<p align="center">
    <strong>English</strong> | <a href="README.fr.md">Français</a>
</p>

A stateless Laravel client for [Registra](https://registra.neocode.ci) licence
verification and App Station software distribution & auto-updates, exposed
through a single `ApsConnect` facade. Every runtime method (everything under
[Licensing](#licensing-registra), [Distribution & auto-update](#distribution--auto-update-app-station)
and [Marketplace catalogue](#marketplace-catalogue-app-station) below) never
persists anything itself — you store the API keys and results it returns in
your own application. [`aps-connect:release`](#packaging--publishing-a-release-app-station)
is the one exception: it builds a release zip on disk and uploads it, on
purpose — see that section for what that involves.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [How it works](#how-it-works)
- [Configuration](#configuration)
  - [`appstation.conf.json`](#appstationconfjson)
  - [`appstation.conf.local.json`](#appstationconflocaljson)
  - [Environment & sandbox routing](#environment--sandbox-routing)
- [Licensing (Registra)](#licensing-registra)
  - [`verifyLicence()`](#verifylicencestring-licencekey-licencestatus)
  - [`verifyModuleLicence()`](#verifymodulelicencestring-licencekey-string-moduleslug-moduleverification)
  - [`subscribe()`](#subscribestring-licencekey-array-customer-array-device--subscriptionresult)
  - [`issueTrial()`](#issuetrialarray-customer-trialissued)
  - [`issueModuleTrial()`](#issuemoduletrialstring-moduleslug-array-customer-moduletrialissued)
  - [`verifyStandaloneModuleLicence()`](#verifystandalonemodulelicencestring-modulelicencekey-standalonemodulelicence)
  - [`activateStandaloneModuleLicence()`](#activatestandalonemodulelicencestring-modulelicencekey-array-customer-standalonemoduleactivation)
  - [`attachStandaloneModuleLicence()`](#attachstandalonemodulelicencestring-modulelicencekey-string-motherlicencekey-standalonemoduleattachment)
  - [`lookupByCustomerDevice()`](#lookupbycustomerdevicestring-email-string-deviceuuid-subscriptionresult)
  - [`me()`](#me-softwareidentity)
- [Distribution & auto-update (App Station)](#distribution--auto-update-app-station)
  - [`registerSoftwareInstance()`](#registersoftwareinstancestring-licencekey-string-clientreference-string-label--softwareinstanceregistration)
  - [`downloadPackage()`](#downloadpackagestring-instanceapikey-int-packagereleaseid-string-modulelicencekey--packagedownload)
  - [`checkForUpdate()`](#checkforupdatestring-instanceapikey-string-currentversion-string-platform-string-minstability-string-currentchannel--updatecheckresult)
  - [A complete distribution flow](#a-complete-distribution-flow)
- [Marketplace catalogue (App Station)](#marketplace-catalogue-app-station)
  - [`listSoftwares()`](#listsoftwaresarray-filters--paginatedsoftware)
  - [`getSoftware()`](#getsoftwarestring-slug-software)
  - [`getSoftwareReleases()`](#getsoftwarereleasesstring-slug-int-page--paginatedsoftwarerelease)
  - [`getSoftwarePackages()`](#getsoftwarepackagesstring-slug-int-page--paginatedpackage)
  - [`listPackages()`](#listpackagesarray-filters--paginatedpackage)
  - [`getPackage()`](#getpackagestring-softwareslug-string-slug-package)
  - [`getPackageReleases()`](#getpackagereleasesstring-softwareslug-string-slug-int-page--paginatedpackagerelease)
  - [`listCategories()`](#listcategories-categorylist)
  - [`listTags()`](#listtagsint-page--paginatedtag)
  - [`search()`](#searchstring-query-string-type--searchresults)
- [Exception handling](#exception-handling)
  - [Registra exceptions](#registra-exceptions)
  - [App Station exceptions](#app-station-exceptions)
  - [Credential resolution errors](#credential-resolution-errors)
- [The `aps-connect:doctor` command](#the-aps-connectdoctor-command)
- [Packaging & publishing a release (App Station)](#packaging--publishing-a-release-app-station)
  - [`--pack`](#--pack)
  - [`--publish`](#--publish)
  - [Source obfuscation: what it does and doesn't protect](#source-obfuscation-what-it-does-and-doesnt-protect)
  - [Installing (or updating) on a customer server](#installing-or-updating-on-a-customer-server)
- [Testing your integration](#testing-your-integration)
- [Data Transfer Objects reference](#data-transfer-objects-reference)
- [Design notes & anti-patterns](#design-notes--anti-patterns)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)

## Requirements

- PHP `^8.3`
- Laravel `illuminate/support` `^12.0` or `^13.0`
- An `appstation.conf.json` (and, in development, an `appstation.conf.local.json`)
  file at your application's root, written by the `aps` CLI (`aps init` / `aps
  promote`) — Aps Connect reads these, it does not write them.

## Installation

You can install the package via Composer:

```bash
composer require neocode/aps-connect
```

There is nothing to publish: no config file, no migrations, views,
translations, or public assets. Every runtime method is a pure HTTP call —
none of them touch your database or your frontend, and every credential
comes exclusively from `appstation.conf.json` / `appstation.conf.local.json`
(see [Configuration](#configuration) below). `aps-connect:release` (see
[Packaging & publishing a release](#packaging--publishing-a-release-app-station))
is dev-time tooling, not part of that runtime surface — it does write a zip
to disk and shell out to `composer`/`npm`.

## How it works

Aps Connect wraps two distinct external services behind one facade:

| Service | Purpose | Client class | Auth header value |
|---|---|---|---|
| **Registra** | Licence issuance & verification | `RegistraClient` | Your software's product API key |
| **App Station** | Marketplace distribution & auto-update | `AppStationClient` | The product key for `registerSoftwareInstance()`; a per-instance key (returned by `registerSoftwareInstance()`) for `downloadPackage()`/`checkForUpdate()` |

Both send the same `X-Software-Api-Key` header, and — this is a load-bearing
detail — **the exact same product API key authenticates both Registra calls and
App Station's `registerSoftwareInstance()` call**. App Station's own accepted
keys are synced from Registra, so there is nothing extra to generate or
configure for App Station on the product-key side.

**The package is entirely stateless.** It never writes to a database, cache, or
disk. Every method call is a single outbound HTTP request, and every response is
converted into a readonly, typed Data Transfer Object. Two consequences follow:

1. Any state you need across requests — the current licence status, a customer's
   App Station instance API key, a "last seen" timestamp — is yours to persist,
   in whatever shape fits your application (a column on your `User`/`Tenant`
   model, a dedicated table, a cache entry — Aps Connect has no opinion).
2. `registerSoftwareInstance()` is not implicitly memoized. Call it once per
   tenant/device with a stable `$clientReference` and store what it returns; do
   not call it on every request or every boot (see
   [`registerSoftwareInstance()`](#registersoftwareinstancestring-licencekey-string-clientreference-string-label--softwareinstanceregistration)
   for why).

## Configuration

### `appstation.conf.json`

This file lives at your application's root (next to `composer.json`) and is
written by the `aps` CLI, not by hand. Aps Connect reads it through
`Neocode\ApsConnect\Support\ProjectConfigReader`:

```json
{
  "$schema": "https://appstation.dev/schemas/appstation.conf.v1.json",
  "version": 1,
  "type": "software",
  "environment": "production",
  "software": { "token": "53b5e626-4d7a-4a8b-8fbd-8db698b520e4", "name": "YourSoftware" },
  "api": { "baseUrl": "https://registra.neocode.ci/api" },
  "auth": { "apiKeySource": "env", "apiKeyEnvVar": "REGISTRA_API_KEY" },
  "appstation": { "baseUrl": "https://app-station.neocode.ci", "softwareId": 2 }
}
```

Aps Connect only reads three of these keys — `environment`, `api.baseUrl`, and
`appstation.baseUrl` — everything else in the file exists for the `aps` CLI's own
bookkeeping and is ignored here.

| Key | Read by | Behaviour |
|---|---|---|
| `environment` | `ProjectConfigReader::credentials()` | `"development"` or `"production"` (or absent). **No config/env fallback at all** — a missing file or key always means `"production"`. This is deliberate: letting an env var flip the environment would let a deployer route real licence checks through Registra's permissive sandbox. |
| `api.baseUrl` | `ProjectConfigReader::credentials()` | Registra's base URL, **including** the trailing `/api` (e.g. `https://registra.neocode.ci/api`). **No config/env fallback at all** — if absent, `MissingCredentialsException` is thrown. |
| `appstation.baseUrl` | `ProjectConfigReader::appStationCredentials()` | App Station's **bare domain**, with **no** `/api/v1` suffix (e.g. `https://app-station.neocode.ci`) — `AppStationClient` adds that prefix to every request path itself. **No config/env fallback at all** — if absent, `MissingCredentialsException` is thrown. |

Both base urls are trust anchors, not mere defaults: which server every licence
check, registration, download, or update check is sent to determines whether
those checks mean anything. That's why neither one is ever configurable through
an environment variable, nor has a config default to fall back to — only this
versioned, publisher-controlled file can set them.

> [!NOTE]
> `type` is `"software"` (a standalone application) or `"module"` (an App
> Station package tied to a parent application) — `aps init --type module`
> writes a differently-shaped file (`module`/`parentSoftware` instead of
> `software`, `appstation.packageId`/`parentSoftwareId` instead of
> `appstation.softwareId`). Aps Connect doesn't care either way: `environment`,
> `api.baseUrl`, and `appstation.baseUrl` exist under the same names in both
> shapes, so credential resolution is identical. The one thing worth knowing if
> you're integrating from a module's codebase: **the resolved API key is always
> the parent software's**, not the module's own — the module has no API key of
> its own. Use `verifyModuleLicence()`/`verifyStandaloneModuleLicence()` for the
> module-scoped licence checks; they still authenticate with that same parent
> key.

### `appstation.conf.local.json`

A **gitignored** sibling file, also written by `aps init`, holding your local
development API key (and a `signingSecret` used by the `aps` CLI's own
`sign`/`verify` commands — Aps Connect never reads it):

```json
{
  "auth": { "apiKey": "your-dev-api-key" },
  "signingSecret": "hmac-secret-for-aps-sign-and-aps-verify"
}
```

`ProjectConfigReader::credentials()` reads `auth.apiKey` from this file —
that's the *only* source for the API key. There is no Laravel config or env
var fallback: if `auth.apiKey` is missing or empty, `MissingCredentialsException`
is thrown. Run `aps init` to write this file.

Aps Connect ships **no config file at all** — nothing under `aps-connect.*` is
ever published or read from `config()`. `environment`, `api.baseUrl`, and
`appstation.baseUrl` come exclusively from `appstation.conf.json`; the API key
comes exclusively from `appstation.conf.local.json`'s `auth.apiKey`. A missing
or empty value in either file throws `MissingCredentialsException` rather than
silently falling back to a value a deployer could edit — run `aps init` (or
`aps promote`) to write them.

### Environment & sandbox routing

`environment` in `appstation.conf.json` controls two things:

1. **Which API key is expected**: `appstation.conf.local.json` holds whichever
   key is correct for the current environment — there is never more than one
   key present in that file at a time, so `auth.apiKey` is read the same way
   regardless of `environment`.
2. **Sandbox routing** for a subset of Registra endpoints. `RegistraClient`
   marks certain calls as `sandboxable: true` (`verifyLicence()`,
   `verifyModuleLicence()`, `subscribe()`). When `environment` is
   `"development"`, those calls are transparently prefixed with `sandbox/`
   (e.g. `POST sandbox/licences/verify` instead of `POST licences/verify`).
   Registra's sandbox accepts reserved dev licence keys without checking real
   data. Every other method (`issueTrial()`, `issueModuleTrial()`,
   `verifyStandaloneModuleLicence()`, `activateStandaloneModuleLicence()`,
   `attachStandaloneModuleLicence()`, `lookupByCustomerDevice()`, `me()`, and
   all three App Station methods) always hits the real endpoint, in both
   environments — there is no sandbox equivalent for them.

## Licensing (Registra)

Use the `ApsConnect` facade (`Neocode\ApsConnect\Facades\ApsConnect`) or inject
`Neocode\ApsConnect\ApsConnect` — both resolve the same singleton.

### `verifyLicence(string $licenceKey): LicenceStatus`

Checks whether a licence key is active. Sandboxable.

```php
use Neocode\ApsConnect\Facades\ApsConnect;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\LicenceInactiveException;

try {
    $status = ApsConnect::verifyLicence($licenceKey);
} catch (LicenceNotFoundException) {
    abort(404, 'Unknown licence key.');
} catch (LicenceInactiveException $e) {
    abort(403, $e->getMessage());
}

if (! $status->active) {
    // $status->reason explains why (e.g. "expired", "suspended")
}

report_usage_dashboard($status->remainingDays, $status->modules);
```

Returns a [`LicenceStatus`](#licencestatus): `licenceKey`, `active`,
`statusActive`, `notExpired`, `reason`, `status`, `expiresAt`, `remainingDays`,
`modules` (a `list<ModuleEntitlement>`), `message`.

### `verifyModuleLicence(string $licenceKey, string $moduleSlug): ModuleVerification`

Checks whether a specific module is entitled under a mother licence. Sandboxable.

```php
$verification = ApsConnect::verifyModuleLicence($licenceKey, 'reports');

if ($verification->module->active) {
    enable_reports_module();
}
```

Returns a [`ModuleVerification`](#moduleverification): `licenceKey`,
`moduleSlug`, `mother` (a full `LicenceStatus` for the mother licence),
`module` (a `ModuleEntitlement`), `message`.

### `subscribe(string $licenceKey, array $customer, array $device = []): SubscriptionResult`

Claims a licence for a specific customer (and optionally a device), typically
right after a purchase or at first activation. Sandboxable.

```php
$result = ApsConnect::subscribe(
    $licenceKey,
    customer: [
        'email' => $user->email,
        'firstname' => $user->first_name,
        'lastname' => $user->last_name,
    ],
    device: [
        'uuid' => $deviceUuid,
        'type' => 'desktop',
        'os' => php_uname('s'),
    ],
);

$user->update(['registra_customer_id' => $result->customer?->id]);
```

- `$customer` accepts `firstname`, `lastname`, `email` (required), `phone`,
  `address`, `software_key`.
- `$device` accepts `uuid`, `type`, `name`, `os`, `browser`; omit it entirely
  (default `[]`) for a customer-only claim — the `device` field is then not
  sent at all, rather than sent as `null`.

Returns a [`SubscriptionResult`](#subscriptionresult): `licence` (a full
`LicenceStatus`), `usagePeriod`, `customer`, `device`.

### `issueTrial(array $customer): TrialIssued`

Issues a fresh trial licence for a customer. Always hits the real endpoint (no
sandbox mirror), so a "trial" issued in development is a real Registra trial —
don't call it from a test/CI environment against production Registra.

```php
$trial = ApsConnect::issueTrial(['email' => $lead->email]);

Mail::to($lead)->send(new TrialLicenceIssued($trial->licenceKey, $trial->mustActivateBeforeAt));
```

Returns a [`TrialIssued`](#trialissued): `licenceKey`, `trialPeriodDays`,
`mustActivateBeforeAt`, `usagePeriod`, `customerEmail`, `message`.

### `issueModuleTrial(string $moduleSlug, array $customer): ModuleTrialIssued`

Same idea, scoped to a single module rather than the whole software.

```php
$trial = ApsConnect::issueModuleTrial('reports', ['email' => $lead->email]);
```

Returns a [`ModuleTrialIssued`](#moduletrialissued): `moduleLicenceKey`,
`moduleSlug`, `trialPeriodDays`, `usagePeriod`, `customerEmail`, `message`.

### `verifyStandaloneModuleLicence(string $moduleLicenceKey): StandaloneModuleLicence`

Checks a module licence that was purchased independently of any mother
licence (before it's necessarily attached to one).

```php
$licence = ApsConnect::verifyStandaloneModuleLicence($moduleLicenceKey);

if ($licence->status === 'pending_attachment') {
    prompt_user_to_attach($licence->moduleLicenceKey);
}
```

Returns a [`StandaloneModuleLicence`](#standalonemodulelicence):
`moduleLicenceKey`, `moduleSlug`, `status`, `active`, `attached`,
`motherLicenceKey`, `reason`, `expiresAt`, `message`.

### `activateStandaloneModuleLicence(string $moduleLicenceKey, array $customer): StandaloneModuleActivation`

Activates a standalone module licence for a customer.

```php
$activation = ApsConnect::activateStandaloneModuleLicence($moduleLicenceKey, [
    'email' => $user->email,
]);

if (! $activation->already) {
    notify_new_module_activation($activation);
}
```

Returns a [`StandaloneModuleActivation`](#standalonemoduleactivation):
`moduleLicenceKey`, `status`, `expiresAt`, `usagePeriod`, `customer`,
`already` (true if it was already active — this call is idempotent), `message`.

### `attachStandaloneModuleLicence(string $moduleLicenceKey, string $motherLicenceKey): StandaloneModuleAttachment`

Attaches a standalone module licence to a mother licence.

```php
$attachment = ApsConnect::attachStandaloneModuleLicence($moduleLicenceKey, $motherLicenceKey);

foreach ($attachment->modules as $module) {
    // $module is a ModuleEntitlement — the mother licence's full module list post-attachment
}
```

Returns a [`StandaloneModuleAttachment`](#standalonemoduleattachment):
`motherLicenceKey`, `modules` (a `list<ModuleEntitlement>`), `message`.

### `lookupByCustomerDevice(string $email, string $deviceUuid): SubscriptionResult`

Finds an existing subscription by customer email + device UUID — useful for
"restore my licence on this device" flows.

```php
try {
    $result = ApsConnect::lookupByCustomerDevice($user->email, $deviceUuid);
} catch (\Neocode\ApsConnect\Exceptions\LicenceNotFoundException) {
    // No subscription for this email/device pair.
}
```

Returns the same [`SubscriptionResult`](#subscriptionresult) shape as
`subscribe()`.

### `me(): SoftwareIdentity`

Returns identity/metadata about the software the configured API key belongs to
— this is what `aps-connect:doctor` uses as its connectivity probe.

```php
$identity = ApsConnect::me();

Log::info("Connected to Registra as {$identity->name} ({$identity->environment})");
```

Returns a [`SoftwareIdentity`](#softwareidentity): `token`, `key`, `name`,
`slug`, `environment`, `hasModules`, `hasApiKey`, `apiKeyFingerprint`,
`linkedProductionToken`, `hasPreviousApiKeyGracePeriod`,
`apiKeyPreviousExpiresAt`. Check `hasPreviousApiKeyGracePeriod` after rotating
your API key: while true, the previous key is still accepted until
`apiKeyPreviousExpiresAt`.

## Distribution & auto-update (App Station)

This is a separate system from licensing: it lets a **specific deployment** of
your software at a customer's site (a "SoftwareInstance") download package
releases and check for updates, gated by its own per-instance API key rather
than the product key.

### `registerSoftwareInstance(string $licenceKey, string $clientReference, ?string $label = null): SoftwareInstanceRegistration`

Registers (or resolves, if already registered) a deployment of your software
tied to a verified `$licenceKey`.

```php
$registration = ApsConnect::registerSoftwareInstance(
    licenceKey: $tenant->registra_licence_key,
    clientReference: (string) $tenant->id, // <-- see below
    label: "{$tenant->name} — production",
);

$tenant->update([
    'app_station_instance_id' => $registration->instance->id,
    'app_station_instance_api_key' => encrypt($registration->apiKey),
]);
```

> [!IMPORTANT]
> **`$clientReference` is App Station's idempotency key.** Pass a stable
> identifier — a tenant id, a device UUID, a machine fingerprint — that is the
> same every time you call this method for the *same* deployment. Reusing it
> returns the *existing* instance and its *existing* API key. Omitting a stable
> value (or passing a fresh one every call, e.g. `Str::uuid()`) creates a
> **brand-new** App Station instance and a **brand-new** API key on every
> single call — orphaning instances server-side. This is why `$clientReference`
> is a required parameter here, unlike App Station's own underlying API where
> it's optional.

Returns a [`SoftwareInstanceRegistration`](#softwareinstanceregistration):
`instance` (a `SoftwareInstance`) and `apiKey` (the instance's API key — **the
package does not store this for you**; persist it yourself, e.g. encrypted on
your tenant model, since it authenticates every subsequent
`downloadPackage()`/`checkForUpdate()` call for this deployment).

### `downloadPackage(string $instanceApiKey, int $packageReleaseId, ?string $moduleLicenceKey = null): PackageDownload`

Requests a signed, time-limited download URL for a package release, using the
instance API key returned by `registerSoftwareInstance()`.

```php
$download = ApsConnect::downloadPackage(
    instanceApiKey: decrypt($tenant->app_station_instance_api_key),
    packageReleaseId: $release->id,
    moduleLicenceKey: $moduleLicenceKey, // required only for modules not included in the base licence
);

return redirect($download->url); // expires at $download->expiresAt
```

Returns a [`PackageDownload`](#packagedownload): `url`, `expiresAt`, `checksum`
— verify the downloaded file against `checksum` before installing it.

### `checkForUpdate(string $instanceApiKey, string $currentVersion, ?string $platform = null, ?string $minStability = null, ?string $currentChannel = null): UpdateCheckResult`

Checks whether a newer release exists for this instance's software. Unlike
`registerSoftwareInstance()`/`downloadPackage()`, this call has **no licence
gate at all** — any active instance can check for updates regardless of licence
state, so it's safe to call unconditionally on every app startup.

```php
$update = ApsConnect::checkForUpdate(
    instanceApiKey: decrypt($tenant->app_station_instance_api_key),
    currentVersion: config('app.version'),
    platform: 'windows',      // one of: windows, macos, linux, android, ios, universal
    minStability: 'stable',   // least stable channel to consider: nightly, alpha, beta, rc, stable — defaults to stable server-side
    currentChannel: 'stable', // channel the caller is currently on — defaults to stable server-side
);

if ($update->updateAvailable) {
    notify_user_update_available($update->latestVersion, $update->url, $update->checksum, $update->signature);
}
```

`minStability` filters out any release published on a less stable channel,
regardless of version number — pass `'beta'` to also receive beta releases as
updates, for instance. `currentChannel` only ever lets App Station offer a
same-*version* upgrade to a *more* stable channel (e.g. `1.0.0-rc` ->
`1.0.0-stable`), never a regression to a less stable one.

Returns an [`UpdateCheckResult`](#updatecheckresult`): `updateAvailable`,
`latestVersion`. When `updateAvailable` is true, also: `release` (a full
`SoftwareRelease`), `url`, `expiresAt`, `checksum`, `signature` (an optional
HMAC signature of the release, when App Station is configured to sign
releases — verify it if present before trusting the download).

### A complete distribution flow

Putting the three App Station methods together, end to end, for a multi-tenant
application:

```php
use Neocode\ApsConnect\Facades\ApsConnect;
use Neocode\ApsConnect\Exceptions\AppStationLicenceRejectedException;
use Neocode\ApsConnect\Exceptions\InvalidAppStationApiKeyException;

class SoftwareInstanceService
{
    public function ensureRegistered(Tenant $tenant): string
    {
        if ($tenant->app_station_instance_api_key !== null) {
            return decrypt($tenant->app_station_instance_api_key);
        }

        try {
            $registration = ApsConnect::registerSoftwareInstance(
                licenceKey: $tenant->registra_licence_key,
                clientReference: (string) $tenant->id,
                label: $tenant->name,
            );
        } catch (AppStationLicenceRejectedException $e) {
            throw new \RuntimeException("Tenant {$tenant->id} has no valid licence: {$e->getMessage()}");
        }

        $tenant->update([
            'app_station_instance_id' => $registration->instance->id,
            'app_station_instance_api_key' => encrypt($registration->apiKey),
        ]);

        return $registration->apiKey;
    }

    public function checkForUpdate(Tenant $tenant, string $currentVersion): \Neocode\ApsConnect\Data\UpdateCheckResult
    {
        try {
            return ApsConnect::checkForUpdate($this->ensureRegistered($tenant), $currentVersion);
        } catch (InvalidAppStationApiKeyException) {
            // The stored instance key was revoked server-side — re-register.
            $tenant->update(['app_station_instance_api_key' => null]);

            return ApsConnect::checkForUpdate($this->ensureRegistered($tenant), $currentVersion);
        }
    }
}
```

## Marketplace catalogue (App Station)

These methods read App Station's **public** marketplace catalogue — the same
data browsable on the App Station storefront itself, including softwares and
packages beyond your own. Unlike every method above, the underlying endpoints
require **no authentication at all** server-side; `AppStationClient` still
attaches your product `X-Software-Api-Key` header to these requests for
consistency, but it's not checked. There is nothing to persist here either:
call these directly wherever you render an in-app "browse marketplace" screen.

Every list method returns a [`Paginated`](#paginatedtitem) wrapper, mirroring
Laravel's own paginator shape (`items`, `currentPage`, `lastPage`, `perPage`,
`total`) — pass `page` again yourself to fetch the next page.

### `listSoftwares(array $filters = []): Paginated<Software>`

```php
$softwares = ApsConnect::listSoftwares(['category_id' => $category->id, 'featured' => true, 'q' => $search, 'sort' => 'recent']);
```

`$filters` mirrors App Station's own listing query: `category_id`, `featured`,
`q` (free-text search), `sort` (`popular` (default), `recent`, `rating`, or
`downloads`), and `page`.

### `getSoftware(string $slug): Software`

Throws `AppStationResourceNotFoundException` for an unknown software slug.

### `getSoftwareReleases(string $slug, int $page = 1): Paginated<SoftwareRelease>`

The published release history of a software listing (reuses the same
[`SoftwareRelease`](#softwarerelease) DTO `checkForUpdate()` returns).

### `getSoftwarePackages(string $slug, int $page = 1): Paginated<Package>`

The marketplace packages (add-ons/modules) published under a given software.

### `listPackages(array $filters = []): Paginated<Package>`

```php
$packages = ApsConnect::listPackages(['software_id' => $software->id, 'page' => 1]);
```

### `getPackage(string $softwareSlug, string $slug): Package`

Throws `AppStationResourceNotFoundException` for an unknown software/package
slug pair.

### `getPackageReleases(string $softwareSlug, string $slug, int $page = 1): Paginated<PackageRelease>`

The published release history of a single package.

### `listCategories(): Category[]`

Returns the full category tree in one call (top-level categories with their
`children` nested) — App Station doesn't paginate this endpoint, so this
returns a plain array rather than a `Paginated`.

```php
foreach (ApsConnect::listCategories() as $category) {
    foreach ($category->children as $child) {
        // ...
    }
}
```

### `listTags(int $page = 1): Paginated<Tag>`

### `search(string $query, string $type = 'software'): SearchResults`

```php
$results = ApsConnect::search('invoic', type: 'all'); // one of: software | package | all

$results->softwares; // Software[]
$results->packages;  // Package[]
```

Unlike the list methods above, App Station caps search results at 10 per
type and does not paginate them — `SearchResults` holds plain arrays, not
`Paginated` wrappers.

## Exception handling

Every exception extends `Neocode\ApsConnect\Exceptions\ApsConnectException`.
Registra and App Station each have their own hierarchy, keyed by HTTP status —
**catching one never accidentally catches the other's failures**, since they
authenticate against different servers for different reasons.

### Registra exceptions

All extend `RegistraRequestException` (`status: int`, `body: array`).

| Exception | HTTP status | Extra properties | Thrown when |
|---|---|---|---|
| `InvalidApiKeyException` | 401 | — | The configured API key was rejected. |
| `LicenceInactiveException` | 403 | — | The licence exists but isn't in a usable state. |
| `LicenceNotFoundException` | 404 | — | No licence matches the given key. |
| `LicenceConflictException` | 409 | — | E.g. a licence already claimed by another customer/device. |
| `RegistraValidationException` | 422 | `errors: array<string, list<string>>` | The request payload failed Registra's validation. |
| `RegistraUnavailableException` | 429 or 503 | `retryAfter: ?int` | Rate-limited or Registra is down; `retryAfter` mirrors the `Retry-After` header, when present. |
| `RegistraRequestException` | any other status | — | Fallback for anything not mapped above. |

### App Station exceptions

All extend `AppStationRequestException` (`status: int`, `body: array`) — the
same shape as `RegistraRequestException`, deliberately kept separate.

| Exception | HTTP status | Extra properties | Thrown when |
|---|---|---|---|
| `InvalidAppStationApiKeyException` | 401 | — | The product key (`registerSoftwareInstance()`) or the instance key (`downloadPackage()`/`checkForUpdate()`) was rejected or revoked. |
| `AppStationLicenceRejectedException` | 403 | — | The licence backing the instance/download doesn't allow it (inactive, wrong module entitlement, ...). |
| `PackageReleaseNotFoundException` | 404 | — | `downloadPackage()` was called with an unknown `$packageReleaseId`. |
| `AppStationResourceNotFoundException` | 404 | — | A [marketplace catalogue](#marketplace-catalogue-app-station) lookup (`getSoftware()`, `getPackage()`, `getSoftwareReleases()`, `getSoftwarePackages()`, `getPackageReleases()`) was called with an unknown slug. |
| `AppStationValidationException` | 422 | `errors: array<string, list<string>>` | E.g. a required `moduleLicenceKey` was missing. |
| `AppStationUnavailableException` | 429 or 503 | `retryAfter: ?int` | Rate-limited or App Station is down. |
| `AppStationRequestException` | any other status | — | Fallback for anything not mapped above. |

### Credential resolution errors

`Neocode\ApsConnect\Exceptions\MissingCredentialsException` is thrown by
`ProjectConfigReader` — before any HTTP request is even attempted — when it
cannot resolve an API key or a base url at all (see the resolution-order tables
in [Configuration](#configuration)). Its message always says exactly which
value is missing and how to provide it.

## The `aps-connect:doctor` command

```bash
php artisan aps-connect:doctor
```

A safe, side-effect-free diagnostic:

1. Calls `me()` to confirm the configured Registra API key is accepted, and
   prints the connected software's name and environment.
2. Warns if a previous API key is still valid during its rotation grace
   period.
3. Calls `verifyLicence('APS-CONNECT-DOCTOR-PROBE')` — a key guaranteed not to
   exist — to confirm the verification endpoint itself responds correctly
   (`LicenceNotFoundException` is the expected, successful outcome here, not a
   failure).
4. Prints the resolved App Station base url.

It does **not** perform a live App Station check: `registerSoftwareInstance()`
(App Station's only endpoint authenticated with the product key) creates a real
`SoftwareInstance` on every call, so — unlike Registra's `verifyLicence()` —
there is no side-effect-free equivalent to probe with a throwaway value.

Exit code is `0` on success, `1` if the API key is rejected at either step.

## Packaging & publishing a release (App Station)

One dev-time command, `aps-connect:release`, covers the part of publishing a
release that repeats on every version: turning the project into an
uploadable artifact, and uploading it. Everything else — linking the repo,
authenticating, signing a repo-linkage manifest, rotating keys — stays in
the [`aps` CLI](https://www.npmjs.com/package/@app-station/cli) (`aps
login`, `aps init`, `aps sign`, `aps fetch-key`, ...); this command picks up
where `aps init` leaves off, reading the same `appstation.conf.json` it
wrote.

Run with no flags, it asks what to do first (pack and publish, pack only,
or publish an existing file), then prompts for whatever it still needs —
version, channel, platform, which file, the token — one question at a
time, only for fields you didn't already pass as an option. Pass
`--pack`/`--publish` (either or both) to skip straight past that first
question; pass every option a field needs and it runs with no prompts at
all, which is what you want in CI (add `--no-interaction` there too, same
as any other Artisan command — every field falls back to its default or
fails with a clear message instead of prompting).

```bash
# fully interactive
php artisan aps-connect:release

# build the zip only
php artisan aps-connect:release --pack [--out=] [--skip-npm] [--build-command=] [--no-obfuscate]

# publish an existing file only
php artisan aps-connect:release <file> --publish \
    --release-version=1.4.0 [--channel=stable] [--platform=universal] \
    [--notes=] [--min-software-version=] [--max-software-version=] \
    [--upload-name=] [--token=]

# build then publish in one shot (CI)
php artisan aps-connect:release --pack --publish --release-version=1.4.0 --token=$APS_TOKEN --no-interaction
```

### `--pack`

Builds a ready-to-deploy zip of the current project:

1. Copies the project into a temporary staging directory — **never in
   place**: running `composer install --no-dev` directly in your working
   copy would strip your own dev dependencies. Excludes `.git`, `.github`,
   `.idea`, `.vscode`, `node_modules`, `tests`, `storage/logs`,
   `storage/framework/{cache,sessions,views}`, `.phpunit.cache`, `*.log`,
   `.DS_Store`, plus anything a project-root `.apsignore` file adds (one
   glob pattern per line — a bare `node_modules`-style pattern matches that
   name anywhere in the tree; a pattern containing `/` is anchored to the
   project root). **`.env*` and `appstation.conf.local.json` are always
   excluded, regardless of `.apsignore`** — they hold secrets (your dev
   Registra API key, the `aps sign` signing secret) that must never end up
   in a published artifact.
2. Runs `composer install --no-dev --optimize-autoloader --no-interaction`
   in the staged copy.
3. If a `package.json` is present and `--skip-npm` wasn't passed: runs
   `npm ci && npm run build` (override with `--build-command=`).
4. Obfuscates the staged PHP source (see below) unless `--no-obfuscate` is
   passed.
5. Zips the result to `--out=` (default:
   `storage/app/aps-connect/releases/{slug}-{date}.zip`).

This only automates a plain Laravel web source zip. It does **not**
orchestrate a [NativePHP](https://nativephp.com) native build — if you're
shipping a NativePHP installer, build it with NativePHP's own tooling and
hand the resulting file straight to `--publish` below, exactly as `aps
release <file>` already treats any file as opaque.

### `--publish`

Uploads any file (typically the zip `--pack` just built, but a NativePHP
installer works just as well) to
`POST /api/v1/publisher/{softwares,packages}/{id}/releases`, mirroring `aps
release <file>`'s own validation (semver, `--channel`
stable|beta|rc|nightly, `--platform`
windows|macos|linux|android|ios|web|cli|browser_extension|universal;
`--min-software-version`/`--max-software-version` only for a module). When
combined with `--pack`, the file `--pack` just produced is used — the
`<file>` argument is only read when `--publish` runs without `--pack`.

Authentication is a **publisher session token**, not the runtime Registra
API key — pass `--token`, set the `APS_TOKEN` environment variable, or run
`aps login` (`aps-cli`) and export the token it prints (interactively, it's
also prompted for as a hidden `secret()` input). There is no browser login
flow here; that stays in `aps-cli`, since it's a one-off per machine, not
something worth reimplementing in PHP.

On success it prints the release's checksum and — once App Station has
computed one — its HMAC signature; warns if the signature isn't available
yet (App Station backfills it once a signing secret exists for the
software).

### Source obfuscation: what it does and doesn't protect

By default, `--pack` runs every staged `.php` file (except `vendor/**`,
which is left untouched) through a source obfuscator before zipping. This
exists because a licence check a customer can just delete from the source
— `ApsConnect::verifyLicence()`, `checkForUpdate()`'s signature check —
isn't much of a licence check. It is a **deterrent against casual
browsing/editing, not real security**:

- **What it does:** strips every comment and docblock, and renames local
  variables wherever that's provably safe (a name is left alone if it's
  passed to `compact()`, declared `global`, part of a closure `use()`
  capture, or if the containing function uses `extract()`/`$$x` at all).
- **What it never touches:** class, method, property, and namespace names.
  Laravel resolves those constantly through reflection and magic strings —
  the service container, Eloquent, route model binding, job/listener
  `handle()` conventions, named arguments — and renaming any of them
  automatically risks silently breaking your app on a customer's server, a
  far worse outcome than merely-weak protection.

This intentionally stops short of ionCube/Zend Guard-grade protection,
which needs a loader extension installed on the target server — not
something you can assume on arbitrary customer-controlled hosting. If you
need real protection against a motivated attacker rather than a deterrent
against casual tampering, that's a different, separate investment (a
compiled component for just the licence check, or requiring ionCube on
hosts you control) — `--no-obfuscate` is there so you can still hand-roll
your own step in front of `--publish` if you go that route.

### Installing (or updating) on a customer server

`--pack` deliberately excludes `.env*` from the zip (it's a secret), so a
customer extracting it can't boot the app yet. `aps-connect:install`
finishes the job:

```bash
php artisan aps-connect:install
```

It's also the **update** path: run it again after extracting a newer
release over an existing install (same `.env`) and it goes straight to
`migrate --force`/`storage:link` — no separate "update" command.

**It takes two runs the first time**, and that's by design, not a bug to
work around: Laravel resolves `config('database.connections.*')` from
`.env` at boot, before this command even runs, so writing new `DB_*`
values to `.env` partway through a process can't retroactively change the
DB connection `migrate` would use in that *same* process. Rather than
override `config()`/`putenv()` at runtime in ways that are easy to get
subtly wrong, the command just stops and asks for a second run once a
fresh boot has actually picked the new `.env` up:

1. **First run** (`.env` missing) — copies `.env.example` (or writes a
   minimal template if there isn't one), prompts for `APP_URL` and a DB
   connection (`sqlite` by default — nothing else to ask; `mysql`/`pgsql`
   also prompt for host/port/database/username/password), writes them into
   `.env`, and stops with a message to run the command again.
2. **Second run** (`.env` now exists) — generates `APP_KEY` if it's empty,
   runs `migrate --force`, runs `storage:link`, in that order.

Every field can be supplied as an option instead of prompted for
(`--app-url=`, `--db-connection=`, `--db-host=`, `--db-port=`,
`--db-database=`, `--db-username=`, `--db-password=`) — with
`--no-interaction`, a scripted first-time deploy is one line:

```bash
php artisan aps-connect:install --no-interaction \
    --app-url=https://mon-logiciel.exemple.com \
    --db-connection=mysql --db-host=127.0.0.1 --db-database=app \
    --db-username=app --db-password="$DB_PASSWORD"
```

`--no-migrate`/`--no-storage-link` skip those two steps individually.

Two events are dispatched — `Neocode\ApsConnect\Events\ApsConnectInstalling`
at the start of *every* run (including a run that only writes `.env`), and
`Neocode\ApsConnect\Events\ApsConnectInstalled` once migrations/storage:link
have actually run. This is the extension point for a software's own
post-install steps (seeding an admin account, warming a cache, ...) — the
package ships no config file to declare those in, by design, so a listener
in your own `EventServiceProvider` is how you hook in:

```php
use Neocode\ApsConnect\Events\ApsConnectInstalled;

Event::listen(ApsConnectInstalled::class, function (): void {
    // e.g. Artisan::call('app:seed-admin-account');
});
```

## Testing your integration

Aps Connect is built to be tested with `Http::fake()` — every example below
mirrors the package's own test suite.

```php
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Facades\ApsConnect;

it('gates a feature behind an active licence', function () {
    Http::fake(['*/licences/verify' => Http::response([
        'success' => true, 'active' => true, 'status_active' => true, 'not_expired' => true,
        'reason' => null, 'licence_key' => 'LIC-1', 'status' => 'active',
        'expires_at' => null, 'remaining_days' => null, 'modules' => [], 'message' => null,
    ])]);

    $status = ApsConnect::verifyLicence('LIC-1');

    expect($status->active)->toBeTrue();
});

it('handles a rejected instance api key by re-registering', function () {
    Http::fake([
        '*/integrations/software/packages/*/download' => Http::response(['message' => 'Invalid.'], 401),
        '*/integrations/software/instances/register' => Http::response([
            'instance' => ['id' => 1, 'label' => null, 'licence_key_mask' => '****', 'client_reference' => 'tenant-1', 'status' => 'active', 'last_seen_at' => null, 'revoked_at' => null, 'registered_at' => null],
            'api_key' => 'new-instance-key',
        ], 201),
    ]);

    // ... exercise your own service class here ...
});
```

For unit tests that don't need the full container (e.g. testing
`ProjectConfigReader` in isolation), write a temporary `appstation.conf.json` to
an isolated directory rather than the shared Testbench skeleton — see
`tests/Unit/Support/ProjectConfigReaderTest.php` in this repository for the
exact pattern, including why it matters under parallel test execution.

## Data Transfer Objects reference

All DTOs are `final readonly` classes under `Neocode\ApsConnect\Data`, each with
a static `fromArray()` constructor. Nested objects follow the same convention.

#### `LicenceStatus`
`licenceKey: string`, `active: bool`, `statusActive: bool`, `notExpired: bool`,
`reason: ?string`, `status: string`, `expiresAt: ?CarbonImmutable`,
`remainingDays: ?int`, `modules: list<ModuleEntitlement>`, `message: ?string`

#### `ModuleEntitlement`
`slug: string`, `status: string`, `active: bool`, `expiresAt: ?CarbonImmutable`,
`remainingDays: ?int`, `reason: ?string`

#### `ModuleVerification`
`licenceKey: string`, `moduleSlug: string`, `mother: LicenceStatus`,
`module: ModuleEntitlement`, `message: ?string`

#### `SubscriptionResult`
`licence: LicenceStatus`, `usagePeriod: ?UsagePeriod`, `customer: ?Customer`,
`device: ?Device`

#### `UsagePeriod`
`value: int`, `unit: string`, `label: string`

#### `Customer`
`id: ?int`, `email: string`, `firstname: ?string`, `lastname: ?string`,
`status: ?string`, `phone: ?string`, `address: ?string`, `softwareKey: ?string`

#### `Device`
`id: int`, `uuid: string`, `type: string`, `name: ?string`, `os: ?string`,
`browser: ?string`, `lastActiveAt: ?CarbonImmutable`

#### `TrialIssued`
`licenceKey: string`, `trialPeriodDays: int`,
`mustActivateBeforeAt: ?CarbonImmutable`, `usagePeriod: UsagePeriod`,
`customerEmail: string`, `message: ?string`

#### `ModuleTrialIssued`
`moduleLicenceKey: string`, `moduleSlug: string`, `trialPeriodDays: int`,
`usagePeriod: UsagePeriod`, `customerEmail: string`, `message: ?string`

#### `StandaloneModuleLicence`
`moduleLicenceKey: string`, `moduleSlug: string`, `status: string`,
`active: bool`, `attached: bool`, `motherLicenceKey: ?string`,
`reason: ?string`, `expiresAt: ?CarbonImmutable`, `message: ?string`

#### `StandaloneModuleActivation`
`moduleLicenceKey: string`, `status: string`, `expiresAt: ?CarbonImmutable`,
`usagePeriod: ?UsagePeriod`, `customer: ?Customer`, `already: bool`,
`message: ?string`

#### `StandaloneModuleAttachment`
`motherLicenceKey: string`, `modules: list<ModuleEntitlement>`,
`message: ?string`

#### `SoftwareIdentity`
`token: string`, `key: string`, `name: string`, `slug: string`,
`environment: string`, `hasModules: bool`, `hasApiKey: bool`,
`apiKeyFingerprint: ?string`, `linkedProductionToken: ?string`,
`hasPreviousApiKeyGracePeriod: bool`, `apiKeyPreviousExpiresAt: ?CarbonImmutable`

#### `SoftwareInstanceRegistration`
`instance: SoftwareInstance`, `apiKey: string`

#### `SoftwareInstance`
`id: int`, `label: ?string`, `licenceKeyMask: string`,
`clientReference: ?string`, `status: string`, `lastSeenAt: ?CarbonImmutable`,
`revokedAt: ?CarbonImmutable`, `registeredAt: ?CarbonImmutable`

#### `PackageDownload`
`url: string`, `expiresAt: CarbonImmutable`, `checksum: string`

#### `UpdateCheckResult`
`updateAvailable: bool`, `latestVersion: ?string`, `release: ?SoftwareRelease`,
`url: ?string`, `expiresAt: ?CarbonImmutable`, `checksum: ?string`,
`signature: ?string`

#### `SoftwareRelease`
`id: int`, `version: string`, `platform: ?string`, `channel: ?string`,
`releaseNotes: ?string`, `checksum: string`, `signature: ?string`,
`fileSize: ?int`, `isYanked: bool`, `publishedAt: ?CarbonImmutable`

#### `Paginated<TItem>`
`items: TItem[]`, `currentPage: int`, `lastPage: int`, `perPage: int`,
`total: int` — returned by every marketplace catalogue *list* method.

#### `Software`
`id: int`, `name: string`, `slug: string`, `tagline: ?string`,
`description: ?string`, `logoUrl: ?string`, `bannerUrl: ?string`,
`licenseType: ?string`, `acquisitionMode: string`, `status: ?string`,
`isFeatured: bool`, `hasModules: bool`, `pricePerDayXof: ?int`,
`lifetimePriceXof: ?int`, `downloadsCount: int`, `ratingAvg: ?float`,
`ratingCount: int`, `publisher: ?Publisher`, `categories: Category[]`,
`tags: Tag[]`

#### `Package`
`id: int`, `name: string`, `slug: string`, `description: ?string`,
`iconUrl: ?string`, `type: ?string`, `isOfficial: bool`, `isFeatured: bool`,
`isIncludedInBase: bool`, `acquisitionMode: string`, `pricePerDayXof: ?int`,
`lifetimePriceXof: ?int`, `hasTrialMode: bool`, `trialPeriodDays: ?int`,
`status: ?string`, `downloadsCount: int`, `ratingAvg: ?float`,
`ratingCount: int`, `software: ?Software`, `publisher: ?Publisher`

#### `PackageRelease`
`id: int`, `version: string`, `platform: ?string`, `channel: ?string`,
`releaseNotes: ?string`, `minSoftwareVersion: ?string`,
`maxSoftwareVersion: ?string`, `checksum: string`, `signature: ?string`,
`fileSize: ?int`, `isYanked: bool`, `publishedAt: ?CarbonImmutable`

#### `Publisher`
`id: int`, `name: string`, `slug: string`, `description: ?string`,
`logoUrl: ?string`, `website: ?string`, `isVerified: bool`, `status: ?string`

#### `Category`
`id: int`, `name: string`, `slug: string`, `icon: ?string`, `type: ?string`,
`position: int`, `children: Category[]`

#### `Tag`
`id: int`, `name: string`, `slug: string`

#### `SearchResults`
`softwares: Software[]`, `packages: Package[]`

#### `RegistraCredentials` / `AppStationCredentials`
Internal, resolved by `ProjectConfigReader` and injected by the service
provider — you won't normally construct these yourself outside of tests.
`RegistraCredentials`: `apiKey: string`, `baseUrl: string`,
`environment: string`. `AppStationCredentials`: `apiKey: string`,
`baseUrl: string`.

## Design notes & anti-patterns

- **Don't call `registerSoftwareInstance()` on every request or every app
  boot without a stable `$clientReference`.** Each call without one — or with
  a freshly generated one — creates a brand-new App Station instance and API
  key, orphaning the previous ones server-side. Register once, persist the
  result, replay it.
- **Don't expect the runtime facade (`ApsConnect::...`) to persist anything
  for you.** No migrations, no models, no cache. If you need to look up
  "which App Station instance belongs to this tenant", that lookup lives in
  your application's own data model. `aps-connect:release --pack` is the one
  deliberate exception — it writes a zip to disk by design; see
  [Packaging & publishing a release](#packaging--publishing-a-release-app-station).
- **Don't try to set `api.baseUrl` through Laravel config or an env var.** It
  only ever comes from `appstation.conf.json` — there is no config/env
  fallback, by design.
- **Don't catch `RegistraValidationException` expecting to catch an App
  Station failure, or vice versa.** The two hierarchies are intentionally
  separate.
- **Don't skip signature verification** on `checkForUpdate()`'s `signature`
  field when App Station provides one — it exists precisely so you can detect
  a tampered or mis-delivered update package before installing it.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Aps Connect! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Armel Meledje](https://github.com/meledjearmel)
- [All Contributors](../../contributors)

## License

Aps Connect is open-sourced software licensed under the [MIT license](LICENSE.md).
