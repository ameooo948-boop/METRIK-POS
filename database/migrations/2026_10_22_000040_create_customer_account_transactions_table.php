<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('operation_uuid')->nullable();
            $table->string('transaction_type', 24);
            $table->decimal('debit_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 2)->default(0);
            $table->string('payment_method', 24)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'operation_uuid'], 'customer_transactions_org_operation_uq');
            $table->index(['organization_id', 'customer_id', 'created_at'], 'customer_transactions_org_customer_created_idx');
            $table->index(['organization_id', 'sale_id', 'transaction_type'], 'customer_transactions_org_sale_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_transactions');
    }
};
