<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $role = (string) $request->attributes->get('currentOrganizationRole');

        return Inertia::render('Dashboard', [
            'user' => $request->user()->only(['name', 'email']),
            'organization' => $organization->only(['id', 'name', 'slug', 'status', 'currency', 'timezone']),
            'organizationRole' => $role,
            'organizations' => $request->attributes->get('availableOrganizations', collect())
                ->map(fn (Organization $item): array => [
                    'id' => $item->getKey(), 'name' => $item->name, 'role' => $item->pivot->role,
                ])->values(),
            'branches' => $request->attributes->get('accessibleBranches', collect())
                ->map(fn ($branch): array => $branch->only(['id', 'name', 'code', 'is_main']))->values(),
            'currentBranch' => $request->attributes->get('currentBranch')?->only(['id', 'name', 'code']),
            'canManageBranches' => OrganizationRole::tryFrom($role)?->canManageBranches() === true,
            'canViewPurchases' => OrganizationRole::tryFrom($role)?->canViewPurchases() === true,
            'flashMessage' => $request->session()->get('success'),
        ]);
    }
}
