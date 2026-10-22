<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLotReturnAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'sale_return_item_id', 'inventory_lot_sale_allocation_id',
        'inventory_lot_id', 'quantity', 'restocked',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'restocked' => 'boolean'];
    }

    public function saleReturnItem(): BelongsTo { return $this->belongsTo(SaleReturnItem::class); }
    public function saleAllocation(): BelongsTo { return $this->belongsTo(InventoryLotSaleAllocation::class, 'inventory_lot_sale_allocation_id'); }
    public function inventoryLot(): BelongsTo { return $this->belongsTo(InventoryLot::class); }
}
