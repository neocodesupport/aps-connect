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
    | Unlike the base url and environment (which come ONLY from
    | appstation.conf.json — see ProjectConfigReader), the api key is
    | allowed here as a config/env override because appstation.conf.local.json
    | is gitignored: it simply does not exist in most CI/CD pipelines or
    | fresh deployments, so an env var is the only way to deliver the key
    | there. A wrong/forged key is also simply rejected by Registra — there
    | is nothing to gain by overriding it, unlike the base url.
    |
    | appstation.conf.json (written by `aps init`, flipped by `aps promote`)
    | is the ONLY source for the dev/production environment and the base
    | url: it is version controlled and only changes when the publisher
    | deliberately promotes the software, unlike an env var anyone deploying
    | the licensed application could edit in their own .env. In
    | "development", licence verification and subscription calls are
    | transparently routed to Registra's /sandbox/... endpoints, which
    | accept reserved dev licence keys without checking real data — so
    | neither is configurable here. No appstation.conf.json means
    | "production" against Registra's real production URL.
    |
    */

    'api_key' => env('REGISTRA_API_KEY'),

    'dev_api_key' => env('REGISTRA_DEV_API_KEY'),

];
