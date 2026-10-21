<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'cash_shift_id', 'cashier_id', 'invoice_number',
        'client_operation_uuid', 'status', 'subtotal', 'discount_total', 'tax_total', 'total', 'notes', 'sold_at', 'offline_created_at', 'offline_sync_received_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2',
            'total' => 'decimal:2', 'sold_at' => 'datetime',
            'offline_created_at' => 'datetime', 'offline_sync_received_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashShift(): BelongsTo { return $this->belongsTo(CashShift::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class); }
    public function payments(): HasMany { return $this->hasMany(SalePayment::class); }
    public function cashMovements(): HasMany { return $this->hasMany(CashMovement::class); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
