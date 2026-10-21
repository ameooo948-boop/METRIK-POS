<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'created_by', 'cash_shift_id', 'category',
        'description', 'amount', 'payment_method', 'expense_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date:Y-m-d',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function cashShift(): BelongsTo { return $this->belongsTo(CashShift::class); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function setCategoryAttribute(string $value): void
    {
        $this->attributes['category'] = trim($value);
    }

    public function setDescriptionAttribute(string $value): void
    {
        $this->attributes['description'] = trim($value);
    }
}
