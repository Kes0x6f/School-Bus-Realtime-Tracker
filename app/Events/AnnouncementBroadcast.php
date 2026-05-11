<?php

namespace App\Events;

use App\Models\Announcement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class AnnouncementBroadcast implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public Announcement $announcement,
        public string $action = 'created' // created | deactivated
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('announcements')];
    }

    public function broadcastAs(): string
    {
        return 'announcement.' . $this->action;
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->announcement->id,
            'message'    => $this->announcement->message,
            'route'      => $this->announcement->route,
            'is_active'  => $this->announcement->is_active,
            'expires_at' => $this->announcement->expires_at?->toISOString(),
            'created_at' => $this->announcement->created_at->toISOString(),
        ];
    }
}