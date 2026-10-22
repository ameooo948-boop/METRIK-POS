<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the migration safe to resume after a partial MySQL DDL failure.
     * MySQL commits CREATE TABLE before adding foreign keys; a failed foreign
     * key can therefore leave an existing table while Laravel has not recorded
     * this migration as complete.
     */
    public function up(): void
    {
        $this->createSaleAllocationsTableIfMissing();
        $this->createReturnAllocationsTableIfMissing();
        $this->createLotMovementsTableIfMissing();

        // Reconcile constraints for tables created during an earlier failed run.
        // Existing correct constraints are kept; only missing ones are added.
        $this->ensureForeignKey('inventory_lot_sale_allocations', 'organization_id', 'organizations', 'lot_sale_org_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_sale_allocations', 'branch_id', 'branches', 'lot_sale_branch_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_sale_allocations', 'sale_item_id', 'sale_items', 'lot_sale_item_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_sale_allocations', 'inventory_lot_id', 'inventory_lots', 'lot_sale_lot_fk', 'restrict');

        $this->ensureForeignKey('inventory_lot_return_allocations', 'organization_id', 'organizations', 'lot_return_org_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_return_allocations', 'branch_id', 'branches', 'lot_return_branch_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_return_allocations', 'sale_return_item_id', 'sale_return_items', 'lot_return_item_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_return_allocations', 'inventory_lot_sale_allocation_id', 'inventory_lot_sale_allocations', 'lot_return_sale_alloc_fk', 'restrict');
        $this->ensureForeignKey('inventory_lot_return_allocations', 'inventory_lot_id', 'inventory_lots', 'lot_return_lot_fk', 'restrict');

        $this->ensureForeignKey('inventory_lot_movements', 'organization_id', 'organizations', 'lot_movement_org_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_movements', 'branch_id', 'branches', 'lot_movement_branch_fk', 'cascade');
        $this->ensureForeignKey('inventory_lot_movements', 'product_id', 'products', 'lot_movement_product_fk', 'restrict');
        $this->ensureForeignKey('inventory_lot_movements', 'inventory_lot_id', 'inventory_lots', 'lot_movement_lot_fk', 'restrict');
        $this->ensureForeignKey('inventory_lot_movements', 'created_by', 'users', 'lot_movement_creator_fk', 'null');

        $this->seedOpeningLotMovementsIdempotently();
    }

    private function createSaleAllocationsTableIfMissing(): void
    {
        if (Schema::hasTable('inventory_lot_sale_allocations')) {
            return;
        }

        Schema::create('inventory_lot_sale_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('inventory_lot_id');
            $table->decimal('quantity_sold', 14, 3);
            $table->decimal('quantity_returned', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'sale_item_id', 'inventory_lot_id'], 'lot_sale_allocations_item_lot_unique');
            $table->index(['organization_id', 'inventory_lot_id'], 'lot_sale_allocations_lot_lookup');

            $table->foreign('organization_id', 'lot_sale_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id', 'lot_sale_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('sale_item_id', 'lot_sale_item_fk')->references('id')->on('sale_items')->cascadeOnDelete();
            $table->foreign('inventory_lot_id', 'lot_sale_lot_fk')->references('id')->on('inventory_lots')->restrictOnDelete();
        });
    }

    private function createReturnAllocationsTableIfMissing(): void
    {
        if (Schema::hasTable('inventory_lot_return_allocations')) {
            return;
        }

        Schema::create('inventory_lot_return_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sale_return_item_id');
            $table->unsignedBigInteger('inventory_lot_sale_allocation_id');
            $table->unsignedBigInteger('inventory_lot_id');
            $table->decimal('quantity', 14, 3);
            $table->boolean('restocked')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'sale_return_item_id', 'inventory_lot_sale_allocation_id'], 'lot_return_allocations_item_allocation_unique');
            $table->index(['organization_id', 'inventory_lot_id'], 'lot_return_allocations_lot_lookup');

            $table->foreign('organization_id', 'lot_return_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id', 'lot_return_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('sale_return_item_id', 'lot_return_item_fk')->references('id')->on('sale_return_items')->cascadeOnDelete();
            // Keep this identifier short enough for MySQL's 64-character limit.
            $table->foreign('inventory_lot_sale_allocation_id', 'lot_return_sale_alloc_fk')
                ->references('id')->on('inventory_lot_sale_allocations')->restrictOnDelete();
            $table->foreign('inventory_lot_id', 'lot_return_lot_fk')->references('id')->on('inventory_lots')->restrictOnDelete();
        });
    }

    private function createLotMovementsTableIfMissing(): void
    {
        if (Schema::hasTable('inventory_lot_movements')) {
            return;
        }

        Schema::create('inventory_lot_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('inventory_lot_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('movement_type', 40);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->string('reason', 240)->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'inventory_lot_id', 'created_at'], 'lot_movements_history_lookup');

            $table->foreign('organization_id', 'lot_movement_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id', 'lot_movement_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('product_id', 'lot_movement_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('inventory_lot_id', 'lot_movement_lot_fk')->references('id')->on('inventory_lots')->restrictOnDelete();
            $table->foreign('created_by', 'lot_movement_creator_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    /** Add a missing FK only; never drop/recreate an existing one. */
    private function ensureForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $constraintName,
        string $onDelete
    ): void {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            throw new RuntimeException("Cannot reconcile foreign key {$constraintName}: {$tableName}.{$column} is missing.");
        }

        if (! Schema::hasTable($referencedTable) || ! Schema::hasColumn($referencedTable, 'id')) {
            throw new RuntimeException("Cannot reconcile foreign key {$constraintName}: referenced table {$referencedTable}.id is missing.");
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            $columns = array_map('strtolower', (array) ($foreignKey['columns'] ?? []));
            if ($columns !== [strtolower($column)]) {
                continue;
            }

            $foreignTable = strtolower((string) ($foreignKey['foreign_table'] ?? ''));
            $foreignColumns = array_map('strtolower', (array) ($foreignKey['foreign_columns'] ?? []));
            if ($foreignTable === strtolower($referencedTable) && $foreignColumns === ['id']) {
                return;
            }

            throw new RuntimeException(
                "Cannot reconcile {$tableName}.{$column}: an existing foreign key points to "
                . ($foreignTable !== '' ? $foreignTable : 'an unknown table')
                . ' instead of ' . $referencedTable . '. Review the schema before retrying.'
            );
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $constraintName, $onDelete): void {
            $foreign = $table->foreign($column, $constraintName)->references('id')->on($referencedTable);

            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    /**
     * Seed opening lot movements once per lot. If a previous interrupted run
     * already inserted some rows, those rows are preserved and not duplicated.
     */
    private function seedOpeningLotMovementsIdempotently(): void
    {
        DB::table('inventory_lots')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($lots): void {
                $lotIds = $lots->pluck('id')->all();
                $existingLotIds = DB::table('inventory_lot_movements')
                    ->where('movement_type', 'opening_balance')
                    ->whereIn('inventory_lot_id', $lotIds)
                    ->pluck('inventory_lot_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $existingLotIds = array_fill_keys($existingLotIds, true);

                $now = now();
                $rows = [];
                foreach ($lots as $lot) {
                    if (isset($existingLotIds[(int) $lot->id])) {
                        continue;
                    }

                    $rows[] = [
                        'organization_id' => $lot->organization_id,
                        'branch_id' => $lot->branch_id,
                        'product_id' => $lot->product_id,
                        'inventory_lot_id' => $lot->id,
                        'created_by' => $lot->created_by,
                        'movement_type' => 'opening_balance',
                        'quantity_delta' => $lot->quantity_remaining,
                        'balance_after' => $lot->quantity_remaining,
                        'reason' => 'رصيد افتتاحي لسجل التشغيلات عند تطبيق المرحلة 41',
                        'reference_type' => null,
                        'reference_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('inventory_lot_movements')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lot_movements');
        Schema::dropIfExists('inventory_lot_return_allocations');
        Schema::dropIfExists('inventory_lot_sale_allocations');
    }
};
