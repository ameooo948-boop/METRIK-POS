<?php

namespace App\Http\Controllers\POS;

use App\Data\POS\CreateSaleData;
use App\Http\Controllers\Controller;
use App\Http\Requests\POS\StoreSaleRequest;
use App\Models\Organization;
use App\Services\POS\CheckoutSale;
use Illuminate\Http\RedirectResponse;

class SaleCheckoutController extends Controller
{
    public function store(StoreSaleRequest $request, CheckoutSale $checkoutSale): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $sale = $checkoutSale->handle(CreateSaleData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        ));

        return redirect()->route('sales.show', $sale->getKey())->with('success', 'تم تسجيل البيع بنجاح. رقم الفاتورة '.$sale->invoice_number);
    }
}
