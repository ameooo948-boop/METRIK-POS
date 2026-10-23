<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_registration_creates_a_user_without_provisioning_an_organization(): void
    {
        $response = $this->post(route('register.store'), [
            'account_type' => 'employee',
            'name' => 'Test Employee',
            'email' => 'employee@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'employee@example.com',
            'registration_type' => 'employee',
        ]);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_employee_without_membership_cannot_provision_an_organization(): void
    {
        $employee = User::factory()->create(['registration_type' => 'employee']);

        $this->actingAs($employee)
            ->get(route('organization.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organizations/EmployeePending')
                ->where('userEmail', $employee->email));

        $this->actingAs($employee)
            ->post(route('organization.setup.store'), [
                'organization_name' => 'Must Not Be Created',
            ])
            ->assertRedirect(route('organization.setup'));

        $this->assertDatabaseCount('organizations', 0);
    }
}
