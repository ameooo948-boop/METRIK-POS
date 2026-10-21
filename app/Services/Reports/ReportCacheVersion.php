<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Cache;

class ReportCacheVersion
{
    public function version(int $organizationId): int
    {
        $key = $this->versionKey($organizationId);
        Cache::add($key, 1, 31_536_000);

        return (int) Cache::get($key, 1);
    }

    public function invalidate(int $organizationId): void
    {
        $key = $this->versionKey($organizationId);
        Cache::add($key, 1, 31_536_000);
        Cache::increment($key);
    }

    public function cacheKey(int $organizationId, array $branchIds, string $fromDate, string $toDate): string
    {
        sort($branchIds, SORT_NUMERIC);
        $scope = hash('sha256', implode(',', $branchIds));

        return sprintf(
            'metrik-pos:reports:org:%d:v:%d:branches:%s:%s:%s',
            $organizationId,
            $this->version($organizationId),
            $scope,
            $fromDate,
            $toDate,
        );
    }

    private function versionKey(int $organizationId): string
    {
        return 'metrik-pos:reports:org:'.$organizationId.':version';
    }
}
