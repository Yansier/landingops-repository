<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

/** Mirrors the server wrapper's deliberately tiny authorization decision. */
final class ForcedCommandPolicy
{
    public function authorize(?string $originalCommand, bool $hasTty = false): bool
    {
        return !$hasTty
            && is_string($originalCommand)
            && hash_equals(OpenSshReadOnlyTransport::REMOTE_COMMAND, $originalCommand);
    }
}
