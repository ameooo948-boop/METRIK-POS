<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('count_number', 50);
            $table->uuid('client_operation_uuid');
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('completed');
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('total_variance_lines')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'count_number'], 'inv_stock_counts_org_number_unique');
            $table->unique(['organization_id', 'client_operation_uuid'], 'inv_stock_counts_org_operation_unique');
            $table->index(['organization_id', 'branch_id', 'created_at'], 'inv_stock_counts_branch_created_index');
        });

        Schema::create('inventory_stock_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_stock_count_id')->constrained('inventory_stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_sku_snapshot', 100);
            $table->string('product_name_snapshot', 255);
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3);
            $table->decimal('variance_quantity', 14, 3);
            $table->timestamps();

            $table->unique(['inventory_stock_count_id', 'product_id'], 'inv_stock_count_items_count_product_unique');
            $table->index(['organization_id', 'product_id'], 'inv_stock_count_items_org_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_count_items');
        Schema::dropIfExists('inventory_stock_counts');
    }
};
