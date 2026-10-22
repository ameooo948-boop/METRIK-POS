<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. A previous failed migration may have
        // created the table before failing on one of its generated index names.
        // Preserve the table and its data; add only indexes that are missing.
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('return_number', 64);
                $table->uuid('client_operation_uuid');
                $table->string('reason', 500);
                $table->string('status', 20)->default('completed');
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamp('returned_at');
                $table->timestamps();
            });
        }

        // Explicit short names avoid MySQL's 64-character identifier limit.
        // Column-based detection also recognizes the longer indexes generated
        // by the original migration if some of them were already created.
        $this->ensureIndex(
            ['organization_id', 'return_number'],
            'pr_org_number_uq',
            'unique',
        );
        $this->ensureIndex(
            ['organization_id', 'client_operation_uuid'],
            'pr_org_client_uuid_uq',
            'unique',
        );
        $this->ensureIndex(
            ['organization_id', 'branch_id', 'returned_at'],
            'pr_org_branch_returned_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'purchase_invoice_id', 'status'],
            'pr_org_invoice_status_idx',
        );
        $this->ensureIndex(
            ['organization_id', 'supplier_id', 'returned_at'],
            'pr_org_supplier_returned_idx',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }

    private function ensureIndex(array $columns, string $name, ?string $type = null): void
    {
        if (Schema::hasIndex('purchase_returns', $columns, $type)) {
            return;
        }

        Schema::table('purchase_returns', function (Blueprint $table) use ($columns, $name, $type): void {
            if ($type === 'unique') {
                $table->unique($columns, $name);

                return;
            }

            $table->index($columns, $name);
        });
    }
};
