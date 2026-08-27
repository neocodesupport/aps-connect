<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registra API keys
    |--------------------------------------------------------------------------
    |
    | The X-Software-Api-Key sent with every Registra request. Leave unset to
    | fall back to appstation.conf.local.json's `auth.apiKey`. Which of the
    | two keys below is used depends on the environment declared in
    | appstation.conf.json — never on APP_ENV or any other ambient env var.
    |
    | The api key is allowed here as a config/env override because
    | appstation.conf.local.json is gitignored: it simply does not exist in
    | most CI/CD pipelines or fresh deployments, so an env var is the only
    | way to deliver the key there. A wrong/forged key is also simply
    | rejected by Registra — there is nothing to gain by overriding it.
    |
    | The dev/production environment itself is different: it comes ONLY from
    | appstation.conf.json (see ProjectConfigReader), never from an env var
    | anyone deploying the licensed application could edit in their own
    | .env — in "development", licence verification and subscription calls
    | are transparently routed to Registra's /sandbox/... endpoints, which
    | accept reserved dev licence keys without checking real data.
    |
    */

    'api_key' => env('REGISTRA_API_KEY'),

    'dev_api_key' => env('REGISTRA_DEV_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Registra / App Station base URLs
    |--------------------------------------------------------------------------
    |
    | Fallbacks used when appstation.conf.json has no `api.baseUrl` /
    | `appstation.baseUrl` — see ProjectConfigReader::credentials() and
    | ::appStationCredentials(). An explicit value in appstation.conf.json
    | always wins over these: they are read-once-and-forget defaults, not
    | overrides, so a deployer can never use them to redirect a call away
    | from a base url the publisher versioned in appstation.conf.json.
    |
    | Both are plain literals, deliberately NOT wrapped in env(): they point
    | at the one real, shared production instance of each service, so there
    | is nothing to gain from letting a deployer's .env redirect them —
    | unlike the api key above, which genuinely differs per installation.
    |
    */

    'registra_base_url' => 'https://registra.neocode.ci/api',

    'appstation_base_url' => 'https://app-station.neocode.ci',

];
