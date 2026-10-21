<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('units')->insert([
            ['code' => 'piece', 'name_ar' => 'قطعة', 'name_en' => 'Piece', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'pack', 'name_ar' => 'عبوة', 'name_en' => 'Pack', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'carton', 'name_ar' => 'كرتونة', 'name_en' => 'Carton', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'gram', 'name_ar' => 'جرام', 'name_en' => 'Gram', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'kilogram', 'name_ar' => 'كيلوجرام', 'name_en' => 'Kilogram', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'milliliter', 'name_ar' => 'ملليلتر', 'name_en' => 'Milliliter', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'liter', 'name_ar' => 'لتر', 'name_en' => 'Liter', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
