<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_type', 30);
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();

            // Keep explicit index names under MySQL's 64-character identifier limit.
            $table->index(
                ['organization_id', 'supplier_id', 'created_at'],
                'sup_tx_org_supplier_created_idx'
            );
            $table->index(
                ['organization_id', 'branch_id', 'created_at'],
                'sup_tx_org_branch_created_idx'
            );
            $table->index(
                ['transaction_type', 'created_at'],
                'sup_tx_type_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_transactions');
    }
};
