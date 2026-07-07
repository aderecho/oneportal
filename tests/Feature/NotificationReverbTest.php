<?php

namespace Tests\Feature;

use App\Events\AccessChanged;
use App\Events\NewsPostPublished;
use App\Models\Department;
use App\Models\NewsPost;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationReverbTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_news_creates_database_notifications_and_broadcast_event(): void
    {
        Event::fake([NewsPostPublished::class]);

        $department = Department::factory()->create();
        $admin = User::factory()->departmentAdmin()->create(['department_id' => $department->id]);
        $recipient = User::factory()->create(['department_id' => $department->id]);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->actingAs($admin)
            ->postJson('/api/news-posts', [
                'title' => 'Enrollment Update',
                'body' => 'A new employee joined the department.',
                'scope' => 'department',
                'department_ids' => [$department->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->id,
            'notifiable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outsider->id,
            'notifiable_type' => User::class,
        ]);

        Event::assertDispatched(NewsPostPublished::class, fn (NewsPostPublished $event) => $event instanceof ShouldBroadcastNow);
    }

    public function test_super_admin_department_news_without_selected_departments_targets_all_active_departments(): void
    {
        Event::fake([NewsPostPublished::class]);

        $activeDepartment = Department::factory()->create(['is_active' => true]);
        $secondActiveDepartment = Department::factory()->create(['is_active' => true]);
        $inactiveDepartment = Department::factory()->create(['is_active' => false]);
        $admin = User::factory()->superAdmin()->create();
        $activeRecipient = User::factory()->create(['department_id' => $activeDepartment->id]);
        $secondRecipient = User::factory()->create(['department_id' => $secondActiveDepartment->id]);
        $inactiveRecipient = User::factory()->create(['department_id' => $inactiveDepartment->id]);

        $postId = $this->actingAs($admin)
            ->postJson('/api/news-posts', [
                'title' => 'All Department Update',
                'body' => 'This should appear on department dashboards automatically.',
                'scope' => 'department',
                'department_ids' => [],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $activeDepartment->id,
            'scope' => 'department',
        ]);
        $this->assertDatabaseHas('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $secondActiveDepartment->id,
            'scope' => 'department',
        ]);
        $this->assertDatabaseMissing('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $inactiveDepartment->id,
            'scope' => 'department',
        ]);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $activeRecipient->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $secondRecipient->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inactiveRecipient->id]);

        Event::assertDispatched(NewsPostPublished::class, function (NewsPostPublished $event) use ($activeDepartment, $activeRecipient, $secondRecipient, $inactiveRecipient) {
            $channels = array_map('strval', $event->broadcastOn());

            return in_array("private-department.{$activeDepartment->id}", $channels, true)
                && in_array("private-user.{$activeRecipient->id}", $channels, true)
                && in_array("private-user.{$secondRecipient->id}", $channels, true)
                && ! in_array("private-user.{$inactiveRecipient->id}", $channels, true);
        });
    }

    public function test_user_access_update_notifies_target_user_and_broadcasts_access_change(): void
    {
        Event::fake([AccessChanged::class]);

        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $provider = ServiceProvider::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/user-access', [
                'user_id' => $user->id,
                'service_provider_ids' => [$provider->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
        ]);

        Event::assertDispatched(AccessChanged::class, fn (AccessChanged $event) => $event instanceof ShouldBroadcastNow);
    }

    public function test_private_broadcast_channels_enforce_role_and_department_boundaries(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');

        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $departmentUser = User::factory()->create(['department_id' => $department->id]);
        $otherUser = User::factory()->create(['department_id' => $otherDepartment->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $request = fn (User $user) => request()
            ->merge(['socket_id' => '123.456', 'channel_name' => "private-department.{$department->id}"])
            ->setUserResolver(fn () => $user);

        $this->assertIsArray(Broadcast::auth($request($departmentUser)));
        $this->assertIsArray(Broadcast::auth($request($superAdmin)));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        Broadcast::auth($request($otherUser));
    }

    public function test_notification_mark_read_is_limited_to_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $owner->notifications()->create([
            'id' => (string) str()->uuid(),
            'type' => 'test',
            'data' => ['kind' => 'test', 'title' => 'Test', 'message' => 'Message'],
        ]);

        $notificationId = $owner->notifications()->first()->id;

        $this->actingAs($otherUser)
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertNotNull($owner->notifications()->first()->read_at);
    }

    public function test_news_broadcast_payload_is_minimal(): void
    {
        $post = NewsPost::factory()->create([
            'title' => 'Security Advisory',
            'body' => 'Detailed body stays in the API response.',
        ]);

        $payload = (new NewsPostPublished($post, [123]))->broadcastWith();

        $this->assertSame('Security Advisory', $payload['title']);
        $this->assertSame('Detailed body stays in the API response.', $payload['excerpt']);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
    }
}
