<?php

namespace App\Http\Controllers\Promotions;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StorePromotionRequest;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\ProductUnitOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PromotionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('managePromotions', $organization);
        $branch = $request->attributes->get('currentBranch');
        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));

        $promotions = Promotion::query()
            ->forOrganization((int) $organization->getKey())
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id');
                if ($branch !== null) {
                    $query->orWhere('branch_id', $branch->getKey());
                }
            })
            ->with([
                'branch:id,name,code',
                'unit:id,name_ar,name_en',
                'products:id,organization_id,name,sku',
            ])
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(function (Promotion $promotion) use ($role, $branch): array {
                return [
                    'id' => (int) $promotion->getKey(),
                    'name' => $promotion->name,
                    'code' => $promotion->code,
                    'type' => $promotion->type,
                    'typeLabel' => match ($promotion->type) {
                        'buy_x_get_y' => 'اشترِ واحصل على مجاني',
                        'product_percentage' => 'خصم على منتج',
                        'bundle_percentage' => 'خصم مجموعة منتجات',
                        default => $promotion->type,
                    },
                    'productIds' => $promotion->products->pluck('id')->map(static fn ($id): int => (int) $id)->values(),
                    'productNames' => $promotion->products->pluck('name')->values(),
                    'unitName' => $promotion->unit?->name_ar ?? $promotion->unit?->name_en,
                    'unitId' => $promotion->unit_id ? (int) $promotion->unit_id : null,
                    'buyQuantity' => $promotion->buy_quantity,
                    'freeQuantity' => $promotion->free_quantity,
                    'discountPercent' => $promotion->discount_percent,
                    'priority' => (int) $promotion->priority,
                    'isActive' => (bool) $promotion->is_active,
                    'startsAt' => $promotion->starts_at?->format('Y-m-d H:i'),
                    'endsAt' => $promotion->ends_at?->format('Y-m-d H:i'),
                    'branchName' => $promotion->branch?->name ?? 'كل الفروع',
                    'canToggle' => $role !== OrganizationRole::BRANCH_MANAGER
                        || ($branch !== null && (int) $promotion->branch_id === (int) $branch->getKey()),
                ];
            })->values();

        $products = Product::query()
            ->forOrganization((int) $organization->getKey())->active()
            ->with(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en'])
            ->orderBy('name')->limit(2000)->get()
            ->map(static fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'baseUnitId' => (int) $product->base_unit_id,
                'units' => ProductUnitOptions::options($product),
            ])->values();

        return Inertia::render('Promotions/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch?->only(['id', 'name', 'code']),
            'branches' => $organization->branches()->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'code'])->map(static fn ($item): array => [
                    'id' => (int) $item->getKey(), 'name' => $item->name, 'code' => $item->code,
                ])->values(),
            'products' => $products,
            'promotions' => $promotions,
            'canUseGlobalScope' => in_array($role, [OrganizationRole::OWNER, OrganizationRole::ADMIN], true),
            'isBranchManager' => $role === OrganizationRole::BRANCH_MANAGER,
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function store(StorePromotionRequest $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));
        $data = $request->validated();
        $productIds = array_map('intval', $data['product_ids']);
        unset($data['product_ids']);

        if ($role === OrganizationRole::BRANCH_MANAGER) {
            abort_if($branch === null, 403);
            if (! empty($data['branch_id']) && (int) $data['branch_id'] !== (int) $branch->getKey()) {
                abort(403, 'مدير الفرع يستطيع إنشاء عروض لفرعه الحالي فقط.');
            }
            $data['branch_id'] = (int) $branch->getKey();
        }

        $data['organization_id'] = (int) $organization->getKey();
        $data['is_active'] = true;
        $data['priority'] = (int) ($data['priority'] ?? 100);
        $data['starts_at'] = $data['starts_at'] ?? null;
        $data['ends_at'] = $data['ends_at'] ?? null;

        DB::transaction(function () use ($data, $productIds): void {
            $promotion = Promotion::query()->create($data);
            $promotion->products()->sync($productIds);
        });

        return redirect()->route('promotions.index')->with('success', 'تم إنشاء العرض. سيطبَّق تلقائيًا عند تحقق الشروط، ويمكن إيقافه من القائمة.');
    }

    public function toggle(Request $request, int $promotionId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('managePromotions', $organization);
        $branch = $request->attributes->get('currentBranch');
        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));

        $promotion = Promotion::query()->forOrganization((int) $organization->getKey())->whereKey($promotionId)->firstOrFail();
        if ($role === OrganizationRole::BRANCH_MANAGER
            && ($branch === null || (int) $promotion->branch_id !== (int) $branch->getKey())) {
            abort(403, 'لا يمكن لمدير الفرع تغيير حالة عرض عام أو عرض لفرع آخر.');
        }

        $promotion->forceFill(['is_active' => ! $promotion->is_active])->save();

        return redirect()->route('promotions.index')->with('success', $promotion->is_active ? 'تم تفعيل العرض.' : 'تم إيقاف العرض.');
    }
}
