<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

/**
 * Which App Station software/module this project is linked to, per
 * appstation.conf.json (`type` + `appstation.softwareId`/`packageId`) —
 * resolved by ProjectConfigReader::projectIdentity() and consumed by the
 * release:pack/release:publish commands to target the right publisher API
 * endpoint.
 */
final readonly class ProjectIdentity
{
    public function __construct(
        public string $type,
        public int $id,
        public string $name,
    ) {}

    public function isModule(): bool
    {
        return $this->type === 'module';
    }
}
