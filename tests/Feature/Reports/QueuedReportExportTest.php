<?php

namespace Tests\Feature\Reports;

use App\Enums\OrganizationRole;
use App\Jobs\GenerateSalesReportExport;
use App\Services\Reports\SalesReport;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueuedReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_queue_a_report_export(): void
    {
        Queue::fake();
        [$owner, $organization, $branch] = $this->createOrganizationFor('Queue Report Market');
        $today = now($organization->timezone)->toDateString();

        $this->actingAs($owner)->post(route('reports.exports.store'), [
            'from_date' => $today,
            'to_date' => $today,
            'branch_id' => (string) $branch->getKey(),
        ])->assertRedirect(route('reports.index', [
            'from_date' => $today,
            'to_date' => $today,
            'branch_id' => (string) $branch->getKey(),
        ]));

        $this->assertDatabaseHas('report_exports', [
            'organization_id' => $organization->getKey(),
            'requested_by' => $owner->getKey(),
            'status' => 'queued',
        ]);

        Queue::assertPushedOn('reports', GenerateSalesReportExport::class, function (GenerateSalesReportExport $job): bool {
            return $job->reportExportId > 0;
        });
    }

    public function test_report_export_job_creates_private_csv_and_database_notification(): void
    {
        Storage::fake('local');
        [$owner, $organization, $branch] = $this->createOrganizationFor('Generated Report Market');
        $export = ReportExport::query()->create([
            'organization_id' => $organization->getKey(),
            'requested_by' => $owner->getKey(),
            'branch_ids' => [$branch->getKey()],
            'from_date' => now($organization->timezone)->toDateString(),
            'to_date' => now($organization->timezone)->toDateString(),
            'status' => 'queued',
        ]);

        (new GenerateSalesReportExport((int) $export->getKey()))->handle(app(SalesReport::class));

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotEmpty($export->file_path);
        Storage::disk('local')->assertExists($export->file_path);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->getKey(),
        ]);
    }

    public function test_cashier_cannot_queue_report_exports(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Cashier Queue Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => OrganizationRole::CASHIER->value, 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->post(route('reports.exports.store'), [
            'from_date' => now($organization->timezone)->toDateString(),
            'to_date' => now($organization->timezone)->toDateString(),
            'branch_id' => (string) $branch->getKey(),
        ])->assertForbidden();

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_user_cannot_download_another_users_export(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Private Export Market');
        $otherUser = User::factory()->create();
        $organization->users()->attach($otherUser->getKey(), ['role' => OrganizationRole::ACCOUNTANT->value, 'is_default' => false]);
        $branch->users()->attach($otherUser->getKey());

        $export = ReportExport::query()->create([
            'organization_id' => $organization->getKey(),
            'requested_by' => $owner->getKey(),
            'branch_ids' => [$branch->getKey()],
            'from_date' => now($organization->timezone)->toDateString(),
            'to_date' => now($organization->timezone)->toDateString(),
            'status' => 'queued',
        ]);

        $this->actingAs($otherUser)
            ->get(route('reports.exports.download', $export))
            ->assertNotFound();
    }

    public function test_reports_page_includes_only_the_current_users_recent_exports(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Exports Overview Market');
        $otherUser = User::factory()->create();
        $organization->users()->attach($otherUser->getKey(), ['role' => OrganizationRole::ACCOUNTANT->value, 'is_default' => false]);
        $branch->users()->attach($otherUser->getKey());

        ReportExport::query()->create([
            'organization_id' => $organization->getKey(), 'requested_by' => $owner->getKey(),
            'branch_ids' => [$branch->getKey()], 'from_date' => now($organization->timezone)->toDateString(), 'to_date' => now($organization->timezone)->toDateString(), 'status' => 'queued',
        ]);
        ReportExport::query()->create([
            'organization_id' => $organization->getKey(), 'requested_by' => $otherUser->getKey(),
            'branch_ids' => [$branch->getKey()], 'from_date' => now($organization->timezone)->toDateString(), 'to_date' => now($organization->timezone)->toDateString(), 'status' => 'queued',
        ]);

        $this->actingAs($owner)->get(route('reports.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->has('reportExports', 1)
                ->where('reportExports.0.status', 'queued'));
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
        $organization->users()->attach($owner->getKey(), ['role' => OrganizationRole::OWNER->value, 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }
}
