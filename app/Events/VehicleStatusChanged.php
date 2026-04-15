<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VehicleStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $vehicle;

    public function __construct($vehicle)
    {
        $this->vehicle = $vehicle;
    }

    public function broadcastOn()
    {
        return [
            new PrivateChannel('vehicles')
        ];
    }

    public function broadcastAs()
    {
        return 'vehicle.status.changed';
    }

    public function broadcastWith()
    {
        return [
            'vehicle' => [
                'id' => $this->vehicle->id,
                'is_active' => $this->vehicle->is_active
            ]
        ];
    }
}