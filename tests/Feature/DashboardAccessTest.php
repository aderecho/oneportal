<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\NewsPost;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_dashboard_payload(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_super_admin_sees_global_navigation_portals_and_news(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $department = Department::factory()->create();
        $provider = ServiceProvider::factory()->create(['name' => 'AMIS', 'slug' => 'amis']);
        $post = NewsPost::factory()->create(['author_id' => $admin->id, 'title' => 'System Maintenance Advisory']);
        $post->visibilities()->create(['scope' => 'department', 'department_id' => $department->id]);

        $this->actingAs($admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.navigation.1', 'Units & Departments')
            ->assertJsonPath('data.portals.0.name', $provider->name)
            ->assertJsonPath('data.news.0.title', 'System Maintenance Advisory')
            ->assertJsonPath('data.stats.integratedSystems', 1);
    }

    public function test_department_admin_is_limited_to_department_portals_and_news(): void
    {
        $ownDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $admin = User::factory()->departmentAdmin()->create(['department_id' => $ownDepartment->id]);
        User::factory()->create(['department_id' => $ownDepartment->id]);
        User::factory()->create(['department_id' => $otherDepartment->id]);
        $ownProvider = ServiceProvider::factory()->create(['name' => 'HRIS', 'slug' => 'hris']);
        $otherProvider = ServiceProvider::factory()->create(['name' => 'Finance', 'slug' => 'finance']);
        $ownDepartment->serviceProviders()->attach($ownProvider->id, ['is_active' => true]);
        $otherDepartment->serviceProviders()->attach($otherProvider->id, ['is_active' => true]);

        $visiblePost = NewsPost::factory()->create(['author_id' => $admin->id, 'title' => 'Department Enrollment']);
        $visiblePost->visibilities()->create(['scope' => 'department', 'department_id' => $ownDepartment->id]);
        $hiddenPost = NewsPost::factory()->create(['author_id' => $admin->id, 'title' => 'Finance Only']);
        $hiddenPost->visibilities()->create(['scope' => 'department', 'department_id' => $otherDepartment->id]);

        $response = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.navigation', ['Dashboard', 'Department Users', 'News Feed', 'Notifications'])
            ->assertJsonPath('data.stats.departmentUsers', 2)
            ->assertJsonFragment(['name' => 'HRIS'])
            ->assertJsonMissing(['name' => 'Finance'])
            ->assertJsonFragment(['title' => 'Department Enrollment'])
            ->assertJsonMissing(['title' => 'Finance Only']);
    }

    public function test_standard_user_sees_only_directly_assigned_portals_and_relevant_news(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $department->id]);
        $assignedProvider = ServiceProvider::factory()->create(['name' => 'Library', 'slug' => 'library']);
        $unassignedProvider = ServiceProvider::factory()->create(['name' => 'Reports', 'slug' => 'reports']);
        $user->directlyAssignedServiceProviders()->attach($assignedProvider->id, ['is_active' => true]);

        $orgPost = NewsPost::factory()->create(['title' => 'Password Security']);
        $orgPost->visibilities()->create(['scope' => 'organization']);
        $deptPost = NewsPost::factory()->create(['title' => 'Library Schedule']);
        $deptPost->visibilities()->create(['scope' => 'department', 'department_id' => $department->id]);
        $hiddenPost = NewsPost::factory()->create(['title' => 'Other Department']);
        $hiddenPost->visibilities()->create(['scope' => 'department', 'department_id' => $otherDepartment->id]);

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.navigation', ['My Dashboard', 'My Applications', 'News Feed', 'Recent Activity'])
            ->assertJsonFragment(['name' => 'Library'])
            ->assertJsonMissing(['name' => 'Reports'])
            ->assertJsonFragment(['title' => 'Password Security'])
            ->assertJsonFragment(['title' => 'Library Schedule'])
            ->assertJsonMissing(['title' => 'Other Department']);

        $this->assertDatabaseHas('service_providers', ['id' => $unassignedProvider->id]);
    }
}
