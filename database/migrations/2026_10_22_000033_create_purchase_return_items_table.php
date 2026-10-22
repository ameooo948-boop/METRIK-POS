<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. If a previous attempt created this
        // table before failing to add an automatically named index, preserve
        // the existing table and add only indexes that are still missing.
        if (! Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
                $table->foreignId('purchase_invoice_item_id')->constrained('purchase_invoice_items')->restrictOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('product_sku_snapshot', 64);
                $table->string('product_name_snapshot', 180);
                $table->string('unit_name_snapshot', 80)->default('وحدة أساسية');
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 12, 2);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();
            });
        }

        // Explicit short names remain under MySQL's 64-character identifier
        // limit. hasIndex() detects an already-created index by its columns,
        // even if an earlier migration attempt used Laravel's generated name.
        $this->ensureIndex(
            ['organization_id', 'purchase_invoice_item_id'],
            'pri_org_invoice_item_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'purchase_return_id'],
            'pri_org_return_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'product_id'],
            'pri_org_product_idx',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }

    private function ensureIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex('purchase_return_items', $columns)) {
            return;
        }

        Schema::table('purchase_return_items', function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }
};
