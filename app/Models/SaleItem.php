<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'sale_id', 'product_id', 'promotion_id', 'promotion_name_snapshot', 'unit_id', 'product_sku_snapshot', 'product_name_snapshot',
        'unit_name_snapshot', 'unit_conversion_factor_snapshot', 'quantity', 'quantity_in_base', 'unit_price',
        'unit_cost_snapshot', 'discount_amount', 'tax_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'quantity_in_base' => 'decimal:3',
            'unit_conversion_factor_snapshot' => 'decimal:3', 'unit_price' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function promotion(): BelongsTo { return $this->belongsTo(Promotion::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
    public function lotAllocations(): HasMany { return $this->hasMany(InventoryLotSaleAllocation::class); }
}
