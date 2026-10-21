<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('offline_created_at')->nullable()->after('sold_at');
            $table->timestamp('offline_sync_received_at')->nullable()->after('offline_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['offline_created_at', 'offline_sync_received_at']);
        });
    }
};
