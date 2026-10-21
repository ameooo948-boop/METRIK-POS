<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            // Covers tenant + branch + paid status + reporting date range filters.
            $table->index(
                ['organization_id', 'branch_id', 'status', 'sold_at'],
                'sales_org_branch_status_sold_idx',
            );

            // Covers the recent-sales lists ordered by descending ID.
            $table->index(
                ['organization_id', 'branch_id', 'id'],
                'sales_org_branch_latest_idx',
            );
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            // Supports report joins from a sale to its payments, grouped by payment method.
            $table->index(
                ['organization_id', 'sale_id', 'payment_method'],
                'sale_payments_org_sale_method_idx',
            );
        });

        Schema::table('products', function (Blueprint $table): void {
            // Helps active product catalog queries filter by tenant/status and order by name.
            $table->index(
                ['organization_id', 'is_active', 'name', 'id'],
                'products_org_active_name_idx',
            );
        });

        Schema::table('notifications', function (Blueprint $table): void {
            // Supports each user's unread-count and newest-notification queries.
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
                'notifications_notifiable_read_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_notifiable_read_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_org_active_name_idx');
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropIndex('sale_payments_org_sale_method_idx');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_org_branch_status_sold_idx');
            $table->dropIndex('sales_org_branch_latest_idx');
        });
    }
};
