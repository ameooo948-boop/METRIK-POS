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

    private function roleFor(User $user, Organization $organization): ?string
    {
        $membership = $user->organizations()
            ->whereKey($organization->getKey())
            ->first();

        return $membership?->pivot?->role;
    }
}
