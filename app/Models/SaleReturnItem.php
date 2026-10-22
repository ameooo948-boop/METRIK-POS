<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'sale_return_id', 'sale_item_id', 'product_id',
        'product_sku_snapshot', 'product_name_snapshot', 'quantity', 'unit_price',
        'unit_cost_snapshot', 'line_total', 'tax_amount', 'restock',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo { return $this->belongsTo(SaleReturn::class); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function lotAllocations(): HasMany { return $this->hasMany(InventoryLotReturnAllocation::class); }
}
