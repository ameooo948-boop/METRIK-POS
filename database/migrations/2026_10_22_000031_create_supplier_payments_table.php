<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_number', 64);
            $table->uuid('client_operation_uuid');
            $table->string('payment_method', 24);
            $table->string('reference_number', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('notes', 500)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['organization_id', 'payment_number']);
            $table->unique(['organization_id', 'client_operation_uuid']);
            $table->index(['organization_id', 'branch_id', 'paid_at']);
            $table->index(['organization_id', 'supplier_id', 'paid_at']);
            $table->index(['organization_id', 'purchase_invoice_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
