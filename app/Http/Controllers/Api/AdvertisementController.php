<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdvertisementController extends Controller
{
    public function active(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => Advertisement::query()
                ->active()
                ->latest('starts_at')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Advertisement $advertisement) => $this->serialize($advertisement))
                ->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => Advertisement::query()
                ->latest()
                ->get()
                ->map(fn (Advertisement $advertisement) => $this->serialize($advertisement))
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $this->validated($request);
        $media = $this->storeMedia($request);

        $advertisement = Advertisement::query()->create([
            ...$data,
            ...$media,
            'author_id' => $request->user()->id,
            'ends_at' => ($data['is_forever'] ?? false) ? null : ($data['ends_at'] ?? null),
        ]);

        $this->audit($request, 'advertisement.created', $advertisement);

        return response()->json([
            'status' => true,
            'message' => 'Advertisement created.',
            'data' => $this->serialize($advertisement),
        ], 201);
    }

    public function update(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $this->validated($request, updating: true);
        $media = $this->storeMedia($request);

        $advertisement->update([
            ...$data,
            ...$media,
            'ends_at' => ($data['is_forever'] ?? false) ? null : ($data['ends_at'] ?? null),
        ]);

        $this->audit($request, 'advertisement.updated', $advertisement);

        return response()->json([
            'status' => true,
            'message' => 'Advertisement updated.',
            'data' => $this->serialize($advertisement->refresh()),
        ]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $data = $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_forever' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'paused'])],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,mov,pdf', 'max:20480'],
        ]);

        unset($data['media']);

        return $data;
    }

    private function storeMedia(Request $request): array
    {
        if (! $request->hasFile('media')) {
            return [];
        }

        $file = $request->file('media');
        $directory = public_path('advertisements');
        File::ensureDirectoryExists($directory);

        $mimeType = (string) $file->getMimeType();
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().($extension ? ".{$extension}" : '');
        $file->move($directory, $filename);

        return [
            'media_url' => "/advertisements/{$filename}",
            'media_type' => str_starts_with($mimeType, 'video/')
                ? 'video'
                : (str_starts_with($mimeType, 'image/') ? 'image' : 'file'),
        ];
    }

    private function serialize(Advertisement $advertisement): array
    {
        return [
            'id' => $advertisement->id,
            'title' => $advertisement->title,
            'body' => $advertisement->body,
            'media_url' => $advertisement->media_url,
            'media_type' => $advertisement->media_type,
            'link_url' => $advertisement->link_url,
            'starts_at' => $advertisement->starts_at?->toISOString(),
            'ends_at' => $advertisement->ends_at?->toISOString(),
            'is_forever' => $advertisement->is_forever,
            'status' => $advertisement->status,
            'created_at' => $advertisement->created_at?->toISOString(),
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    private function audit(Request $request, string $action, Advertisement $advertisement): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'target_type' => Advertisement::class,
            'target_id' => $advertisement->id,
            'metadata' => [
                'status' => $advertisement->status,
                'starts_at' => $advertisement->starts_at?->toISOString(),
                'ends_at' => $advertisement->ends_at?->toISOString(),
                'is_forever' => $advertisement->is_forever,
            ],
        ]);
    }
}
