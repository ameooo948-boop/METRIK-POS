<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HardwareDevicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_organization_member_can_open_device_setup(): void
    {
        [$owner] = $this->createOrganizationFor('Device Setup Market');

        $this->actingAs($owner)->get(route('pos.devices'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('POS/Devices'));
    }

    public function test_guest_is_redirected_from_device_setup(): void
    {
        $this->get(route('pos.devices'))->assertRedirect(route('login'));
    }

    /** @return array{User, Organization, Branch} */
    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
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
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }
}
