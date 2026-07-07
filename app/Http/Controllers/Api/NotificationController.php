<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        $record->markAsRead();

        return response()->json([
            'status' => true,
            'message' => 'Notification marked as read.',
            'data' => [
                'id' => $record->id,
                'read_at' => $record->read_at?->toISOString(),
            ],
        ]);
    }
}
