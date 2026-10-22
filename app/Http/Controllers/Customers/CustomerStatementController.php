<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Organization;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerStatementController extends Controller
{
    public function index(Request $request, int $customerId): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);
        $customer = Customer::query()->forOrganization((int) $organization->getKey())->findOrFail($customerId);
        [$from, $to] = $this->dateRange($request);

        $base = $this->baseQuery((int) $organization->getKey(), (int) $customer->getKey());
        $period = $this->periodQuery(clone $base, $from, $to);
        $totals = $this->aggregate(clone $period);
        $opening = $this->movementBalance(
            $this->aggregate((clone $base)->whereDate('created_at', '<', $from)),
        );
        $current = $this->movementBalance($this->aggregate(clone $base));

        $entries = (clone $period)
            ->with(['branch:id,name,code', 'createdBy:id,name', 'sale:id,invoice_number'])
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

        $entries->setCollection($entries->getCollection()->map(function (CustomerAccountTransaction $transaction) use (&$balanceCents): array {
            $debit = DecimalMoney::toCents((string) $transaction->debit_amount);
            $credit = DecimalMoney::toCents((string) $transaction->credit_amount);
            $balanceCents += $debit - $credit;

            return [
                'id' => (int) $transaction->getKey(),
                'date' => $transaction->created_at?->format('Y-m-d H:i:s'),
                'type' => $transaction->transaction_type,
                'typeLabel' => $this->typeLabel($transaction->transaction_type),
                'description' => $transaction->description ?: $transaction->reference ?: ($transaction->sale?->invoice_number ? 'فاتورة '.$transaction->sale->invoice_number : null),
                'reference' => $transaction->reference,
                'saleId' => $transaction->sale_id === null ? null : (int) $transaction->sale_id,
                'saleNumber' => $transaction->sale?->invoice_number,
                'branchName' => $transaction->branch?->name,
                'createdBy' => $transaction->createdBy?->name,
                'debit' => DecimalMoney::fromCents($debit),
                'credit' => DecimalMoney::fromCents($credit),
                'balanceAfter' => DecimalMoney::fromCents($balanceCents),
            ];
        }));

        $debitTotal = DecimalMoney::toCents((string) ($totals->debit_total ?? '0.00'));
        $creditTotal = DecimalMoney::toCents((string) ($totals->credit_total ?? '0.00'));

        return Inertia::render('Customers/Statement', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'customer' => [
                'id' => (int) $customer->getKey(), 'code' => $customer->code, 'name' => $customer->name,
                'phone' => $customer->phone, 'email' => $customer->email,
                'currentBalance' => DecimalMoney::fromCents($current),
            ],
            'statement' => $entries,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'openingBalance' => DecimalMoney::fromCents($opening),
                'debitTotal' => DecimalMoney::fromCents($debitTotal),
                'creditTotal' => DecimalMoney::fromCents($creditTotal),
                'closingBalance' => DecimalMoney::fromCents($opening + $debitTotal - $creditTotal),
                'currentBalance' => DecimalMoney::fromCents($current),
                'transactionCount' => (int) ((clone $period)->count()),
            ],
        ]);
    }

    public function export(Request $request, int $customerId)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);
        $customer = Customer::query()->forOrganization((int) $organization->getKey())->findOrFail($customerId);
        [$from, $to] = $this->dateRange($request);
        $base = $this->baseQuery((int) $organization->getKey(), (int) $customer->getKey());
        $opening = $this->movementBalance($this->aggregate((clone $base)->whereDate('created_at', '<', $from)));
        $filename = 'customer-statement-'.$customer->getKey().'-'.$from.'-'.$to.'.csv';

        return response()->streamDownload(function () use ($base, $from, $to, $opening): void {
            echo "\xEF\xBB\xBF";
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', 'Type', 'Description', 'Reference', 'Branch ID', 'Debit', 'Credit', 'Balance']);
            $balance = $opening;
            foreach ($this->periodQuery(clone $base, $from, $to)->orderBy('created_at')->orderBy('id')->cursor() as $entry) {
                $debit = DecimalMoney::toCents((string) $entry->debit_amount);
                $credit = DecimalMoney::toCents((string) $entry->credit_amount);
                $balance += $debit - $credit;
                fputcsv($stream, array_map($this->safeCsvCell(...), [
                    $entry->created_at?->format('Y-m-d H:i:s'), $this->typeLabel($entry->transaction_type),
                    $entry->description, $entry->reference, $entry->branch_id,
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

    private function baseQuery(int $organizationId, int $customerId): Builder
    {
        return CustomerAccountTransaction::query()->forOrganization($organizationId)->where('customer_id', $customerId);
    }

    private function periodQuery(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
    }

    private function aggregate(Builder $query): object
    {
        return $query->selectRaw('COALESCE(SUM(debit_amount), 0) AS debit_total, COALESCE(SUM(credit_amount), 0) AS credit_total')->first();
    }

    private function movementBalance(object $totals): int
    {
        return DecimalMoney::toCents((string) ($totals->debit_total ?? '0.00'))
            - DecimalMoney::toCents((string) ($totals->credit_total ?? '0.00'));
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'opening_balance' => 'رصيد افتتاحي',
            'sale' => 'بيع آجل',
            'payment' => 'تحصيل',
            'adjustment' => 'تسوية حساب',
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
