<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->after('product_id')->constrained('promotions')->nullOnDelete();
            $table->string('promotion_name_snapshot', 120)->nullable()->after('product_name_snapshot');
            $table->index(['organization_id', 'promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'promotion_id']);
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('promotion_name_snapshot');
        });
    }
};
