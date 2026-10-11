<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\ApsCliSession;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/aps-connect-cli-session-'.bin2hex(random_bytes(8)).'.json';
});

afterEach(function () {
    @unlink($this->path);
});

it('reads the token and base url aps login stored', function () {
    file_put_contents($this->path, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test/', 'accessToken' => ' a-token '],
    ]));

    $credentials = (new ApsCliSession($this->path))->credentials();

    expect($credentials?->token)->toBe('a-token')
        ->and($credentials?->baseUrl)->toBe('https://app-station.test');
});

it('returns null when there is no session file', function () {
    expect((new ApsCliSession($this->path))->credentials())->toBeNull();
});

it('returns null for a file that is not a usable session', function (string $contents) {
    file_put_contents($this->path, $contents);

    expect((new ApsCliSession($this->path))->credentials())->toBeNull();
})->with([
    'invalid json' => ['{not json'],
    'no appstation key' => ['{"other": {}}'],
    'missing token' => ['{"appstation": {"baseUrl": "https://app-station.test"}}'],
    'empty base url' => ['{"appstation": {"baseUrl": "", "accessToken": "a-token"}}'],
]);
