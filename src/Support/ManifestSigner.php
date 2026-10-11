<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Builds, signs and verifies the repo-linkage manifest exactly like aps-cli's
 * src/lib/manifest.ts, so a manifest signed by either tool verifies with the
 * other (and on App Station's side): HMAC-SHA256, base64, over the canonical
 * JSON of the payload — keys sorted recursively, no whitespace, top-level
 * `signature` removed — matching `JSON.stringify(sortKeysDeep(...))`.
 */
final class ManifestSigner
{
    public const string SCHEMA = 'appstation.manifest.v1';

    public const int DEFAULT_MAX_AGE_DAYS = 30;

    /**
     * The manifest payload for a project: its appstation.conf.json identity,
     * and the api key's SHA-256 only — never the key itself.
     *
     * @param  array<string, mixed>  $projectConfig
     * @return array<string, mixed>
     */
    public function buildPayload(array $projectConfig, string $apiKey, DateTimeImmutable $now): array
    {
        $api = is_array($projectConfig['api'] ?? null) ? $projectConfig['api'] : [];
        $appStation = is_array($projectConfig['appstation'] ?? null) ? $projectConfig['appstation'] : [];
        $integration = is_array($projectConfig['integration'] ?? null) ? $projectConfig['integration'] : [];

        $payload = [
            'schema' => self::SCHEMA,
            'registra' => [
                'baseUrl' => $api['baseUrl'] ?? null,
                'environment' => $projectConfig['environment'] ?? null,
                'apiKeyFingerprint' => hash('sha256', $apiKey),
            ],
            'project' => [
                'fingerprint' => $appStation['projectFingerprint'] ?? null,
                'stack' => $integration['detectedStack'] ?? null,
            ],
            // Date.prototype.toISOString(): UTC, milliseconds, "Z".
            'generatedAt' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];

        if (($projectConfig['type'] ?? null) === 'module') {
            return [...$payload, 'module' => $projectConfig['module'] ?? null, 'parentSoftware' => $projectConfig['parentSoftware'] ?? null];
        }

        return [...$payload, 'software' => $projectConfig['software'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, signature: string}
     */
    public function sign(array $payload, string $signingSecret): array
    {
        return ['payload' => $payload, 'signature' => $this->signature($payload, $signingSecret)];
    }

    /**
     * Schema, HMAC signature and freshness of `generatedAt`. Returns the list
     * of errors — empty when the manifest is valid.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function verify(array $payload, string $signature, string $signingSecret, DateTimeImmutable $now, int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS): array
    {
        $errors = [];
        $schema = $payload['schema'] ?? null;

        if ($schema !== self::SCHEMA) {
            $errors[] = 'Schéma inattendu : "'.(is_scalar($schema) ? $schema : '?').'" (attendu "'.self::SCHEMA.'").';
        }

        if (! hash_equals($this->signature($payload, $signingSecret), $signature)) {
            $errors[] = 'Signature HMAC-SHA256 invalide.';
        }

        $rawGeneratedAt = $payload['generatedAt'] ?? null;
        $generatedAt = is_string($rawGeneratedAt) ? $this->parseIsoDate($rawGeneratedAt) : null;

        if ($generatedAt === null) {
            $errors[] = '"generatedAt" invalide : "'.(is_scalar($rawGeneratedAt) ? $rawGeneratedAt : '?').'".';
        } else {
            $ageDays = ($now->getTimestamp() - $generatedAt->getTimestamp()) / 86400;

            if ($ageDays > $maxAgeDays) {
                $errors[] = 'Manifest trop ancien : généré il y a '.(int) floor($ageDays)." jours (max {$maxAgeDays}).";
            } elseif ($ageDays < 0) {
                $errors[] = '"generatedAt" est dans le futur.';
            }
        }

        return $errors;
    }

    /**
     * `JSON.stringify(sortKeysDeep(stripSignature(value)))`.
     *
     * @param  array<string, mixed>  $value
     */
    public function canonicalJson(array $value): string
    {
        unset($value['signature']);

        return json_encode(
            $this->sortKeysDeep($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signature(array $payload, string $signingSecret): string
    {
        return base64_encode(hash_hmac('sha256', $this->canonicalJson($payload), $signingSecret, true));
    }

    private function sortKeysDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->sortKeysDeep(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->sortKeysDeep(...), $value);
    }

    private function parseIsoDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
