<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, $userId): bool {
    return $user->id === (int) $userId || $user->isSuperAdmin();
});

Broadcast::channel('department.{departmentId}', function (User $user, $departmentId): bool {
    return $user->isSuperAdmin() || $user->department_id === (int) $departmentId;
});

Broadcast::channel('admin.system', function (User $user): bool {
    return $user->isSuperAdmin();
});
