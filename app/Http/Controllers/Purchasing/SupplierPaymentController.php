<?php

namespace App\Http\Controllers\Purchasing;

use App\Data\Purchasing\RecordSupplierPaymentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierPaymentRequest;
use App\Models\CashShift;
use App\Models\Organization;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Services\Purchasing\RecordSupplierPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierPaymentController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        if (! $branch) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $canManageSupplierPayments = $request->user()->can('manageSupplierPayments', $organization);
        $dueInvoices = PurchaseInvoice::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('status', 'received')
            ->where('due_amount', '>', 0)
            ->with('supplier:id,name,code')
            ->orderBy('received_at')
            ->limit(100)
            ->get(['id', 'organization_id', 'branch_id', 'supplier_id', 'invoice_number', 'total', 'paid_amount', 'returned_amount', 'due_amount', 'received_at'])
            ->map(static fn (PurchaseInvoice $invoice): array => [
                'id' => $invoice->getKey(),
                'invoiceNumber' => $invoice->invoice_number,
                'supplier' => $invoice->supplier?->only(['id', 'name', 'code']),
                'total' => $invoice->total,
                'paidAmount' => $invoice->paid_amount,
                'returnedAmount' => $invoice->returned_amount ?? '0.00',
                'dueAmount' => $invoice->due_amount,
                'receivedAt' => $invoice->received_at?->format('Y-m-d H:i'),
            ])->values();

        $openCashShifts = $canManageSupplierPayments
            ? CashShift::query()->forOrganization((int) $organization->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('status', 'open')
                ->with('cashier:id,name')
                ->orderBy('opened_at')
                ->get(['id', 'organization_id', 'branch_id', 'cashier_id', 'opened_at'])
                ->map(static fn (CashShift $shift): array => [
                    'id' => $shift->getKey(),
                    'cashierName' => $shift->cashier?->name ?? 'كاشير',
                    'openedAt' => $shift->opened_at?->format('Y-m-d H:i'),
                ])->values()
            : collect();

        $payments = SupplierPayment::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->with(['supplier:id,name', 'purchaseInvoice:id,invoice_number', 'createdBy:id,name'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (SupplierPayment $payment): array => [
                'id' => $payment->getKey(),
                'paymentNumber' => $payment->payment_number,
                'supplier' => $payment->supplier?->only(['id', 'name']),
                'invoiceNumber' => $payment->purchaseInvoice?->invoice_number,
                'createdBy' => $payment->createdBy?->only(['id', 'name']),
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
                'referenceNumber' => $payment->reference_number,
                'paidAt' => $payment->paid_at?->format('Y-m-d H:i'),
            ])->values();

        return Inertia::render('SupplierPayments/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'dueInvoices' => $dueInvoices,
            'openCashShifts' => $openCashShifts,
            'payments' => $payments,
            'canManageSupplierPayments' => $canManageSupplierPayments,
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request, RecordSupplierPayment $recordPayment): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        abort_if(! $branch, 404);

        $payment = $recordPayment->handle(RecordSupplierPaymentData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        ));

        return redirect()->route('supplier-payments.index')
            ->with('success', 'تم تسجيل دفعة المورد '.$payment->payment_number.' بقيمة '.$payment->amount.' ج.م.');
    }
}
