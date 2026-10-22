<?php

namespace Tests\Feature\Catalog;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\ProductUnitOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selling_in_cartons_deducts_stock_in_base_units_and_keeps_invoice_snapshot(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Unit Conversion POS Market');
        $piece = Unit::query()->where('code', 'piece')->firstOrFail();
        $carton = Unit::query()->where('code', 'carton')->firstOrFail();
        $product = $this->createProduct($organization, $owner, $branch, 'CARTON-001', 'مياه', '30.000');

        ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(),
            'unit_id' => $carton->getKey(),
            'factor_to_base' => '12.000',
            'purchase_price' => '10.00',
            'selling_price' => '18.00',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])
            ->assertRedirect(route('pos.index'));

        $uuid = (string) Str::uuid();
        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->getKey(),
                'unit_id' => $carton->getKey(),
                'quantity' => '2.000',
            ]],
        ])->assertRedirect();

        $sale = Sale::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_operation_uuid', $uuid)
            ->firstOrFail();

        $this->assertSame('36.00', $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $carton->getKey(),
            'quantity' => '2.000',
            'unit_name_snapshot' => 'كرتونة',
            'unit_conversion_factor_snapshot' => '12.000',
            'quantity_in_base' => '24.000',
            'unit_price' => '18.00',
            'line_total' => '36.00',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '6.000',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'movement_type' => 'sale',
            'quantity_delta' => '-24.000',
            'reference_type' => Sale::class,
            'reference_id' => $sale->getKey(),
        ]);

        $this->assertSame((int) $piece->getKey(), (int) $product->base_unit_id);
    }

    public function test_receiving_cartons_updates_inventory_and_base_purchase_cost(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Unit Conversion Purchasing Market');
        $carton = Unit::query()->where('code', 'carton')->firstOrFail();
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'مورد الكراتين',
            'is_active' => true,
        ]);
        $product = $this->createProduct($organization, $owner, $branch, 'PUR-CARTON-001', 'مناديل', '10.000');
        ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(),
            'unit_id' => $carton->getKey(),
            'factor_to_base' => '12.000',
            'purchase_price' => '48.00',
            'selling_price' => '60.00',
            'is_active' => true,
        ]);

        $this->actingAs($owner)->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->getKey(),
            'paid_amount' => '0.00',
            'items' => [[
                'product_id' => $product->getKey(),
                'unit_id' => $carton->getKey(),
                'quantity' => '2.000',
                'unit_cost' => '48.00',
            ]],
        ])->assertRedirect(route('purchase-invoices.index'));

        $this->assertDatabaseHas('purchase_invoice_items', [
            'product_id' => $product->getKey(),
            'unit_id' => $carton->getKey(),
            'quantity' => '2.000',
            'unit_name_snapshot' => 'كرتونة',
            'unit_conversion_factor_snapshot' => '12.000',
            'quantity_in_base' => '24.000',
            'unit_cost' => '48.00',
            'line_total' => '96.00',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '34.000',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'movement_type' => 'purchase',
            'quantity_delta' => '24.000',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->getKey(),
            'purchase_price' => '4.00',
        ]);
    }


    public function test_unit_options_do_not_duplicate_legacy_base_unit_conversion(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Unit Options Market');
        $piece = Unit::query()->where('code', 'piece')->firstOrFail();
        $carton = Unit::query()->where('code', 'carton')->firstOrFail();
        $product = $this->createProduct($organization, $owner, $branch, 'OPTIONS-001', 'منظف', '10.000');
        ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(), 'unit_id' => $carton->getKey(),
            'factor_to_base' => '12.000', 'is_active' => true,
        ]);
        $product->load(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en']);

        $options = ProductUnitOptions::options($product);
        $baseOptions = array_values(array_filter($options, static fn (array $option): bool => (int) $option['id'] === (int) $piece->getKey()));

        $this->assertCount(1, $baseOptions);
        $this->assertCount(2, $options);
    }

    public function test_a_barcode_can_be_assigned_to_the_base_unit(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Base Barcode Market');
        $piece = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = $this->createProduct($organization, $owner, $branch, 'BASE-BARCODE-001', 'علبة', '5.000');

        $this->actingAs($owner)->post(route('products.barcodes.store', $product->getKey()), [
            'unit_id' => $piece->getKey(),
            'barcode' => '6221234567891',
        ])->assertRedirect(route('products.units.edit', $product->getKey()));

        $this->assertDatabaseHas('product_barcodes', [
            'organization_id' => $organization->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $piece->getKey(),
            'barcode' => '6221234567891',
        ]);
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
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
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, $branch, string $sku, string $name, string $quantity): Product
    {
        $piece = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $piece->getKey(),
            'created_by' => $owner->getKey(),
            'sku' => $sku,
            'name' => $name,
            'purchase_price' => '1.00',
            'selling_price' => '2.00',
            'low_stock_threshold' => '1.000',
            'is_active' => true,
        ]);
        // Product creation in the existing project stores this legacy factor=1 row.
        ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(), 'unit_id' => $piece->getKey(), 'factor_to_base' => '1.000',
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(),
            'movement_type' => 'opening',
            'quantity_delta' => $quantity,
            'balance_after' => $quantity,
            'source' => 'test',
            'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        return $product;
    }
}
