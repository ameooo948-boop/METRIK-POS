<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('name', 180);
            $table->string('phone', 40)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            // Positive balance means the customer owes the organization.
            $table->decimal('balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
