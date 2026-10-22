<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_balances', 'reorder_threshold')) {
            Schema::table('inventory_balances', function (Blueprint $table): void {
                $table->decimal('reorder_threshold', 14, 3)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_balances', 'reorder_threshold')) {
            Schema::table('inventory_balances', function (Blueprint $table): void {
                $table->dropColumn('reorder_threshold');
            });
        }
    }
};
