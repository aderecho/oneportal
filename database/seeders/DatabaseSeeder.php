<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\NewsPost;
use App\Models\ServiceProvider;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $unit = Unit::query()->updateOrCreate(
            ['code' => 'ADMIN'],
            ['name' => 'Administration', 'slug' => 'administration', 'is_active' => true],
        );

        $itDepartment = Department::query()->updateOrCreate(
            ['code' => 'IT'],
            ['unit_id' => $unit->id, 'name' => 'Information Technology', 'slug' => 'information-technology', 'is_active' => true],
        );

        $hrDepartment = Department::query()->updateOrCreate(
            ['code' => 'HR'],
            ['unit_id' => $unit->id, 'name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true],
        );

        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'super.admin@oneportal.test'],
            ['name' => 'Super Admin', 'password' => Hash::make('password'), 'role' => 'super_admin', 'department_id' => null],
        );

        $departmentAdmin = User::query()->updateOrCreate(
            ['email' => 'dept.admin@oneportal.test'],
            ['name' => 'Department Admin', 'password' => Hash::make('password'), 'role' => 'department_admin', 'department_id' => $itDepartment->id],
        );

        $standardUser = User::query()->updateOrCreate(
            ['email' => 'standard.user@oneportal.test'],
            ['name' => 'Standard User', 'password' => Hash::make('password'), 'role' => 'user', 'department_id' => $itDepartment->id],
        );

        User::query()->updateOrCreate(
            ['email' => 'hr.user@oneportal.test'],
            ['name' => 'HR User', 'password' => Hash::make('password'), 'role' => 'user', 'department_id' => $hrDepartment->id],
        );

        $itDepartment->update(['department_head_id' => $departmentAdmin->id]);

        $amis = $this->serviceProvider('AMIS', 'amis', 'healthy');
        $hris = $this->serviceProvider('HRIS', 'hris', 'healthy');
        $library = $this->serviceProvider('Library', 'library', 'healthy');
        $reports = $this->serviceProvider('Reports', 'reports', 'warning');
        $rooms = ServiceProvider::query()->updateOrCreate(
            ['slug' => 'rooms'],
            [
                'name' => 'Cebu Rooms',
                'entity_id' => 'http://localhost:8000/saml2/metadata',
                'acs_url' => 'http://localhost:8000/saml2/acs',
                'slo_url' => 'http://localhost:8000/saml2/logout',
                'launch_url' => '/sso/rooms',
                'status' => 'healthy',
                'attribute_release' => ['email', 'name', 'role', 'department'],
                'is_active' => true,
            ],
        );

        $itDepartment->serviceProviders()->syncWithoutDetaching([
            $amis->id => ['is_active' => true],
            $library->id => ['is_active' => true],
            $reports->id => ['is_active' => true],
            $rooms->id => ['is_active' => true],
        ]);

        $hrDepartment->serviceProviders()->syncWithoutDetaching([
            $hris->id => ['is_active' => true],
        ]);

        $standardUser->directlyAssignedServiceProviders()->syncWithoutDetaching([
            $amis->id => ['is_active' => true],
            $library->id => ['is_active' => true],
            $rooms->id => ['is_active' => true],
        ]);

        $this->newsPost(
            $superAdmin,
            'System Maintenance Advisory',
            'Scheduled maintenance on DTR this Saturday, 10:00 PM to 2:00 AM.',
            'organization',
        );

        $this->newsPost(
            $departmentAdmin,
            'IT Department Enrollment',
            'A new user was enrolled under Information Technology.',
            'department',
            $itDepartment,
        );

        $this->newsPost(
            $superAdmin,
            'HR Policy Reminder',
            'Human Resources policy review is available in HRIS.',
            'department',
            $hrDepartment,
        );
    }

    private function serviceProvider(string $name, string $slug, string $status): ServiceProvider
    {
        return ServiceProvider::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'entity_id' => "https://{$slug}.example.test/saml/metadata",
                'acs_url' => "https://{$slug}.example.test/saml/acs",
                'launch_url' => "/sso/{$slug}",
                'status' => $status,
                'attribute_release' => ['email', 'name', 'role'],
                'is_active' => true,
            ],
        );
    }

    private function newsPost(User $author, string $title, string $body, string $scope, ?Department $department = null): void
    {
        $post = NewsPost::query()->updateOrCreate(
            ['title' => $title],
            [
                'author_id' => $author->id,
                'body' => $body,
                'status' => 'published',
                'published_at' => now(),
            ],
        );

        $post->visibilities()->updateOrCreate(
            ['scope' => $scope, 'department_id' => $department?->id],
            ['scope' => $scope, 'department_id' => $department?->id],
        );
    }
}
