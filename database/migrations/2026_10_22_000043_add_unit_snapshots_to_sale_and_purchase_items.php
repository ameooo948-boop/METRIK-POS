<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('unit_conversion_factor_snapshot', 14, 3)->default(1.000);
            $table->decimal('quantity_in_base', 14, 3)->nullable();
        });
        DB::table('sale_items')->whereNull('quantity_in_base')->update([
            'quantity_in_base' => DB::raw('quantity'),
        ]);

        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('unit_conversion_factor_snapshot', 14, 3)->default(1.000);
            $table->decimal('quantity_in_base', 14, 3)->nullable();
        });
        DB::table('purchase_invoice_items')->whereNull('quantity_in_base')->update([
            'quantity_in_base' => DB::raw('quantity'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['unit_id', 'unit_conversion_factor_snapshot', 'quantity_in_base']);
        });
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['unit_id', 'unit_conversion_factor_snapshot', 'quantity_in_base']);
        });
    }
};
