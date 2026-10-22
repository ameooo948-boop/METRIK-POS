<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignId('sale_return_id')->nullable()->after('sale_id')
                ->constrained('sale_returns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropForeign(['sale_return_id']);
            $table->dropColumn('sale_return_id');
        });
    }
};
