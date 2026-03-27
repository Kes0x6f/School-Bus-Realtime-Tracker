<?php


namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class VehicleLocationUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;
    public $vehicle;

    public function __construct(Vehicle $vehicle)
    {
        $this->vehicle = $vehicle;
    }

    public function broadcastOn()
    {
        return new Channel('vehicle.' . $this->vehicle->id);
    }

    public function broadcastAs()
    {
        return 'location.updated';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->vehicle->id,
            'latitude' => $this->vehicle->latitude,
            'longitude' => $this->vehicle->longitude,
            'speed' => $this->vehicle->speed,
            'last_seen' => $this->vehicle->last_seen
        ];
    }
}