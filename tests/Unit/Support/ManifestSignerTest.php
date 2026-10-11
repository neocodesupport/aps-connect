<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\ManifestSigner;

/**
 * Signed by the real `aps sign` (aps-cli) with the secret "s3cr3t/é": accents,
 * slashes, quotes and non-ASCII names must canonicalize byte-for-byte alike.
 */
function apsConnectManifestFromApsCli(): array
{
    return [
        'payload' => [
            'schema' => 'appstation.manifest.v1',
            'registra' => [
                'baseUrl' => 'https://registra-dev.test/api',
                'environment' => 'development',
                'apiKeyFingerprint' => '3605a9e4358da4302f8acea41f0f52cef85d0e3f727c7b020fc7305aec8d56b4',
            ],
            'project' => ['fingerprint' => 'fp-xyz', 'stack' => 'php'],
            'generatedAt' => '2026-10-11T19:24:17.086Z',
            'module' => ['token' => 'mod-tok', 'slug' => 'factures', 'name' => "Café / Facturation l'été"],
            'parentSoftware' => ['token' => 'sw-tok', 'name' => 'Boutique "Pro" ünicode ✓'],
        ],
        'signature' => '31DZmjd0tTdZYVz+VAdN8p415MIl1gFjhhzt9GgPiRg=',
    ];
}

function apsConnectManifestProjectConfig(): array
{
    return [
        'type' => 'module',
        'environment' => 'development',
        'module' => ['token' => 'mod-tok', 'slug' => 'factures', 'name' => "Café / Facturation l'été"],
        'parentSoftware' => ['token' => 'sw-tok', 'name' => 'Boutique "Pro" ünicode ✓'],
        'api' => ['baseUrl' => 'https://registra-dev.test/api'],
        'integration' => ['detectedStack' => 'php'],
        'appstation' => ['packageId' => 12, 'projectFingerprint' => 'fp-xyz'],
    ];
}

beforeEach(function () {
    $this->signer = new ManifestSigner;
    $this->signedAt = new DateTimeImmutable('2026-10-11T19:24:17.086Z');
});

it('verifies a manifest signed by aps-cli', function () {
    $manifest = apsConnectManifestFromApsCli();

    expect($this->signer->verify($manifest['payload'], $manifest['signature'], 's3cr3t/é', $this->signedAt->modify('+1 day')))->toBe([]);
});

it('signs exactly like aps-cli', function () {
    $payload = $this->signer->buildPayload(apsConnectManifestProjectConfig(), 'k-123', $this->signedAt);

    expect($this->signer->sign($payload, 's3cr3t/é'))->toBe(apsConnectManifestFromApsCli());
});

it('builds a software payload carrying the api key fingerprint only', function () {
    $payload = $this->signer->buildPayload([
        'type' => 'software',
        'environment' => 'production',
        'software' => ['token' => 'sw-tok', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra.test/api'],
        'integration' => ['detectedStack' => null],
        'appstation' => ['softwareId' => 7, 'projectFingerprint' => 'fp'],
    ], 'secret-api-key', new DateTimeImmutable('2026-01-02 03:04:05.6', new DateTimeZone('Africa/Abidjan')));

    expect($payload)->toBe([
        'schema' => 'appstation.manifest.v1',
        'registra' => ['baseUrl' => 'https://registra.test/api', 'environment' => 'production', 'apiKeyFingerprint' => hash('sha256', 'secret-api-key')],
        'project' => ['fingerprint' => 'fp', 'stack' => null],
        'generatedAt' => '2026-01-02T03:04:05.600Z',
        'software' => ['token' => 'sw-tok', 'name' => 'My App'],
    ])->and(json_encode($payload))->not->toContain('secret-api-key');
});

it('canonicalizes with sorted keys, no whitespace, no signature', function () {
    expect($this->signer->canonicalJson(['signature' => 'x', 'b' => ['z' => 1, 'a' => 'é/'], 'a' => [3, 1]]))
        ->toBe('{"a":[3,1],"b":{"a":"é/","z":1}}');
});

it('rejects a tampered payload, a wrong secret, a wrong schema', function () {
    $manifest = apsConnectManifestFromApsCli();
    $now = $this->signedAt->modify('+1 day');

    $tampered = $manifest['payload'];
    $tampered['project']['fingerprint'] = 'fp-other';

    expect($this->signer->verify($tampered, $manifest['signature'], 's3cr3t/é', $now))->toBe(['Signature HMAC-SHA256 invalide.'])
        ->and($this->signer->verify($manifest['payload'], $manifest['signature'], 'other', $now))->toBe(['Signature HMAC-SHA256 invalide.']);

    $otherSchema = [...$manifest['payload'], 'schema' => 'v0'];
    $signed = $this->signer->sign($otherSchema, 's');

    expect($this->signer->verify($signed['payload'], $signed['signature'], 's', $now))
        ->toBe(['Schéma inattendu : "v0" (attendu "appstation.manifest.v1").']);
});

it('rejects a manifest older than 30 days, from the future, or without a valid date', function () {
    $signed = $this->signer->sign($this->signer->buildPayload(apsConnectManifestProjectConfig(), 'k', $this->signedAt), 's');

    expect($this->signer->verify($signed['payload'], $signed['signature'], 's', $this->signedAt->modify('+31 days')))
        ->toBe(['Manifest trop ancien : généré il y a 31 jours (max 30).'])
        ->and($this->signer->verify($signed['payload'], $signed['signature'], 's', $this->signedAt->modify('-1 hour')))
        ->toBe(['"generatedAt" est dans le futur.']);

    $undated = $this->signer->sign([...$signed['payload'], 'generatedAt' => 'yesterday-ish'], 's');

    expect($this->signer->verify($undated['payload'], $undated['signature'], 's', $this->signedAt))
        ->toBe(['"generatedAt" invalide : "yesterday-ish".']);
});
