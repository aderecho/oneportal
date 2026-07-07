<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\NewsPost;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load('department');

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'department' => $user->department?->only(['id', 'name', 'code', 'slug']),
                ],
                'navigation' => $this->navigationFor($user),
                'stats' => $this->statsFor($user),
                'portals' => $this->portalsFor($user),
                'news' => $this->newsFor($user),
                'notifications' => $this->notificationsFor($user),
                'departmentUsers' => $this->departmentUsersFor($user),
                'departments' => $this->departmentsFor($user),
            ],
        ]);
    }

    private function navigationFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return [
                'Dashboard',
                'Units & Departments',
                'System Integration',
                'User Management',
                'Integration Management',
                'Dashboard Management',
                'Advertisements',
                'Logs',
                'User Access',
                'News Feed',
            ];
        }

        if ($user->isDepartmentAdmin()) {
            return ['Dashboard', 'Department Users', 'News Feed', 'Notifications'];
        }

        return ['My Dashboard', 'My Applications', 'News Feed', 'Recent Activity'];
    }

    private function statsFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return [
                'integratedSystems' => ServiceProvider::count(),
                'activeDepartments' => Department::where('is_active', true)->count(),
                'totalUsers' => User::count(),
            ];
        }

        if ($user->isDepartmentAdmin()) {
            return [
                'departmentUsers' => User::where('department_id', $user->department_id)->count(),
                'activePortals' => $user->department?->serviceProviders()->where('service_providers.is_active', true)->count() ?? 0,
            ];
        }

        return [
            'allowedPortals' => $this->portalQueryFor($user)->count(),
            'unreadNews' => NewsPost::visibleTo($user)->where('status', 'published')->count(),
            'unreadNotifications' => $user->unreadNotifications()->count(),
        ];
    }

    private function portalsFor(User $user): array
    {
        return $this->portalQueryFor($user)
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'slug' => $provider->slug,
                'launch_url' => $provider->launch_url ?? "/sso/{$provider->slug}",
                'status' => $provider->status,
            ])
            ->values()
            ->all();
    }

    private function portalQueryFor(User $user)
    {
        if ($user->isSuperAdmin()) {
            return ServiceProvider::query()->where('is_active', true);
        }

        if ($user->isDepartmentAdmin()) {
            return $user->department?->serviceProviders()->where('service_providers.is_active', true)
                ?? ServiceProvider::query()->whereRaw('1 = 0');
        }

        return $user->directlyAssignedServiceProviders()->where('service_providers.is_active', true);
    }

    private function newsFor(User $user): array
    {
        return NewsPost::query()
            ->visibleTo($user)
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->latest('published_at')
            ->limit(5)
            ->get()
            ->map(fn (NewsPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => $post->body,
                'published_at' => $post->published_at?->toISOString(),
            ])
            ->all();
    }

    private function notificationsFor(User $user): array
    {
        return $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'kind' => $notification->data['kind'] ?? 'notification',
                'title' => $notification->data['title'] ?? 'OnePortal notification',
                'message' => $notification->data['message'] ?? '',
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ])
            ->all();
    }

    private function departmentUsersFor(User $user): array
    {
        if (! $user->isDepartmentAdmin()) {
            return [];
        }

        return User::query()
            ->where('department_id', $user->department_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at'])
            ->map(fn (User $departmentUser) => [
                'id' => $departmentUser->id,
                'name' => $departmentUser->name,
                'email' => $departmentUser->email,
                'role' => $departmentUser->role,
                'status' => $departmentUser->created_at->greaterThan(now()->subDays(7)) ? 'New' : 'Active',
            ])
            ->all();
    }

    private function departmentsFor(User $user): array
    {
        if (! $user->isSuperAdmin() && ! $user->isDepartmentAdmin()) {
            return [];
        }

        return Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Department $department) => $department->only(['id', 'name', 'code']))
            ->all();
    }
}
