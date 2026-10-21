<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Notifications\ReportExportReady;
use App\Services\Reports\SalesReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateSalesReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30];

    public function __construct(public readonly int $reportExportId)
    {
        $this->onQueue('reports');
    }

    public function handle(SalesReport $salesReport): void
    {
        $export = ReportExport::query()->with('requester')->findOrFail($this->reportExportId);

        // A retry after a successful write must not create a duplicate notification.
        if ($export->status === 'completed' && $export->file_path && Storage::disk('local')->exists($export->file_path)) {
            return;
        }

        $export->forceFill([
            'status' => 'processing',
            'started_at' => now(),
            'failure_message' => null,
            'attempts' => $export->attempts + 1,
        ])->save();

        $result = $salesReport->handle(
            (int) $export->organization_id,
            array_map('intval', $export->branch_ids ?? []),
            $export->from_date->format('Y-m-d'),
            $export->to_date->format('Y-m-d'),
        );

        $csv = $this->toCsv($result);
        $path = sprintf(
            'report-exports/org-%d/report-%d.csv',
            $export->organization_id,
            $export->getKey(),
        );

        if (! Storage::disk('local')->put($path, $csv)) {
            throw new RuntimeException('Unable to save the generated report export.');
        }

        $export->forceFill([
            'status' => 'completed',
            'file_path' => $path,
            'failure_message' => null,
            'completed_at' => now(),
        ])->save();

        if ($export->requester) {
            try {
                $export->requester->notify(new ReportExportReady(
                    (int) $export->getKey(),
                    route('reports.exports.download', $export),
                    $export->from_date->format('Y-m-d'),
                    $export->to_date->format('Y-m-d'),
                ));
            } catch (Throwable $exception) {
                // Notification failure must not turn a successfully created file into a failed export.
                Log::warning('Metrik POS report export notification failed.', [
                    'report_export_id' => $export->getKey(),
                    'organization_id' => $export->organization_id,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $export = ReportExport::query()->find($this->reportExportId);

        if ($export) {
            $export->forceFill([
                'status' => 'failed',
                'failure_message' => 'تعذر إنشاء ملف التقرير. راجع السجل أو حاول إنشاء تصدير جديد.',
            ])->save();
        }

        Log::error('Metrik POS queued report export failed.', [
            'report_export_id' => $this->reportExportId,
            'organization_id' => $export?->organization_id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /** @param array<string, mixed> $report */
    private function toCsv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory CSV stream.');
        }

        // BOM improves Arabic compatibility with Microsoft Excel.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['التاريخ', 'عدد الفواتير', 'المبيعات', 'تكلفة البضاعة', 'مجمل الربح التقديري', 'المصروفات', 'صافي التشغيل التقديري']);

        foreach (($report['daily'] ?? []) as $row) {
            fputcsv($stream, [
                $row['date'] ?? '', $row['invoices'] ?? 0, $row['sales'] ?? '0.00',
                $row['costOfGoods'] ?? '0.00', $row['grossProfit'] ?? '0.00',
                $row['expenses'] ?? '0.00', $row['netProfit'] ?? '0.00',
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the generated CSV stream.');
        }

        return $contents;
    }
}
