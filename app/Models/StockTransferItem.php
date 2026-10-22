<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'stock_transfer_id', 'product_id', 'product_sku_snapshot',
        'product_name_snapshot', 'quantity', 'source_balance_after', 'destination_balance_after',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'source_balance_after' => 'decimal:3',
            'destination_balance_after' => 'decimal:3',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
