<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->string('source', 30)->default('manual');
            $table->string('reason', 500)->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'created_at']);
            $table->index(['organization_id', 'product_id', 'created_at']);
            $table->index(['movement_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
