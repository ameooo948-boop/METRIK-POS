<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
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
        Notification::fake();
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
        Notification::assertSentTo(User::query()->where('email', 'amr@example.com')->firstOrFail(), VerifyEmail::class);
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

    public function test_guests_can_view_password_recovery_pages(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));

        $this->get(route('password.reset', ['token' => 'example-token', 'email' => 'test@example.test']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'example-token')
                ->where('email', 'test@example.test'));
    }

    public function test_guest_can_request_a_password_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'recover@example.test']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_changes_the_password_and_invalidates_existing_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.test',
            'remember_token' => 'old-remember-token',
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewStrongPass123!', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
    }

    public function test_unverified_users_are_redirected_to_email_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/VerifyEmail')
                ->where('email', $user->email));
    }

    public function test_unverified_user_can_resend_verification_notification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_verification_link_marks_the_email_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('status', 'email-verified');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_link_cannot_be_used_by_another_user(): void
    {
        $intendedUser = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $intendedUser->getKey(),
            'hash' => sha1($intendedUser->getEmailForVerification()),
        ]);

        $this->actingAs($otherUser)->get($url)->assertForbidden();
        $this->assertNull($intendedUser->fresh()->email_verified_at);
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
