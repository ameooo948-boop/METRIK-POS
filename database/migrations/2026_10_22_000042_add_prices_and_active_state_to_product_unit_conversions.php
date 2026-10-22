<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table): void {
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('selling_price', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table): void {
            $table->dropColumn(['purchase_price', 'selling_price', 'is_active']);
        });
    }
};
