<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'is_active')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('name');
                $table->index(['organization_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'is_active')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropIndex(['organization_id', 'is_active']);
                $table->dropColumn('is_active');
            });
        }
    }
};
