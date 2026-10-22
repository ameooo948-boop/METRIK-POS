<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'purchase_return_id', 'purchase_invoice_item_id', 'product_id',
        'product_sku_snapshot', 'product_name_snapshot', 'unit_name_snapshot', 'quantity',
        'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function purchaseReturn(): BelongsTo { return $this->belongsTo(PurchaseReturn::class); }
    public function purchaseInvoiceItem(): BelongsTo { return $this->belongsTo(PurchaseInvoiceItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
