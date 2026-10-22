<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductCatalogCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_import_new_products_and_update_existing_catalog_fields_without_changing_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('CSV Import Market');
        $category = $organization->categories()->create(['name' => 'مشروبات']);
        $unitId = $this->unitId('piece');

        $this->actingAs($owner)->post(route('products.store'), [
            'name' => 'اسم قديم',
            'sku' => 'EXIST-001',
            'category_id' => $category->getKey(),
            'base_unit_id' => $unitId,
            'barcode' => '6220000000010',
            'purchase_price' => '1.00',
            'selling_price' => '2.00',
            'low_stock_threshold' => '2.000',
            'initial_stock' => '4.000',
        ])->assertRedirect(route('products.index'));

        $csv = $this->makeCsv([
            ['sku', 'name', 'description', 'category', 'unit_code', 'barcode', 'purchase_price', 'selling_price', 'low_stock_threshold', 'initial_stock', 'is_active'],
            ['EXIST-001', 'اسم معدل', 'وصف جديد', 'مشروبات', 'piece', '6220000000010', '1.50', '3.25', '3.000', '99.000', '1'],
            ['NEW-001', 'منتج جديد', 'وصف', 'مشروبات', 'piece', '6220000000020', '2.00', '4.50', '1.000', '7.125', '1'],
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->actingAs($owner)->post(route('products.import'), ['file' => $file])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $existing = Product::query()->forOrganization((int) $organization->getKey())->where('sku', 'EXIST-001')->firstOrFail();
        $this->assertSame('اسم معدل', $existing->name);
        $this->assertSame('3.25', (string) $existing->selling_price);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $existing->getKey(),
            'quantity' => '4.000',
        ]);

        $new = Product::query()->forOrganization((int) $organization->getKey())->where('sku', 'NEW-001')->firstOrFail();
        $this->assertDatabaseHas('product_barcodes', [
            'organization_id' => $organization->getKey(), 'product_id' => $new->getKey(), 'barcode' => '6220000000020',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $new->getKey(),
            'quantity' => '7.125',
        ]);
    }

    public function test_import_reports_duplicate_barcode_and_does_not_create_the_conflicting_product(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('CSV Duplicate Barcode Market');
        $category = $organization->categories()->create(['name' => 'مشروبات']);
        $unitId = $this->unitId('piece');
        $existing = Product::query()->create([
            'organization_id' => $organization->getKey(), 'category_id' => $category->getKey(), 'base_unit_id' => $unitId,
            'sku' => 'EXIST-BAR', 'name' => 'منتج موجود', 'purchase_price' => '1.00', 'selling_price' => '2.00',
            'low_stock_threshold' => '0.000', 'is_active' => true,
        ]);
        ProductBarcode::query()->create([
            'organization_id' => $organization->getKey(), 'product_id' => $existing->getKey(),
            'unit_id' => $unitId, 'barcode' => '6220000000999',
        ]);

        $csv = $this->makeCsv([
            ['sku', 'name', 'description', 'category', 'unit_code', 'barcode', 'purchase_price', 'selling_price', 'low_stock_threshold', 'initial_stock', 'is_active'],
            ['NEW-BAR', 'منتج جديد', '', 'مشروبات', 'piece', '6220000000999', '1.00', '2.00', '0.000', '0.000', '1'],
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->actingAs($owner)->post(route('products.import'), ['file' => $file])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error')
            ->assertSessionHas('importErrors');

        $this->assertDatabaseMissing('products', [
            'organization_id' => $organization->getKey(), 'sku' => 'NEW-BAR',
        ]);
    }

    public function test_export_and_template_are_csv_and_export_only_current_organization_products(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('CSV Export Market');
        [, $otherOrganization] = $this->createOrganizationFor('Other CSV Market');
        $unitId = $this->unitId('piece');

        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unitId,
            'sku' => 'EXPORT-001', 'name' => 'منتج للتصدير', 'purchase_price' => '2.50', 'selling_price' => '4.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '8.500',
        ]);
        Product::query()->create([
            'organization_id' => $otherOrganization->getKey(), 'base_unit_id' => $unitId,
            'sku' => 'PRIVATE-OTHER', 'name' => 'منتج مؤسسة أخرى', 'purchase_price' => '1.00', 'selling_price' => '2.00',
            'low_stock_threshold' => '0.000', 'is_active' => true,
        ]);

        $export = $this->actingAs($owner)->get(route('products.export'))->assertOk();
        $contents = $export->streamedContent();
        $this->assertStringContainsString('EXPORT-001', $contents);
        $this->assertStringContainsString('8.500', $contents);
        $this->assertStringNotContainsString('PRIVATE-OTHER', $contents);
        $this->assertStringContainsString('sku,name,description,category,unit_code,barcode', $contents);

        $template = $this->actingAs($owner)->get(route('products.template'))->assertOk();
        $this->assertStringContainsString('initial_stock,is_active', $template->streamedContent());
    }

    public function test_cashier_cannot_import_or_export_catalog(): void
    {
        [, $organization, $branch] = $this->createOrganizationFor('CSV Authorization Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('products.export'))->assertForbidden();
        $this->actingAs($cashier)->get(route('products.template'))->assertForbidden();
        $file = UploadedFile::fake()->createWithContent('products.csv', "sku,name,unit_code,purchase_price,selling_price\nX-1,Test,piece,1.00,2.00\n");
        $this->actingAs($cashier)->post(route('products.import'), ['file' => $file])->assertForbidden();
    }

    public function test_exact_barcode_match_is_prioritized_in_pos_product_search(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Barcode Search Market');
        $unitId = $this->unitId('piece');

        $partial = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unitId,
            'sku' => 'A-PARTIAL', 'name' => 'ألف منتج', 'purchase_price' => '1.00', 'selling_price' => '2.00',
            'low_stock_threshold' => '0.000', 'is_active' => true,
        ]);
        $exact = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unitId,
            'sku' => 'Z-EXACT', 'name' => 'منتج زاي', 'purchase_price' => '1.00', 'selling_price' => '2.00',
            'low_stock_threshold' => '0.000', 'is_active' => true,
        ]);
        ProductBarcode::query()->create([
            'organization_id' => $organization->getKey(), 'product_id' => $partial->getKey(), 'unit_id' => $unitId,
            'barcode' => 'X6220000000001',
        ]);
        ProductBarcode::query()->create([
            'organization_id' => $organization->getKey(), 'product_id' => $exact->getKey(), 'unit_id' => $unitId,
            'barcode' => '6220000000001',
        ]);

        $this->actingAs($owner)->getJson(route('pos.products.search', ['q' => '6220000000001']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $exact->getKey());
    }

    /** @param list<list<string>> $rows */
    private function makeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return (string) $contents;
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
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }

    private function unitId(string $code): int
    {
        return (int) Unit::query()->where('code', $code)->value('id');
    }
}
