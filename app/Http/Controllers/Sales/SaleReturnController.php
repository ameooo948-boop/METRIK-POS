<?php

namespace App\Http\Controllers\Sales;

use App\Data\Sales\CreateSaleReturnData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Models\Organization;
use App\Services\Sales\CreateSaleReturn;
use Illuminate\Http\RedirectResponse;

class SaleReturnController extends Controller
{
    public function store(StoreSaleReturnRequest $request, CreateSaleReturn $createSaleReturn, int $saleId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $saleReturn = $createSaleReturn->handle(CreateSaleReturnData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            $saleId,
            (int) $request->user()->getKey(),
        ));

        return redirect()->route('sales.show', $saleId)
            ->with('success', 'تم تسجيل المرتجع رقم '.$saleReturn->return_number.' بقيمة '.$saleReturn->total.' ج.م.');
    }
}
