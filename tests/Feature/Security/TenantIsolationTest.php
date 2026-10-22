<?php

namespace Tests\Feature\Security;

use App\Enums\OrganizationRole;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_open_sale_detail_from_another_organization(): void
    {
        [$user] = $this->createOrganizationFor('Local Security Market');
        [$foreignOwner, $foreignOrganization, $foreignBranch] = $this->createOrganizationFor('Foreign Security Market');
        $shift = CashShift::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'cashier_id' => $foreignOwner->getKey(),
            'opened_by' => $foreignOwner->getKey(),
            'status' => 'open',
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
        $sale = Sale::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'cash_shift_id' => $shift->getKey(),
            'cashier_id' => $foreignOwner->getKey(),
            'invoice_number' => 'SEC-FOREIGN-001',
            'client_operation_uuid' => (string) Str::uuid(),
            'status' => 'paid',
            'subtotal' => '25.00',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'total' => '25.00',
            'sold_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('sales.show', $sale->getKey()))
            ->assertNotFound();
    }

    public function test_user_cannot_download_report_export_from_another_organization(): void
    {
        [$user] = $this->createOrganizationFor('Local Export Security Market');
        [$foreignOwner, $foreignOrganization, $foreignBranch] = $this->createOrganizationFor('Foreign Export Security Market');
        $export = ReportExport::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'requested_by' => $foreignOwner->getKey(),
            'branch_ids' => [$foreignBranch->getKey()],
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'status' => 'completed',
            'file_path' => 'report-exports/foreign.csv',
        ]);

        $this->actingAs($user)
            ->get(route('reports.exports.download', $export))
            ->assertNotFound();
    }

    public function test_cashier_cannot_view_financial_reports_or_expenses(): void
    {
        [, $organization, $branch] = $this->createOrganizationFor('Cashier Least Privilege Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), [
            'role' => OrganizationRole::CASHIER->value,
            'is_default' => true,
        ]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('expenses.index'))->assertForbidden();
    }

    /** @return array{User, Organization, Branch} */
    private function createOrganizationFor(string $name): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), [
            'role' => OrganizationRole::OWNER->value,
            'is_default' => true,
        ]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }
}
