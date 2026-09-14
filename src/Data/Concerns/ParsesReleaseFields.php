<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data\Concerns;

use Carbon\CarbonImmutable;

/**
 * Shared by SoftwareRelease and PackageRelease, which carry the same core
 * release fields — PackageRelease just adds min/max software version.
 */
trait ParsesReleaseFields
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{id: int, version: string, platform: ?string, channel: ?string, releaseNotes: ?string, checksum: string, signature: ?string, fileSize: ?int, isYanked: bool, publishedAt: ?CarbonImmutable}
     */
    private static function parseReleaseFields(array $data): array
    {
        return [
            'id' => (int) $data['id'],
            'version' => (string) $data['version'],
            'platform' => $data['platform'] ?? null,
            'channel' => $data['channel'] ?? null,
            'releaseNotes' => $data['release_notes'] ?? null,
            'checksum' => (string) $data['checksum'],
            'signature' => $data['signature'] ?? null,
            'fileSize' => isset($data['file_size']) ? (int) $data['file_size'] : null,
            'isYanked' => (bool) ($data['is_yanked'] ?? false),
            'publishedAt' => isset($data['published_at']) ? CarbonImmutable::parse($data['published_at']) : null,
        ];
    }
}
