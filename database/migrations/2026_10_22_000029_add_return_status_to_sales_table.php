<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('return_status', 24)->default('none')->after('status');
            $table->index(['organization_id', 'return_status', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'return_status', 'sold_at']);
            $table->dropColumn('return_status');
        });
    }
};
