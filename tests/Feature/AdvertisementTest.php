<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdvertisementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_active_advertisements_only_returns_current_active_ads(): void
    {
        Advertisement::factory()->create([
            'title' => 'Current campus announcement',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'status' => 'active',
            'is_forever' => false,
        ]);
        Advertisement::factory()->create([
            'title' => 'Forever login ad',
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'status' => 'active',
            'is_forever' => true,
        ]);
        Advertisement::factory()->create([
            'title' => 'Expired announcement',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
            'status' => 'active',
        ]);
        Advertisement::factory()->create([
            'title' => 'Draft announcement',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/advertisements/active')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $response->assertJsonFragment(['title' => 'Current campus announcement'])
            ->assertJsonFragment(['title' => 'Forever login ad'])
            ->assertJsonMissing(['title' => 'Expired announcement'])
            ->assertJsonMissing(['title' => 'Draft announcement']);
    }

    public function test_super_admin_can_create_scheduled_forever_advertisement(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/advertisements', [
                'title' => 'UP Cebu Enrollment Week',
                'body' => 'Enrollment reminders and portal updates for all users.',
                'link_url' => 'https://upcebu.edu.ph',
                'starts_at' => now()->addDay()->toISOString(),
                'ends_at' => now()->addWeek()->toISOString(),
                'is_forever' => true,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'UP Cebu Enrollment Week')
            ->assertJsonPath('data.is_forever', true)
            ->assertJsonPath('data.ends_at', null);

        $this->assertDatabaseHas('advertisements', [
            'title' => 'UP Cebu Enrollment Week',
            'author_id' => $admin->id,
            'is_forever' => true,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advertisement.created']);
    }

    public function test_super_admin_can_create_advertisement_with_uploaded_media(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $publicPath = storage_path('framework/testing/public');

        File::deleteDirectory($publicPath);
        $this->app->usePublicPath($publicPath);

        try {
            $response = $this->actingAs($admin)
                ->post('/api/admin/advertisements', [
                    'title' => 'Campus fair banner',
                    'body' => 'A login page media placement.',
                    'status' => 'active',
                    'media' => UploadedFile::fake()->image('campus-fair.jpg', 900, 500),
                ])
                ->assertCreated()
                ->assertJsonPath('data.title', 'Campus fair banner')
                ->assertJsonPath('data.media_type', 'image');

            $mediaUrl = $response->json('data.media_url');

            $this->assertNotNull($mediaUrl);
            $this->assertFileExists(public_path(ltrim($mediaUrl, '/')));
            $this->assertDatabaseHas('advertisements', [
                'title' => 'Campus fair banner',
                'media_type' => 'image',
                'media_url' => $mediaUrl,
            ]);
        } finally {
            File::deleteDirectory($publicPath);
        }
    }

    public function test_non_super_admin_cannot_create_advertisement(): void
    {
        $departmentAdmin = User::factory()->departmentAdmin()->create();

        $this->actingAs($departmentAdmin)
            ->postJson('/api/admin/advertisements', [
                'title' => 'Blocked ad',
                'status' => 'active',
            ])
            ->assertForbidden();
    }
}
