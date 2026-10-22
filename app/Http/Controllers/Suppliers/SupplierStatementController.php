<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierStatementController extends Controller
{
    public function index(Request $request, int $supplierId): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $supplier = Supplier::query()->forOrganization((int) $organization->getKey())->findOrFail($supplierId);
        [$from, $to] = $this->dateRange($request);

        $base = $this->baseQuery((int) $organization->getKey(), (int) $supplier->getKey());
        $period = $this->periodQuery(clone $base, $from, $to);
        $totals = $this->aggregate(clone $period);
        $opening = $this->movementBalance($this->aggregate((clone $base)->whereDate('created_at', '<', $from)));
        $current = $this->movementBalance($this->aggregate(clone $base));

        $entries = (clone $period)
            ->with(['branch:id,name,code', 'createdBy:id,name'])
            ->orderBy('created_at')->orderBy('id')
            ->paginate(50)->withQueryString();

        $first = $entries->getCollection()->first();
        if ($first) {
            $balanceCents = $this->movementBalance($this->aggregate(
                (clone $base)->where(function ($query) use ($first): void {
                    $query->where('created_at', '<', $first->created_at)
                        ->orWhere(function ($sameTimestamp) use ($first): void {
                            $sameTimestamp->where('created_at', $first->created_at)->where('id', '<', $first->getKey());
                        });
                }),
            ));
        } else {
            $balanceCents = $opening;
        }

        $entries->setCollection($entries->getCollection()->map(function (SupplierTransaction $transaction) use (&$balanceCents): array {
            $debit = DecimalMoney::toCents((string) $transaction->debit);
            $credit = DecimalMoney::toCents((string) $transaction->credit);
            $balanceCents += $credit - $debit;

            return [
                'id' => (int) $transaction->getKey(),
                'date' => $transaction->created_at?->format('Y-m-d H:i:s'),
                'type' => $transaction->transaction_type,
                'typeLabel' => $this->typeLabel($transaction->transaction_type),
                'description' => $transaction->notes,
                'referenceType' => $transaction->reference_type,
                'referenceId' => $transaction->reference_id === null ? null : (int) $transaction->reference_id,
                'branchName' => $transaction->branch?->name,
                'createdBy' => $transaction->createdBy?->name,
                'debit' => DecimalMoney::fromCents($debit),
                'credit' => DecimalMoney::fromCents($credit),
                'balanceAfter' => DecimalMoney::fromCents($balanceCents),
            ];
        }));

        $debitTotal = DecimalMoney::toCents((string) ($totals->debit_total ?? '0.00'));
        $creditTotal = DecimalMoney::toCents((string) ($totals->credit_total ?? '0.00'));

        return Inertia::render('Suppliers/Statement', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'supplier' => [
                'id' => (int) $supplier->getKey(), 'code' => $supplier->code, 'name' => $supplier->name,
                'phone' => $supplier->phone, 'email' => $supplier->email,
                'currentBalance' => DecimalMoney::fromCents($current),
            ],
            'statement' => $entries,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'openingBalance' => DecimalMoney::fromCents($opening),
                'debitTotal' => DecimalMoney::fromCents($debitTotal),
                'creditTotal' => DecimalMoney::fromCents($creditTotal),
                'closingBalance' => DecimalMoney::fromCents($opening + $creditTotal - $debitTotal),
                'currentBalance' => DecimalMoney::fromCents($current),
                'transactionCount' => (int) ((clone $period)->count()),
            ],
        ]);
    }

    public function export(Request $request, int $supplierId)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $supplier = Supplier::query()->forOrganization((int) $organization->getKey())->findOrFail($supplierId);
        [$from, $to] = $this->dateRange($request);
        $base = $this->baseQuery((int) $organization->getKey(), (int) $supplier->getKey());
        $opening = $this->movementBalance($this->aggregate((clone $base)->whereDate('created_at', '<', $from)));
        $filename = 'supplier-statement-'.$supplier->getKey().'-'.$from.'-'.$to.'.csv';

        return response()->streamDownload(function () use ($base, $from, $to, $opening): void {
            echo "\xEF\xBB\xBF";
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', 'Type', 'Description', 'Reference Type', 'Reference ID', 'Branch ID', 'Debit', 'Credit', 'Balance']);
            $balance = $opening;
            foreach ($this->periodQuery(clone $base, $from, $to)->orderBy('created_at')->orderBy('id')->cursor() as $entry) {
                $debit = DecimalMoney::toCents((string) $entry->debit);
                $credit = DecimalMoney::toCents((string) $entry->credit);
                $balance += $credit - $debit;
                fputcsv($stream, array_map($this->safeCsvCell(...), [
                    $entry->created_at?->format('Y-m-d H:i:s'), $this->typeLabel($entry->transaction_type),
                    $entry->notes, $entry->reference_type, $entry->reference_id, $entry->branch_id,
                    DecimalMoney::fromCents($debit), DecimalMoney::fromCents($credit), DecimalMoney::fromCents($balance),
                ]));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'تاريخ النهاية يجب أن يكون مساويًا لتاريخ البداية أو بعده.']);
        }
        return [$from, $to];
    }

    private function baseQuery(int $organizationId, int $supplierId): Builder
    {
        return SupplierTransaction::query()->where('organization_id', $organizationId)->where('supplier_id', $supplierId);
    }

    private function periodQuery(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
    }

    private function aggregate(Builder $query): object
    {
        return $query->selectRaw('COALESCE(SUM(debit), 0) AS debit_total, COALESCE(SUM(credit), 0) AS credit_total')->first();
    }

    private function movementBalance(object $totals): int
    {
        return DecimalMoney::toCents((string) ($totals->credit_total ?? '0.00'))
            - DecimalMoney::toCents((string) ($totals->debit_total ?? '0.00'));
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'purchase_invoice' => 'فاتورة شراء',
            'payment' => 'دفعة للمورد',
            'purchase_return' => 'مرتجع مشتريات',
            'adjustment' => 'تسوية حساب',
            'opening_balance' => 'رصيد افتتاحي',
            default => $type,
        };
    }

    private function safeCsvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if (is_numeric($value)) {
            return $text;
        }
        return preg_match('/^[=+\-@\t\r]/u', $text) === 1 ? "'".$text : $text;
    }
}
