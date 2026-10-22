<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPerformanceController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404, 'لا يوجد فرع محدد.');
        $filters = $this->filters($request);
        $orgId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();

        $rows = $this->baseQuery($orgId, $branchId, $filters)
            ->selectRaw('suppliers.id as supplier_id, suppliers.name as supplier_name, suppliers.code as supplier_code, suppliers.phone as supplier_phone')
            ->selectRaw('COUNT(purchase_invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(purchase_invoices.total), 0) as total_purchased')
            ->selectRaw('COALESCE(SUM(purchase_invoices.returned_amount), 0) as total_returned')
            ->selectRaw('COALESCE(SUM(purchase_invoices.paid_amount), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(purchase_invoices.due_amount), 0) as total_due')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.code', 'suppliers.phone')
            ->orderByDesc('total_purchased')->paginate(20)->withQueryString();
        $rows->through(fn ($row) => $this->presentRow($row));

        $totals = $this->baseQuery($orgId, $branchId, $filters)
            ->selectRaw('COUNT(purchase_invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(purchase_invoices.total), 0) as total_purchased')
            ->selectRaw('COALESCE(SUM(purchase_invoices.returned_amount), 0) as total_returned')
            ->selectRaw('COALESCE(SUM(purchase_invoices.paid_amount), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(purchase_invoices.due_amount), 0) as total_due')->first();

        return Inertia::render('Suppliers/Performance', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'suppliers' => $rows,
            'filters' => $filters,
            'summary' => [
                'invoiceCount' => (int) ($totals->invoice_count ?? 0),
                'totalPurchased' => number_format((float) ($totals->total_purchased ?? 0), 2, '.', ''),
                'totalReturned' => number_format((float) ($totals->total_returned ?? 0), 2, '.', ''),
                'totalPaid' => number_format((float) ($totals->total_paid ?? 0), 2, '.', ''),
                'totalDue' => number_format((float) ($totals->total_due ?? 0), 2, '.', ''),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404, 'لا يوجد فرع محدد.');
        $filters = $this->filters($request);
        $rows = $this->baseQuery((int) $organization->getKey(), (int) $branch->getKey(), $filters)
            ->selectRaw('suppliers.id as supplier_id, suppliers.name as supplier_name, suppliers.code as supplier_code, suppliers.phone as supplier_phone')
            ->selectRaw('COUNT(purchase_invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(purchase_invoices.total), 0) as total_purchased')
            ->selectRaw('COALESCE(SUM(purchase_invoices.returned_amount), 0) as total_returned')
            ->selectRaw('COALESCE(SUM(purchase_invoices.paid_amount), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(purchase_invoices.due_amount), 0) as total_due')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.code', 'suppliers.phone')->orderBy('suppliers.name');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['supplier_id', 'supplier_name', 'supplier_code', 'phone', 'invoice_count', 'total_purchased', 'total_returned', 'total_paid', 'total_due']);
            foreach ($rows->cursor() as $row) {
                fputcsv($out, [$row->supplier_id, $row->supplier_name, $row->supplier_code, $row->supplier_phone, $row->invoice_count, $row->total_purchased, $row->total_returned, $row->total_paid, $row->total_due]);
            }
            fclose($out);
        }, 'supplier-performance.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function baseQuery(int $organizationId, int $branchId, array $filters): Builder
    {
        return DB::table('purchase_invoices')
            ->join('suppliers', function ($join) use ($organizationId): void {
                $join->on('suppliers.id', '=', 'purchase_invoices.supplier_id')
                    ->where('suppliers.organization_id', '=', $organizationId)->whereNull('suppliers.deleted_at');
            })
            ->where('purchase_invoices.organization_id', $organizationId)
            ->where('purchase_invoices.branch_id', $branchId)
            ->where('purchase_invoices.status', 'received')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('suppliers.name', 'like', $term)->orWhere('suppliers.code', 'like', $term)->orWhere('suppliers.phone', 'like', $term);
                });
            })
            ->when($filters['from'] !== null, fn (Builder $query) => $query->whereDate('purchase_invoices.received_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn (Builder $query) => $query->whereDate('purchase_invoices.received_at', '<=', $filters['to']));
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (($validated['from'] ?? null) !== null && ($validated['to'] ?? null) !== null && $validated['to'] < $validated['from']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'تاريخ النهاية يجب أن يكون مساويًا لتاريخ البداية أو بعده.']);
        }
        return ['search' => trim((string) ($validated['search'] ?? '')), 'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null];
    }

    private function presentRow(object $row): array
    {
        return [
            'id' => (int) $row->supplier_id, 'name' => (string) $row->supplier_name,
            'code' => $row->supplier_code, 'phone' => $row->supplier_phone,
            'invoiceCount' => (int) $row->invoice_count,
            'totalPurchased' => number_format((float) $row->total_purchased, 2, '.', ''),
            'totalReturned' => number_format((float) $row->total_returned, 2, '.', ''),
            'totalPaid' => number_format((float) $row->total_paid, 2, '.', ''),
            'totalDue' => number_format((float) $row->total_due, 2, '.', ''),
        ];
    }
}
