# Release Notes

## [Unreleased](https://github.com/neocodesupport/aps-connect/compare/v1.0.0...1.x)

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
