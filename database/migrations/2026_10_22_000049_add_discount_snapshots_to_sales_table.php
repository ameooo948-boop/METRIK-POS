<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('promotion_code_snapshot', 40)->nullable();
            $table->decimal('manual_discount_percent', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['promotion_code_snapshot', 'manual_discount_percent']);
        });
    }
};
