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
through a single `ApsConnect` facade. The package never persists anything
itself — you store the API keys and results it returns in your own
application.

## Installation

You can install the package via Composer:

```bash
composer require neocode/aps-connect
```

The package ships a single publishable resource, its config file:

```bash
php artisan vendor:publish --tag="aps-connect-config"
```

This is optional — the package works out of the box with sane defaults. Publish it
only if you need to override the Registra API key resolution (see [Configuration](#configuration)
below).

## Configuration

Aps Connect resolves almost everything from an `appstation.conf.json` file at your
application's root — written by the `aps` CLI (`aps init`), not by hand:

```json
{
  "environment": "production",
  "api": { "baseUrl": "https://registra.example.com/api" },
  "appstation": { "baseUrl": "https://app-station.example.com" }
}
```

- `environment` and both base urls are read from this file. `api.baseUrl` and
  `appstation.baseUrl` fall back to `config('aps-connect.registra_base_url')` /
  `config('aps-connect.appstation_base_url')` (Registra's / App Station's real
  production addresses) when the file omits them, but an explicit value in the file
  always wins — neither is configurable through an environment variable, since that
  would let a deployer silently redirect licence checks to their own server.
- The Registra API key is the one value that also reads from Laravel config/env:
  `config('aps-connect.api_key')` / `REGISTRA_API_KEY` for production, or
  `config('aps-connect.dev_api_key')` / `REGISTRA_DEV_API_KEY` for the development
  environment declared in `appstation.conf.json`. It is also read from the
  gitignored `appstation.conf.local.json` (written by `aps init`) when no config/env
  value is set. This same key authenticates both Registra calls and App Station's
  `instances/register` call — there is nothing else to configure for App Station.

Run `php artisan aps-connect:doctor` at any time to confirm the Registra API key is
accepted and see the resolved App Station configuration.

## Usage

```php
use Neocode\ApsConnect\Facades\ApsConnect;

// Licensing (Registra)
$status = ApsConnect::verifyLicence($licenceKey);
$result = ApsConnect::subscribe($licenceKey, ['email' => $email]);
$trial = ApsConnect::issueTrial(['email' => $email]);

// Distribution / auto-update (App Station), once a customer has an active licence.
// Always pass a stable $clientReference (tenant id, device id...): App Station uses
// it as the idempotency key, so reusing it returns the existing instance/key
// instead of creating a new one on every call.
$registration = ApsConnect::registerSoftwareInstance($licenceKey, $clientReference);

// Persist $registration->apiKey yourself (the package is stateless), then:
$download = ApsConnect::downloadPackage($registration->apiKey, $packageReleaseId);
$update = ApsConnect::checkForUpdate($registration->apiKey, $currentVersion);
```

See the bundled Boost skill at
[`resources/boost/skills/aps-connect-development/SKILL.md`](resources/boost/skills/aps-connect-development/SKILL.md)
for the full method list, the exception hierarchy to catch, and more examples.

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
