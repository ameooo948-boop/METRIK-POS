<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'branch_id', 'cash_shift_id', 'sale_id', 'sale_return_id', 'supplier_payment_id', 'created_by', 'movement_type', 'amount', 'notes',
    ];

    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function shift(): BelongsTo { return $this->belongsTo(CashShift::class, 'cash_shift_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function saleReturn(): BelongsTo { return $this->belongsTo(SaleReturn::class); }
    public function supplierPayment(): BelongsTo { return $this->belongsTo(SupplierPayment::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
