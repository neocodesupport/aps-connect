# Release Notes

## [Unreleased](https://github.com/neocodesupport/aps-connect/compare/v1.1.0...master)

### Enhancements

- Add `aps-connect:sign` / `aps-connect:verify`, the PHP counterparts of
  `aps sign` / `aps verify`: HMAC-SHA256 repo-linkage manifests
  interchangeable with aps-cli's (same canonical JSON and signature,
  checked against a manifest signed by aps-cli itself).
- Add `aps-connect:status`, the PHP counterpart of `aps status`: the local
  link with no network call, `--remote` for the App Station status and
  release count, `--secrets` for where secrets come from (never their value).
  `PublisherClient` gains `listSoftwareReleases()`/`listPackageReleases()`.
- Add `aps-connect:fetch-key`, the PHP counterpart of `aps fetch-key`:
  re-issues the current environment's Registra key into
  `appstation.conf.local.json`, or with `--production` switches both project
  files to production, warning while the software/module isn't submitted yet
  and when git tracks `appstation.conf.local.json`.
- Add `aps-connect:login` / `aps-connect:logout`: checks a publisher token
  against App Station and stores it in aps-cli's own `credentials.json`
  (`--token`/`APS_TOKEN`, `--base-url`/`APPSTATION_BASE_URL`, `--dev`), so one
  login serves both tools; logout deletes it.
- Add `aps-connect:init`, the PHP counterpart of `aps init`: links the
  repository to an existing App Station software/module or creates one
  (`--link`/`--create`, `--type`, `--env`), then writes
  `appstation.conf.json`, `appstation.conf.local.json` and the `.gitignore`
  block in the same layout and with the same repository fingerprint as
  aps-cli. Authenticates with `APS_TOKEN` + `APPSTATION_BASE_URL`, else the
  `aps login` session. `PublisherClient` gains the matching developer
  endpoints and a `ProjectAlreadyLinkedException` (409).
- `aps-connect:release --publish` now uses the session `aps login` (aps-cli)
  stored on the machine when neither `--token` nor `APS_TOKEN` is given, and
  sends it to the App Station instance that session was created for.
  `APS_TOKEN` is sent to `APPSTATION_BASE_URL` when that variable is set,
  matching aps-cli's own resolution order.

## [1.1.0](https://github.com/neocodesupport/aps-connect/compare/v1.0.0...v1.1.0) - 2026-10-11

### Enhancements

- Add `aps-connect:release` to build and/or publish a release of the linked
  software or module: `--pack` builds a zip archive (optional frontend build,
  optional source obfuscation via comment stripping and safe local variable
  renaming), `--publish` uploads it to App Station with a semver version,
  channel, platform and release notes, authenticated by a publisher token
  (`--token` or `APS_TOKEN`). Prompts for missing options when run interactively.
- Add `aps-connect:install`, run by the customer after extracting a packed
  release (which excludes `.env*`): the first run writes `.env` (app URL,
  database connection) and stops; later runs generate the app key, migrate
  (`--no-migrate`) and link storage (`--no-storage-link`), which also makes it
  the update path for a redeployed archive. Dispatches the new
  `ApsConnectInstalling` / `ApsConnectInstalled` events.
- Add `PublisherClient` with its own exception hierarchy
  (`PublisherRequestException`, `InvalidPublisherTokenException`,
  `PublisherUnavailableException`, `PublisherValidationException`).

## [1.0.0](https://github.com/neocodesupport/aps-connect/compare/v0.1.0...v1.0.0) - 2026-09-14

### Breaking changes

- Removed `config/aps-connect.php` entirely, along with the `aps-connect-config`
  publish tag and the `REGISTRA_API_KEY`/`REGISTRA_DEV_API_KEY` env vars. The
  Registra API key is now read exclusively from `appstation.conf.local.json`'s
  `auth.apiKey` — there is no Laravel config or env var override or fallback
  for it, matching `environment`/`api.baseUrl`/`appstation.baseUrl`, which
  already had none.
- `ApsConnect::checkForUpdate()` and `AppStationClient::checkForUpdate()` replace
  their `$channel` parameter with `$minStability` and `$currentChannel`, matching
  App Station's update-check API, which now filters by least-stable channel to
  consider (`min_stability`) and lets a same-version upgrade cross only to a more
  stable channel (`current_channel`) — the old `channel` field was silently
  ignored by the server and never actually filtered anything.

### Enhancements

- Add App Station SoftwareInstance distribution/auto-update client:
  `ApsConnect::registerSoftwareInstance()`, `::downloadPackage()`, and
  `::checkForUpdate()`, backed by a dedicated `AppStationClient` and its own
  exception hierarchy (`AppStationRequestException` and subclasses).
- `aps-connect:doctor` now also reports the resolved App Station configuration.
- Add an App Station marketplace catalogue client: `ApsConnect::listSoftwares()`,
  `::getSoftware()`, `::getSoftwareReleases()`, `::getSoftwarePackages()`,
  `::listPackages()`, `::getPackage()`, `::getPackageReleases()`,
  `::listCategories()`, `::listTags()`, and `::search()`, with their own
  `Software`/`Package`/`PackageRelease`/`Category`/`Tag`/`SearchResults` DTOs.

## [v0.1.0](https://github.com/neocodesupport/aps-connect/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
