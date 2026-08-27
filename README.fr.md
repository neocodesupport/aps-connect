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
    <a href="README.md">English</a> | <strong>Français</strong>
</p>

Un client Laravel sans état pour la vérification de licences via
[Registra](https://registra.neocode.ci) et la distribution/mise à jour
automatique de logiciels via App Station, exposé via une seule façade
`ApsConnect`. Le package ne persiste jamais rien lui-même — c'est à votre
application de stocker les clés API et les résultats qu'il retourne.

## Installation

Vous pouvez installer le package via Composer :

```bash
composer require neocode/aps-connect
```

Le package expose une seule ressource publiable, son fichier de configuration :

```bash
php artisan vendor:publish --tag="aps-connect-config"
```

C'est optionnel — le package fonctionne dès l'installation avec des valeurs par
défaut raisonnables. Ne le publiez que si vous devez surcharger la résolution
de la clé API Registra (voir [Configuration](#configuration) ci-dessous).

## Configuration

Aps Connect résout presque tout à partir d'un fichier `appstation.conf.json` à
la racine de votre application — écrit par le CLI `aps` (`aps init`), pas à la
main :

```json
{
  "environment": "production",
  "api": { "baseUrl": "https://registra.example.com/api" },
  "appstation": { "baseUrl": "https://app-station.example.com" }
}
```

- `environment` et les deux URL de base sont lues depuis ce fichier.
  `api.baseUrl` et `appstation.baseUrl` retombent sur
  `config('aps-connect.registra_base_url')` /
  `config('aps-connect.appstation_base_url')` (les adresses de production
  réelles de Registra / App Station) quand le fichier ne les précise pas, mais
  une valeur explicite dans le fichier l'emporte toujours — aucune des deux
  n'est configurable via une variable d'environnement, car cela permettrait à
  un déployeur de rediriger silencieusement les vérifications de licence vers
  son propre serveur.
- La clé API Registra est la seule valeur qui lit aussi la config/l'env
  Laravel : `config('aps-connect.api_key')` / `REGISTRA_API_KEY` pour la
  production, ou `config('aps-connect.dev_api_key')` / `REGISTRA_DEV_API_KEY`
  pour l'environnement de développement déclaré dans `appstation.conf.json`.
  Elle est aussi lue depuis le fichier `appstation.conf.local.json` (gitignoré,
  écrit par `aps init`) quand aucune valeur de config/env n'est définie. Cette
  même clé authentifie à la fois les appels Registra et l'appel
  `instances/register` d'App Station — il n'y a rien d'autre à configurer pour
  App Station.

Lancez `php artisan aps-connect:doctor` à tout moment pour confirmer que la clé
API Registra est acceptée et voir la configuration App Station résolue.

## Utilisation

```php
use Neocode\ApsConnect\Facades\ApsConnect;

// Licences (Registra)
$status = ApsConnect::verifyLicence($licenceKey);
$result = ApsConnect::subscribe($licenceKey, ['email' => $email]);
$trial = ApsConnect::issueTrial(['email' => $email]);

// Distribution / mise à jour auto (App Station), une fois qu'un client a une licence active.
// Passez toujours un $clientReference stable (id de tenant, id d'appareil...) : App Station
// s'en sert comme clé d'idempotence, donc le réutiliser retourne l'instance/clé existante
// au lieu d'en créer une nouvelle à chaque appel.
$registration = ApsConnect::registerSoftwareInstance($licenceKey, $clientReference);

// Persistez $registration->apiKey vous-même (le package est sans état), puis :
$download = ApsConnect::downloadPackage($registration->apiKey, $packageReleaseId);
$update = ApsConnect::checkForUpdate($registration->apiKey, $currentVersion);
```

Consultez la skill Boost fournie
[`resources/boost/skills/aps-connect-development/SKILL.md`](resources/boost/skills/aps-connect-development/SKILL.md)
pour la liste complète des méthodes, la hiérarchie d'exceptions à intercepter,
et davantage d'exemples.

## Changelog

Voir le [CHANGELOG](CHANGELOG.md) pour plus d'informations sur les derniers
changements.

## Contribuer

Merci d'envisager de contribuer à Aps Connect ! Consultez notre
[guide de contribution](.github/CONTRIBUTING.md) pour démarrer.

## Vulnérabilités de sécurité

Consultez notre [politique de sécurité](.github/SECURITY.md) pour savoir
comment signaler une vulnérabilité.

## Crédits

- [Armel Meledje](https://github.com/meledjearmel)
- [Tous les contributeurs](../../contributors)

## Licence

Aps Connect est un logiciel open-source distribué sous
[licence MIT](LICENSE.md).
