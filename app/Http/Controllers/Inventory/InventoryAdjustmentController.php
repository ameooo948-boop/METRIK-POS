<?php

namespace App\Http\Controllers\Inventory;

use App\Data\Inventory\StockAdjustmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Models\Organization;
use App\Services\Inventory\AdjustStock;
use Illuminate\Http\RedirectResponse;

class InventoryAdjustmentController extends Controller
{
    public function store(AdjustStockRequest $request, AdjustStock $adjustStock): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $data = StockAdjustmentData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        );

        $adjustStock->handle($data);

        return redirect()->route('products.index')->with('success', 'تم تسجيل حركة المخزون بنجاح.');
    }
}
