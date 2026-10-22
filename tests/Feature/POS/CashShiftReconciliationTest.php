<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashShiftReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_can_close_with_counted_denominations_and_notes(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Cash Count Market');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '50.00'])
            ->assertRedirect(route('pos.index'));
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();

        $this->actingAs($owner)->post(route('pos.shifts.close', $shift->getKey()), [
            'closing_amount' => '58.00',
            'closing_notes' => 'مطابقة تجربة العد',
            'cash_count' => [
                ['denomination' => '50.00', 'count' => 1],
                ['denomination' => '5.00', 'count' => 1],
                ['denomination' => '1.00', 'count' => 3],
            ],
        ])->assertRedirect(route('pos.index'));

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame('8.00', $shift->difference_amount);
        $this->assertSame('مطابقة تجربة العد', $shift->closing_notes);
        $this->assertCount(3, $shift->cash_count_details);
        $this->assertSame('50.00', $shift->cash_count_details[0]['line_total']);
        $this->assertDatabaseHas('cash_shifts', [
            'id' => $shift->getKey(), 'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
        ]);
    }

    public function test_counted_total_must_match_closing_amount_and_shift_remains_open(): void
    {
        [$owner] = $this->createOrganizationFor('Cash Count Mismatch Market');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '10.00'])->assertRedirect(route('pos.index'));
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();

        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.shifts.close', $shift->getKey()), [
            'closing_amount' => '10.00',
            'cash_count' => [['denomination' => '5.00', 'count' => 1]],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors('closing_amount');

        $this->assertDatabaseHas('cash_shifts', ['id' => $shift->getKey(), 'status' => 'open', 'closed_at' => null]);
    }

    public function test_report_reconciles_cash_sales_in_out_and_cash_returns(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Cash Return Market');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])->assertRedirect(route('pos.index'));
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();

        foreach ([
            ['cash_sale', '30.00'], ['cash_return', '10.00'], ['cash_in', '5.00'], ['cash_out', '20.00'],
        ] as [$type, $amount]) {
            CashMovement::query()->create([
                'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
                'cash_shift_id' => $shift->getKey(), 'created_by' => $owner->getKey(),
                'movement_type' => $type, 'amount' => $amount, 'notes' => 'اختبار تسوية',
            ]);
        }

        $this->actingAs($owner)->get(route('pos.shifts.report', $shift->getKey()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/ShiftReport')
                ->where('shift.calculatedExpectedCash', '105.00')
                ->where('summary.cashSales', '30.00')
                ->where('summary.cashIn', '5.00')
                ->where('summary.cashOut', '20.00')
                ->where('summary.cashReturns', '10.00')
                ->has('movements', 4));
    }

    public function test_cashier_shift_history_and_report_are_scoped_to_their_own_shifts(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Cashier Scope Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '10.00'])->assertRedirect(route('pos.index'));
        $ownerShift = CashShift::query()->where('cashier_id', $owner->getKey())->firstOrFail();

        $this->actingAs($cashier)->post(route('pos.shifts.open'), ['opening_amount' => '20.00'])->assertRedirect(route('pos.index'));
        $cashierShift = CashShift::query()->where('cashier_id', $cashier->getKey())->firstOrFail();

        $this->actingAs($cashier)->get(route('pos.shifts.history'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('POS/ShiftHistory')->has('shifts.data', 1)->where('shifts.data.0.id', $cashierShift->getKey()));
        $this->actingAs($cashier)->get(route('pos.shifts.report', $ownerShift->getKey()))->assertNotFound();
    }

    /** @return array{User,Organization,Branch} */
    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());
        return [$user, $organization, $branch];
    }
}
