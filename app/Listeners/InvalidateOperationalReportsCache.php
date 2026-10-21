<?php

namespace App\Listeners;

use App\Events\OperationalReportDataChanged;
use App\Services\Reports\ReportCacheVersion;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvalidateOperationalReportsCache
{
    public function handle(OperationalReportDataChanged $event): void
    {
        try {
            app(ReportCacheVersion::class)->invalidate($event->organizationId);
        } catch (Throwable $exception) {
            // Cache invalidation must never make an already-committed sale appear to fail.
            Log::warning('Metrik POS report cache invalidation failed.', [
                'organization_id' => $event->organizationId,
                'exception' => $exception::class,
            ]);
        }
    }
}
