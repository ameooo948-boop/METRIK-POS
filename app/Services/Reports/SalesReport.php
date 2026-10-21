<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Sale;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesReport
{
    /** @param list<int> $branchIds */
    public function handle(int $organizationId, array $branchIds, string $fromDate, string $toDate): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        sort($branchIds, SORT_NUMERIC);

        if ($branchIds === []) {
            return $this->emptyReport($fromDate, $toDate);
        }

        $key = app(ReportCacheVersion::class)->cacheKey($organizationId, $branchIds, $fromDate, $toDate);

        $ttlSeconds = max(1, (int) config('metrik.performance.report_cache_ttl_seconds', 45));

        return Cache::remember($key, $ttlSeconds, fn (): array => $this->calculate(
            $organizationId,
            $branchIds,
            $fromDate,
            $toDate,
        ));
    }

    /** @param list<int> $branchIds */
    private function calculate(int $organizationId, array $branchIds, string $fromDate, string $toDate): array
    {
        $startAt = $fromDate.' 00:00:00';
        $endAt = $toDate.' 23:59:59';

        $sales = Sale::query()
            ->forOrganization($organizationId)
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'paid')
            ->whereBetween('sold_at', [$startAt, $endAt]);

        $salesStats = (clone $sales)->selectRaw(
            'COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as sales_total'
        )->first();

        $itemsBase = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.organization_id', $organizationId)
            ->whereIn('sales.branch_id', $branchIds)
            ->where('sales.status', 'paid')
            ->whereBetween('sales.sold_at', [$startAt, $endAt]);

        $itemStats = (clone $itemsBase)->selectRaw(
            'COALESCE(SUM(sale_items.line_total), 0) as item_revenue, '
            .'COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost_snapshot, 2)), 0) as cost_of_goods_sold'
        )->first();

        $expensesBase = Expense::query()
            ->forOrganization($organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('expense_date', [$fromDate, $toDate]);
        $expenseTotal = (string) ((clone $expensesBase)->sum('amount') ?: '0.00');

        $revenueCents = DecimalMoney::toCents((string) ($salesStats->sales_total ?? '0.00'));
        $itemRevenueCents = DecimalMoney::toCents((string) ($itemStats->item_revenue ?? '0.00'));
        $costCents = DecimalMoney::toCents((string) ($itemStats->cost_of_goods_sold ?? '0.00'));
        $expenseCents = DecimalMoney::toCents($expenseTotal);
        $grossProfitCents = $itemRevenueCents - $costCents;
        $netProfitCents = $grossProfitCents - $expenseCents;
        $invoiceCount = (int) ($salesStats->invoice_count ?? 0);
        $averageCents = $invoiceCount > 0 ? intdiv($revenueCents + intdiv($invoiceCount, 2), $invoiceCount) : 0;

        $dailySales = (clone $sales)->selectRaw(
            'DATE(sold_at) as report_day, COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as sales_total'
        )->groupByRaw('DATE(sold_at)')->get()->keyBy('report_day');

        $dailyItems = (clone $itemsBase)->selectRaw(
            'DATE(sales.sold_at) as report_day, '
            .'COALESCE(SUM(sale_items.line_total), 0) as item_revenue, '
            .'COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost_snapshot, 2)), 0) as cost_total'
        )->groupByRaw('DATE(sales.sold_at)')->get()->keyBy('report_day');

        $dailyExpenses = (clone $expensesBase)->selectRaw(
            'expense_date as report_day, COALESCE(SUM(amount), 0) as expense_total'
        )->groupBy('expense_date')->get()->keyBy('report_day');

        $daily = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);
        while ($cursor <= $lastDay) {
            $day = $cursor->toDateString();
            $salesRow = $dailySales->get($day);
            $itemsRow = $dailyItems->get($day);
            $expenseRow = $dailyExpenses->get($day);
            $dayItemRevenueCents = DecimalMoney::toCents((string) ($itemsRow->item_revenue ?? '0.00'));
            $dayCostCents = DecimalMoney::toCents((string) ($itemsRow->cost_total ?? '0.00'));
            $dayExpenseCents = DecimalMoney::toCents((string) ($expenseRow->expense_total ?? '0.00'));
            $dayGrossCents = $dayItemRevenueCents - $dayCostCents;

            $daily[] = [
                'date' => $day,
                'invoices' => (int) ($salesRow->invoice_count ?? 0),
                'sales' => DecimalMoney::fromCents(DecimalMoney::toCents((string) ($salesRow->sales_total ?? '0.00'))),
                'costOfGoods' => DecimalMoney::fromCents($dayCostCents),
                'grossProfit' => DecimalMoney::fromCents($dayGrossCents),
                'expenses' => DecimalMoney::fromCents($dayExpenseCents),
                'netProfit' => DecimalMoney::fromCents($dayGrossCents - $dayExpenseCents),
            ];
            $cursor = $cursor->addDay();
        }

        $topProducts = (clone $itemsBase)->selectRaw(
            'sale_items.product_id, sale_items.product_sku_snapshot as sku, '
            .'sale_items.product_name_snapshot as name, '
            .'COALESCE(SUM(sale_items.quantity), 0) as quantity_sold, '
            .'COALESCE(SUM(sale_items.line_total), 0) as sales_total, '
            .'COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost_snapshot, 2)), 0) as cost_total'
        )->groupBy('sale_items.product_id', 'sale_items.product_sku_snapshot', 'sale_items.product_name_snapshot')
            ->orderByDesc('quantity_sold')->limit(10)->get()
            ->map(static function ($row): array {
                $salesCents = DecimalMoney::toCents((string) $row->sales_total);
                $costCents = DecimalMoney::toCents((string) $row->cost_total);

                return [
                    'productId' => $row->product_id === null ? null : (int) $row->product_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'quantity' => (string) $row->quantity_sold,
                    'sales' => DecimalMoney::fromCents($salesCents),
                    'profit' => DecimalMoney::fromCents($salesCents - $costCents),
                ];
            })->values()->all();

        $paymentMethods = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.organization_id', $organizationId)
            ->whereIn('sales.branch_id', $branchIds)
            ->where('sales.status', 'paid')
            ->whereBetween('sales.sold_at', [$startAt, $endAt])
            ->selectRaw('sale_payments.payment_method as method, COALESCE(SUM(sale_payments.amount), 0) as total')
            ->groupBy('sale_payments.payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($row): array => [
                'method' => (string) $row->method,
                'label' => match ((string) $row->method) {
                    'cash' => 'نقدي',
                    'card' => 'بطاقة',
                    'mobile_wallet' => 'محفظة إلكترونية',
                    default => 'أخرى',
                },
                'total' => DecimalMoney::fromCents(DecimalMoney::toCents((string) $row->total)),
            ])->values()->all();

        return [
            'summary' => [
                'invoiceCount' => $invoiceCount,
                'sales' => DecimalMoney::fromCents($revenueCents),
                'costOfGoods' => DecimalMoney::fromCents($costCents),
                'grossProfit' => DecimalMoney::fromCents($grossProfitCents),
                'expenses' => DecimalMoney::fromCents($expenseCents),
                'netProfit' => DecimalMoney::fromCents($netProfitCents),
                'averageSale' => DecimalMoney::fromCents($averageCents),
            ],
            'daily' => $daily,
            'topProducts' => $topProducts,
            'paymentMethods' => $paymentMethods,
        ];
    }

    private function emptyReport(string $fromDate, string $toDate): array
    {
        $daily = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);
        while ($cursor <= $lastDay) {
            $daily[] = [
                'date' => $cursor->toDateString(), 'invoices' => 0,
                'sales' => '0.00', 'costOfGoods' => '0.00', 'grossProfit' => '0.00',
                'expenses' => '0.00', 'netProfit' => '0.00',
            ];
            $cursor = $cursor->addDay();
        }

        return [
            'summary' => [
                'invoiceCount' => 0, 'sales' => '0.00', 'costOfGoods' => '0.00',
                'grossProfit' => '0.00', 'expenses' => '0.00', 'netProfit' => '0.00', 'averageSale' => '0.00',
            ],
            'daily' => $daily,
            'topProducts' => [],
            'paymentMethods' => [],
        ];
    }
}
