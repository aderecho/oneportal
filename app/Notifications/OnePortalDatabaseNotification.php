<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OnePortalDatabaseNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->payload['kind'],
            'title' => $this->payload['title'],
            'message' => $this->payload['message'],
            'department_id' => $this->payload['department_id'] ?? null,
            'target_type' => $this->payload['target_type'] ?? null,
            'target_id' => $this->payload['target_id'] ?? null,
        ];
    }
}
