<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

interface BaotaReadOnlyGateway
{
    /** @return array<string,mixed> */
    public function systemSummary(string $baseUrl, string $apiKey): array;
    /** @return list<array<string,mixed>> */
    public function sites(string $baseUrl, string $apiKey): array;
    /** @return array<string,mixed> */
    public function siteDomains(string $baseUrl, string $apiKey, int $siteId): array;
    /** @return array<string,mixed> */
    public function phpVersions(string $baseUrl, string $apiKey): array;
    /** @return array<string,mixed> */
    public function sitePhpVersion(string $baseUrl, string $apiKey, string $siteName): array;
    /** @return array{status:string,path:string,entry_count:?int,has_unknown_entries:?bool,is_symlink:?bool} */
    public function directoryObservation(string $normalizedPath): array;
}
