<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transfer_number', 60);
            $table->string('client_operation_uuid', 36);
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('completed');
            $table->unsignedSmallInteger('total_lines')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'transfer_number'], 'stock_transfers_org_number_unique');
            $table->unique(['organization_id', 'client_operation_uuid'], 'stock_transfers_org_operation_unique');
            $table->index(['organization_id', 'source_branch_id', 'created_at'], 'stock_transfers_source_history_index');
            $table->index(['organization_id', 'destination_branch_id', 'created_at'], 'stock_transfers_destination_history_index');
            $table->index(['organization_id', 'status', 'created_at'], 'stock_transfers_status_history_index');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_sku_snapshot', 64);
            $table->string('product_name_snapshot', 180);
            $table->decimal('quantity', 14, 3);
            $table->decimal('source_balance_after', 14, 3);
            $table->decimal('destination_balance_after', 14, 3);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id'], 'stock_transfer_items_product_unique');
            $table->index(['organization_id', 'product_id'], 'stock_transfer_items_org_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
