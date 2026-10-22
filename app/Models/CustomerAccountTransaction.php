<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'customer_id', 'sale_id', 'created_by', 'operation_uuid',
        'transaction_type', 'debit_amount', 'credit_amount', 'balance_after', 'payment_method',
        'reference', 'description',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
