<div align="center">
    <h1>Aps Connect</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/meledjearmel/aps-connect"><img src="https://img.shields.io/packagist/v/meledjearmel/aps-connect.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/meledjearmel/aps-connect"><img src="https://img.shields.io/packagist/php-v/meledjearmel/aps-connect.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/meledjearmel/aps-connect"><img src="https://badge.laravel.cloud/badge/meledjearmel/aps-connect?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/meledjearmel/aps-connect/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/meledjearmel/aps-connect/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/meledjearmel/aps-connect"><img src="https://img.shields.io/packagist/dt/meledjearmel/aps-connect.svg?style=flat-square" alt="Total Downloads"></a>
</p>



## Installation

You can install the package via Composer:

```bash
composer require meledjearmel/aps-connect
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="aps-connect"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="aps-connect-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="aps-connect-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="aps-connect-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="aps-connect-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="aps-connect-assets"
```

## Usage

<!-- Add a basic usage example here. -->

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
