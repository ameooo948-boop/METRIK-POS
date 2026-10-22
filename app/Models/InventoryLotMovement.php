<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryLotMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'product_id', 'inventory_lot_id', 'created_by',
        'movement_type', 'quantity_delta', 'balance_after', 'reason', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:3', 'balance_after' => 'decimal:3'];
    }

    public function inventoryLot(): BelongsTo { return $this->belongsTo(InventoryLot::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reference(): MorphTo { return $this->morphTo(); }
}
