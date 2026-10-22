<?php

namespace App\Models;

use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'cashier_id', 'opened_by', 'closed_by', 'status',
        'opening_amount', 'expected_cash', 'closing_amount', 'difference_amount', 'cash_count_details', 'closing_notes', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'cash_count_details' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'cashier_id'); }
    public function openedBy(): BelongsTo { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function movements(): HasMany { return $this->hasMany(CashMovement::class); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function expectedCashCents(): int
    {
        $movementTotal = $this->movements()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN movement_type IN (?, ?) THEN amount WHEN movement_type IN (?, ?) THEN -amount ELSE 0 END), 0) AS movement_total",
                ['cash_sale', 'cash_in', 'cash_out', 'cash_return'],
            )
            ->first()?->movement_total ?? '0.00';

        return DecimalMoney::toCents((string) $this->opening_amount)
            + DecimalMoney::toCents((string) ($movementTotal ?? '0.00'));
    }

    public function expectedCashAmount(): string
    {
        return DecimalMoney::fromCents($this->expectedCashCents());
    }
}
