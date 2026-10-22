<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\DecimalMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerReceivablesController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);
        $filters = $this->filters($request);
        $query = $this->reportQuery((int) $organization->getKey(), $filters);

        $summary = (clone $query)
            ->selectRaw('COUNT(customers.id) AS customer_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN customers.balance > 0 THEN customers.balance ELSE 0 END), 0) AS total_receivable')
            ->selectRaw('COALESCE(SUM(COALESCE(aging.bucket_0_30, 0)), 0) AS bucket_0_30')
            ->selectRaw('COALESCE(SUM(COALESCE(aging.bucket_31_60, 0)), 0) AS bucket_31_60')
            ->selectRaw('COALESCE(SUM(COALESCE(aging.bucket_61_90, 0)), 0) AS bucket_61_90')
            ->selectRaw('COALESCE(SUM(COALESCE(aging.bucket_over_90, 0)), 0) AS bucket_over_90')
            ->selectRaw('COALESCE(SUM(COALESCE(aging.invoice_due, 0)), 0) AS invoice_due_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN customers.balance > COALESCE(aging.invoice_due, 0) THEN customers.balance - COALESCE(aging.invoice_due, 0) ELSE 0 END), 0) AS unaged_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN customers.balance > customers.credit_limit THEN 1 ELSE 0 END), 0) AS over_limit_count')
            ->first();

        $rows = (clone $query)
            ->select([
                'customers.id AS customer_id', 'customers.code AS customer_code',
                'customers.name AS customer_name', 'customers.phone AS customer_phone',
                'customers.balance AS account_balance', 'customers.credit_limit AS credit_limit',
            ])
            ->selectRaw('COALESCE(aging.invoice_count, 0) AS invoice_count')
            ->selectRaw('COALESCE(aging.invoice_due, 0) AS invoice_due')
            ->selectRaw('COALESCE(aging.bucket_0_30, 0) AS bucket_0_30')
            ->selectRaw('COALESCE(aging.bucket_31_60, 0) AS bucket_31_60')
            ->selectRaw('COALESCE(aging.bucket_61_90, 0) AS bucket_61_90')
            ->selectRaw('COALESCE(aging.bucket_over_90, 0) AS bucket_over_90')
            ->orderByDesc('customers.balance')->orderBy('customers.name')
            ->paginate(25)->withQueryString();
        $rows->through(fn (object $row): array => $this->presentRow($row));

        return Inertia::render('Customers/Receivables', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'asOfDate' => Carbon::today()->toDateString(),
            'filters' => $filters,
            'customers' => $rows,
            'summary' => [
                'customerCount' => (int) ($summary->customer_count ?? 0),
                'totalReceivable' => $this->money($summary->total_receivable ?? '0.00'),
                'invoiceDueTotal' => $this->money($summary->invoice_due_total ?? '0.00'),
                'unagedBalance' => $this->money($summary->unaged_balance ?? '0.00'),
                'bucket0To30' => $this->money($summary->bucket_0_30 ?? '0.00'),
                'bucket31To60' => $this->money($summary->bucket_31_60 ?? '0.00'),
                'bucket61To90' => $this->money($summary->bucket_61_90 ?? '0.00'),
                'bucketOver90' => $this->money($summary->bucket_over_90 ?? '0.00'),
                'overLimitCount' => (int) ($summary->over_limit_count ?? 0),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);
        $filters = $this->filters($request);
        $rows = $this->reportQuery((int) $organization->getKey(), $filters)
            ->select([
                'customers.id AS customer_id', 'customers.code AS customer_code',
                'customers.name AS customer_name', 'customers.phone AS customer_phone',
                'customers.balance AS account_balance', 'customers.credit_limit AS credit_limit',
            ])
            ->selectRaw('COALESCE(aging.invoice_count, 0) AS invoice_count')
            ->selectRaw('COALESCE(aging.invoice_due, 0) AS invoice_due')
            ->selectRaw('COALESCE(aging.bucket_0_30, 0) AS bucket_0_30')
            ->selectRaw('COALESCE(aging.bucket_31_60, 0) AS bucket_31_60')
            ->selectRaw('COALESCE(aging.bucket_61_90, 0) AS bucket_61_90')
            ->selectRaw('COALESCE(aging.bucket_over_90, 0) AS bucket_over_90')
            ->orderByDesc('customers.balance')->orderBy('customers.name');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'customer_id', 'customer_code', 'customer_name', 'phone', 'account_balance',
                'credit_limit', 'open_invoice_count', 'open_invoice_due', 'age_0_30',
                'age_31_60', 'age_61_90', 'age_over_90', 'unaged_balance', 'over_credit_limit',
            ]);
            foreach ($rows->cursor() as $row) {
                $balanceCents = DecimalMoney::toCents((string) $row->account_balance);
                $limitCents = DecimalMoney::toCents((string) $row->credit_limit);
                $invoiceDueCents = DecimalMoney::toCents((string) $row->invoice_due);
                fputcsv($out, [
                    (int) $row->customer_id,
                    $this->safeCsvText($row->customer_code),
                    $this->safeCsvText($row->customer_name),
                    $this->safeCsvText($row->customer_phone),
                    $this->money($row->account_balance), $this->money($row->credit_limit),
                    (int) $row->invoice_count, $this->money($row->invoice_due),
                    $this->money($row->bucket_0_30), $this->money($row->bucket_31_60),
                    $this->money($row->bucket_61_90), $this->money($row->bucket_over_90),
                    DecimalMoney::fromCents(max(0, $balanceCents - $invoiceDueCents)),
                    $balanceCents > $limitCents ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, 'customer-receivables-aging.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function reportQuery(int $organizationId, array $filters): Builder
    {
        $today = Carbon::today();
        $cutoff30 = $today->copy()->subDays(30)->toDateString();
        $cutoff60 = $today->copy()->subDays(60)->toDateString();
        $cutoff90 = $today->copy()->subDays(90)->toDateString();
        $paidTotals = DB::table('sale_payments')
            ->select('sale_id')->selectRaw('SUM(amount) AS paid_total')
            ->where('organization_id', $organizationId)->groupBy('sale_id');
        $due = 'CASE WHEN (sales.total - COALESCE(sale_paid.paid_total, 0)) > 0 THEN (sales.total - COALESCE(sale_paid.paid_total, 0)) ELSE 0 END';

        $aging = DB::table('sales')
            ->leftJoinSub($paidTotals, 'sale_paid', fn ($join) => $join->on('sale_paid.sale_id', '=', 'sales.id'))
            ->where('sales.organization_id', $organizationId)
            ->whereIn('sales.status', ['due', 'partially_paid'])
            ->whereNotNull('sales.customer_id')
            ->whereNotNull('sales.sold_at')
            ->whereDate('sales.sold_at', '<=', $today->toDateString())
            ->whereRaw('(sales.total - COALESCE(sale_paid.paid_total, 0)) > 0')
            ->select('sales.customer_id')
            ->selectRaw("SUM($due) AS invoice_due")
            ->selectRaw('COUNT(sales.id) AS invoice_count')
            ->selectRaw("SUM(CASE WHEN sales.sold_at >= ? THEN $due ELSE 0 END) AS bucket_0_30", [$cutoff30])
            ->selectRaw("SUM(CASE WHEN sales.sold_at < ? AND sales.sold_at >= ? THEN $due ELSE 0 END) AS bucket_31_60", [$cutoff30, $cutoff60])
            ->selectRaw("SUM(CASE WHEN sales.sold_at < ? AND sales.sold_at >= ? THEN $due ELSE 0 END) AS bucket_61_90", [$cutoff60, $cutoff90])
            ->selectRaw("SUM(CASE WHEN sales.sold_at < ? THEN $due ELSE 0 END) AS bucket_over_90", [$cutoff90])
            ->groupBy('sales.customer_id');

        return DB::table('customers')
            ->leftJoinSub($aging, 'aging', fn ($join) => $join->on('aging.customer_id', '=', 'customers.id'))
            ->where('customers.organization_id', $organizationId)
            ->whereNull('customers.deleted_at')
            ->where(function (Builder $query): void {
                $query->where('customers.balance', '>', 0)
                    ->orWhereRaw('COALESCE(aging.invoice_due, 0) > 0');
            })
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('customers.name', 'like', $term)
                        ->orWhere('customers.code', 'like', $term)
                        ->orWhere('customers.phone', 'like', $term)
                        ->orWhere('customers.email', 'like', $term);
                });
            });
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        return ['search' => mb_substr(trim((string) ($validated['search'] ?? '')), 0, 100)];
    }

    private function presentRow(object $row): array
    {
        $balanceCents = DecimalMoney::toCents((string) $row->account_balance);
        $limitCents = DecimalMoney::toCents((string) $row->credit_limit);
        $invoiceDueCents = DecimalMoney::toCents((string) $row->invoice_due);

        return [
            'id' => (int) $row->customer_id,
            'code' => $row->customer_code,
            'name' => (string) $row->customer_name,
            'phone' => $row->customer_phone,
            'balance' => $this->money($row->account_balance),
            'creditLimit' => $this->money($row->credit_limit),
            'invoiceCount' => (int) $row->invoice_count,
            'invoiceDue' => $this->money($row->invoice_due),
            'age0To30' => $this->money($row->bucket_0_30),
            'age31To60' => $this->money($row->bucket_31_60),
            'age61To90' => $this->money($row->bucket_61_90),
            'ageOver90' => $this->money($row->bucket_over_90),
            'unagedBalance' => DecimalMoney::fromCents(max(0, $balanceCents - $invoiceDueCents)),
            'overCreditLimit' => $balanceCents > $limitCents,
        ];
    }

    private function money(mixed $amount): string
    {
        return DecimalMoney::fromCents(DecimalMoney::toCents((string) ($amount ?? '0.00')));
    }

    private function safeCsvText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'".$text;
        }
        return $text;
    }
}
