<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'product_id', 'created_by', 'batch_code',
        'manufactured_at', 'expires_at', 'received_at', 'quantity_received', 'quantity_remaining',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date', 'expires_at' => 'date', 'received_at' => 'date',
            'quantity_received' => 'decimal:3', 'quantity_remaining' => 'decimal:3',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
