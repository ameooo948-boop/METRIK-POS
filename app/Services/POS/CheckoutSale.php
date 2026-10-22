<?php

namespace App\Services\POS;

use App\Data\POS\CreateSaleData;
use App\Events\OperationalReportDataChanged;
use App\Enums\OrganizationRole;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\InventoryBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\InventoryLotSaleAllocation;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use App\Support\OrganizationTaxSettings;
use App\Support\ProductUnitOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutSale
{
    public function handle(CreateSaleData $data): Sale
    {
        try {
            $sale = DB::transaction(function () use ($data): Sale {
                $existing = $this->existingSale($data, lock: true);
                if ($existing) {
                    return $existing->load(['items', 'payments', 'branch', 'cashier']);
                }

                $branch = Branch::query()
                    ->where('organization_id', $data->organizationId)
                    ->whereKey($data->branchId)
                    ->where('status', 'active')
                    ->firstOrFail();

                $shift = CashShift::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $branch->getKey())
                    ->where('cashier_id', $data->cashierId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if (! $shift) {
                    throw ValidationException::withMessages([
                        ($data->expectedCashShiftId !== null ? 'shift_id' : 'items') => 'افتح وردية كاشير قبل تسجيل أي عملية بيع.',
                    ]);
                }

                if ($data->expectedCashShiftId !== null && (int) $shift->getKey() !== $data->expectedCashShiftId) {
                    throw ValidationException::withMessages([
                        'shift_id' => 'الوردية الحالية تختلف عن الوردية التي سُجلت فيها المبيعات دون اتصال. لا يمكن مزامنة العملية تلقائيًا؛ راجع مدير الفرع.',
                    ]);
                }

                if ($data->offlineCreatedAt !== null) {
                    $offlineCreatedAt = CarbonImmutable::parse($data->offlineCreatedAt);
                    $openedAt = $shift->opened_at ? CarbonImmutable::parse($shift->opened_at) : null;

                    if ($offlineCreatedAt->greaterThan(now()->addMinutes(5)) || ($openedAt && $offlineCreatedAt->lessThan($openedAt->subMinutes(2)))) {
                        throw ValidationException::withMessages([
                            'offline_created_at' => 'وقت عملية البيع خارج فترة الوردية المسموح بها. راجع الوقت والوردية قبل المزامنة.',
                        ]);
                    }
                }

                // Recheck the idempotency key after locking the shift. A retry may have
                // waited for the first request to commit while waiting on this row lock.
                $existing = $this->existingSale($data, lock: true);
                if ($existing) {
                    return $existing->load(['items', 'payments', 'branch', 'cashier']);
                }

                $customer = null;
                if ($data->customerId !== null) {
                    $customer = Customer::query()
                        ->forOrganization($data->organizationId)
                        ->active()
                        ->whereKey($data->customerId)
                        ->lockForUpdate()
                        ->first();

                    if (! $customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'العميل غير موجود أو غير نشط في المؤسسة الحالية.',
                        ]);
                    }
                }

                $isCreditSale = $data->paymentMethod === 'credit';
                if ($isCreditSale && ! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'اختيار عميل نشط مطلوب للبيع الآجل.',
                    ]);
                }

                $productIds = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) $item['product_id'],
                    $data->items,
                )));
                sort($productIds, SORT_NUMERIC);

                // Lock products in a stable order. Purchases and inventory adjustments
                // lock the same rows before changing balances, preventing overselling.
                $products = Product::query()
                    ->forOrganization($data->organizationId)
                    ->active()
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->with(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en'])
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (Product $product): int => (int) $product->getKey());

                if ($products->count() !== count($productIds)) {
                    throw ValidationException::withMessages([
                        'items' => 'يوجد منتج غير نشط أو لا يتبع المؤسسة الحالية. حدّث السلة وحاول مرة أخرى.',
                    ]);
                }

                // Final idempotency check after the product locks also serializes duplicate
                // submissions whose carts happen to overlap.
                $existing = $this->existingSale($data, lock: true);
                if ($existing) {
                    return $existing->load(['items', 'payments', 'branch', 'cashier']);
                }

                $preparedItems = [];
                $subtotalCents = 0;
                $balances = [];

                foreach ($data->items as $index => $item) {
                    /** @var Product $product */
                    $product = $products->get((int) $item['product_id']);
                    $quantityMilli = DecimalQuantity::toMilli((string) $item['quantity']);
                    $requestedUnitId = isset($item['unit_id']) && $item['unit_id'] !== '' ? (int) $item['unit_id'] : null;
                    $unit = ProductUnitOptions::resolve($product, $requestedUnitId);
                    if ($unit === null) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.unit_id' => 'وحدة القياس المختارة غير مفعلة لهذا المنتج. حدّث المنتج وحاول مرة أخرى.',
                        ]);
                    }
                    $baseQuantityMilli = intdiv(($quantityMilli * $unit['factorMilli']) + 500, 1000);
                    if ($baseQuantityMilli <= 0) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.quantity' => 'الكمية بعد تحويل وحدة القياس صغيرة جدًا أو غير صالحة.',
                        ]);
                    }
                    $unitPriceCents = DecimalMoney::toCents($unit['sellingPrice']);
                    $unitCostCents = DecimalMoney::toCents($unit['purchasePrice']);

                    if (array_key_exists('price_snapshot', $item)
                        && DecimalMoney::toCents((string) $item['price_snapshot']) !== $unitPriceCents) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.price_snapshot' => 'تغير سعر «'.$product->name.'» منذ آخر مزامنة للكتالوج. حدّث الأسعار وراجع الفاتورة قبل إعادة المزامنة.',
                        ]);
                    }

                    if ($unitPriceCents < 0 || $unitPriceCents > 999_999_999 || $unitCostCents < 0 || $unitCostCents > 999_999_999) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.product_id' => 'سعر المنتج أو تكلفته خارج النطاق المسموح به. راجع بيانات المنتج.',
                        ]);
                    }
                    $lineTotalCents = intdiv(($quantityMilli * $unitPriceCents) + 500, 1000);

                    $balance = InventoryBalance::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $branch->getKey())
                        ->where('product_id', $product->getKey())
                        ->lockForUpdate()
                        ->first();

                    $currentMilli = $balance ? DecimalQuantity::toMilli((string) $balance->quantity) : 0;
                    if ($baseQuantityMilli > $currentMilli) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.quantity' => 'الكمية المطلوبة من «'.$product->name.'» أكبر من رصيد الفرع الحالي ('.DecimalQuantity::fromMilli($currentMilli).').',
                        ]);
                    }

                    // Lot-tracked stock is sold FEFO (earliest expiry first). Legacy/untracked
                    // stock remains sellable, but expired tracked quantities are never available.
                    $lots = InventoryLot::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $branch->getKey())
                        ->where('product_id', $product->getKey())
                        ->where('quantity_remaining', '>', 0)
                        ->orderBy('expires_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $trackedMilli = 0;
                    foreach ($lots as $lot) {
                        $trackedMilli += DecimalQuantity::toMilli((string) $lot->quantity_remaining);
                    }
                    if ($trackedMilli > $currentMilli) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.quantity' => 'رصيد التشغيلات المسجل لمنتج «'.$product->name.'» أكبر من رصيد المخزون. أوقف البيع وراجع بيانات التشغيلات.',
                        ]);
                    }
                    $untrackedMilli = $currentMilli - $trackedMilli;
                    $validLots = $lots->filter(fn (InventoryLot $lot): bool => $lot->expires_at->toDateString() >= now()->toDateString())->values();
                    $validTrackedMilli = 0;
                    foreach ($validLots as $lot) {
                        $validTrackedMilli += DecimalQuantity::toMilli((string) $lot->quantity_remaining);
                    }
                    if ($baseQuantityMilli > ($untrackedMilli + $validTrackedMilli)) {
                        throw ValidationException::withMessages([
                            'items.'.$index.'.quantity' => 'الكمية المتاحة للبيع من «'.$product->name.'» غير كافية بعد استبعاد التشغيلات منتهية الصلاحية. راجع تواريخ الصلاحية والمخزون.',
                        ]);
                    }

                    $remainingToAllocate = min($baseQuantityMilli, $validTrackedMilli);
                    $lotAllocations = [];
                    foreach ($validLots as $lot) {
                        if ($remainingToAllocate <= 0) {
                            break;
                        }
                        $lotMilli = DecimalQuantity::toMilli((string) $lot->quantity_remaining);
                        $takeMilli = min($remainingToAllocate, $lotMilli);
                        if ($takeMilli > 0) {
                            $lotAllocations[] = ['model' => $lot, 'quantity_milli' => $takeMilli];
                            $remainingToAllocate -= $takeMilli;
                        }
                    }

                    $newMilli = $currentMilli - $baseQuantityMilli;
                    $balances[(int) $product->getKey()] = ['model' => $balance, 'new_milli' => $newMilli];
                    $subtotalCents += $lineTotalCents;
                    $preparedItems[] = [
                        'product' => $product,
                        'quantity_milli' => $quantityMilli,
                        'base_quantity_milli' => $baseQuantityMilli,
                        'unit' => $unit,
                        'unit_price_cents' => $unitPriceCents,
                        'unit_cost_cents' => $unitCostCents,
                        'line_total_cents' => $lineTotalCents,
                        'lot_allocations' => $lotAllocations,
                    ];
                }

                if ($subtotalCents <= 0 || $subtotalCents > 99_999_999_999_999) {
                    throw ValidationException::withMessages([
                        'items' => 'إجمالي البيع غير صالح أو أكبر من الحد المسموح به.',
                    ]);
                }

                // Offline transactions intentionally use the cached catalog price without
                // automatic promotions or manual discounts. This avoids changing the amount
                // already charged to the customer while the device was disconnected.
                $promotionQuery = Promotion::query()
                    ->forOrganization($data->organizationId)
                    ->where('is_active', true)
                    ->where(function ($query) use ($branch): void {
                        $query->whereNull('branch_id')->orWhere('branch_id', $branch->getKey());
                    })
                    ->where(function ($query): void {
                        $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($query): void {
                        $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                    })
                    ->where(function ($query) use ($data): void {
                        $query->whereNull('code');
                        if ($data->promotionCode !== null) {
                            $query->orWhereRaw('UPPER(code) = ?', [strtoupper($data->promotionCode)]);
                        }
                    })
                    ->with(['products:id,organization_id,name,sku', 'unit:id,name_ar,name_en'])
                    ->orderBy('priority')->orderBy('id');
                $promotions = $data->offlineCreatedAt === null ? $promotionQuery->get() : collect();

                $membershipRole = DB::table('organization_user')
                    ->where('organization_id', $data->organizationId)
                    ->where('user_id', $data->cashierId)
                    ->value('role');
                $manualDiscountLimit = OrganizationRole::tryFrom((string) $membershipRole)?->manualDiscountLimitPercent() ?? 0;
                $pricing = (new PromotionPricing())->apply(
                    $preparedItems,
                    $promotions,
                    $data->organizationId,
                    $data->offlineCreatedAt === null ? $data->promotionCode : null,
                    $data->offlineCreatedAt === null ? $data->manualDiscountPercent : '0.00',
                    $manualDiscountLimit,
                );
                $preparedItems = $pricing['items'];
                $subtotalCents = $pricing['subtotalCents'];
                $discountTotalCents = $pricing['discountTotalCents'];
                $preTaxTotalCents = $pricing['totalCents'];

                // Tax settings belong to the organization. Prices are tax-exclusive;
                // calculate and round tax per discounted line, then snapshot the rate
                // and total on the invoice so later settings changes never rewrite history.
                $organization = Organization::query()->findOrFail($data->organizationId);
                $taxRateBasisPoints = OrganizationTaxSettings::rateBasisPoints($organization);
                $taxTotalCents = 0;
                foreach ($preparedItems as &$preparedItem) {
                    $preparedItem['tax_cents'] = OrganizationTaxSettings::taxCents(
                        (int) $preparedItem['net_line_total_cents'],
                        $taxRateBasisPoints,
                    );
                    $taxTotalCents += $preparedItem['tax_cents'];
                }
                unset($preparedItem);
                $totalCents = $preTaxTotalCents + $taxTotalCents;

                if ($preTaxTotalCents <= 0 || $totalCents > 99_999_999_999_999) {
                    throw ValidationException::withMessages([
                        'items' => 'يجب أن يكون إجمالي الفاتورة بعد الخصومات أكبر من صفر وضمن الحد المسموح به.',
                    ]);
                }

                if ($data->expectedTotal !== null && DecimalMoney::toCents($data->expectedTotal) !== $totalCents) {
                    throw ValidationException::withMessages([
                        'expected_total' => 'تغير الإجمالي بسبب تحديث سعر أو عرض ترويجي. لم يتم تسجيل البيع؛ حدّث شاشة نقطة البيع وراجع المبلغ المطلوب من العميل.',
                    ]);
                }

                $customerBalanceAfterCents = null;
                if ($isCreditSale && $customer) {
                    $currentCustomerBalanceCents = DecimalMoney::toCents((string) $customer->balance);
                    $customerCreditLimitCents = DecimalMoney::toCents((string) $customer->credit_limit);
                    $customerBalanceAfterCents = $currentCustomerBalanceCents + $totalCents;

                    if ($customerCreditLimitCents <= 0 || $customerBalanceAfterCents > $customerCreditLimitCents) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'الفاتورة ستتجاوز الحد الائتماني المتاح للعميل. راجع رصيده وحده الائتماني.',
                        ]);
                    }
                }

                $sale = Sale::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'cash_shift_id' => $shift->getKey(),
                    'cashier_id' => $data->cashierId,
                    'customer_id' => $customer?->getKey(),
                    'invoice_number' => 'POS-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                    'client_operation_uuid' => $data->clientOperationUuid,
                    'status' => $isCreditSale ? 'due' : 'paid',
                    'subtotal' => DecimalMoney::fromCents($subtotalCents),
                    'discount_total' => DecimalMoney::fromCents($discountTotalCents),
                    'promotion_code_snapshot' => $data->offlineCreatedAt === null ? $data->promotionCode : null,
                    'manual_discount_percent' => $data->offlineCreatedAt === null ? $data->manualDiscountPercent : '0.00',
                    'tax_total' => DecimalMoney::fromCents($taxTotalCents),
                    'tax_rate_percent' => OrganizationTaxSettings::ratePercent($taxRateBasisPoints),
                    'total' => DecimalMoney::fromCents($totalCents),
                    'notes' => $data->notes,
                    'sold_at' => now(),
                    'offline_created_at' => $data->offlineCreatedAt,
                    'offline_sync_received_at' => $data->offlineCreatedAt !== null ? now() : null,
                ]);

                foreach ($preparedItems as $item) {
                    /** @var Product $product */
                    $product = $item['product'];
                    $quantity = DecimalQuantity::fromMilli($item['quantity_milli']);
                    $lineTotal = DecimalMoney::fromCents($item['line_total_cents']);
                    $unitPrice = DecimalMoney::fromCents($item['unit_price_cents']);
                    $unitCost = DecimalMoney::fromCents($item['unit_cost_cents']);

                    $saleItem = SaleItem::query()->create([
                        'organization_id' => $data->organizationId,
                        'sale_id' => $sale->getKey(),
                        'product_id' => $product->getKey(),
                        'promotion_id' => $item['promotion_id'],
                        'promotion_name_snapshot' => $item['promotion_name'],
                        'unit_id' => $item['unit']['persistedId'],
                        'product_sku_snapshot' => $product->sku,
                        'product_name_snapshot' => $product->name,
                        'unit_name_snapshot' => $item['unit']['unitName'],
                        'unit_conversion_factor_snapshot' => $item['unit']['factorToBase'],
                        'quantity' => $quantity,
                        'quantity_in_base' => DecimalQuantity::fromMilli($item['base_quantity_milli']),
                        'unit_price' => $unitPrice,
                        'unit_cost_snapshot' => $unitCost,
                        'discount_amount' => DecimalMoney::fromCents($item['discount_cents']),
                        'tax_amount' => DecimalMoney::fromCents((int) ($item['tax_cents'] ?? 0)),
                        'line_total' => DecimalMoney::fromCents($item['net_line_total_cents']),
                    ]);

                    $newMilli = $balances[(int) $product->getKey()]['new_milli'];
                    /** @var InventoryBalance|null $balance */
                    $balance = $balances[(int) $product->getKey()]['model'];
                    if ($balance) {
                        $balance->quantity = DecimalQuantity::fromMilli($newMilli);
                        $balance->save();
                    } else {
                        // A positive stock quantity cannot be sold if no balance exists, so
                        // this branch is only defensive and would still create the zero row.
                        $balance = InventoryBalance::query()->create([
                            'organization_id' => $data->organizationId,
                            'branch_id' => $branch->getKey(),
                            'product_id' => $product->getKey(),
                            'quantity' => DecimalQuantity::fromMilli($newMilli),
                        ]);
                    }

                    foreach ($item['lot_allocations'] as $allocation) {
                        /** @var InventoryLot $lot */
                        $lot = $allocation['model'];
                        $lotBalanceMilli = DecimalQuantity::toMilli((string) $lot->quantity_remaining) - $allocation['quantity_milli'];
                        $lot->quantity_remaining = DecimalQuantity::fromMilli($lotBalanceMilli);
                        $lot->save();

                        InventoryLotSaleAllocation::query()->create([
                            'organization_id' => $data->organizationId,
                            'branch_id' => $branch->getKey(),
                            'sale_item_id' => $saleItem->getKey(),
                            'inventory_lot_id' => $lot->getKey(),
                            'quantity_sold' => DecimalQuantity::fromMilli($allocation['quantity_milli']),
                            'quantity_returned' => '0.000',
                        ]);
                        InventoryLotMovement::query()->create([
                            'organization_id' => $data->organizationId,
                            'branch_id' => $branch->getKey(),
                            'product_id' => $product->getKey(),
                            'inventory_lot_id' => $lot->getKey(),
                            'created_by' => $data->cashierId,
                            'movement_type' => 'sale',
                            'quantity_delta' => DecimalQuantity::fromMilli(-$allocation['quantity_milli']),
                            'balance_after' => DecimalQuantity::fromMilli($lotBalanceMilli),
                            'reason' => 'بيع من التشغيلة عبر الفاتورة '.$sale->invoice_number,
                            'reference_type' => Sale::class,
                            'reference_id' => $sale->getKey(),
                        ]);
                    }

                    InventoryMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $branch->getKey(),
                        'product_id' => $product->getKey(),
                        'created_by' => $data->cashierId,
                        'movement_type' => 'sale',
                        'quantity_delta' => DecimalQuantity::fromMilli(-$item['base_quantity_milli']),
                        'balance_after' => DecimalQuantity::fromMilli($newMilli),
                        'source' => 'pos',
                        'reason' => 'بيع نقطة البيع '.$sale->invoice_number,
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->getKey(),
                    ]);
                }

                $total = DecimalMoney::fromCents($totalCents);
                if ($isCreditSale && $customer && $customerBalanceAfterCents !== null) {
                    CustomerAccountTransaction::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $branch->getKey(),
                        'customer_id' => $customer->getKey(),
                        'sale_id' => $sale->getKey(),
                        'created_by' => $data->cashierId,
                        'transaction_type' => 'sale',
                        'debit_amount' => $total,
                        'credit_amount' => '0.00',
                        'balance_after' => DecimalMoney::fromCents($customerBalanceAfterCents),
                        'payment_method' => 'credit',
                        'reference' => $sale->invoice_number,
                        'description' => 'فاتورة بيع آجل '.$sale->invoice_number,
                    ]);
                    $customer->forceFill(['balance' => DecimalMoney::fromCents($customerBalanceAfterCents)])->save();
                } else {
                    SalePayment::query()->create([
                        'organization_id' => $data->organizationId,
                        'sale_id' => $sale->getKey(),
                        'payment_method' => $data->paymentMethod,
                        'amount' => $total,
                    ]);
                }

                if ($data->paymentMethod === 'cash') {
                    CashMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $branch->getKey(),
                        'cash_shift_id' => $shift->getKey(),
                        'sale_id' => $sale->getKey(),
                        'created_by' => $data->cashierId,
                        'movement_type' => 'cash_sale',
                        'amount' => $total,
                        'notes' => 'تحصيل نقدي للفاتورة '.$sale->invoice_number,
                    ]);
                }

                return $sale->load(['items', 'payments', 'branch', 'cashier']);
            }, attempts: 3);

            event(new OperationalReportDataChanged($data->organizationId));

            return $sale;
        } catch (QueryException $exception) {
            // Concurrent duplicate submissions may race on the database unique key.
            $existing = $this->existingSale($data, lock: false);
            if ($existing) {
                return $existing->load(['items', 'payments', 'branch', 'cashier']);
            }

            throw $exception;
        }
    }

    private function existingSale(CreateSaleData $data, bool $lock = false): ?Sale
    {
        $query = Sale::query()
            ->where('organization_id', $data->organizationId)
            ->where('client_operation_uuid', $data->clientOperationUuid);

        if ($lock) {
            $query->lockForUpdate();
        }

        $existing = $query->with(['items', 'payments'])->first();
        if (! $existing) {
            return null;
        }

        if ((int) $existing->branch_id !== $data->branchId || (int) $existing->cashier_id !== $data->cashierId) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'معرّف عملية البيع مستخدم لعملية أخرى. أعد المحاولة بعملية جديدة.',
            ]);
        }

        if ($data->expectedCashShiftId !== null && (int) $existing->cash_shift_id !== $data->expectedCashShiftId) {
            throw ValidationException::withMessages([
                'shift_id' => 'معرّف العملية مرتبط بورديّة أخرى.',
            ]);
        }

        $requestedItems = [];
        foreach ($data->items as $item) {
            $requestedItems[(int) $item['product_id']] = [
                'quantity_milli' => DecimalQuantity::toMilli((string) $item['quantity']),
                'unit_id' => isset($item['unit_id']) && $item['unit_id'] !== '' ? (int) $item['unit_id'] : null,
            ];
        }
        ksort($requestedItems, SORT_NUMERIC);

        $savedItems = [];
        foreach ($existing->items as $item) {
            $savedItems[(int) $item->product_id] = [
                'quantity_milli' => DecimalQuantity::toMilli((string) $item->quantity),
                'unit_id' => $item->unit_id === null ? null : (int) $item->unit_id,
            ];
        }
        ksort($savedItems, SORT_NUMERIC);

        $wasCreditSale = CustomerAccountTransaction::query()
            ->where('organization_id', $data->organizationId)
            ->where('sale_id', $existing->getKey())
            ->where('transaction_type', 'sale')
            ->exists();
        $savedPaymentMethod = $wasCreditSale ? 'credit' : $existing->payments->first()?->payment_method;
        $savedPromotionCode = strtoupper(trim((string) ($existing->promotion_code_snapshot ?? '')));
        $requestedPromotionCode = $data->offlineCreatedAt !== null ? '' : strtoupper(trim((string) ($data->promotionCode ?? '')));
        $savedManualDiscount = DecimalMoney::toCents((string) ($existing->manual_discount_percent ?? '0.00'));
        $requestedManualDiscount = DecimalMoney::toCents($data->offlineCreatedAt !== null ? '0.00' : $data->manualDiscountPercent);
        $expectedTotalMismatch = $data->expectedTotal !== null
            && DecimalMoney::toCents($data->expectedTotal) !== DecimalMoney::toCents((string) $existing->total);
        if ($requestedItems !== $savedItems || $savedPaymentMethod !== $data->paymentMethod
            || (int) ($existing->customer_id ?? 0) !== (int) ($data->customerId ?? 0)
            || $savedPromotionCode !== $requestedPromotionCode
            || $savedManualDiscount !== $requestedManualDiscount
            || $expectedTotalMismatch) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'تم استخدام معرّف العملية سابقًا مع سلة أو طريقة دفع مختلفة. أعد المحاولة بمعرّف جديد.',
            ]);
        }

        return $existing;
    }
}
