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
`ApsConnect`. Chaque méthode runtime (tout ce qui est sous [Licences](#licences-registra),
[Distribution & mise à jour automatique](#distribution--mise-à-jour-automatique-app-station)
et [Catalogue marketplace](#catalogue-marketplace-app-station) ci-dessous) ne
persiste jamais rien elle-même — c'est à votre application de stocker les
clés API et les résultats qu'elle retourne. La commande
[`aps-connect:release`](#packaging--publication-dune-release-app-station)
est l'unique exception : elle construit volontairement un zip de release
sur disque et le publie — voir cette section pour ce que ça implique.

## Sommaire

- [Prérequis](#prérequis)
- [Installation](#installation)
- [Comment ça marche](#comment-ça-marche)
- [Configuration](#configuration)
  - [`appstation.conf.json`](#appstationconfjson)
  - [`appstation.conf.local.json`](#appstationconflocaljson)
  - [Environnement & routage sandbox](#environnement--routage-sandbox)
- [Licences (Registra)](#licences-registra)
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
- [Distribution & mise à jour automatique (App Station)](#distribution--mise-à-jour-automatique-app-station)
  - [`registerSoftwareInstance()`](#registersoftwareinstancestring-licencekey-string-clientreference-string-label--softwareinstanceregistration)
  - [`downloadPackage()`](#downloadpackagestring-instanceapikey-int-packagereleaseid-string-modulelicencekey--packagedownload)
  - [`checkForUpdate()`](#checkforupdatestring-instanceapikey-string-currentversion-string-platform-string-minstability-string-currentchannel--updatecheckresult)
  - [Un flux de distribution complet](#un-flux-de-distribution-complet)
- [Catalogue marketplace (App Station)](#catalogue-marketplace-app-station)
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
- [Gestion des exceptions](#gestion-des-exceptions)
  - [Exceptions Registra](#exceptions-registra)
  - [Exceptions App Station](#exceptions-app-station)
  - [Erreurs de résolution des credentials](#erreurs-de-résolution-des-credentials)
- [La commande `aps-connect:doctor`](#la-commande-aps-connectdoctor)
- [Packaging & publication d'une release (App Station)](#packaging--publication-dune-release-app-station)
  - [`--pack`](#--pack)
  - [`--publish`](#--publish)
  - [Obfuscation du code source : ce qui est protégé, et ce qui ne l'est pas](#obfuscation-du-code-source--ce-qui-est-protégé-et-ce-qui-ne-lest-pas)
  - [Installer (ou mettre à jour) sur un serveur client](#installer-ou-mettre-à-jour-sur-un-serveur-client)
- [Tester votre intégration](#tester-votre-intégration)
- [Référence des Data Transfer Objects](#référence-des-data-transfer-objects)
- [Notes de conception & anti-patterns](#notes-de-conception--anti-patterns)
- [Changelog](#changelog)
- [Contribuer](#contribuer)
- [Vulnérabilités de sécurité](#vulnérabilités-de-sécurité)
- [Crédits](#crédits)
- [Licence](#licence)

## Prérequis

- PHP `^8.3`
- Laravel `illuminate/support` `^12.0` ou `^13.0`
- Un fichier `appstation.conf.json` (et, en développement, un
  `appstation.conf.local.json`) à la racine de votre application, écrit par le
  CLI `aps` (`aps init` / `aps promote`) — Aps Connect les lit, il ne les écrit
  pas.

## Installation

Vous pouvez installer le package via Composer :

```bash
composer require neocode/aps-connect
```

Il n'y a rien à publier : aucun fichier de config, aucune migration, vue,
traduction ou asset public. Chaque méthode runtime est un simple appel
HTTP — aucune ne touche à votre base de données ni à votre frontend, et
chaque credential vient exclusivement de `appstation.conf.json` /
`appstation.conf.local.json` (voir [Configuration](#configuration)
ci-dessous). `aps-connect:release` (voir
[Packaging & publication d'une release](#packaging--publication-dune-release-app-station))
est de l'outillage de développement, pas cette surface runtime — elle
écrit bien un zip sur disque et lance `composer`/`npm`.

## Comment ça marche

Aps Connect enveloppe deux services externes distincts derrière une seule
façade :

| Service | Rôle | Classe cliente | Valeur de l'en-tête d'auth |
|---|---|---|---|
| **Registra** | Émission & vérification de licences | `RegistraClient` | La clé API produit de votre logiciel |
| **App Station** | Distribution marketplace & mise à jour auto | `AppStationClient` | La clé produit pour `registerSoftwareInstance()` ; une clé par instance (retournée par `registerSoftwareInstance()`) pour `downloadPackage()`/`checkForUpdate()` |

Les deux envoient le même en-tête `X-Software-Api-Key`, et — c'est un détail
structurant — **la même clé API produit authentifie à la fois les appels
Registra et l'appel `registerSoftwareInstance()` d'App Station**. Les clés
acceptées par App Station sont synchronisées depuis Registra, donc il n'y a
rien de plus à générer ou configurer pour App Station côté clé produit.

**Le package est entièrement sans état.** Il n'écrit jamais dans une base de
données, un cache ou sur le disque. Chaque appel de méthode est une unique
requête HTTP sortante, et chaque réponse est convertie en un Data Transfer
Object readonly et typé. Deux conséquences en découlent :

1. Tout état dont vous avez besoin entre les requêtes — le statut de licence
   courant, la clé API d'instance App Station d'un client, un timestamp
   « dernière vue » — est à vous de le persister, sous la forme qui convient à
   votre application (une colonne sur votre modèle `User`/`Tenant`, une table
   dédiée, une entrée de cache — Aps Connect n'a aucun avis sur la question).
2. `registerSoftwareInstance()` n'est pas implicitement mémoïsée. Appelez-la
   une fois par tenant/appareil avec un `$clientReference` stable et stockez ce
   qu'elle retourne ; ne l'appelez pas à chaque requête ou à chaque démarrage
   (voir
   [`registerSoftwareInstance()`](#registersoftwareinstancestring-licencekey-string-clientreference-string-label--softwareinstanceregistration)
   pour comprendre pourquoi).

## Configuration

### `appstation.conf.json`

Ce fichier vit à la racine de votre application (à côté de `composer.json`) et
est écrit par le CLI `aps`, pas à la main. Aps Connect le lit via
`Neocode\ApsConnect\Support\ProjectConfigReader` :

```json
{
  "$schema": "https://appstation.dev/schemas/appstation.conf.v1.json",
  "version": 1,
  "type": "software",
  "environment": "production",
  "software": { "token": "53b5e626-4d7a-4a8b-8fbd-8db698b520e4", "name": "VotreLogiciel" },
  "api": { "baseUrl": "https://registra.neocode.ci/api" },
  "auth": { "apiKeySource": "env", "apiKeyEnvVar": "REGISTRA_API_KEY" },
  "appstation": { "baseUrl": "https://app-station.neocode.ci", "softwareId": 2 }
}
```

Aps Connect ne lit que trois de ces clés — `environment`, `api.baseUrl` et
`appstation.baseUrl` — tout le reste du fichier existe pour la comptabilité
propre au CLI `aps` et est ignoré ici.

| Clé | Lue par | Comportement |
|---|---|---|
| `environment` | `ProjectConfigReader::credentials()` | `"development"` ou `"production"` (ou absente). **Aucun repli config/env, jamais** — un fichier ou une clé absente signifie toujours `"production"`. C'est délibéré : laisser une variable d'env basculer l'environnement permettrait à un déployeur de router de vraies vérifications de licence via le sandbox permissif de Registra. |
| `api.baseUrl` | `ProjectConfigReader::credentials()` | L'URL de base de Registra, **incluant** le `/api` final (ex. `https://registra.neocode.ci/api`). **Aucun repli config/env, jamais** — si absente, `MissingCredentialsException` est levée. |
| `appstation.baseUrl` | `ProjectConfigReader::appStationCredentials()` | Le **domaine nu** d'App Station, **sans** suffixe `/api/v1` (ex. `https://app-station.neocode.ci`) — `AppStationClient` ajoute lui-même ce préfixe à chaque chemin de requête. **Aucun repli config/env, jamais** — si absente, `MissingCredentialsException` est levée. |

Les deux URL de base sont des ancrages de confiance, pas de simples valeurs par
défaut : le serveur vers lequel chaque vérification de licence, enregistrement,
téléchargement ou vérification de mise à jour est envoyé détermine si ces
vérifications ont un sens. C'est pourquoi aucune des deux n'est jamais
configurable via une variable d'environnement, ni n'a de valeur de config par
défaut — seul ce fichier versionné et contrôlé par l'éditeur peut les définir.

> [!NOTE]
> `type` vaut `"software"` (une application autonome) ou `"module"` (un
> package App Station rattaché à une application parente) —
> `aps init --type module` écrit un fichier de forme différente
> (`module`/`parentSoftware` au lieu de `software`,
> `appstation.packageId`/`parentSoftwareId` au lieu de
> `appstation.softwareId`). Aps Connect ne s'en soucie pas : `environment`,
> `api.baseUrl` et `appstation.baseUrl` existent sous les mêmes noms dans les
> deux formes, donc la résolution des credentials est identique. La seule
> chose à savoir si vous intégrez depuis le code d'un module : **la clé API
> résolue est toujours celle du logiciel parent**, pas celle du module — le
> module n'a pas de clé API propre. Utilisez
> `verifyModuleLicence()`/`verifyStandaloneModuleLicence()` pour les
> vérifications de licence propres au module ; elles s'authentifient tout de
> même avec cette même clé du parent.

### `appstation.conf.local.json`

Un fichier voisin **gitignoré**, également écrit par `aps init`, contenant
votre clé API de développement locale (et un `signingSecret` utilisé par les
commandes `sign`/`verify` propres au CLI `aps` — Aps Connect ne le lit
jamais) :

```json
{
  "auth": { "apiKey": "votre-cle-api-dev" },
  "signingSecret": "secret-hmac-pour-aps-sign-et-aps-verify"
}
```

`ProjectConfigReader::credentials()` lit `auth.apiKey` depuis ce fichier —
c'est l'**unique** source pour la clé API. Il n'y a aucun repli config Laravel
ou variable d'env : si `auth.apiKey` manque ou est vide,
`MissingCredentialsException` est levée. Exécutez `aps init` pour écrire ce
fichier.

Aps Connect n'embarque **aucun fichier de config** : rien sous `aps-connect.*`
n'est jamais publié ni lu via `config()`. `environment`, `api.baseUrl` et
`appstation.baseUrl` viennent exclusivement de `appstation.conf.json` ; la clé
API vient exclusivement de `auth.apiKey` dans `appstation.conf.local.json`.
Une valeur manquante ou vide dans l'un ou l'autre fichier lève
`MissingCredentialsException` plutôt que de retomber silencieusement sur une
valeur qu'un déployeur pourrait modifier — exécutez `aps init` (ou
`aps promote`) pour les écrire.

### Environnement & routage sandbox

`environment` dans `appstation.conf.json` contrôle deux choses :

1. **Quelle clé API est attendue** : `appstation.conf.local.json` contient
   toujours la clé correspondant à l'environnement courant — il n'y a jamais
   deux clés présentes en même temps dans ce fichier, donc `auth.apiKey` est
   lue de la même façon quel que soit `environment`.
2. **Le routage sandbox** pour un sous-ensemble des endpoints Registra.
   `RegistraClient` marque certains appels comme `sandboxable: true`
   (`verifyLicence()`, `verifyModuleLicence()`, `subscribe()`). Quand
   `environment` vaut `"development"`, ces appels sont transparemment préfixés
   par `sandbox/` (ex. `POST sandbox/licences/verify` au lieu de
   `POST licences/verify`). Le sandbox de Registra accepte des clés de licence
   de développement réservées sans vérifier de vraies données. Toutes les
   autres méthodes (`issueTrial()`, `issueModuleTrial()`,
   `verifyStandaloneModuleLicence()`, `activateStandaloneModuleLicence()`,
   `attachStandaloneModuleLicence()`, `lookupByCustomerDevice()`, `me()`, et
   les trois méthodes App Station) tapent toujours le vrai endpoint, dans les
   deux environnements — il n'existe pas d'équivalent sandbox pour elles.

## Licences (Registra)

Utilisez la façade `ApsConnect` (`Neocode\ApsConnect\Facades\ApsConnect`) ou
injectez `Neocode\ApsConnect\ApsConnect` — les deux résolvent le même
singleton.

### `verifyLicence(string $licenceKey): LicenceStatus`

Vérifie si une clé de licence est active. Sandboxable.

```php
use Neocode\ApsConnect\Facades\ApsConnect;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\LicenceInactiveException;

try {
    $status = ApsConnect::verifyLicence($licenceKey);
} catch (LicenceNotFoundException) {
    abort(404, 'Clé de licence inconnue.');
} catch (LicenceInactiveException $e) {
    abort(403, $e->getMessage());
}

if (! $status->active) {
    // $status->reason explique pourquoi (ex. "expired", "suspended")
}

report_usage_dashboard($status->remainingDays, $status->modules);
```

Retourne un [`LicenceStatus`](#licencestatus) : `licenceKey`, `active`,
`statusActive`, `notExpired`, `reason`, `status`, `expiresAt`,
`remainingDays`, `modules` (une `list<ModuleEntitlement>`), `message`.

### `verifyModuleLicence(string $licenceKey, string $moduleSlug): ModuleVerification`

Vérifie si un module spécifique est autorisé sous une licence mère.
Sandboxable.

```php
$verification = ApsConnect::verifyModuleLicence($licenceKey, 'reports');

if ($verification->module->active) {
    enable_reports_module();
}
```

Retourne un [`ModuleVerification`](#moduleverification) : `licenceKey`,
`moduleSlug`, `mother` (un `LicenceStatus` complet pour la licence mère),
`module` (un `ModuleEntitlement`), `message`.

### `subscribe(string $licenceKey, array $customer, array $device = []): SubscriptionResult`

Réclame une licence pour un client spécifique (et optionnellement un appareil),
typiquement juste après un achat ou à la première activation. Sandboxable.

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

- `$customer` accepte `firstname`, `lastname`, `email` (requis), `phone`,
  `address`, `software_key`.
- `$device` accepte `uuid`, `type`, `name`, `os`, `browser` ; omettez-le
  entièrement (défaut `[]`) pour une réclamation client seul — le champ
  `device` n'est alors pas envoyé du tout, plutôt qu'envoyé comme `null`.

Retourne un [`SubscriptionResult`](#subscriptionresult) : `licence` (un
`LicenceStatus` complet), `usagePeriod`, `customer`, `device`.

### `issueTrial(array $customer): TrialIssued`

Émet une nouvelle licence d'essai pour un client. Tape toujours le vrai
endpoint (aucun miroir sandbox), donc un « essai » émis en développement est un
vrai essai Registra — ne l'appelez pas depuis un environnement de test/CI
contre le Registra de production.

```php
$trial = ApsConnect::issueTrial(['email' => $lead->email]);

Mail::to($lead)->send(new TrialLicenceIssued($trial->licenceKey, $trial->mustActivateBeforeAt));
```

Retourne un [`TrialIssued`](#trialissued) : `licenceKey`, `trialPeriodDays`,
`mustActivateBeforeAt`, `usagePeriod`, `customerEmail`, `message`.

### `issueModuleTrial(string $moduleSlug, array $customer): ModuleTrialIssued`

Même principe, limité à un seul module plutôt qu'au logiciel entier.

```php
$trial = ApsConnect::issueModuleTrial('reports', ['email' => $lead->email]);
```

Retourne un [`ModuleTrialIssued`](#moduletrialissued) : `moduleLicenceKey`,
`moduleSlug`, `trialPeriodDays`, `usagePeriod`, `customerEmail`, `message`.

### `verifyStandaloneModuleLicence(string $moduleLicenceKey): StandaloneModuleLicence`

Vérifie une licence de module achetée indépendamment de toute licence mère
(avant qu'elle soit nécessairement attachée à l'une d'elles).

```php
$licence = ApsConnect::verifyStandaloneModuleLicence($moduleLicenceKey);

if ($licence->status === 'pending_attachment') {
    prompt_user_to_attach($licence->moduleLicenceKey);
}
```

Retourne un [`StandaloneModuleLicence`](#standalonemodulelicence) :
`moduleLicenceKey`, `moduleSlug`, `status`, `active`, `attached`,
`motherLicenceKey`, `reason`, `expiresAt`, `message`.

### `activateStandaloneModuleLicence(string $moduleLicenceKey, array $customer): StandaloneModuleActivation`

Active une licence de module standalone pour un client.

```php
$activation = ApsConnect::activateStandaloneModuleLicence($moduleLicenceKey, [
    'email' => $user->email,
]);

if (! $activation->already) {
    notify_new_module_activation($activation);
}
```

Retourne un [`StandaloneModuleActivation`](#standalonemoduleactivation) :
`moduleLicenceKey`, `status`, `expiresAt`, `usagePeriod`, `customer`,
`already` (true si déjà active — cet appel est idempotent), `message`.

### `attachStandaloneModuleLicence(string $moduleLicenceKey, string $motherLicenceKey): StandaloneModuleAttachment`

Attache une licence de module standalone à une licence mère.

```php
$attachment = ApsConnect::attachStandaloneModuleLicence($moduleLicenceKey, $motherLicenceKey);

foreach ($attachment->modules as $module) {
    // $module est un ModuleEntitlement — la liste complète des modules de la licence mère après attachement
}
```

Retourne un [`StandaloneModuleAttachment`](#standalonemoduleattachment) :
`motherLicenceKey`, `modules` (une `list<ModuleEntitlement>`), `message`.

### `lookupByCustomerDevice(string $email, string $deviceUuid): SubscriptionResult`

Trouve un abonnement existant par email client + UUID d'appareil — utile pour
les flux « restaurer ma licence sur cet appareil ».

```php
try {
    $result = ApsConnect::lookupByCustomerDevice($user->email, $deviceUuid);
} catch (\Neocode\ApsConnect\Exceptions\LicenceNotFoundException) {
    // Aucun abonnement pour cette paire email/appareil.
}
```

Retourne la même forme [`SubscriptionResult`](#subscriptionresult) que
`subscribe()`.

### `me(): SoftwareIdentity`

Retourne l'identité/les métadonnées du logiciel auquel appartient la clé API
configurée — c'est ce qu'utilise `aps-connect:doctor` comme sonde de
connectivité.

```php
$identity = ApsConnect::me();

Log::info("Connecté à Registra en tant que {$identity->name} ({$identity->environment})");
```

Retourne un [`SoftwareIdentity`](#softwareidentity) : `token`, `key`, `name`,
`slug`, `environment`, `hasModules`, `hasApiKey`, `apiKeyFingerprint`,
`linkedProductionToken`, `hasPreviousApiKeyGracePeriod`,
`apiKeyPreviousExpiresAt`. Vérifiez `hasPreviousApiKeyGracePeriod` après avoir
fait tourner votre clé API : tant que c'est vrai, l'ancienne clé est encore
acceptée jusqu'à `apiKeyPreviousExpiresAt`.

## Distribution & mise à jour automatique (App Station)

Ceci est un système distinct de la licence : il permet à un **déploiement
spécifique** de votre logiciel chez un client (une « SoftwareInstance ») de
télécharger des versions de packages et de vérifier les mises à jour, protégé
par sa propre clé API par instance plutôt que par la clé produit.

### `registerSoftwareInstance(string $licenceKey, string $clientReference, ?string $label = null): SoftwareInstanceRegistration`

Enregistre (ou résout, si déjà enregistré) un déploiement de votre logiciel lié
à une `$licenceKey` vérifiée.

```php
$registration = ApsConnect::registerSoftwareInstance(
    licenceKey: $tenant->registra_licence_key,
    clientReference: (string) $tenant->id, // <-- voir ci-dessous
    label: "{$tenant->name} — production",
);

$tenant->update([
    'app_station_instance_id' => $registration->instance->id,
    'app_station_instance_api_key' => encrypt($registration->apiKey),
]);
```

> [!IMPORTANT]
> **`$clientReference` est la clé d'idempotence d'App Station.** Passez un
> identifiant stable — un id de tenant, un UUID d'appareil, une empreinte
> machine — qui reste le même à chaque appel de cette méthode pour le *même*
> déploiement. Le réutiliser retourne l'instance *existante* et sa clé API
> *existante*. Omettre une valeur stable (ou en passer une nouvelle à chaque
> appel, ex. `Str::uuid()`) crée une **toute nouvelle** instance App Station et
> une **toute nouvelle** clé API à chaque appel — orphelinant les instances
> côté serveur. C'est pourquoi `$clientReference` est un paramètre obligatoire
> ici, contrairement à l'API App Station sous-jacente où il est optionnel.

Retourne un [`SoftwareInstanceRegistration`](#softwareinstanceregistration) :
`instance` (un `SoftwareInstance`) et `apiKey` (la clé API de l'instance — **le
package ne la stocke pas pour vous** ; persistez-la vous-même, ex. chiffrée sur
votre modèle tenant, puisqu'elle authentifie chaque appel ultérieur de
`downloadPackage()`/`checkForUpdate()` pour ce déploiement).

### `downloadPackage(string $instanceApiKey, int $packageReleaseId, ?string $moduleLicenceKey = null): PackageDownload`

Demande une URL de téléchargement signée et à durée limitée pour une version
de package, en utilisant la clé API d'instance retournée par
`registerSoftwareInstance()`.

```php
$download = ApsConnect::downloadPackage(
    instanceApiKey: decrypt($tenant->app_station_instance_api_key),
    packageReleaseId: $release->id,
    moduleLicenceKey: $moduleLicenceKey, // requis seulement pour les modules non inclus dans la licence de base
);

return redirect($download->url); // expire à $download->expiresAt
```

Retourne un [`PackageDownload`](#packagedownload) : `url`, `expiresAt`,
`checksum` — vérifiez le fichier téléchargé contre `checksum` avant de
l'installer.

### `checkForUpdate(string $instanceApiKey, string $currentVersion, ?string $platform = null, ?string $minStability = null, ?string $currentChannel = null): UpdateCheckResult`

Vérifie si une version plus récente existe pour le logiciel de cette instance.
Contrairement à `registerSoftwareInstance()`/`downloadPackage()`, cet appel n'a
**aucun contrôle de licence** — n'importe quelle instance active peut vérifier
les mises à jour quel que soit l'état de la licence, donc c'est sûr de
l'appeler sans condition à chaque démarrage de l'application.

```php
$update = ApsConnect::checkForUpdate(
    instanceApiKey: decrypt($tenant->app_station_instance_api_key),
    currentVersion: config('app.version'),
    platform: 'windows',       // l'un de : windows, macos, linux, android, ios, universal
    minStability: 'stable',    // canal le moins stable à considérer : nightly, alpha, beta, rc, stable — défaut stable côté serveur
    currentChannel: 'stable',  // canal sur lequel se trouve l'appelant — défaut stable côté serveur
);

if ($update->updateAvailable) {
    notify_user_update_available($update->latestVersion, $update->url, $update->checksum, $update->signature);
}
```

`minStability` exclut toute version publiée sur un canal moins stable, quel
que soit son numéro de version — passez `'beta'` pour recevoir aussi les
versions beta comme mises à jour, par exemple. `currentChannel` ne permet à
App Station de proposer qu'une mise à jour à numéro de version identique vers
un canal *plus* stable (ex. `1.0.0-rc` -> `1.0.0-stable`), jamais une
régression vers un canal moins stable.

Retourne un [`UpdateCheckResult`](#updatecheckresult) : `updateAvailable`,
`latestVersion`. Quand `updateAvailable` est vrai, aussi : `release` (un
`SoftwareRelease` complet), `url`, `expiresAt`, `checksum`, `signature` (une
signature HMAC optionnelle de la version, quand App Station est configuré pour
signer les versions — vérifiez-la si présente avant de faire confiance au
téléchargement).

### Un flux de distribution complet

En combinant les trois méthodes App Station, de bout en bout, pour une
application multi-tenant :

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
            throw new \RuntimeException("Le tenant {$tenant->id} n'a pas de licence valide : {$e->getMessage()}");
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
            // La clé d'instance stockée a été révoquée côté serveur — on ré-enregistre.
            $tenant->update(['app_station_instance_api_key' => null]);

            return ApsConnect::checkForUpdate($this->ensureRegistered($tenant), $currentVersion);
        }
    }
}
```

## Catalogue marketplace (App Station)

Ces méthodes lisent le catalogue marketplace **public** d'App Station — les
mêmes données que celles visibles sur la vitrine App Station elle-même, y
compris des logiciels et packages autres que le vôtre. Contrairement à toutes
les méthodes ci-dessus, les endpoints sous-jacents ne nécessitent **aucune
authentification** côté serveur ; `AppStationClient` attache quand même votre
en-tête produit `X-Software-Api-Key` à ces requêtes par cohérence, mais elle
n'est pas vérifiée. Rien à persister non plus ici : appelez ces méthodes
directement partout où vous affichez un écran « parcourir le marketplace »
dans votre application.

Chaque méthode de liste retourne un wrapper [`Paginated`](#paginatedtitem),
qui reprend la forme du paginateur Laravel lui-même (`items`, `currentPage`,
`lastPage`, `perPage`, `total`) — repassez `page` vous-même pour récupérer la
page suivante.

### `listSoftwares(array $filters = []): Paginated<Software>`

```php
$softwares = ApsConnect::listSoftwares(['category_id' => $category->id, 'featured' => true, 'q' => $search, 'sort' => 'recent']);
```

`$filters` reprend la requête de liste d'App Station elle-même : `category_id`,
`featured`, `q` (recherche libre), `sort` (`popular` (défaut), `recent`,
`rating`, ou `downloads`), et `page`.

### `getSoftware(string $slug): Software`

Lève `AppStationResourceNotFoundException` pour un slug de logiciel inconnu.

### `getSoftwareReleases(string $slug, int $page = 1): Paginated<SoftwareRelease>`

L'historique des versions publiées d'une fiche logicielle (réutilise le même
DTO [`SoftwareRelease`](#softwarerelease) que `checkForUpdate()`).

### `getSoftwarePackages(string $slug, int $page = 1): Paginated<Package>`

Les packages marketplace (add-ons/modules) publiés sous un logiciel donné.

### `listPackages(array $filters = []): Paginated<Package>`

```php
$packages = ApsConnect::listPackages(['software_id' => $software->id, 'page' => 1]);
```

### `getPackage(string $softwareSlug, string $slug): Package`

Lève `AppStationResourceNotFoundException` pour une paire slug logiciel/package
inconnue.

### `getPackageReleases(string $softwareSlug, string $slug, int $page = 1): Paginated<PackageRelease>`

L'historique des versions publiées d'un package.

### `listCategories(): Category[]`

Retourne l'arbre complet des catégories en un seul appel (catégories de
premier niveau avec leurs `children` imbriqués) — App Station ne pagine pas
cet endpoint, donc ceci retourne un tableau simple plutôt qu'un `Paginated`.

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
$results = ApsConnect::search('facture', type: 'all'); // un de : software | package | all

$results->softwares; // Software[]
$results->packages;  // Package[]
```

Contrairement aux méthodes de liste ci-dessus, App Station limite les
résultats de recherche à 10 par type et ne les pagine pas — `SearchResults`
contient des tableaux simples, pas des wrappers `Paginated`.

## Gestion des exceptions

Toutes les exceptions héritent de
`Neocode\ApsConnect\Exceptions\ApsConnectException`. Registra et App Station
ont chacun leur propre hiérarchie, indexée par code HTTP — **intercepter l'une
ne capture jamais accidentellement les échecs de l'autre**, puisqu'elles
s'authentifient contre des serveurs différents pour des raisons différentes.

### Exceptions Registra

Toutes héritent de `RegistraRequestException` (`status: int`, `body: array`).

| Exception | Code HTTP | Propriétés additionnelles | Levée quand |
|---|---|---|---|
| `InvalidApiKeyException` | 401 | — | La clé API configurée a été rejetée. |
| `LicenceInactiveException` | 403 | — | La licence existe mais n'est pas dans un état utilisable. |
| `LicenceNotFoundException` | 404 | — | Aucune licence ne correspond à la clé donnée. |
| `LicenceConflictException` | 409 | — | Ex. une licence déjà réclamée par un autre client/appareil. |
| `RegistraValidationException` | 422 | `errors: array<string, list<string>>` | Le payload de la requête a échoué la validation de Registra. |
| `RegistraUnavailableException` | 429 ou 503 | `retryAfter: ?int` | Rate-limité ou Registra est indisponible ; `retryAfter` reflète l'en-tête `Retry-After`, quand présent. |
| `RegistraRequestException` | tout autre code | — | Repli pour tout ce qui n'est pas mappé ci-dessus. |

### Exceptions App Station

Toutes héritent de `AppStationRequestException` (`status: int`, `body: array`)
— la même forme que `RegistraRequestException`, délibérément gardée séparée.

| Exception | Code HTTP | Propriétés additionnelles | Levée quand |
|---|---|---|---|
| `InvalidAppStationApiKeyException` | 401 | — | La clé produit (`registerSoftwareInstance()`) ou la clé d'instance (`downloadPackage()`/`checkForUpdate()`) a été rejetée ou révoquée. |
| `AppStationLicenceRejectedException` | 403 | — | La licence derrière l'instance/le téléchargement ne l'autorise pas (inactive, mauvais droit module...). |
| `PackageReleaseNotFoundException` | 404 | — | `downloadPackage()` a été appelée avec un `$packageReleaseId` inconnu. |
| `AppStationResourceNotFoundException` | 404 | — | Une recherche du [catalogue marketplace](#catalogue-marketplace-app-station) (`getSoftware()`, `getPackage()`, `getSoftwareReleases()`, `getSoftwarePackages()`, `getPackageReleases()`) a été appelée avec un slug inconnu. |
| `AppStationValidationException` | 422 | `errors: array<string, list<string>>` | Ex. un `moduleLicenceKey` requis était manquant. |
| `AppStationUnavailableException` | 429 ou 503 | `retryAfter: ?int` | Rate-limité ou App Station est indisponible. |
| `AppStationRequestException` | tout autre code | — | Repli pour tout ce qui n'est pas mappé ci-dessus. |

### Erreurs de résolution des credentials

`Neocode\ApsConnect\Exceptions\MissingCredentialsException` est levée par
`ProjectConfigReader` — avant même qu'une requête HTTP soit tentée — quand il
ne peut résoudre ni une clé API ni une URL de base du tout (voir les tableaux
d'ordre de résolution dans [Configuration](#configuration)). Son message
indique toujours précisément quelle valeur manque et comment la fournir.

## La commande `aps-connect:doctor`

```bash
php artisan aps-connect:doctor
```

Un diagnostic sûr, sans effet de bord :

1. Appelle `me()` pour confirmer que la clé API Registra configurée est
   acceptée, et affiche le nom et l'environnement du logiciel connecté.
2. Avertit si une ancienne clé API est encore valide pendant sa période de
   grâce de rotation.
3. Appelle `verifyLicence('APS-CONNECT-DOCTOR-PROBE')` — une clé garantie de ne
   pas exister — pour confirmer que l'endpoint de vérification lui-même
   répond correctement (`LicenceNotFoundException` est ici le résultat
   attendu et réussi, pas un échec).
4. Affiche l'URL de base App Station résolue.

Elle n'effectue **pas** de vérification réelle contre App Station :
`registerSoftwareInstance()` (le seul endpoint d'App Station authentifié avec
la clé produit) crée une vraie `SoftwareInstance` à chaque appel, donc —
contrairement à `verifyLicence()` de Registra — il n'y a pas d'équivalent sans
effet de bord à sonder avec une valeur jetable.

Le code de sortie est `0` en cas de succès, `1` si la clé API est rejetée à
l'une des étapes.

## Packaging & publication d'une release (App Station)

Une commande de développement, `aps-connect:release`, couvre la partie de la
publication d'une release qui se répète à chaque version : transformer le
projet en artefact publiable, puis le publier. Tout le reste — lier le
dépôt, s'authentifier, signer un manifeste de liaison, faire tourner les
clés — reste dans le
[CLI `aps`](https://www.npmjs.com/package/@app-station/cli) (`aps login`,
`aps init`, `aps sign`, `aps fetch-key`, ...) ; cette commande prend le
relais là où `aps init` s'arrête, en lisant le même `appstation.conf.json`
qu'il a écrit.

Lancée sans flag, elle demande d'abord quoi faire (empaqueter et publier,
empaqueter seulement, ou publier un fichier existant), puis pose une
question à la fois pour tout ce qu'il lui manque encore — version, channel,
plateforme, quel fichier, le token — uniquement pour les champs que vous
n'avez pas déjà passés en option. Passez `--pack`/`--publish` (l'un ou
l'autre, ou les deux) pour sauter directement cette première question ;
passez toutes les options nécessaires et elle tourne sans aucune question,
ce qu'on veut en CI (ajoutez `--no-interaction` là aussi, comme pour
n'importe quelle commande Artisan — chaque champ retombe alors sur sa
valeur par défaut ou échoue avec un message clair plutôt que de demander).

```bash
# entièrement interactif
php artisan aps-connect:release

# construire le zip seulement
php artisan aps-connect:release --pack [--out=] [--skip-npm] [--build-command=] [--no-obfuscate]

# publier un fichier existant seulement
php artisan aps-connect:release <file> --publish \
    --release-version=1.4.0 [--channel=stable] [--platform=universal] \
    [--notes=] [--min-software-version=] [--max-software-version=] \
    [--upload-name=] [--token=]

# construire puis publier en une fois (CI)
php artisan aps-connect:release --pack --publish --release-version=1.4.0 --token=$APS_TOKEN --no-interaction
```

### `--pack`

Construit un zip prêt à déployer du projet courant :

1. Copie le projet dans un répertoire temporaire — **jamais sur place** :
   lancer `composer install --no-dev` directement dans votre copie de
   travail supprimerait vos propres dépendances de dev. Exclut `.git`,
   `.github`, `.idea`, `.vscode`, `node_modules`, `tests`, `storage/logs`,
   `storage/framework/{cache,sessions,views}`, `.phpunit.cache`, `*.log`,
   `.DS_Store`, plus tout ce qu'ajoute un fichier `.apsignore` à la racine
   du projet (un motif glob par ligne — un motif sans `/` correspond à ce
   nom n'importe où dans l'arborescence ; un motif contenant `/` est ancré à
   la racine du projet). **`.env*` et `appstation.conf.local.json` sont
   toujours exclus, quel que soit `.apsignore`** — ils contiennent des
   secrets (votre clé API Registra de dev, le secret de signature `aps
   sign`) qui ne doivent jamais finir dans un artefact publié.
2. Lance `composer install --no-dev --optimize-autoloader --no-interaction`
   dans la copie mise en scène.
3. Si un `package.json` est présent et que `--skip-npm` n'a pas été passé :
   lance `npm ci && npm run build` (à surcharger avec `--build-command=`).
4. Obfusque le code PHP mis en scène (voir ci-dessous), sauf si
   `--no-obfuscate` est passé.
5. Zippe le résultat vers `--out=` (par défaut :
   `storage/app/aps-connect/releases/{slug}-{date}.zip`).

Ceci automatise uniquement un zip source web Laravel classique. Ça
n'orchestre **pas** un build natif [NativePHP](https://nativephp.com) — si
vous publiez un installeur NativePHP, construisez-le avec l'outillage propre
à NativePHP et passez directement le fichier obtenu à `--publish`
ci-dessous, exactement comme `aps release <file>` traite déjà n'importe
quel fichier de façon opaque.

### `--publish`

Publie n'importe quel fichier (typiquement le zip que `--pack` vient de
construire, mais un installeur NativePHP fonctionne tout aussi bien) vers
`POST /api/v1/publisher/{softwares,packages}/{id}/releases`, en reproduisant
la même validation que `aps release <file>` (semver, `--channel`
stable|beta|rc|nightly, `--platform`
windows|macos|linux|android|ios|web|cli|browser_extension|universal ;
`--min-software-version`/`--max-software-version` uniquement pour un
module). Combiné avec `--pack`, c'est le fichier que `--pack` vient de
produire qui est utilisé — l'argument `<file>` n'est lu que quand
`--publish` tourne sans `--pack`.

L'authentification utilise un **token de session éditeur**, pas la clé API
Registra runtime — passez `--token`, définissez la variable d'environnement
`APS_TOKEN`, ou lancez `aps login` (`aps-cli`) et exportez le token qu'il
affiche (en mode interactif, il est aussi demandé via un prompt masqué
`secret()`). Il n'y a pas de flow de connexion navigateur ici ; ça reste
dans `aps-cli`, puisque c'est une action ponctuelle par machine, pas
quelque chose qui vaut la peine d'être réimplémenté en PHP.

En cas de succès, affiche le checksum de la release et — une fois qu'App
Station l'a calculée — sa signature HMAC ; avertit si la signature n'est pas
encore disponible (App Station la calcule rétroactivement dès qu'un secret
de signature existe pour le logiciel).

### Obfuscation du code source : ce qui est protégé, et ce qui ne l'est pas

Par défaut, `--pack` fait passer chaque fichier `.php` mis en scène (sauf
`vendor/**`, jamais touché) dans un obfuscateur avant de zipper. Ça existe
parce qu'une vérification de licence qu'un client peut
simplement supprimer du code source —`ApsConnect::verifyLicence()`, la
vérification de signature de `checkForUpdate()` — n'est plus vraiment une
vérification de licence. C'est un **frein contre le bidouillage/l'édition
occasionnels, pas une vraie sécurité** :

- **Ce que ça fait :** supprime tous les commentaires et docblocks, et
  renomme les variables locales partout où c'est prouvablement sûr (un nom
  est laissé tel quel s'il est passé à `compact()`, déclaré `global`, capturé
  par un `use()` de closure, ou si la fonction contenante utilise
  `extract()`/`$$x` du tout).
- **Ce que ça ne touche jamais :** les noms de classes, méthodes,
  propriétés et namespaces. Laravel les résout en permanence via la
  réflexion et des chaînes magiques — le conteneur de services, Eloquent, le
  route model binding, les conventions `handle()` des jobs/listeners, les
  arguments nommés — et renommer automatiquement l'un d'eux risquerait de
  casser silencieusement votre appli chez un client, un résultat bien pire
  qu'une protection simplement faible.

Ça s'arrête volontairement avant le niveau de protection d'ionCube/Zend
Guard, qui nécessite une extension loader installée sur le serveur cible —
pas quelque chose qu'on peut supposer sur un hébergement quelconque côté
client. Si vous avez besoin d'une vraie protection contre un attaquant
motivé plutôt que d'un frein contre le bidouillage occasionnel, c'est un
investissement différent et séparé (un composant compilé pour uniquement la
vérification de licence, ou exiger ionCube sur des hébergements que vous
contrôlez) — `--no-obfuscate` existe pour que vous puissiez quand même
brancher votre propre étape avant `--publish` si vous
partez dans cette direction.

### Installer (ou mettre à jour) sur un serveur client

`--pack` exclut volontairement `.env*` du zip (c'est un secret), donc un
client qui l'extrait ne peut pas encore faire démarrer l'appli.
`aps-connect:install` termine le travail :

```bash
php artisan aps-connect:install
```

C'est aussi le chemin de **mise à jour** : relancez-la après avoir extrait
une nouvelle release par-dessus une installation existante (même `.env`) et
elle va directement à `migrate --force`/`storage:link` — pas besoin de
commande "update" séparée.

**Ça prend deux lancements la première fois**, et c'est voulu, pas un bug
à contourner : Laravel résout `config('database.connections.*')` depuis
`.env` au boot, avant même que cette commande ne tourne — donc écrire de
nouvelles valeurs `DB_*` dans `.env` en cours de route ne peut pas changer
rétroactivement la connexion DB que `migrate` utiliserait dans ce *même*
processus. Plutôt que de surcharger `config()`/`putenv()` à l'exécution
d'une façon facile à mal faire, la commande s'arrête simplement et demande
un second lancement une fois qu'un vrai redémarrage a pris en compte le
nouveau `.env` :

1. **Premier lancement** (`.env` manquant) — copie `.env.example` (ou
   écrit un template minimal s'il n'y en a pas), demande `APP_URL` et une
   connexion DB (`sqlite` par défaut — rien d'autre à demander ;
   `mysql`/`pgsql` demandent aussi host/port/database/username/password),
   les écrit dans `.env`, et s'arrête avec un message pour relancer la
   commande.
2. **Second lancement** (`.env` existe maintenant) — génère `APP_KEY` s'il
   est vide, lance `migrate --force`, puis `storage:link`, dans cet ordre.

Chaque champ peut être fourni en option plutôt que demandé (`--app-url=`,
`--db-connection=`, `--db-host=`, `--db-port=`, `--db-database=`,
`--db-username=`, `--db-password=`) — avec `--no-interaction`, un premier
déploiement scripté tient en une ligne :

```bash
php artisan aps-connect:install --no-interaction \
    --app-url=https://mon-logiciel.exemple.com \
    --db-connection=mysql --db-host=127.0.0.1 --db-database=app \
    --db-username=app --db-password="$DB_PASSWORD"
```

`--no-migrate`/`--no-storage-link` sautent ces deux étapes individuellement.

Deux events sont émis — `Neocode\ApsConnect\Events\ApsConnectInstalling` au
début de *chaque* lancement (y compris un lancement qui se contente
d'écrire `.env`), et `Neocode\ApsConnect\Events\ApsConnectInstalled` une
fois que migrations/storage:link ont vraiment tourné. C'est le point
d'extension pour les propres étapes post-install d'un logiciel (seed d'un
compte admin, réchauffement d'un cache...) — le package n'expose aucun
fichier de config pour déclarer ça, par conception, donc un listener dans
votre propre `EventServiceProvider` est la façon de s'y brancher :

```php
use Neocode\ApsConnect\Events\ApsConnectInstalled;

Event::listen(ApsConnectInstalled::class, function (): void {
    // ex. Artisan::call('app:seed-admin-account');
});
```

## Tester votre intégration

Aps Connect est conçu pour être testé avec `Http::fake()` — chaque exemple
ci-dessous reflète la propre suite de tests du package.

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

    // ... exercez votre propre classe de service ici ...
});
```

Pour des tests unitaires qui n'ont pas besoin du conteneur complet (ex. tester
`ProjectConfigReader` isolément), écrivez un `appstation.conf.json` temporaire
dans un répertoire isolé plutôt que dans le squelette Testbench partagé — voir
`tests/Unit/Support/ProjectConfigReaderTest.php` dans ce dépôt pour le motif
exact, y compris pourquoi ça compte sous exécution de tests en parallèle.

## Référence des Data Transfer Objects

Tous les DTOs sont des classes `final readonly` sous `Neocode\ApsConnect\Data`,
chacune avec un constructeur statique `fromArray()`. Les objets imbriqués
suivent la même convention.

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
`total: int` — retourné par chaque méthode de liste du catalogue marketplace.

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
Internes, résolus par `ProjectConfigReader` et injectés par le service
provider — vous n'aurez normalement pas à les construire vous-même en dehors
des tests. `RegistraCredentials` : `apiKey: string`, `baseUrl: string`,
`environment: string`. `AppStationCredentials` : `apiKey: string`,
`baseUrl: string`.

## Notes de conception & anti-patterns

- **N'appelez pas `registerSoftwareInstance()` à chaque requête ou à chaque
  démarrage de l'application sans `$clientReference` stable.** Chaque appel
  sans en fournir un — ou avec un nouveau généré à chaque fois — crée une
  toute nouvelle instance App Station et une nouvelle clé API, orphelinant
  les précédentes côté serveur. Enregistrez une fois, persistez le résultat,
  rejouez-le.
- **N'attendez pas de la façade runtime (`ApsConnect::...`) qu'elle persiste
  quoi que ce soit pour vous.** Aucune migration, aucun modèle, aucun cache.
  Si vous devez retrouver « quelle instance App Station appartient à ce
  tenant », cette recherche vit dans le modèle de données de votre propre
  application. `aps-connect:release --pack` est l'unique exception délibérée —
  elle écrit un zip sur disque par conception ; voir
  [Packaging & publication d'une release](#packaging--publication-dune-release-app-station).
- **N'essayez pas de définir `api.baseUrl` via la config Laravel ou une
  variable d'env.** Elle ne vient toujours que de `appstation.conf.json` — il
  n'y a aucun repli config/env, par design.
- **N'interceptez pas `RegistraValidationException` en espérant capturer un
  échec App Station, ou l'inverse.** Les deux hiérarchies sont délibérément
  séparées.
- **Ne sautez pas la vérification de signature** sur le champ `signature` de
  `checkForUpdate()` quand App Station en fournit une — elle existe
  précisément pour que vous puissiez détecter un package de mise à jour
  altéré ou mal livré avant de l'installer.

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
