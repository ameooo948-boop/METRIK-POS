<?php

namespace Tests\Feature\Performance;

use App\Models\CashShift;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesListingQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_listing_query_count_does_not_grow_per_sale(): void
    {
        [$owner, $organization, $branch, $shift] = $this->createOrganizationAndShift();
        $this->createSales($organization, $branch, $owner, $shift, 1);

        $originalLazyLoadingSetting = (bool) config('metrik.performance.prevent_lazy_loading', false);
        Model::preventLazyLoading(true);

        try {
            $oneSaleQueryCount = $this->countQueriesForSalesPage($owner);

            // The page returns up to 20 sales. A cashier relationship loaded one-by-one
            // would add up to 20 queries instead of using a single eager-load query.
            $this->createSales($organization, $branch, $owner, $shift, 24, 1);
            $manySalesQueryCount = $this->countQueriesForSalesPage($owner);
        } finally {
            Model::preventLazyLoading($originalLazyLoadingSetting);
        }

        $this->assertLessThanOrEqual(
            $oneSaleQueryCount + 1,
            $manySalesQueryCount,
            sprintf(
                'The sales listing query count increased from %d to %d when additional sales were added. Check eager loading for N+1 queries.',
                $oneSaleQueryCount,
                $manySalesQueryCount,
            ),
        );
    }

    /** @return array{User, Organization, \App\Models\Branch, CashShift} */
    private function createOrganizationAndShift(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Performance Market',
            'slug' => 'performance-market-'.Str::lower(Str::random(8)),
            'status' => 'trial',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_main' => true,
            'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        $shift = CashShift::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'cashier_id' => $owner->getKey(),
            'opened_by' => $owner->getKey(),
            'status' => 'open',
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);

        return [$owner, $organization, $branch, $shift];
    }

    private function createSales(
        Organization $organization,
        \App\Models\Branch $branch,
        User $owner,
        CashShift $shift,
        int $count,
        int $offset = 0,
    ): void {
        for ($index = $offset; $index < $offset + $count; $index++) {
            Sale::query()->create([
                'organization_id' => $organization->getKey(),
                'branch_id' => $branch->getKey(),
                'cash_shift_id' => $shift->getKey(),
                'cashier_id' => $owner->getKey(),
                'invoice_number' => sprintf('PERF-%04d', $index + 1),
                'client_operation_uuid' => (string) Str::uuid(),
                'status' => 'paid',
                'subtotal' => '10.00',
                'discount_total' => '0.00',
                'tax_total' => '0.00',
                'total' => '10.00',
                'sold_at' => now()->subSeconds($index),
            ]);
        }
    }

    private function countQueriesForSalesPage(User $owner): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->actingAs($owner)->get(route('sales.index'))->assertOk();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
