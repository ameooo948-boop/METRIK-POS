<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InventoryStockCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'created_by', 'count_number', 'client_operation_uuid',
        'payload_hash', 'status', 'total_lines', 'total_variance_lines', 'notes', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_lines' => 'integer',
            'total_variance_lines' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryStockCountItem::class, 'inventory_stock_count_id');
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
