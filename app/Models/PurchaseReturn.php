<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'purchase_invoice_id', 'supplier_id', 'created_by',
        'return_number', 'client_operation_uuid', 'reason', 'status', 'total', 'returned_at',
    ];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'returned_at' => 'datetime'];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function purchaseInvoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function items(): HasMany { return $this->hasMany(PurchaseReturnItem::class); }
    public function inventoryMovements(): MorphMany { return $this->morphMany(InventoryMovement::class, 'reference'); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
