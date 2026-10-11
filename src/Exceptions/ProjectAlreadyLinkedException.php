<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

/**
 * App Station answered 409 to a link request: the software/module is already
 * linked to another local repository (a different project fingerprint).
 * Retrying with `force` re-links it to this one.
 */
final class ProjectAlreadyLinkedException extends PublisherRequestException {}
