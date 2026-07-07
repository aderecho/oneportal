<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsPostMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_admin_can_publish_to_selected_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $admin = User::factory()->departmentAdmin()->create(['department_id' => $department->id]);

        $postId = $this->actingAs($admin)
            ->postJson('/api/news-posts', [
                'title' => 'Enrollment Update',
                'body' => 'A new employee joined the department.',
                'scope' => 'department',
                'department_ids' => [$otherDepartment->id],
            ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->json('data.id');

        $this->assertDatabaseHas('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $otherDepartment->id,
            'scope' => 'department',
        ]);
        $this->assertDatabaseMissing('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $department->id,
        ]);
    }

    public function test_department_admin_news_defaults_to_own_department_when_no_department_is_selected(): void
    {
        $department = Department::factory()->create();
        $admin = User::factory()->departmentAdmin()->create(['department_id' => $department->id]);

        $postId = $this->actingAs($admin)
            ->postJson('/api/news-posts', [
                'title' => 'Enrollment Update',
                'body' => 'A new employee joined the department.',
                'scope' => 'department',
                'department_ids' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->json('data.id');

        $this->assertDatabaseHas('news_post_visibilities', [
            'news_post_id' => $postId,
            'department_id' => $department->id,
            'scope' => 'department',
        ]);
    }

    public function test_standard_user_cannot_publish_news(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/news-posts', [
                'title' => 'Blocked',
                'body' => 'Blocked',
                'scope' => 'organization',
            ])
            ->assertForbidden();
    }
}
