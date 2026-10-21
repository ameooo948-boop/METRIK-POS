<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 80);
            $table->string('description', 180);
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 24);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'expense_date']);
            $table->index(['organization_id', 'category', 'expense_date']);
            $table->index(['cash_shift_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
