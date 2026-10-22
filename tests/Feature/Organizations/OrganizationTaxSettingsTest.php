<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationTaxSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_tax_rate_without_overwriting_other_organization_settings(): void
    {
        [$owner, $organization] = $this->createOrganization('Tax Settings Owner Market');
        $organization->forceFill(['settings' => ['receipt' => ['footer' => 'شكرًا'], 'tax' => ['enabled' => false, 'rate_percent' => '0.00']]])->save();

        $this->actingAs($owner)->get(route('organization.tax-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organizations/TaxSettings')
                ->where('taxSettings.enabled', false)
                ->where('taxSettings.effectiveRateBasisPoints', 0));

        $this->actingAs($owner)->put(route('organization.tax-settings.update'), [
            'enabled' => true,
            'rate_percent' => '14.00',
        ])->assertRedirect(route('organization.tax-settings.index'));

        $fresh = $organization->fresh();
        $this->assertSame('شكرًا', $fresh->settings['receipt']['footer']);
        $this->assertTrue($fresh->settings['tax']['enabled']);
        $this->assertSame('14.00', $fresh->settings['tax']['rate_percent']);
        $this->assertSame('exclusive', $fresh->settings['tax']['mode']);
    }

    public function test_non_owner_cannot_read_or_change_tax_settings(): void
    {
        [$owner, $organization] = $this->createOrganization('Tax Settings Access Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => false]);
        $branch = $organization->branches()->firstOrFail();
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('organization.tax-settings.index'))->assertForbidden();
        $this->actingAs($cashier)->put(route('organization.tax-settings.update'), [
            'enabled' => true,
            'rate_percent' => '14.00',
        ])->assertForbidden();
        $this->assertSame(0, (int) ($organization->fresh()->settings['tax']['enabled'] ?? 0));
    }

    public function test_tax_rate_rejects_values_outside_the_supported_percentage_range(): void
    {
        [$owner] = $this->createOrganization('Tax Settings Validation Market');

        $this->actingAs($owner)->from(route('organization.tax-settings.index'))
            ->put(route('organization.tax-settings.update'), [
                'enabled' => true,
                'rate_percent' => '120.00',
            ])->assertRedirect(route('organization.tax-settings.index'))
            ->assertSessionHasErrors('rate_percent');
    }

    public function test_enabled_setting_rejects_malformed_boolean_input(): void
    {
        [$owner, $organization] = $this->createOrganization('Tax Settings Boolean Validation Market');

        $this->actingAs($owner)->from(route('organization.tax-settings.index'))
            ->put(route('organization.tax-settings.update'), [
                'enabled' => 'not-a-boolean',
                'rate_percent' => '14.00',
            ])->assertRedirect(route('organization.tax-settings.index'))
            ->assertSessionHasErrors('enabled');

        $this->assertSame([], $organization->fresh()->settings ?? []);
    }

    /** @return array{0:User,1:Organization} */
    private function createOrganization(string $name): array
    {
        $owner = User::factory()->create();
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
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization];
    }
}
