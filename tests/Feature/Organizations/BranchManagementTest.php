<?php

namespace Tests\Feature\Organizations;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_branch_management_page(): void
    {
        $user = User::factory()->create();
        $this->createOrganizationFor($user, 'Main Market');

        $this->actingAs($user)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Branches/Index')
                ->where('organization.name', 'Main Market')
                ->where('canManageBranches', true)
                ->has('branches', 1));
    }

    public function test_owner_can_create_a_branch(): void
    {
        $user = User::factory()->create();
        $organization = $this->createOrganizationFor($user, 'Branch Market');

        $this->actingAs($user)
            ->post(route('branches.store'), [
                'name' => 'فرع الجامعة',
                'code' => 'uni-02',
                'phone' => '01000000000',
                'address' => 'المنصورة',
            ])
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', [
            'organization_id' => $organization->getKey(),
            'name' => 'فرع الجامعة',
            'code' => 'UNI-02',
            'is_main' => false,
        ]);
    }

    public function test_cashier_cannot_create_a_branch(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganizationFor($owner, 'Permission Market');
        $branch = $organization->branches()->firstOrFail();
        $cashier = User::factory()->create();

        $organization->users()->attach($cashier->getKey(), [
            'role' => 'cashier',
            'is_default' => true,
        ]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)
            ->post(route('branches.store'), [
                'name' => 'غير مصرح',
                'code' => 'NO-01',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('branches', [
            'organization_id' => $organization->getKey(),
            'code' => 'NO-01',
        ]);
    }

    public function test_user_cannot_switch_to_an_organization_they_do_not_belong_to(): void
    {
        $user = User::factory()->create();
        $this->createOrganizationFor($user, 'My Market');
        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganizationFor($otherOwner, 'Other Market');

        $this->actingAs($user)
            ->post(route('organizations.switch', $otherOrganization))
            ->assertForbidden();
    }

    public function test_user_cannot_switch_to_a_branch_from_another_organization(): void
    {
        $user = User::factory()->create();
        $this->createOrganizationFor($user, 'Current Market');
        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganizationFor($otherOwner, 'Foreign Market');
        $foreignBranch = $otherOrganization->branches()->firstOrFail();

        $this->actingAs($user)
            ->post(route('branches.switch', $foreignBranch))
            ->assertNotFound();
    }

    public function test_owner_can_switch_to_another_branch_in_their_organization(): void
    {
        $user = User::factory()->create();
        $organization = $this->createOrganizationFor($user, 'Switch Market');
        $branch = $organization->branches()->create([
            'name' => 'الفرع الثاني',
            'code' => 'BR-02',
            'is_main' => false,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('branches.switch', $branch))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_branch_id', $branch->getKey());
    }

    public function test_branch_code_must_be_unique_within_the_organization(): void
    {
        $user = User::factory()->create();
        $organization = $this->createOrganizationFor($user, 'Unique Code Market');

        $this->actingAs($user)
            ->from(route('branches.index'))
            ->post(route('branches.store'), [
                'name' => 'Duplicate Main',
                'code' => 'main',
            ])
            ->assertRedirect(route('branches.index'))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Branch::query()->where('organization_id', $organization->getKey())->count());
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
