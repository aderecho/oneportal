<?php

namespace App\Events;

use App\Models\NewsPost;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsPostPublished implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly NewsPost $post,
        private readonly array $departmentIds,
        private readonly array $userIds = [],
    ) {
    }

    public function broadcastAs(): string
    {
        return 'oneportal.news-published';
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('admin.system')];

        foreach ($this->departmentIds as $departmentId) {
            $channels[] = new PrivateChannel("department.{$departmentId}");
        }

        foreach ($this->userIds as $userId) {
            $channels[] = new PrivateChannel("user.{$userId}");
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => 'news_post.published',
            'id' => $this->post->id,
            'title' => $this->post->title,
            'excerpt' => str($this->post->body)->limit(180)->toString(),
            'published_at' => $this->post->published_at?->toISOString(),
            'department_ids' => $this->departmentIds,
        ];
    }
}
