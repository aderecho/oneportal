<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_department_integration_user_and_assign_access(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $departmentId = $this->actingAs($admin)
            ->postJson('/api/admin/departments', [
                'name' => 'Registrar',
                'code' => 'REG',
            ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->json('data.id');

        $providerId = $this->actingAs($admin)
            ->postJson('/api/admin/service-providers', [
                'name' => 'Registrar Portal',
                'slug' => 'registrar-portal',
                'entity_id' => 'https://registrar.example.test/saml/metadata',
                'acs_url' => 'https://registrar.example.test/saml/acs',
                'attribute_release' => ['email', 'name', 'role'],
            ])
            ->assertCreated()
            ->json('data.id');

        $userId = $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'Registrar User',
                'email' => 'registrar.user@example.test',
                'password' => 'password123',
                'role' => 'user',
                'department_id' => $departmentId,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)
            ->postJson('/api/admin/user-access', [
                'user_id' => $userId,
                'service_provider_ids' => [$providerId],
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('departments', ['code' => 'REG']);
        $this->assertDatabaseHas('service_providers', ['slug' => 'registrar-portal']);
        $this->assertDatabaseHas('user_service_provider_access', [
            'user_id' => $userId,
            'service_provider_id' => $providerId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_access.updated']);
    }

    public function test_non_super_admin_cannot_use_admin_mvp_endpoints(): void
    {
        $departmentAdmin = User::factory()->departmentAdmin()->create();

        $this->actingAs($departmentAdmin)
            ->postJson('/api/admin/departments', [
                'name' => 'Blocked',
                'code' => 'BLK',
            ])
            ->assertForbidden();
    }

    public function test_admin_overview_returns_management_data_and_audit_logs(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Department::factory()->create(['name' => 'Information Technology']);
        ServiceProvider::factory()->create(['name' => 'AMIS']);
        AuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'service_provider.created',
            'target_type' => ServiceProvider::class,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonFragment(['name' => 'Information Technology'])
            ->assertJsonFragment(['name' => 'AMIS'])
            ->assertJsonFragment(['action' => 'service_provider.created']);
    }
}
