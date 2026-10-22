<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'purchase_order_id', 'product_id', 'unit_id',
        'product_sku_snapshot', 'product_name_snapshot', 'unit_name_snapshot',
        'unit_conversion_factor_snapshot', 'quantity_ordered', 'quantity_received',
        'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_conversion_factor_snapshot' => 'decimal:3',
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
