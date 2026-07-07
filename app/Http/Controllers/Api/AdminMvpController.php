<?php

namespace App\Http\Controllers\Api;

use App\Events\AccessChanged;
use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\ServiceProvider;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\OnePortalDatabaseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMvpController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'departments' => Department::query()->withCount('users')->orderBy('name')->get(),
                'users' => User::query()->with('department:id,name,code')->orderBy('name')->get(['id', 'name', 'email', 'role', 'department_id']),
                'serviceProviders' => ServiceProvider::query()->orderBy('name')->get(),
                'auditLogs' => AuditLog::query()->latest()->limit(20)->get(),
                'advertisements' => Advertisement::query()->latest()->get(),
            ],
        ]);
    }

    public function createDepartment(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'unique:departments,code'],
            'unit_id' => ['nullable', 'exists:units,id'],
        ]);

        $unit = isset($data['unit_id'])
            ? Unit::query()->findOrFail($data['unit_id'])
            : Unit::query()->firstOrCreate(
                ['code' => 'ORG'],
                ['name' => 'Organization', 'slug' => 'organization', 'is_active' => true],
            );

        $department = Department::query()->create([
            'unit_id' => $unit->id,
            'name' => $data['name'],
            'code' => Str::upper($data['code']),
            'slug' => Str::slug($data['name']),
            'is_active' => true,
        ]);

        $this->audit($request, 'department.created', $department, $department->id);

        return $this->created($department, 'Department created.');
    }

    public function createUser(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['super_admin', 'department_admin', 'user'])],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        $this->audit($request, 'user.created', $user, $user->department_id);

        return $this->created($user->only(['id', 'name', 'email', 'role', 'department_id']), 'User created.');
    }

    public function createServiceProvider(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:100', 'unique:service_providers,slug'],
            'entity_id' => ['required', 'url', 'unique:service_providers,entity_id'],
            'acs_url' => ['required', 'url'],
            'launch_url' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'down', 'inactive'])],
            'attribute_release' => ['nullable', 'array'],
            'x509_cert' => ['nullable', 'string'],
        ]);

        $provider = ServiceProvider::query()->create([
            ...$data,
            'status' => $data['status'] ?? 'healthy',
            'launch_url' => $data['launch_url'] ?? "/sso/{$data['slug']}",
            'attribute_release' => $data['attribute_release'] ?? ['email', 'name', 'role'],
            'is_active' => ($data['status'] ?? 'healthy') !== 'inactive',
        ]);

        $this->audit($request, 'service_provider.created', $provider);

        return $this->created($provider, 'Integration created.');
    }

    public function assignAccess(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'service_provider_ids' => ['required', 'array'],
            'service_provider_ids.*' => ['integer', 'exists:service_providers,id'],
        ]);

        $user = User::query()->findOrFail($data['user_id']);
        $sync = collect($data['service_provider_ids'])->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->all();

        $user->directlyAssignedServiceProviders()->sync($sync);
        $user->notify(new OnePortalDatabaseNotification([
            'kind' => 'access.changed',
            'title' => 'Portal access updated',
            'message' => 'Your OnePortal application access was updated.',
            'target_type' => User::class,
            'target_id' => $user->id,
            'department_id' => $user->department_id,
        ]));

        $this->audit($request, 'user_access.updated', $user, $user->department_id, [
            'service_provider_ids' => array_values($data['service_provider_ids']),
        ]);

        AccessChanged::dispatch($user, count($data['service_provider_ids']));

        return response()->json([
            'status' => true,
            'message' => 'Access updated.',
            'data' => [],
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    private function audit(Request $request, string $action, object $target, ?int $departmentId = null, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->id ?? null,
            'department_id' => $departmentId,
            'metadata' => $metadata,
        ]);
    }

    private function created(mixed $data, string $message): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], 201);
    }
}
