<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) !== null;
    }

    public function manageBranches(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageBranches() === true;
    }

    public function viewTeam(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageTeam() === true;
    }

    public function manageTeam(User $user, Organization $organization): bool
    {
        return $this->viewTeam($user, $organization);
    }

    public function viewCatalog(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    public function manageCatalog(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageCatalog() === true;
    }

    public function manageInventory(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageInventory() === true;
    }

    public function viewPurchases(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canViewPurchases() === true;
    }

    public function managePurchases(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManagePurchases() === true;
    }

    public function manageSupplierPayments(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageSupplierPayments() === true;
    }

    public function managePromotions(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManagePromotions() === true;
    }

    public function usePos(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canUsePos() === true;
    }

    public function viewSales(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canViewSales() === true;
    }

    public function viewReports(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canViewReports() === true;
    }

    public function manageExpenses(User $user, Organization $organization): bool
    {
        $role = $this->roleFor($user, $organization);

        return $role !== null
            && OrganizationRole::tryFrom($role)?->canManageExpenses() === true;
    }

    public function viewCustomers(User $user, Organization $organization): bool
    {
        return in_array($this->roleFor($user, $organization), ['owner', 'admin', 'branch_manager', 'accountant'], true);
    }

    public function manageCustomers(User $user, Organization $organization): bool
    {
        return in_array($this->roleFor($user, $organization), ['owner', 'admin', 'branch_manager', 'accountant'], true);
    }

    public function manageCustomerAccounts(User $user, Organization $organization): bool
    {
        return in_array($this->roleFor($user, $organization), ['owner', 'admin', 'branch_manager', 'accountant'], true);
    }

    public function viewAuditLogs(User $user, Organization $organization): bool
    {
        // Audit history includes financial and permission changes, so keep it to organization owners/admins.
        return in_array($this->roleFor($user, $organization), ['owner', 'admin'], true);
    }

    private function roleFor(User $user, Organization $organization): ?string
    {
        $membership = $user->organizations()
            ->whereKey($organization->getKey())
            ->first();

        return $membership?->pivot?->role;
    }
}
