<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id', 'code', 'name', 'phone', 'email', 'address', 'notes',
        'credit_limit', 'balance', 'is_active',
    ];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => trim($value));
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null ? null : strtoupper(trim($value)));
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function accountTransactions(): HasMany { return $this->hasMany(CustomerAccountTransaction::class); }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
