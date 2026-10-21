<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $organizations = $user->organizations()
            ->withPivot(['role', 'is_default'])
            ->orderBy('organizations.name')
            ->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('organization.setup');
        }

        $organizationId = (int) $request->session()->get('current_organization_id', 0);
        $organization = $organizations->firstWhere('id', $organizationId);

        if (! $organization) {
            $organization = $organizations->first(
                static fn ($item): bool => (bool) $item->pivot->is_default
            ) ?? $organizations->first();
        }

        $request->session()->put('current_organization_id', $organization->getKey());
        $role = (string) $organization->pivot->role;

        $branchesQuery = $organization->branches();

        if (OrganizationRole::tryFrom($role)?->canManageBranches() !== true) {
            $branchesQuery->whereHas('users', fn ($query) => $query->where('users.id', $user->getKey()));
        }

        $branches = $branchesQuery
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $branchId = (int) $request->session()->get('current_branch_id', 0);
        $currentBranch = $branches->firstWhere('id', $branchId) ?? $branches->first();

        if ($currentBranch) {
            $request->session()->put('current_branch_id', $currentBranch->getKey());
        } else {
            $request->session()->forget('current_branch_id');
        }

        $request->attributes->set('currentOrganization', $organization);
        $request->attributes->set('currentOrganizationRole', $role);
        $request->attributes->set('availableOrganizations', $organizations);
        $request->attributes->set('accessibleBranches', $branches);
        $request->attributes->set('currentBranch', $currentBranch);

        return $next($request);
    }
}
