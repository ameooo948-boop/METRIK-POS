<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('cashier_id')->constrained('customers')->nullOnDelete();
            $table->index(['organization_id', 'customer_id', 'sold_at'], 'sales_org_customer_sold_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_org_customer_sold_at_idx');
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
