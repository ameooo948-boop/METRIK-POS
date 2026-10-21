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

    private function roleFor(User $user, Organization $organization): ?string
    {
        $membership = $user->organizations()
            ->whereKey($organization->getKey())
            ->first();

        return $membership?->pivot?->role;
    }
}
