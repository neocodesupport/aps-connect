# Release Notes

## [Unreleased](https://github.com/meledjearmel/aps-connect/compare/v0.1.0...1.x)

### Enhancements

- Add App Station SoftwareInstance distribution/auto-update client:
  `ApsConnect::registerSoftwareInstance()`, `::downloadPackage()`, and
  `::checkForUpdate()`, backed by a dedicated `AppStationClient` and its own
  exception hierarchy (`AppStationRequestException` and subclasses).
- `aps-connect:doctor` now also reports the resolved App Station configuration.
- Registra's and App Station's production base URLs now have a config default
  (`aps-connect.registra_base_url` / `aps-connect.appstation_base_url`) used only
  when `appstation.conf.json` doesn't set `api.baseUrl` / `appstation.baseUrl` —
  an explicit value in the file still always wins.

## [v0.1.0](https://github.com/meledjearmel/aps-connect/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
