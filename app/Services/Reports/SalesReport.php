<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Sale;
use App\Support\DecimalMoney;
use DateTimeZone;
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

        $timezone = $this->organizationTimezone($organizationId);
        $key = app(ReportCacheVersion::class)->cacheKey($organizationId, $branchIds, $fromDate, $toDate)
            .':timezone:'.hash('sha256', $timezone);

        $ttlSeconds = max(1, (int) config('metrik.performance.report_cache_ttl_seconds', 45));

        return Cache::remember($key, $ttlSeconds, fn (): array => $this->calculate(
            $organizationId,
            $branchIds,
            $fromDate,
            $toDate,
            $timezone,
        ));
    }

    /** @param list<int> $branchIds */
    private function calculate(int $organizationId, array $branchIds, string $fromDate, string $toDate, string $timezone): array
    {
        // Report dates belong to the organization's business calendar, but timestamps
        // are stored as UTC. Use an exclusive UTC end boundary to avoid midnight gaps.
        $startLocal = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone)->startOfDay();
        $endLocalExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone)->addDay()->startOfDay();
        $startAt = $startLocal->utc()->format('Y-m-d H:i:s');
        $endAtExclusive = $endLocalExclusive->utc()->format('Y-m-d H:i:s');
        [$salesDayExpression, $salesDayBindings] = $this->localDayCase('sold_at', $fromDate, $toDate, $timezone);
        [$itemsDayExpression, $itemsDayBindings] = $this->localDayCase('sales.sold_at', $fromDate, $toDate, $timezone);
        [$returnsDayExpression, $returnsDayBindings] = $this->localDayCase('returned_at', $fromDate, $toDate, $timezone);
        [$returnItemsDayExpression, $returnItemsDayBindings] = $this->localDayCase('sale_returns.returned_at', $fromDate, $toDate, $timezone);

        $sales = Sale::query()
            ->forOrganization($organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', ['paid', 'due', 'partially_paid'])
            ->where('sold_at', '>=', $startAt)
            ->where('sold_at', '<', $endAtExclusive);

        $salesStats = (clone $sales)->selectRaw(
            'COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as sales_total, COALESCE(SUM(tax_total), 0) as sales_tax_total'
        )->first();

        $itemsBase = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.organization_id', $organizationId)
            ->whereIn('sales.branch_id', $branchIds)
            ->whereIn('sales.status', ['paid', 'due', 'partially_paid'])
            ->where('sales.sold_at', '>=', $startAt)
            ->where('sales.sold_at', '<', $endAtExclusive);

        $itemStats = (clone $itemsBase)->selectRaw(
            'COALESCE(SUM(sale_items.line_total), 0) as item_revenue, '
            .'COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost_snapshot, 2)), 0) as cost_of_goods_sold'
        )->first();

        // Recognize return activity on the date the return is processed, even when the original sale was earlier.
        $returnsBase = DB::table('sale_returns')
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->where('returned_at', '>=', $startAt)
            ->where('returned_at', '<', $endAtExclusive);
        $returnsStats = (clone $returnsBase)->selectRaw(
            'COALESCE(SUM(total), 0) as return_total, COALESCE(SUM(tax_total), 0) as return_tax_total'
        )->first();

        $returnItemsBase = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_return_items.organization_id', $organizationId)
            ->whereIn('sale_returns.branch_id', $branchIds)
            ->where('sale_returns.status', 'completed')
            ->where('sale_returns.returned_at', '>=', $startAt)
            ->where('sale_returns.returned_at', '<', $endAtExclusive);
        $returnCostStats = (clone $returnItemsBase)->selectRaw(
            'COALESCE(SUM(sale_return_items.line_total), 0) as returned_revenue, '
            .'COALESCE(SUM(CASE WHEN sale_return_items.restock = 1 '
            .'THEN ROUND(sale_return_items.quantity * sale_return_items.unit_cost_snapshot, 2) ELSE 0 END), 0) as returned_cost'
        )->first();

        $expensesBase = Expense::query()
            ->forOrganization($organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('expense_date', [$fromDate, $toDate]);
        $expenseTotal = (string) ((clone $expensesBase)->sum('amount') ?: '0.00');

        $revenueCents = DecimalMoney::toCents((string) ($salesStats->sales_total ?? '0.00'));
        $itemRevenueCents = DecimalMoney::toCents((string) ($itemStats->item_revenue ?? '0.00'));
        $costCents = DecimalMoney::toCents((string) ($itemStats->cost_of_goods_sold ?? '0.00'));
        $returnCents = DecimalMoney::toCents((string) ($returnsStats->return_total ?? '0.00'));
        $salesTaxCents = DecimalMoney::toCents((string) ($salesStats->sales_tax_total ?? '0.00'));
        $returnTaxCents = DecimalMoney::toCents((string) ($returnsStats->return_tax_total ?? '0.00'));
        $taxCollectedCents = $salesTaxCents - $returnTaxCents;
        $returnedRevenueCents = DecimalMoney::toCents((string) ($returnCostStats->returned_revenue ?? '0.00'));
        $returnedCostCents = DecimalMoney::toCents((string) ($returnCostStats->returned_cost ?? '0.00'));
        $netRevenueCents = $revenueCents - $returnCents;
        $netCostCents = $costCents - $returnedCostCents;
        $expenseCents = DecimalMoney::toCents($expenseTotal);
        // Profit is computed from tax-exclusive line values. Sales/return totals shown
        // elsewhere include tax because they represent amounts charged/refunded.
        $grossProfitCents = $itemRevenueCents - $returnedRevenueCents - $costCents + $returnedCostCents;
        $netProfitCents = $grossProfitCents - $expenseCents;
        $invoiceCount = (int) ($salesStats->invoice_count ?? 0);
        $averageCents = $invoiceCount > 0 ? intdiv($revenueCents + intdiv($invoiceCount, 2), $invoiceCount) : 0;

        $dailySales = (clone $sales)->selectRaw(
            $salesDayExpression.' as report_day, COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as sales_total, COALESCE(SUM(tax_total), 0) as sales_tax_total',
            $salesDayBindings,
        )->groupByRaw($salesDayExpression, $salesDayBindings)->get()->keyBy('report_day');

        $dailyItems = (clone $itemsBase)->selectRaw(
            $itemsDayExpression.' as report_day, '
            .'COALESCE(SUM(sale_items.line_total), 0) as item_revenue, '
            .'COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost_snapshot, 2)), 0) as cost_total',
            $itemsDayBindings,
        )->groupByRaw($itemsDayExpression, $itemsDayBindings)->get()->keyBy('report_day');

        $dailyReturns = (clone $returnsBase)->selectRaw(
            $returnsDayExpression.' as report_day, COALESCE(SUM(total), 0) as return_total, COALESCE(SUM(tax_total), 0) as return_tax_total',
            $returnsDayBindings,
        )->groupByRaw($returnsDayExpression, $returnsDayBindings)->get()->keyBy('report_day');

        $dailyReturnCosts = (clone $returnItemsBase)->selectRaw(
            $returnItemsDayExpression.' as report_day, '
            .'COALESCE(SUM(sale_return_items.line_total), 0) as returned_revenue, '
            .'COALESCE(SUM(CASE WHEN sale_return_items.restock = 1 '
            .'THEN ROUND(sale_return_items.quantity * sale_return_items.unit_cost_snapshot, 2) ELSE 0 END), 0) as returned_cost',
            $returnItemsDayBindings,
        )->groupByRaw($returnItemsDayExpression, $returnItemsDayBindings)->get()->keyBy('report_day');

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
            $returnRow = $dailyReturns->get($day);
            $returnCostRow = $dailyReturnCosts->get($day);
            $expenseRow = $dailyExpenses->get($day);
            $daySalesCents = DecimalMoney::toCents((string) ($salesRow->sales_total ?? '0.00'));
            $dayItemRevenueCents = DecimalMoney::toCents((string) ($itemsRow->item_revenue ?? '0.00'));
            $dayCostCents = DecimalMoney::toCents((string) ($itemsRow->cost_total ?? '0.00'));
            $dayReturnCents = DecimalMoney::toCents((string) ($returnRow->return_total ?? '0.00'));
            $daySalesTaxCents = DecimalMoney::toCents((string) ($salesRow->sales_tax_total ?? '0.00'));
            $dayReturnTaxCents = DecimalMoney::toCents((string) ($returnRow->return_tax_total ?? '0.00'));
            $dayReturnedRevenueCents = DecimalMoney::toCents((string) ($returnCostRow->returned_revenue ?? '0.00'));
            $dayReturnedCostCents = DecimalMoney::toCents((string) ($returnCostRow->returned_cost ?? '0.00'));
            $dayExpenseCents = DecimalMoney::toCents((string) ($expenseRow->expense_total ?? '0.00'));
            $dayGrossCents = $dayItemRevenueCents - $dayReturnedRevenueCents - $dayCostCents + $dayReturnedCostCents;

            $daily[] = [
                'date' => $day,
                'invoices' => (int) ($salesRow->invoice_count ?? 0),
                'sales' => DecimalMoney::fromCents($daySalesCents),
                'returns' => DecimalMoney::fromCents($dayReturnCents),
                'netSales' => DecimalMoney::fromCents($daySalesCents - $dayReturnCents),
                'taxCollected' => DecimalMoney::fromCents($daySalesTaxCents - $dayReturnTaxCents),
                'costOfGoods' => DecimalMoney::fromCents($dayCostCents - $dayReturnedCostCents),
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
            ->whereIn('sales.status', ['paid', 'due', 'partially_paid'])
            ->where('sales.sold_at', '>=', $startAt)
            ->where('sales.sold_at', '<', $endAtExclusive)
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
                'returns' => DecimalMoney::fromCents($returnCents),
                'netSales' => DecimalMoney::fromCents($netRevenueCents),
                'taxCollected' => DecimalMoney::fromCents($taxCollectedCents),
                'costOfGoods' => DecimalMoney::fromCents($netCostCents),
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

    private function organizationTimezone(int $organizationId): string
    {
        $timezone = trim((string) DB::table('organizations')->where('id', $organizationId)->value('timezone'));
        if ($timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    /**
     * Maps UTC timestamps to each organization-local reporting day using portable SQL.
     * This avoids relying on MySQL timezone tables and handles timezone offset changes.
     *
     * @return array{0:string,1:list<string>}
     */
    private function localDayCase(string $column, string $fromDate, string $toDate, string $timezone): array
    {
        $expression = 'CASE';
        $bindings = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone)->startOfDay();
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone)->startOfDay();

        while ($cursor <= $lastDay) {
            $nextDay = $cursor->addDay()->startOfDay();
            $expression .= ' WHEN '.$column.' >= ? AND '.$column.' < ? THEN ?';
            $bindings[] = $cursor->utc()->format('Y-m-d H:i:s');
            $bindings[] = $nextDay->utc()->format('Y-m-d H:i:s');
            $bindings[] = $cursor->toDateString();
            $cursor = $nextDay;
        }

        return [$expression.' ELSE NULL END', $bindings];
    }

    private function emptyReport(string $fromDate, string $toDate): array
    {
        $daily = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);
        while ($cursor <= $lastDay) {
            $daily[] = [
                'date' => $cursor->toDateString(), 'invoices' => 0,
                'sales' => '0.00', 'returns' => '0.00', 'netSales' => '0.00', 'taxCollected' => '0.00', 'costOfGoods' => '0.00', 'grossProfit' => '0.00',
                'expenses' => '0.00', 'netProfit' => '0.00',
            ];
            $cursor = $cursor->addDay();
        }

        return [
            'summary' => [
                'invoiceCount' => 0, 'sales' => '0.00', 'returns' => '0.00', 'netSales' => '0.00', 'taxCollected' => '0.00', 'costOfGoods' => '0.00',
                'grossProfit' => '0.00', 'expenses' => '0.00', 'netProfit' => '0.00', 'averageSale' => '0.00',
            ],
            'daily' => $daily,
            'topProducts' => [],
            'paymentMethods' => [],
        ];
    }
}
