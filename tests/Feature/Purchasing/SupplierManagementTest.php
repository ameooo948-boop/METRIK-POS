<?php

namespace Tests\Feature\Purchasing;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_supplier_and_view_ledger_balance(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Supplier Test Market');

        $this->actingAs($owner)->post(route('suppliers.store'), [
            'name' => '  شركة الأغذية  ',
            'code' => 'food-001',
            'phone' => '01000000000',
            'email' => 'sales@example.test',
            'address' => 'المنصورة',
        ])->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $organization->getKey(),
            'name' => 'شركة الأغذية',
            'code' => 'FOOD-001',
            'is_active' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Suppliers/Index')
                ->has('suppliers.data', 1)
                ->where('suppliers.data.0.name', 'شركة الأغذية')
                ->where('suppliers.data.0.balance', '0.00'));
    }

    public function test_supplier_codes_are_unique_within_an_organization_but_not_across_organizations(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Supplier Unique Market');
        [$otherOwner, $otherOrganization] = $this->createOrganizationFor('Other Supplier Market');

        Supplier::query()->create(['organization_id' => $otherOrganization->getKey(), 'code' => 'LOCAL-01', 'name' => 'مورد آخر']);

        $this->actingAs($owner)->post(route('suppliers.store'), [
            'name' => 'مورد محلي', 'code' => 'local-01',
        ])->assertRedirect(route('suppliers.index'));

        $this->actingAs($owner)->from(route('suppliers.index'))->post(route('suppliers.store'), [
            'name' => 'مورد مكرر', 'code' => 'LOCAL-01',
        ])->assertRedirect(route('suppliers.index'))->assertSessionHasErrors('code');

        $this->assertDatabaseHas('suppliers', ['organization_id' => $organization->getKey(), 'code' => 'LOCAL-01']);
    }

    public function test_cashier_cannot_manage_suppliers_or_purchase_invoices(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Supplier Permissions Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('suppliers.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('purchase-invoices.index'))->assertForbidden();
    }

    public function test_owner_can_update_and_deactivate_supplier_without_deleting_its_history(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Supplier Lifecycle Market');
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->getKey(), 'code' => 'LIFE-001',
            'name' => 'مورد قديم', 'phone' => '01000000000', 'is_active' => true,
        ]);

        $this->actingAs($owner)->patch(route('suppliers.update', $supplier->getKey()), [
            'name' => 'مورد معدل', 'code' => 'LIFE-001', 'phone' => '01111111111',
            'email' => 'new@example.test', 'address' => 'عنوان جديد', 'notes' => 'ملاحظة',
        ])->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->getKey(), 'name' => 'مورد معدل', 'phone' => '01111111111',
            'email' => 'new@example.test', 'organization_id' => $organization->getKey(),
        ]);

        $this->actingAs($owner)->patch(route('suppliers.status.update', $supplier->getKey()), ['is_active' => false])
            ->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->getKey(), 'is_active' => 0, 'deleted_at' => null,
        ]);
    }

    public function test_supplier_updates_are_tenant_scoped_and_cashiers_cannot_modify_them(): void
    {
        [$owner] = $this->createOrganizationFor('Supplier Owner Boundary');
        [, $otherOrganization, $otherBranch] = $this->createOrganizationFor('Supplier Foreign Boundary');
        $supplier = Supplier::query()->create([
            'organization_id' => $otherOrganization->getKey(), 'code' => 'BOUNDARY-01', 'name' => 'محمي',
        ]);

        $this->actingAs($owner)->patch(route('suppliers.status.update', $supplier->getKey()), ['is_active' => false])->assertNotFound();
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->getKey(), 'is_active' => 1]);

        $cashier = User::factory()->create();
        $otherOrganization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $otherBranch->users()->attach($cashier->getKey());
        $this->actingAs($cashier)->patch(route('suppliers.status.update', $supplier->getKey()), ['is_active' => false])->assertForbidden();
    }

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
