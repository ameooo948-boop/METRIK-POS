<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_view_another_cashiers_receipt(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Sales Permissions Market');
        $product = $this->createProduct($organization, $owner, $branch);
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));
        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'card',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect();
        $sale = Sale::query()->firstOrFail();

        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('sales.show', $sale->getKey()))->assertForbidden();
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active']);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());
        return [$user, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, Branch $branch): Product
    {
        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unit->getKey(), 'created_by' => $owner->getKey(),
            'sku' => 'RECEIPT-01', 'name' => 'منتج إيصال', 'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '2.000',
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'movement_type' => 'opening', 'quantity_delta' => '2.000', 'balance_after' => '2.000',
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);
        return $product;
    }
}
