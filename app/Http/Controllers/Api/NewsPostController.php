<?php

namespace App\Http\Controllers\Api;

use App\Events\NewsPostPublished;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\NewsPost;
use App\Models\User;
use App\Notifications\OnePortalDatabaseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NewsPostController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->isDepartmentAdmin(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'scope' => ['required', Rule::in(['organization', 'department'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        if ($user->isDepartmentAdmin()) {
            $data['scope'] = 'department';

            if (empty($data['department_ids'])) {
                $data['department_ids'] = [$user->department_id];
            }
        }

        if ($user->isSuperAdmin() && $data['scope'] === 'department' && empty($data['department_ids'])) {
            $data['department_ids'] = Department::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        $post = NewsPost::query()->create([
            'author_id' => $user->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $departmentIds = [];

        if ($data['scope'] === 'organization') {
            $post->visibilities()->create(['scope' => 'organization']);
        } else {
            foreach (($data['department_ids'] ?? []) as $departmentId) {
                $department = Department::query()->findOrFail($departmentId);
                $post->visibilities()->create(['scope' => 'department', 'department_id' => $department->id]);
                $departmentIds[] = $department->id;
            }
        }

        $recipients = $this->recipientsFor($data['scope'], $departmentIds);

        $recipients->each(fn (User $recipient) => $recipient->notify(new OnePortalDatabaseNotification([
            'kind' => 'news_post.published',
            'title' => 'News published',
            'message' => $post->title,
            'target_type' => NewsPost::class,
            'target_id' => $post->id,
            'department_id' => $recipient->department_id,
        ])));

        AuditLog::query()->create([
            'actor_id' => $user->id,
            'action' => 'news_post.created',
            'target_type' => NewsPost::class,
            'target_id' => $post->id,
            'department_id' => $user->isDepartmentAdmin() ? $user->department_id : null,
            'metadata' => ['scope' => $data['scope']],
        ]);

        NewsPostPublished::dispatch(
            $post,
            $departmentIds,
            $recipients->pluck('id')->all(),
        );

        return response()->json([
            'status' => true,
            'message' => 'News post published.',
            'data' => $post,
        ], 201);
    }

    private function recipientsFor(string $scope, array $departmentIds)
    {
        $query = User::query();

        if ($scope === 'department') {
            $query->whereIn('department_id', $departmentIds);
        }

        return $query->get();
    }
}
