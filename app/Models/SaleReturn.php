<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'sale_id', 'cash_shift_id', 'cashier_id',
        'return_number', 'client_operation_uuid', 'refund_method', 'refund_reference',
        'reason', 'status', 'total', 'tax_total', 'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function cashShift(): BelongsTo { return $this->belongsTo(CashShift::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items(): HasMany { return $this->hasMany(SaleReturnItem::class); }
    public function inventoryMovements(): MorphMany { return $this->morphMany(InventoryMovement::class, 'reference'); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
