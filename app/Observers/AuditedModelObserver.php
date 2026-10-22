<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes tenant-scoped change records for the allow-listed business models.
 * Sensitive free-text and credential fields are never copied into before/after snapshots.
 */
class AuditedModelObserver
{
    private const SAFE_VALUE_FIELDS = [
        'name', 'code', 'sku', 'barcode', 'status', 'is_active', 'type', 'role',
        'price', 'purchase_price', 'selling_price', 'cost_price', 'factor_to_base',
        'quantity', 'qty', 'on_hand_quantity', 'available_quantity', 'reserved_quantity', 'stock_quantity', 'min_stock', 'reorder_level', 'reorder_threshold',
        'amount', 'subtotal', 'discount_total', 'tax_total', 'total', 'paid_amount', 'due_amount',
        'opening_amount', 'expected_cash', 'closing_amount', 'difference_amount',
        'debit_amount', 'credit_amount', 'balance_after', 'payment_method', 'transaction_type',
        'movement_type', 'invoice_number', 'reference_number', 'supplier_invoice_number',
        'unit_id', 'category_id', 'product_id', 'supplier_id', 'customer_id', 'branch_id',
        'cash_shift_id', 'purchase_invoice_id', 'purchase_order_id', 'sale_id', 'sale_return_id',
        'purchase_return_id', 'factor', 'discount_type', 'discount_value', 'promotion_type',
        'starts_at', 'ends_at', 'expires_at', 'expiry_date', 'received_quantity', 'ordered_quantity',
    ];

    private const SENSITIVE_FIELD_PATTERN = '/password|token|secret|credential|email|phone|address|notes|description|remember|api.?key/i';

    public function created(Model $model): void
    {
        $attributes = $model->getAttributes();
        $fields = $this->safeFieldNames(array_keys($attributes));

        $this->write($model, 'created', [], $this->safeValues($attributes), $fields);
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at'], $changes['created_at']);
        $fields = $this->safeFieldNames(array_keys($changes));

        if ($fields === []) {
            return;
        }

        $original = [];
        $current = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $changes)) {
                $original[$field] = $model->getRawOriginal($field);
                $current[$field] = $model->getAttributes()[$field] ?? null;
            }
        }

        $this->write($model, 'updated', $this->safeValues($original), $this->safeValues($current), $fields);
    }

    public function deleted(Model $model): void
    {
        $attributes = $model->getAttributes();
        $fields = $this->safeFieldNames(array_keys($attributes));

        $this->write($model, 'deleted', $this->safeValues($attributes), [], $fields);
    }

    /** @param array<string,mixed> $oldValues @param array<string,mixed> $newValues @param list<string> $fields */
    private function write(Model $model, string $event, array $oldValues, array $newValues, array $fields): void
    {
        if ($model instanceof AuditLog || $fields === []) {
            return;
        }

        $actorId = Auth::id();
        if ($actorId === null) {
            // Background jobs and seeders can opt into explicit audit writes separately.
            return;
        }

        $organizationId = $this->organizationId($model);
        if ($organizationId === null) {
            return;
        }

        $request = app()->bound('request') ? request() : null;
        $currentOrganization = $request?->attributes->get('currentOrganization');
        $currentBranch = $request?->attributes->get('currentBranch');
        $branchId = $this->numericAttribute($model, 'branch_id')
            ?? $this->relatedBranchId($model)
            ?? (($currentOrganization?->getKey() === $organizationId) ? $currentBranch?->getKey() : null);

        AuditLog::query()->create([
            'organization_id' => $organizationId,
            'branch_id' => $branchId !== null ? (int) $branchId : null,
            'actor_id' => (int) $actorId,
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'summary' => Str::limit(class_basename($model).' #'.$model->getKey().' '.$event, 250, ''),
            'changed_fields' => array_values($fields),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'request_id' => $request?->header('X-Request-ID'),
        ]);
    }

    private function organizationId(Model $model): ?int
    {
        $direct = $this->numericAttribute($model, 'organization_id');
        if ($direct !== null) {
            return $direct;
        }

        $lookup = [
            'product_id' => 'products',
            'sale_id' => 'sales',
            'sale_return_id' => 'sale_returns',
            'purchase_invoice_id' => 'purchase_invoices',
            'purchase_order_id' => 'purchase_orders',
            'purchase_return_id' => 'purchase_returns',
            'customer_id' => 'customers',
            'supplier_id' => 'suppliers',
            'cash_shift_id' => 'cash_shifts',
            'inventory_balance_id' => 'inventory_balances',
        ];

        foreach ($lookup as $foreignKey => $table) {
            $foreignId = $this->numericAttribute($model, $foreignKey);
            if ($foreignId === null) {
                continue;
            }

            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    continue;
                }
                $organizationId = DB::table($table)->where('id', $foreignId)->value('organization_id');
                if ($organizationId !== null) {
                    return (int) $organizationId;
                }
            } catch (Throwable) {
                // An unavailable optional relationship must not block the business operation.
            }
        }

        $request = app()->bound('request') ? request() : null;
        $organization = $request?->attributes->get('currentOrganization');

        return $organization?->getKey() !== null ? (int) $organization->getKey() : null;
    }

    private function relatedBranchId(Model $model): ?int
    {
        $lookup = [
            'sale_id' => 'sales',
            'sale_return_id' => 'sale_returns',
            'purchase_invoice_id' => 'purchase_invoices',
            'purchase_order_id' => 'purchase_orders',
            'purchase_return_id' => 'purchase_returns',
            'cash_shift_id' => 'cash_shifts',
        ];

        foreach ($lookup as $foreignKey => $table) {
            $foreignId = $this->numericAttribute($model, $foreignKey);
            if ($foreignId === null) {
                continue;
            }

            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    continue;
                }
                $branchId = DB::table($table)->where('id', $foreignId)->value('branch_id');
                if ($branchId !== null) {
                    return (int) $branchId;
                }
            } catch (Throwable) {
                // Ignore optional lookup errors and continue with organization-level scope.
            }
        }

        return null;
    }

    private function numericAttribute(Model $model, string $key): ?int
    {
        $value = $model->getAttribute($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param list<string> $fields @return list<string> */
    private function safeFieldNames(array $fields): array
    {
        $metadata = ['id', 'created_at', 'updated_at', 'deleted_at', 'organization_id'];

        return array_values(array_unique(array_filter($fields, fn (string $field): bool =>
            ! in_array($field, $metadata, true)
            && preg_match(self::SENSITIVE_FIELD_PATTERN, $field) !== 1
        )));
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function safeValues(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            if (! in_array((string) $key, self::SAFE_VALUE_FIELDS, true)) {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_string($value)) {
                $value = Str::limit($value, 180, '');
            } elseif (! is_null($value) && ! is_bool($value) && ! is_numeric($value)) {
                continue;
            }
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
