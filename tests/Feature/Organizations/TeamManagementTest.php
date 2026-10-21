<?php

namespace Tests\Feature\Organizations;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_team_members_and_assignable_roles(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Team View Market');

        $this->actingAs($owner)
            ->get(route('team-members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Team/Index')
                ->where('organization.name', 'Team View Market')
                ->where('canAssignAdmin', true)
                ->has('members', 1)
                ->where('members.0.role', 'owner')
                ->has('roles', 5));
    }

    public function test_owner_can_add_existing_user_and_assign_branch_access(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Team Add Market');
        $branch = $organization->branches()->firstOrFail();
        $employee = User::factory()->create(['email' => 'cashier@example.test']);

        $this->actingAs($owner)
            ->from(route('team-members.index'))
            ->post(route('team-members.store'), [
                'email' => ' CASHIER@EXAMPLE.TEST ',
                'role' => 'cashier',
                'branch_ids' => [$branch->getKey()],
            ])
            ->assertRedirect(route('team-members.index'));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $employee->getKey(),
            'role' => 'cashier',
        ]);
        $this->assertDatabaseHas('branch_user', [
            'branch_id' => $branch->getKey(),
            'user_id' => $employee->getKey(),
        ]);
    }

    public function test_owner_can_update_member_role_and_branch_access(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Team Update Market');
        $firstBranch = $organization->branches()->firstOrFail();
        $secondBranch = $organization->branches()->create([
            'name' => 'الفرع الثاني',
            'code' => 'BR-02',
            'is_main' => false,
            'status' => 'active',
        ]);
        $employee = User::factory()->create();
        $organization->users()->attach($employee->getKey(), ['role' => 'cashier', 'is_default' => false]);
        $firstBranch->users()->attach($employee->getKey());

        $this->actingAs($owner)
            ->patch(route('team-members.update', $employee), [
                'role' => 'inventory_manager',
                'branch_ids' => [$secondBranch->getKey()],
            ])
            ->assertRedirect(route('team-members.index'));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $employee->getKey(),
            'role' => 'inventory_manager',
        ]);
        $this->assertDatabaseMissing('branch_user', [
            'branch_id' => $firstBranch->getKey(),
            'user_id' => $employee->getKey(),
        ]);
        $this->assertDatabaseHas('branch_user', [
            'branch_id' => $secondBranch->getKey(),
            'user_id' => $employee->getKey(),
        ]);
    }

    public function test_user_cannot_be_assigned_a_branch_from_another_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Local Team Market');
        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganizationFor($otherOwner, 'Foreign Team Market');
        $foreignBranch = $otherOrganization->branches()->firstOrFail();
        $employee = User::factory()->create();

        $this->actingAs($owner)
            ->from(route('team-members.index'))
            ->post(route('team-members.store'), [
                'email' => $employee->email,
                'role' => 'cashier',
                'branch_ids' => [$foreignBranch->getKey()],
            ])
            ->assertRedirect(route('team-members.index'))
            ->assertSessionHasErrors('branch_ids.0');

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $employee->getKey(),
        ]);
    }

    public function test_cashier_cannot_view_or_manage_team_members(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Restricted Team Market');
        $branch = $organization->branches()->firstOrFail();
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => false]);
        $branch->users()->attach($cashier->getKey());
        $newEmployee = User::factory()->create();

        $this->actingAs($cashier)->get(route('team-members.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('team-members.store'), [
            'email' => $newEmployee->email,
            'role' => 'cashier',
            'branch_ids' => [$branch->getKey()],
        ])->assertForbidden();
    }

    public function test_admin_cannot_assign_another_admin_role(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Admin Assignment Market');
        $branch = $organization->branches()->firstOrFail();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->getKey(), ['role' => 'admin', 'is_default' => false]);
        $newEmployee = User::factory()->create();

        $this->actingAs($admin)->post(route('team-members.store'), [
            'email' => $newEmployee->email,
            'role' => 'admin',
            'branch_ids' => [$branch->getKey()],
        ])->assertForbidden();

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $newEmployee->getKey(),
        ]);
    }

    public function test_owner_membership_cannot_be_removed_from_team_screen(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Owner Protection Market');
        $secondOwner = User::factory()->create();
        $organization->users()->attach($secondOwner->getKey(), ['role' => 'owner', 'is_default' => false]);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', $secondOwner))
            ->assertForbidden();

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $secondOwner->getKey(),
            'role' => 'owner',
        ]);
    }

    public function test_owner_can_remove_an_employee_without_deleting_the_user_account(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Team Remove Market');
        $branch = $organization->branches()->firstOrFail();
        $employee = User::factory()->create();
        $organization->users()->attach($employee->getKey(), ['role' => 'cashier', 'is_default' => false]);
        $branch->users()->attach($employee->getKey());

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', $employee))
            ->assertRedirect(route('team-members.index'));

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $employee->getKey(),
        ]);
        $this->assertDatabaseMissing('branch_user', [
            'branch_id' => $branch->getKey(),
            'user_id' => $employee->getKey(),
        ]);
        $this->assertDatabaseHas('users', ['id' => $employee->getKey()]);
    }

    private function createOrganizationFor(User $user, string $name): Organization
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_main' => true,
            'status' => 'active',
        ]);

        $organization->users()->attach($user->getKey(), [
            'role' => 'owner',
            'is_default' => true,
        ]);
        $branch->users()->attach($user->getKey());

        return $organization;
    }
}
