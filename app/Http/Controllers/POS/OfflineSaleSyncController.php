<?php

namespace App\Http\Controllers\POS;

use App\Data\POS\CreateSaleData;
use App\Http\Controllers\Controller;
use App\Http\Requests\POS\SyncOfflineSaleRequest;
use App\Models\Organization;
use App\Services\POS\CheckoutSale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class OfflineSaleSyncController extends Controller
{
    public function __invoke(SyncOfflineSaleRequest $request, CheckoutSale $checkoutSale): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $validated = $request->validated();
        $createdAt = CarbonImmutable::parse((string) $validated['offline_created_at']);

        $sale = $checkoutSale->handle(CreateSaleData::fromValidated(
            $validated,
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
            (int) $validated['shift_id'],
            $createdAt->toIso8601String(),
        ));

        return response()->json([
            'status' => 'synced',
            'sale' => [
                'id' => (int) $sale->getKey(),
                'invoiceNumber' => (string) $sale->invoice_number,
                'total' => (string) $sale->total,
                'soldAt' => $sale->sold_at?->toIso8601String(),
                'offlineCreatedAt' => $sale->offline_created_at?->toIso8601String(),
            ],
        ]);
    }
}
