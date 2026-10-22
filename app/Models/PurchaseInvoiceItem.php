<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'purchase_invoice_id', 'product_id', 'unit_id', 'product_sku_snapshot',
        'product_name_snapshot', 'unit_name_snapshot', 'unit_conversion_factor_snapshot',
        'quantity', 'quantity_in_base', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'quantity_in_base' => 'decimal:3',
            'unit_conversion_factor_snapshot' => 'decimal:3', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
}
