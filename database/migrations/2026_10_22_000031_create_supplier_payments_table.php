<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. If an earlier attempt created the
        // table but failed while adding an index, keep the existing table and
        // safely create only the indexes that are still missing.
        if (! Schema::hasTable('supplier_payments')) {
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
            });
        }

        // Use explicit short names to stay within MySQL's 64-character limit.
        // hasIndex() checks by column list and type, so it also recognizes indexes
        // created with Laravel's previous automatically-generated names.
        $this->ensureIndex(
            ['organization_id', 'payment_number'],
            'sup_pay_org_number_uq',
            'unique',
        );
        $this->ensureIndex(
            ['organization_id', 'client_operation_uuid'],
            'sup_pay_org_client_uuid_uq',
            'unique',
        );
        $this->ensureIndex(
            ['organization_id', 'branch_id', 'paid_at'],
            'sup_pay_org_branch_paid_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'supplier_id', 'paid_at'],
            'sup_pay_org_supplier_paid_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'purchase_invoice_id', 'paid_at'],
            'sup_pay_org_invoice_paid_idx',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }

    private function ensureIndex(array $columns, string $name, ?string $type = null): void
    {
        if (Schema::hasIndex('supplier_payments', $columns, $type)) {
            return;
        }

        Schema::table('supplier_payments', function (Blueprint $table) use ($columns, $name, $type): void {
            if ($type === 'unique') {
                $table->unique($columns, $name);

                return;
            }

            $table->index($columns, $name);
        });
    }
};
