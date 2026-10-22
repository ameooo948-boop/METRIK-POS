<?php

namespace App\Http\Controllers\POS;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Organization;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashShiftAuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRead($request);
        [$from, $to, $status] = $this->filters($request);
        $query = $this->baseQuery($request);
        $this->applyFilters($query, $from, $to, $status);

        $summaryQuery = clone $query;
        $total = (clone $summaryQuery)->count();
        $open = (clone $summaryQuery)->where('status', 'open')->count();
        $closed = (clone $summaryQuery)->where('status', 'closed')->count();

        $shifts = $query->with(['cashier:id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->orderByDesc('opened_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $shifts->through(fn (CashShift $shift): array => $this->shiftRow($shift));

        return Inertia::render('POS/ShiftHistory', [
            'organization' => $request->attributes->get('currentOrganization')->only(['id', 'name', 'currency']),
            'currentBranch' => $request->attributes->get('currentBranch')->only(['id', 'name', 'code']),
            'shifts' => $shifts,
            'filters' => ['from' => $from, 'to' => $to, 'status' => $status],
            'summary' => ['total' => $total, 'open' => $open, 'closed' => $closed],
        ]);
    }

    public function report(Request $request, int $shiftId): Response
    {
        $this->authorizeRead($request);
        $shift = $this->baseQuery($request)
            ->with(['cashier:id,name', 'openedBy:id,name', 'closedBy:id,name', 'branch:id,name,code'])
            ->whereKey($shiftId)->firstOrFail();

        $movements = CashMovement::query()
            ->where('organization_id', $shift->organization_id)
            ->where('branch_id', $shift->branch_id)
            ->where('cash_shift_id', $shift->getKey())
            ->with(['sale:id,invoice_number', 'saleReturn:id,return_number', 'supplierPayment:id,payment_number', 'createdBy:id,name'])
            ->orderBy('created_at')->orderBy('id')->get();

        $cashSales = 0;
        $cashIn = 0;
        $cashOut = 0;
        $cashReturns = 0;
        $movementRows = $movements->map(function (CashMovement $movement) use (&$cashSales, &$cashIn, &$cashOut, &$cashReturns): array {
            $cents = DecimalMoney::toCents((string) $movement->amount);
            match ($movement->movement_type) {
                'cash_sale' => $cashSales += $cents,
                'cash_in' => $cashIn += $cents,
                'cash_out' => $cashOut -= $cents,
                'cash_return' => $cashReturns -= $cents,
                default => null,
            };
            $effectCents = match ($movement->movement_type) {
                'cash_sale', 'cash_in' => $cents,
                'cash_out', 'cash_return' => -$cents,
                default => 0,
            };

            return [
                'id' => (int) $movement->getKey(),
                'date' => $movement->created_at?->format('Y-m-d H:i:s'),
                'type' => (string) $movement->movement_type,
                'typeLabel' => $this->movementLabel((string) $movement->movement_type),
                'amount' => (string) $movement->amount,
                'cashEffect' => DecimalMoney::fromCents($effectCents),
                'notes' => $movement->notes,
                'reference' => $movement->sale?->invoice_number
                    ?? $movement->saleReturn?->return_number
                    ?? $movement->supplierPayment?->payment_number,
                'createdBy' => $movement->createdBy?->name,
            ];
        })->values()->all();

        $computedExpectedCents = DecimalMoney::toCents((string) $shift->opening_amount) + $cashSales + $cashIn + $cashOut + $cashReturns;
        $storedExpected = $shift->expected_cash !== null ? (string) $shift->expected_cash : null;
        $closingCents = $shift->closing_amount !== null ? DecimalMoney::toCents((string) $shift->closing_amount) : null;
        $storedDifference = $shift->difference_amount !== null ? (string) $shift->difference_amount : null;

        return Inertia::render('POS/ShiftReport', [
            'organization' => $request->attributes->get('currentOrganization')->only(['id', 'name', 'currency']),
            'currentBranch' => $request->attributes->get('currentBranch')->only(['id', 'name', 'code']),
            'shift' => [
                'id' => (int) $shift->getKey(),
                'status' => (string) $shift->status,
                'cashierName' => $shift->cashier?->name,
                'openedBy' => $shift->openedBy?->name,
                'closedBy' => $shift->closedBy?->name,
                'openedAt' => $shift->opened_at?->format('Y-m-d H:i:s'),
                'closedAt' => $shift->closed_at?->format('Y-m-d H:i:s'),
                'openingAmount' => (string) $shift->opening_amount,
                'storedExpectedCash' => $storedExpected,
                'calculatedExpectedCash' => DecimalMoney::fromCents($computedExpectedCents),
                'closingAmount' => $shift->closing_amount !== null ? (string) $shift->closing_amount : null,
                'differenceAmount' => $storedDifference,
                'recalculatedDifference' => $closingCents === null ? null : DecimalMoney::fromCents($closingCents - $computedExpectedCents),
                'cashCountDetails' => $shift->cash_count_details ?? [],
                'closingNotes' => $shift->closing_notes,
                'branchName' => $shift->branch?->name,
                'branchCode' => $shift->branch?->code,
            ],
            'summary' => [
                'cashSales' => DecimalMoney::fromCents($cashSales),
                'cashIn' => DecimalMoney::fromCents($cashIn),
                'cashOut' => DecimalMoney::fromCents(-$cashOut),
                'cashReturns' => DecimalMoney::fromCents(-$cashReturns),
                'movementCount' => count($movementRows),
            ],
            'movements' => $movementRows,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeRead($request);
        [$from, $to, $status] = $this->filters($request);
        $query = $this->baseQuery($request);
        $this->applyFilters($query, $from, $to, $status);
        $filename = 'metrik-cash-shifts-'.$from.'-'.$to.'.csv';

        return response()->streamDownload(function () use ($query): void {
            echo "\xEF\xBB\xBF";
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Shift ID', 'Branch', 'Cashier', 'Status', 'Opened At', 'Closed At', 'Opening Cash', 'Expected Cash', 'Actual Cash', 'Difference', 'Closing Notes']);
            foreach ((clone $query)->with(['cashier:id,name', 'branch:id,name'])->orderBy('opened_at')->orderBy('id')->cursor() as $shift) {
                $expected = $shift->expected_cash !== null ? (string) $shift->expected_cash : $shift->expectedCashAmount();
                foreach ([
                    (int) $shift->getKey(), $shift->branch?->name, $shift->cashier?->name,
                    $shift->status, $shift->opened_at?->format('Y-m-d H:i:s'), $shift->closed_at?->format('Y-m-d H:i:s'),
                    (string) $shift->opening_amount, $expected,
                    $shift->closing_amount !== null ? (string) $shift->closing_amount : '',
                    $shift->difference_amount !== null ? (string) $shift->difference_amount : '',
                    $shift->closing_notes,
                ] as $value) {
                    $cell = (string) ($value ?? '');
                    if (!is_numeric($value) && preg_match('/^[=+\-@\t\r]/u', $cell) === 1) $cell = "'".$cell;
                    $row[] = $cell;
                }
                fputcsv($stream, $row ?? []);
                unset($row);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeRead(Request $request): void
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        abort_unless($organization instanceof Organization, 403);
        abort_unless($request->user()?->can('usePos', $organization) || $request->user()?->can('viewReports', $organization), 403);
        abort_if($request->attributes->get('currentBranch') === null, 404);
    }

    private function baseQuery(Request $request): Builder
    {
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));

        return CashShift::query()->forOrganization((int) $organization->getKey())
            ->where('branch_id', (int) $branch->getKey())
            ->when($role === OrganizationRole::CASHIER, fn (Builder $query) => $query->where('cashier_id', (int) $request->user()->getKey()));
    }

    /** @return array{string,string,?string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:open,closed'],
        ]);
        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'تاريخ النهاية يجب أن يكون مساويًا لتاريخ البداية أو بعده.']);
        }
        return [$from, $to, $validated['status'] ?? null];
    }

    private function applyFilters(Builder $query, string $from, string $to, ?string $status): void
    {
        $query->whereDate('opened_at', '>=', $from)->whereDate('opened_at', '<=', $to)
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status));
    }

    /** @return array<string,mixed> */
    private function shiftRow(CashShift $shift): array
    {
        return [
            'id' => (int) $shift->getKey(), 'status' => (string) $shift->status,
            'cashierName' => $shift->cashier?->name, 'openedBy' => $shift->openedBy?->name,
            'closedBy' => $shift->closedBy?->name,
            'openedAt' => $shift->opened_at?->format('Y-m-d H:i:s'), 'closedAt' => $shift->closed_at?->format('Y-m-d H:i:s'),
            'openingAmount' => (string) $shift->opening_amount,
            'expectedCash' => $shift->expected_cash !== null ? (string) $shift->expected_cash : $shift->expectedCashAmount(),
            'closingAmount' => $shift->closing_amount !== null ? (string) $shift->closing_amount : null,
            'differenceAmount' => $shift->difference_amount !== null ? (string) $shift->difference_amount : null,
        ];
    }

    private function movementLabel(string $type): string
    {
        return match ($type) {
            'cash_sale' => 'مبيعات نقدية', 'cash_in' => 'قبض نقدي',
            'cash_out' => 'صرف نقدي', 'cash_return' => 'رد نقدي لمرتجع',
            default => $type,
        };
    }
}
