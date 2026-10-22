<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function view(User $user, Sale $sale): bool
    {
        $membership = $user->organizations()->whereKey($sale->organization_id)->first();
        if (! $membership) {
            return false;
        }

        $role = OrganizationRole::tryFrom((string) $membership->pivot->role);
        if (! $role?->canViewSales()) {
            return false;
        }

        if (in_array($role, [OrganizationRole::OWNER, OrganizationRole::ADMIN], true)) {
            return true;
        }

        $hasBranchAccess = $user->branches()->whereKey($sale->branch_id)->exists();
        if (! $hasBranchAccess) {
            return false;
        }

        if ($role === OrganizationRole::CASHIER) {
            return (int) $sale->cashier_id === (int) $user->getKey();
        }

        return in_array($role, [OrganizationRole::BRANCH_MANAGER, OrganizationRole::ACCOUNTANT], true);
    }

    public function createReturn(User $user, Sale $sale): bool
    {
        $membership = $user->organizations()->whereKey($sale->organization_id)->first();
        if (! $membership) {
            return false;
        }

        $role = OrganizationRole::tryFrom((string) $membership->pivot->role);
        if (! $role) {
            return false;
        }

        if (in_array($role, [OrganizationRole::OWNER, OrganizationRole::ADMIN], true)) {
            return true;
        }

        if (! $user->branches()->whereKey($sale->branch_id)->exists()) {
            return false;
        }

        if ($role === OrganizationRole::BRANCH_MANAGER) {
            return true;
        }

        // Cashiers may return only the sale they processed. Accountants have read-only access.
        return $role === OrganizationRole::CASHIER
            && (int) $sale->cashier_id === (int) $user->getKey();
    }

}
