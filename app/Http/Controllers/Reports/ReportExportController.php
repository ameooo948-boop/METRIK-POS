<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Jobs\GenerateSalesReportExport;
use App\Models\Organization;
use App\Models\ReportExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function store(ReportFilterRequest $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branches = collect($request->attributes->get('accessibleBranches', []));
        $accessibleIds = $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $selection = (string) $request->validated('branch_id');
        $branchIds = $selection === 'all'
            ? array_values(array_unique($accessibleIds))
            : (in_array((int) $selection, $accessibleIds, true) ? [(int) $selection] : []);

        abort_if($branchIds === [], 404);

        $export = ReportExport::query()->create([
            'organization_id' => $organization->getKey(),
            'requested_by' => $request->user()->getKey(),
            'branch_ids' => $branchIds,
            'from_date' => $request->validated('from_date'),
            'to_date' => $request->validated('to_date'),
            'status' => 'queued',
        ]);

        GenerateSalesReportExport::dispatch((int) $export->getKey());

        return redirect()->route('reports.index', [
            'from_date' => $request->validated('from_date'),
            'to_date' => $request->validated('to_date'),
            'branch_id' => $selection,
        ])->with('success', 'تم إرسال التصدير للمعالجة. راجع حالة الملف في قسم التصديرات.');
    }

    public function download(Request $request, ReportExport $reportExport): StreamedResponse|RedirectResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('currentOrganization');
        abort_if(! $organization, 404);
        abort_unless($request->user()->can('viewReports', $organization), 403);

        // Exports are private to their creator as well as scoped to the active organization.
        abort_unless(
            (int) $reportExport->organization_id === (int) $organization->getKey()
            && (int) $reportExport->requested_by === (int) $request->user()->getKey(),
            404,
        );

        if (in_array($reportExport->status, ['queued', 'processing'], true)) {
            return redirect()->route('reports.index')->with('success', 'التقرير ما زال قيد المعالجة. حدّث الصفحة بعد قليل.');
        }

        abort_unless($reportExport->status === 'completed' && is_string($reportExport->file_path), 409);

        if (! Storage::disk('local')->exists($reportExport->file_path)) {
            Log::warning('Metrik POS completed report export file was missing.', [
                'report_export_id' => $reportExport->getKey(),
                'organization_id' => $organization->getKey(),
            ]);
            abort(404);
        }

        $filename = sprintf(
            'metrik-pos-report-%s-%s.csv',
            $reportExport->from_date->format('Y-m-d'),
            $reportExport->to_date->format('Y-m-d'),
        );

        return Storage::disk('local')->download($reportExport->file_path, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
