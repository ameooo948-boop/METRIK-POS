<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionOrganizationForUser
{
    public function handle(User $owner, string $organizationName): Organization
    {
        return DB::transaction(function () use ($owner, $organizationName): Organization {
            $baseSlug = Str::slug($organizationName) ?: 'organization';

            $organization = Organization::query()->create([
                'name' => $organizationName,
                'slug' => $baseSlug.'-'.Str::lower(Str::random(6)),
                'status' => 'trial',
                'timezone' => 'Africa/Cairo',
                'currency' => 'EGP',
            ]);

            /** @var Branch $mainBranch */
            $mainBranch = $organization->branches()->create([
                'name' => 'الفرع الرئيسي',
                'code' => 'MAIN',
                'is_main' => true,
                'status' => 'active',
            ]);

            $organization->users()->attach($owner->getKey(), [
                'role' => OrganizationRole::OWNER->value,
                'is_default' => true,
            ]);

            $mainBranch->users()->attach($owner->getKey());

            return $organization;
        });
    }
}
