<?php

namespace App\Http\Controllers\POS;

use App\Data\POS\CloseCashShiftData;
use App\Data\POS\OpenCashShiftData;
use App\Http\Controllers\Controller;
use App\Http\Requests\POS\CloseCashShiftRequest;
use App\Http\Requests\POS\OpenCashShiftRequest;
use App\Models\Organization;
use App\Services\POS\CloseCashShift;
use App\Services\POS\OpenCashShift;
use Illuminate\Http\RedirectResponse;

class CashShiftController extends Controller
{
    public function open(OpenCashShiftRequest $request, OpenCashShift $openCashShift): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $shift = $openCashShift->handle(new OpenCashShiftData(
            organizationId: (int) $organization->getKey(),
            branchId: (int) $branch->getKey(),
            cashierId: (int) $request->user()->getKey(),
            openingAmount: (string) $request->validated('opening_amount'),
        ));

        return redirect()->route('pos.index')->with('success', 'تم فتح الوردية رقم '.$shift->getKey().' بنجاح.');
    }

    public function close(
        CloseCashShiftRequest $request,
        CloseCashShift $closeCashShift,
        int $shiftId,
    ): RedirectResponse {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $shift = $closeCashShift->handle(new CloseCashShiftData(
            organizationId: (int) $organization->getKey(),
            branchId: (int) $branch->getKey(),
            cashierId: (int) $request->user()->getKey(),
            shiftId: $shiftId,
            closingAmount: (string) $request->validated('closing_amount'),
        ));

        $difference = (string) $shift->difference_amount;

        return redirect()->route('pos.index')->with(
            'success',
            'تم إغلاق الوردية. النقدية المتوقعة '.$shift->expected_cash.' ج.م، والفرق '.$difference.' ج.م.'
        );
    }
}
