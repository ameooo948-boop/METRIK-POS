<?php

namespace Tests\Feature\Security;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_page_includes_basic_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_authenticated_dashboard_is_not_cached_by_shared_browser_caches(): void
    {
        [$owner] = $this->createOrganizationFor('Security Headers Market');

        $response = $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Frame-Options', 'DENY');

        // Cache-Control directives are a comma-separated set; their order is not significant.
        $cacheControlDirectives = array_map(
            static fn (string $directive): string => trim(strtolower($directive)),
            explode(',', (string) $response->headers->get('Cache-Control')),
        );

        $this->assertContains('private', $cacheControlDirectives);
        $this->assertContains('no-store', $cacheControlDirectives);
        $this->assertContains('max-age=0', $cacheControlDirectives);
        $this->assertContains('must-revalidate', $cacheControlDirectives);
    }

    private function createOrganizationFor(string $name): array
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
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), [
            'role' => OrganizationRole::OWNER->value,
            'is_default' => true,
        ]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }
}
