<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_tenant_scoped_audit_log_and_export_csv(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Local Audit Market');
        $this->actingAs($owner);
        $shift = $this->createShift($owner, $organization, $branch);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'actor_id' => $owner->getKey(),
            'event' => 'created',
            'auditable_type' => CashShift::class,
            'auditable_id' => $shift->getKey(),
        ]);

        $this->actingAs($owner)->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audit/Index')
                ->where('organization.name', 'Local Audit Market')
                ->has('logs.data', 1)
                ->where('logs.data.0.entityKey', 'CashShift')
                ->where('logs.data.0.entityId', $shift->getKey())
                ->where('logs.data.0.actorName', $owner->name));

        $this->actingAs($owner)->get(route('audit-log.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_cashier_cannot_view_audit_log(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Audit Permission Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => false]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('audit-log.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('audit-log.export'))->assertForbidden();
    }

    public function test_audit_page_does_not_expose_another_organizations_records(): void
    {
        [$localOwner] = $this->createOrganizationFor('Local Audit Isolation Market');
        [$foreignOwner, $foreignOrganization, $foreignBranch] = $this->createOrganizationFor('Foreign Audit Isolation Market');
        $this->actingAs($foreignOwner);
        $foreignShift = $this->createShift($foreignOwner, $foreignOrganization, $foreignBranch);

        $this->actingAs($localOwner)->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 0)
                ->where('summary.matchingRecords', 0));

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $foreignOrganization->getKey(),
            'auditable_id' => $foreignShift->getKey(),
        ]);
        $this->assertSame(0, AuditLog::query()->where('organization_id', '!=', $foreignOrganization->getKey())
            ->where('auditable_id', $foreignShift->getKey())->where('auditable_type', CashShift::class)->count());
    }

    public function test_audit_snapshot_does_not_store_sensitive_customer_contact_fields(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Audit Safe Fields Market');
        $this->actingAs($owner);
        $shift = $this->createShift($owner, $organization, $branch);
        $shift->update(['opening_amount' => '14.00']);

        $update = AuditLog::query()->where('organization_id', $organization->getKey())
            ->where('auditable_type', CashShift::class)->where('auditable_id', $shift->getKey())
            ->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertContains('opening_amount', $update->changed_fields ?? []);
        $this->assertArrayNotHasKey('email', $update->old_values ?? []);
        $this->assertArrayNotHasKey('password', $update->new_values ?? []);
    }

    private function createShift(User $owner, Organization $organization, $branch): CashShift
    {
        return CashShift::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'cashier_id' => $owner->getKey(),
            'opened_by' => $owner->getKey(),
            'status' => 'open',
            'opening_amount' => '10.00',
            'opened_at' => now(),
        ]);
    }

    /** @return array{User, Organization, \App\Models\Branch} */
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
            'role' => 'owner',
            'is_default' => true,
        ]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }
}
