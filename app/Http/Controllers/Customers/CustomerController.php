<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CollectCustomerAccountPaymentRequest;
use App\Http\Requests\Customers\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Organization;
use App\Models\Sale;
use App\Services\Customers\CollectCustomerAccountPayment;
use App\Support\DecimalMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);

        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $customers = Customer::query()->forOrganization((int) $organization->getKey())
            ->withCount('sales')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $like = '%'.$search.'%';
                $nested->where('name', 'like', $like)->orWhere('code', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like);
            }))
            ->orderBy('name')->paginate(20)->withQueryString()
            ->through(static fn (Customer $customer): array => [
                'id' => (int) $customer->getKey(), 'code' => $customer->code,
                'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email,
                'balance' => (string) $customer->balance, 'creditLimit' => (string) $customer->credit_limit,
                'isActive' => (bool) $customer->is_active, 'salesCount' => (int) $customer->sales_count,
            ]);

        return Inertia::render('Customers/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'customers' => $customers,
            'filters' => ['search' => $search],
            'canManageCustomers' => $request->user()->can('manageCustomers', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(SaveCustomerRequest $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        $data = $request->validated();
        $openingCents = DecimalMoney::toCents((string) ($data['opening_balance'] ?? '0.00'));

        $customer = DB::transaction(function () use ($data, $organization, $branch, $request, $openingCents): Customer {
            $customer = Customer::query()->create([
                'organization_id' => (int) $organization->getKey(),
                'code' => $data['code'] ?? null,
                'name' => $data['name'], 'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'credit_limit' => (string) ($data['credit_limit'] ?? '0.00'),
                'balance' => DecimalMoney::fromCents($openingCents),
                'is_active' => true,
            ]);

            if ($openingCents > 0) {
                CustomerAccountTransaction::query()->create([
                    'organization_id' => (int) $organization->getKey(),
                    'branch_id' => $branch?->getKey(), 'customer_id' => $customer->getKey(),
                    'created_by' => $request->user()->getKey(), 'transaction_type' => 'opening_balance',
                    'debit_amount' => DecimalMoney::fromCents($openingCents), 'credit_amount' => '0.00',
                    'balance_after' => DecimalMoney::fromCents($openingCents),
                    'description' => 'الرصيد الافتتاحي عند إنشاء حساب العميل',
                ]);
            }
            return $customer;
        });

        return redirect()->route('customers.show', $customer->getKey())->with('success', 'تم إنشاء حساب العميل بنجاح.');
    }

    public function show(Request $request, int $customerId): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCustomers', $organization);

        $organizationId = (int) $organization->getKey();
        $customer = Customer::query()->forOrganization($organizationId)->findOrFail($customerId);

        $allSales = Sale::query()->forOrganization($organizationId)
            ->where('customer_id', $customer->getKey())
            ->with('branch:id,name,code')
            ->select(['id', 'organization_id', 'branch_id', 'invoice_number', 'total', 'status', 'sold_at'])
            ->withSum('payments as paid_total', 'amount')
            ->latest('id')->limit(100)->get();

        $saleRows = $allSales->map(static function (Sale $sale): array {
            $totalCents = DecimalMoney::toCents((string) $sale->total);
            $paidCents = DecimalMoney::toCents((string) ($sale->paid_total ?? '0.00'));
            $dueCents = max(0, $totalCents - $paidCents);
            return [
                'id' => (int) $sale->getKey(), 'invoiceNumber' => $sale->invoice_number,
                'branchName' => $sale->branch?->name, 'total' => (string) $sale->total,
                'paid' => DecimalMoney::fromCents(min($totalCents, $paidCents)),
                'due' => DecimalMoney::fromCents($dueCents), 'status' => $sale->status,
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i'),
            ];
        })->values();
        $openSales = Sale::query()->forOrganization($organizationId)
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', ['due', 'partially_paid'])
            ->with('branch:id,name,code')
            ->select(['id', 'organization_id', 'branch_id', 'invoice_number', 'total', 'status', 'sold_at'])
            ->withSum('payments as paid_total', 'amount')
            ->latest('id')->get();
        $openInvoices = $openSales->map(static function (Sale $sale): array {
            $totalCents = DecimalMoney::toCents((string) $sale->total);
            $paidCents = DecimalMoney::toCents((string) ($sale->paid_total ?? '0.00'));
            return [
                'id' => (int) $sale->getKey(), 'invoiceNumber' => $sale->invoice_number,
                'branchName' => $sale->branch?->name, 'total' => (string) $sale->total,
                'paid' => DecimalMoney::fromCents(min($totalCents, $paidCents)),
                'due' => DecimalMoney::fromCents(max(0, $totalCents - $paidCents)),
                'status' => $sale->status, 'soldAt' => $sale->sold_at?->format('Y-m-d H:i'),
            ];
        })->filter(static fn (array $sale): bool => (float) $sale['due'] > 0)->values();
        $openInvoiceTotalCents = $openInvoices->sum(fn (array $sale): int => DecimalMoney::toCents($sale['due']));
        $generalBalanceCents = max(0, DecimalMoney::toCents((string) $customer->balance) - $openInvoiceTotalCents);

        $transactions = CustomerAccountTransaction::query()->forOrganization($organizationId)
            ->where('customer_id', $customer->getKey())
            ->with(['createdBy:id,name', 'branch:id,name,code', 'sale:id,invoice_number'])
            ->latest('id')->limit(100)->get()
            ->map(static fn (CustomerAccountTransaction $transaction): array => [
                'id' => (int) $transaction->getKey(), 'type' => $transaction->transaction_type,
                'debit' => (string) $transaction->debit_amount, 'credit' => (string) $transaction->credit_amount,
                'balanceAfter' => (string) $transaction->balance_after, 'method' => $transaction->payment_method,
                'reference' => $transaction->reference, 'description' => $transaction->description,
                'saleNumber' => $transaction->sale?->invoice_number,
                'branchName' => $transaction->branch?->name,
                'createdBy' => $transaction->createdBy?->name,
                'createdAt' => $transaction->created_at?->format('Y-m-d H:i'),
            ])->values();

        return Inertia::render('Customers/Show', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'customer' => [
                'id' => (int) $customer->getKey(), 'code' => $customer->code, 'name' => $customer->name,
                'phone' => $customer->phone, 'email' => $customer->email, 'address' => $customer->address,
                'notes' => $customer->notes, 'balance' => (string) $customer->balance,
                'creditLimit' => (string) $customer->credit_limit, 'isActive' => (bool) $customer->is_active,
            ],
            'sales' => $saleRows,
            'openInvoices' => $openInvoices,
            'generalBalance' => DecimalMoney::fromCents($generalBalanceCents),
            'transactions' => $transactions,
            'canManageCustomers' => $request->user()->can('manageCustomers', $organization),
            'canManageCustomerAccounts' => $request->user()->can('manageCustomerAccounts', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function update(SaveCustomerRequest $request, int $customerId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $customer = Customer::query()->forOrganization((int) $organization->getKey())->findOrFail($customerId);
        $data = $request->validated();
        $customer->fill([
            'code' => $data['code'] ?? null, 'name' => $data['name'],
            'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null, 'notes' => $data['notes'] ?? null,
            'credit_limit' => (string) $data['credit_limit'],
            'is_active' => (bool) ($data['is_active'] ?? $customer->is_active),
        ])->save();

        return redirect()->route('customers.show', $customer->getKey())->with('success', 'تم تحديث بيانات العميل.');
    }

    public function collectPayment(
        CollectCustomerAccountPaymentRequest $request,
        int $customerId,
        CollectCustomerAccountPayment $collector,
    ): RedirectResponse {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        $customer = Customer::query()->forOrganization((int) $organization->getKey())->findOrFail($customerId);
        $data = $request->validated();

        $collector->handle(
            (int) $organization->getKey(), $branch?->getKey() === null ? null : (int) $branch->getKey(),
            (int) $request->user()->getKey(), (int) $customer->getKey(),
            isset($data['sale_id']) && $data['sale_id'] !== null ? (int) $data['sale_id'] : null,
            (string) $data['operation_uuid'], (string) $data['amount'], (string) $data['payment_method'],
            isset($data['reference']) ? (string) $data['reference'] : null,
        );

        return redirect()->route('customers.show', $customer->getKey())->with('success', 'تم تسجيل التحصيل وتحديث رصيد العميل.');
    }
}
