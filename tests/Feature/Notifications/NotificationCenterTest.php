<?php

namespace Tests\Feature\Notifications;

use App\Enums\OrganizationRole;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_their_own_notifications_and_gets_a_request_id(): void
    {
        [$user] = $this->createOwner('Notification Market');
        $other = User::factory()->create();
        $own = $this->createNotification($user, 'تقرير جاهز');
        $this->createNotification($other, 'إشعار خاص بمستخدم آخر');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertHeader('X-Request-ID')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->where('unreadCount', 1)
                ->has('notifications', 1)
                ->where('notifications.0.id', (string) $own->getKey())
                ->where('notifications.0.title', 'تقرير جاهز'));
    }

    public function test_user_can_mark_their_own_notification_as_read(): void
    {
        [$user] = $this->createOwner('Read Own Notification Market');
        $notification = $this->createNotification($user, 'تنبيه جديد');

        $this->actingAs($user)
            ->post(route('notifications.read', ['notificationId' => $notification->getKey()]))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        [$user] = $this->createOwner('Private Notification Market');
        $other = User::factory()->create();
        $notification = $this->createNotification($other, 'خاص');

        $this->actingAs($user)
            ->post(route('notifications.read', ['notificationId' => $notification->getKey()]))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_updates_the_authenticated_users_notifications(): void
    {
        [$user] = $this->createOwner('Read All Market');
        $other = User::factory()->create();
        $first = $this->createNotification($user, 'الأول');
        $second = $this->createNotification($user, 'الثاني');
        $private = $this->createNotification($other, 'تنبيه مستخدم آخر');

        $this->actingAs($user)->post(route('notifications.readAll'))->assertRedirect();

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($private->fresh()->read_at);
    }

    private function createNotification(User $user, string $title): object
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test_notification',
            'data' => ['title' => $title, 'message' => 'محتوى تجريبي'],
        ]);
    }

    /** @return array{0: User, 1: Organization, 2: Branch} */
    private function createOwner(string $name): array
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
        $organization->users()->attach($user->getKey(), [
            'role' => OrganizationRole::OWNER->value,
            'is_default' => true,
        ]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }
}
