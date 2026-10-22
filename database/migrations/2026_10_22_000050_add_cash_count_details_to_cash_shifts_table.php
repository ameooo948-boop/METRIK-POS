<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->json('cash_count_details')->nullable();
            $table->string('closing_notes', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->dropColumn(['cash_count_details', 'closing_notes']);
        });
    }
};
