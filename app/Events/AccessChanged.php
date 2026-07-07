<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user, private readonly int $portalCount)
    {
    }

    public function broadcastAs(): string
    {
        return 'oneportal.access-changed';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->user->id}"),
            new PrivateChannel('admin.system'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => 'access.changed',
            'user_id' => $this->user->id,
            'portal_count' => $this->portalCount,
        ];
    }
}
