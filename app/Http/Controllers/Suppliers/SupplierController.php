<?php

namespace App\Http\Controllers\Suppliers;

use App\Data\Suppliers\CreateSupplierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Models\Organization;
use App\Models\Supplier;
use App\Services\Suppliers\CreateSupplier;
use App\Support\DecimalMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $suppliers = Supplier::query()
            ->forOrganization((int) $organization->getKey())
            ->withSum('transactions as total_credit', 'credit')
            ->withSum('transactions as total_debit', 'debit')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(static function (Supplier $supplier): array {
                $credit = DecimalMoney::toCents((string) ($supplier->total_credit ?? '0'));
                $debit = DecimalMoney::toCents((string) ($supplier->total_debit ?? '0'));

                return [
                    'id' => $supplier->getKey(),
                    'name' => $supplier->name,
                    'code' => $supplier->code,
                    'phone' => $supplier->phone,
                    'email' => $supplier->email,
                    'address' => $supplier->address,
                    'isActive' => $supplier->is_active,
                    'balance' => DecimalMoney::fromCents($credit - $debit),
                ];
            });

        return Inertia::render('Suppliers/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'suppliers' => $suppliers,
            'canManagePurchases' => $request->user()->can('managePurchases', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StoreSupplierRequest $request, CreateSupplier $createSupplier): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        $createSupplier->handle(CreateSupplierData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
        ));

        return redirect()->route('suppliers.index')->with('success', 'تمت إضافة المورد بنجاح.');
    }
}
