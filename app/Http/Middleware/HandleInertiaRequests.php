<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** The root template that is loaded on the first page visit. */
    protected $rootView = 'app';

    /** Determine the current asset version. */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** Shared navigation metadata allows the UI to adapt to the active membership. */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'authUser' => fn (): ?array => $request->user()?->only(['id', 'name', 'email']),
            'currentOrganizationRole' => fn (): ?string => $request->attributes->get('currentOrganizationRole'),
            'currentOrganizationName' => fn (): ?string => $request->attributes->get('currentOrganization')?->name,
            'currentBranchName' => fn (): ?string => $request->attributes->get('currentBranch')?->name,
            'navCapabilities' => fn (): array => $this->navigationCapabilities($request),
            'unreadNotificationsCount' => fn (): int => $request->user()?->unreadNotifications()->count() ?? 0,
        ];
    }

    /**
     * These are presentation hints only. Every route and mutation remains protected
     * by its existing server-side middleware, policy, and authorization checks.
     *
     * @return array<string, bool>
     */
    private function navigationCapabilities(Request $request): array
    {
        $user = $request->user();
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('currentOrganization');
        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));

        if (! $user || ! $organization || ! $role) {
            return [];
        }

        return [
            'canUsePos' => $role->canUsePos(),
            'canViewSales' => $role->canViewSales(),
            'canViewCatalog' => $user->can('viewCatalog', $organization),
            'canManageCatalog' => $user->can('manageCatalog', $organization),
            'canManageInventory' => $user->can('manageInventory', $organization),
            'canViewPurchases' => $role->canViewPurchases(),
            'canManageSupplierPayments' => $role->canManageSupplierPayments(),
            'canViewCustomers' => $user->can('viewCustomers', $organization),
            'canManageExpenses' => $role->canManageExpenses(),
            'canViewReports' => $role->canViewReports(),
            'canManageBranches' => $role->canManageBranches(),
            'canManageTeam' => $user->can('manageTeam', $organization),
            'canManagePromotions' => $role->canManagePromotions(),
            'canManageTaxSettings' => in_array($role, [OrganizationRole::OWNER, OrganizationRole::ADMIN], true),
            'canViewAuditLogs' => $user->can('viewAuditLogs', $organization),
        ];
    }
}
