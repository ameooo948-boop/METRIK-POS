<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\CashShift;
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

class OfflineSaleSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_catalog_is_scoped_to_current_organization_and_branch(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Catalog Market');
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-001', 'مياه محفوظة', '7.000');

        $this->actingAs($owner)
            ->getJson(route('pos.offline.catalog', ['limit' => 100]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->getKey())
            ->assertJsonPath('data.0.stock', '7.000')
            ->assertJsonPath('data.0.catalogScopeKey', $organization->getKey().':'.$branch->getKey());
    }

    public function test_offline_sale_sync_records_original_time_and_deducts_stock_once(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Sync Market');
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-002', 'لبن محفوظ', '10.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $uuid = (string) Str::uuid();
        $offlineCreatedAt = now()->subMinute()->toIso8601String();
        $payload = $this->offlinePayload($uuid, $shift, $product, $offlineCreatedAt, '2.000');

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'synced')
            ->assertJsonPath('sale.total', '16.00');

        $sale = Sale::query()->where('organization_id', $organization->getKey())
            ->where('client_operation_uuid', $uuid)->firstOrFail();
        $this->assertNotNull($sale->offline_created_at);
        $this->assertNotNull($sale->offline_sync_received_at);
        $this->assertSame((int) $shift->getKey(), (int) $sale->cash_shift_id);

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertOk()
            ->assertJsonPath('sale.id', $sale->getKey());

        $balance = InventoryBalance::query()->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('8.000', (string) $balance->quantity);
        $this->assertSame(1, Sale::query()->where('organization_id', $organization->getKey())->count());
        $this->assertSame(2, InventoryMovement::query()->where('product_id', $product->getKey())->count());
    }

    public function test_offline_sync_accepts_tax_inclusive_estimate_and_validates_it_against_server_settings(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Tax Sync Market');
        $organization->forceFill(['settings' => [
            'tax' => ['enabled' => true, 'rate_percent' => '14.00', 'mode' => 'exclusive'],
        ]])->save();
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-TAX-001', 'منتج متزامن بضريبة', '10.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $createdAt = now()->subMinute()->toIso8601String();

        $payload = $this->offlinePayload((string) Str::uuid(), $shift, $product, $createdAt, '2.000');
        $payload['expected_total'] = '18.24';
        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertOk()
            ->assertJsonPath('sale.total', '18.24');

        $stalePayload = $this->offlinePayload((string) Str::uuid(), $shift, $product, $createdAt, '1.000');
        $stalePayload['expected_total'] = '9.12'; // Valid estimate at the cached 14% rate; current 20% must be rejected.
        $organization->forceFill(['settings' => [
            'tax' => ['enabled' => true, 'rate_percent' => '20.00', 'mode' => 'exclusive'],
        ]])->save();
        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $stalePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_total');
    }

    public function test_offline_sync_rejects_reusing_operation_uuid_with_a_different_cart(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Idempotency Conflict Market');
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-UUID-001', 'منتج التكرار', '10.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $uuid = (string) Str::uuid();
        $payload = $this->offlinePayload($uuid, $shift, $product, now()->subMinute()->toIso8601String(), '2.000');

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'synced');

        // A retry with the same operation UUID is only valid when it represents the
        // exact same business operation. It must not turn a previous two-unit sale
        // into a new three-unit sale or silently bypass idempotency checks.
        $changedPayload = $payload;
        $changedPayload['items'][0]['quantity'] = '3.000';

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $changedPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_operation_uuid');

        $this->assertSame(1, Sale::query()->where('organization_id', $organization->getKey())->count());
        $balance = InventoryBalance::query()->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('8.000', (string) $balance->quantity);
    }

    public function test_offline_sync_rejects_a_product_price_that_changed_after_catalog_was_cached(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Price Conflict Market');
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-003', 'سكر', '10.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $payload = $this->offlinePayload((string) Str::uuid(), $shift, $product, now()->subMinute()->toIso8601String(), '1.000');

        $product->forceFill(['selling_price' => '9.00'])->save();

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.price_snapshot');

        $this->assertDatabaseCount('sales', 0);
        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('10.000', (string) $balance->quantity);
    }

    public function test_offline_sync_rejects_a_shift_id_that_does_not_match_the_cashier_current_shift(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Offline Shift Conflict Market');
        $product = $this->createProduct($organization, $owner, $branch, 'OFFLINE-004', 'زيت', '10.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $payload = $this->offlinePayload((string) Str::uuid(), $shift, $product, now()->subMinute()->toIso8601String(), '1.000');
        $payload['shift_id'] = $shift->getKey() + 100;

        $this->actingAs($owner)->postJson(route('pos.offline.sync'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');

        $this->assertDatabaseCount('sales', 0);
    }

    private function openShift(User $user): void
    {
        $this->actingAs($user)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])
            ->assertRedirect(route('pos.index'));
    }

    private function offlinePayload(string $uuid, CashShift $shift, Product $product, string $createdAt, string $quantity): array
    {
        return [
            'client_operation_uuid' => $uuid,
            'payment_method' => 'cash',
            'notes' => 'اختبار مزامنة دون اتصال',
            'shift_id' => (int) $shift->getKey(),
            'offline_created_at' => $createdAt,
            'items' => [[
                'product_id' => (int) $product->getKey(),
                'quantity' => $quantity,
                'price_snapshot' => (string) $product->selling_price,
            ]],
        ];
    }

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
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_main' => true,
            'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, Branch $branch, string $sku, string $name, string $stock): Product
    {
        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $unit->getKey(),
            'created_by' => $owner->getKey(),
            'sku' => $sku,
            'name' => $name,
            'purchase_price' => '5.00',
            'selling_price' => '8.00',
            'low_stock_threshold' => '1.000',
            'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $stock,
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(),
            'movement_type' => 'opening',
            'quantity_delta' => $stock,
            'balance_after' => $stock,
            'source' => 'test',
            'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        return $product;
    }
}
