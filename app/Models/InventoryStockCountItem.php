<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'inventory_stock_count_id', 'product_id', 'product_sku_snapshot',
        'product_name_snapshot', 'expected_quantity', 'counted_quantity', 'variance_quantity',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'variance_quantity' => 'decimal:3',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(InventoryStockCount::class, 'inventory_stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
