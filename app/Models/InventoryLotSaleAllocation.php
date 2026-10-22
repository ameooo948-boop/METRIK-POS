<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLotSaleAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'sale_item_id', 'inventory_lot_id', 'quantity_sold', 'quantity_returned',
    ];

    protected function casts(): array
    {
        return ['quantity_sold' => 'decimal:3', 'quantity_returned' => 'decimal:3'];
    }

    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function inventoryLot(): BelongsTo { return $this->belongsTo(InventoryLot::class); }
    public function returnAllocations(): HasMany { return $this->hasMany(InventoryLotReturnAllocation::class); }
}
