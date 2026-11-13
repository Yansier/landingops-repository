<?php

declare(strict_types=1);

namespace LandingOps\Template;

use LandingOps\Zip\InspectedArchive;
use stdClass;

final class ValidatedTemplate
{
    public function __construct(
        public readonly InspectedArchive $archive,
        public readonly stdClass $manifest,
        public readonly stdClass $configSchema,
    ) {
    }
}
