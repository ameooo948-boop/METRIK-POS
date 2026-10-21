<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\Organization;
use App\Services\Reports\SalesReport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, SalesReport $salesReport): InertiaResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branches = collect($request->attributes->get('accessibleBranches', []));
        $branchIds = $this->selectedBranchIds((string) $request->validated('branch_id'), $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        abort_if($branchIds === [], 404);

        $report = $salesReport->handle(
            (int) $organization->getKey(),
            $branchIds,
            (string) $request->validated('from_date'),
            (string) $request->validated('to_date'),
        );

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
            'selectedBranchLabel' => (string) $request->validated('branch_id') === 'all'
                ? 'كل الفروع المتاحة'
                : ($branches->firstWhere('id', (int) $request->validated('branch_id'))?->name ?? 'الفرع الحالي'),
            ...$report,
            'canManageExpenses' => $request->user()->can('manageExpenses', $organization),
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
            fputcsv($stream, ['التاريخ', 'عدد الفواتير', 'المبيعات', 'تكلفة البضاعة', 'مجمل الربح التقديري', 'المصروفات', 'صافي التشغيل التقديري']);
            foreach ($report['daily'] as $row) {
                fputcsv($stream, [
                    $row['date'], $row['invoices'], $row['sales'], $row['costOfGoods'],
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
