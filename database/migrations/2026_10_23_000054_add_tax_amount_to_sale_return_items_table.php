<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_items', function (Blueprint $table): void {
            $table->decimal('tax_amount', 14, 2)->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_items', function (Blueprint $table): void {
            $table->dropColumn('tax_amount');
        });
    }
};
