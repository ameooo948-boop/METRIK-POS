<?php

namespace App\Http\Controllers\Branches;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreBranchRequest;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $role = (string) $request->attributes->get('currentOrganizationRole');

        $this->authorize('view', $organization);

        return Inertia::render('Branches/Index', [
            'organization' => $organization->only(['id', 'name', 'slug', 'currency']),
            'branches' => $request->attributes->get('accessibleBranches', collect())
                ->map(fn (Branch $branch): array => $branch->only([
                    'id', 'name', 'code', 'phone', 'address', 'is_main', 'status',
                ]))->values(),
            'currentBranch' => $request->attributes->get('currentBranch')?->only(['id', 'name', 'code']),
            'canManageBranches' => OrganizationRole::tryFrom($role)?->canManageBranches() === true,
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageBranches', $organization);

        $organization->branches()->create([
            ...$request->validated(),
            'is_main' => false,
            'status' => 'active',
        ]);

        return redirect()->route('branches.index')->with('success', 'تم إنشاء الفرع بنجاح.');
    }

    public function switch(Request $request, Branch $branch): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('view', $organization);

        abort_unless((int) $branch->organization_id === (int) $organization->getKey(), 404);

        $role = (string) $request->attributes->get('currentOrganizationRole');
        $hasAccess = OrganizationRole::tryFrom($role)?->canManageBranches() === true
            || $branch->users()->whereKey($request->user()->getKey())->exists();

        abort_unless($hasAccess, 403);

        $request->session()->put('current_branch_id', $branch->getKey());

        return redirect()->route('dashboard')->with('success', 'تم تغيير الفرع الحالي.');
    }
}
