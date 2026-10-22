<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('batch_code', 80);
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at');
            $table->date('received_at');
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_remaining', 12, 3);
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'product_id', 'batch_code'], 'inventory_lots_batch_unique');
            $table->index(['organization_id', 'branch_id', 'expires_at'], 'inventory_lots_expiry_lookup');
            $table->index(['organization_id', 'product_id', 'expires_at'], 'inventory_lots_product_expiry_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
