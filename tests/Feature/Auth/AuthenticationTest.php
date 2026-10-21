<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_view_the_login_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_guests_can_view_the_registration_page(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    public function test_authenticated_routes_redirect_guests_to_login(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_registration_creates_user_organization_and_main_branch(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Metrik Tester',
            'email' => '  AMR@example.com ',
            'organization_name' => 'سوبر ماركت الأمانة',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Metrik Tester',
            'email' => 'amr@example.com',
        ]);
        $this->assertDatabaseHas('organizations', [
            'name' => 'سوبر ماركت الأمانة',
            'currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
        ]);
        $this->assertDatabaseHas('branches', [
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_main' => true,
        ]);
        $this->assertDatabaseHas('organization_user', [
            'role' => 'owner',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('branch_user', [
            'user_id' => User::query()->where('email', 'amr@example.com')->value('id'),
        ]);
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make('StrongPass123!'),
        ]);

        $this->post(route('login.store'), [
            'email' => 'cashier@example.com',
            'password' => 'StrongPass123!',
        ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make('StrongPass123!'),
        ]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'WrongPassword!',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_view_the_dashboard_with_organization_context(): void
    {
        $user = User::factory()->create([
            'name' => 'Metrik Owner',
            'email' => 'owner@example.com',
        ]);
        $organization = $this->createOrganizationFor($user, 'Owner Market');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('user.name', 'Metrik Owner')
                ->where('user.email', 'owner@example.com')
                ->where('organization.name', 'Owner Market')
                ->where('organizationRole', 'owner')
            );
    }

    public function test_authenticated_user_without_organization_is_redirected_to_setup(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organization.setup'));
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
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
