<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Services\Reports\SalesReport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, SalesReport $salesReport): InertiaResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branches = collect($request->attributes->get('accessibleBranches', []));
        $branchIds = $this->selectedBranchIds((string) $request->validated('branch_id'), $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $report = $salesReport->handle(
            (int) $organization->getKey(),
            $branchIds,
            (string) $request->validated('from_date'),
            (string) $request->validated('to_date'),
        );

        // The report page must remain usable if a deployment has not yet applied
        // the queued-export migration. The queue section is disabled in that case.
        $reportExportQueueAvailable = Schema::hasTable('report_exports');
        if (! $reportExportQueueAvailable) {
            Log::warning('Report export queue table is missing; showing sales report without export history.', [
                'organization_id' => (int) $organization->getKey(),
                'user_id' => (int) $request->user()->getKey(),
            ]);
        }

        $reportExports = $reportExportQueueAvailable
            ? ReportExport::query()
                ->forOrganization((int) $organization->getKey())
                ->where('requested_by', $request->user()->getKey())
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(static fn (ReportExport $export): array => [
                    'id' => (int) $export->getKey(),
                    'status' => $export->status,
                    'fromDate' => $export->from_date->format('Y-m-d'),
                    'toDate' => $export->to_date->format('Y-m-d'),
                    'requestedAt' => $export->created_at?->toDateTimeString(),
                    'completedAt' => $export->completed_at?->toDateTimeString(),
                    'failureMessage' => $export->status === 'failed' ? $export->failure_message : null,
                    'downloadUrl' => $export->status === 'completed'
                        ? route('reports.exports.download', $export)
                        : null,
                ])->values()->all()
            : [];

        return Inertia::render('Reports/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'branches' => $branches->map(static fn ($branch): array => [
                'id' => (int) $branch->getKey(), 'name' => $branch->name, 'code' => $branch->code,
            ])->values(),
            'filters' => [
                'fromDate' => (string) $request->validated('from_date'),
                'toDate' => (string) $request->validated('to_date'),
                'branchId' => (string) $request->validated('branch_id'),
            ],
            'selectedBranchLabel' => $branches->isEmpty()
                ? 'لا توجد فروع متاحة'
                : ((string) $request->validated('branch_id') === 'all'
                    ? 'كل الفروع المتاحة'
                    : ($branches->firstWhere('id', (int) $request->validated('branch_id'))?->name ?? 'الفرع الحالي')),
            ...$report,
            'canManageExpenses' => $request->user()->can('manageExpenses', $organization),
            'reportExports' => $reportExports,
            'reportExportQueueAvailable' => $reportExportQueueAvailable,
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function export(ReportFilterRequest $request, SalesReport $salesReport): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branches = collect($request->attributes->get('accessibleBranches', []));
        $branchIds = $this->selectedBranchIds((string) $request->validated('branch_id'), $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        abort_if($branchIds === [], 404);

        $fromDate = (string) $request->validated('from_date');
        $toDate = (string) $request->validated('to_date');
        $report = $salesReport->handle((int) $organization->getKey(), $branchIds, $fromDate, $toDate);
        $filename = sprintf('metrik-pos-report-%s-%s.csv', $fromDate, $toDate);

        return response()->streamDownload(static function () use ($report): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            // UTF-8 BOM improves Arabic CSV compatibility with Microsoft Excel.
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['التاريخ', 'عدد الفواتير', 'المبيعات قبل المرتجعات', 'المرتجعات', 'صافي المبيعات', 'تكلفة البضاعة بعد المرتجعات المعادة للمخزون', 'مجمل الربح التقديري', 'المصروفات', 'صافي التشغيل التقديري']);
            foreach ($report['daily'] as $row) {
                fputcsv($stream, [
                    $row['date'], $row['invoices'], $row['sales'], $row['returns'], $row['netSales'], $row['costOfGoods'],
                    $row['grossProfit'], $row['expenses'], $row['netProfit'],
                ]);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param list<int> $accessibleIds @return list<int> */
    private function selectedBranchIds(string $selection, array $accessibleIds): array
    {
        if ($selection === 'all') {
            return array_values(array_unique($accessibleIds));
        }

        $selectedId = (int) $selection;

        return in_array($selectedId, $accessibleIds, true) ? [$selectedId] : [];
    }
}
