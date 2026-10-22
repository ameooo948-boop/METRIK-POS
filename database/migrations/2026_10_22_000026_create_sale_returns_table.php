<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('return_number', 64);
            $table->uuid('client_operation_uuid');
            $table->string('refund_method', 24);
            $table->string('refund_reference', 120)->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('completed');
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->unique(['organization_id', 'return_number']);
            $table->unique(['organization_id', 'client_operation_uuid']);
            $table->index(['organization_id', 'branch_id', 'returned_at']);
            $table->index(['organization_id', 'sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
