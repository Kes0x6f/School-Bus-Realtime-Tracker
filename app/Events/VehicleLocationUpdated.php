<?php


namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Broadcasting\PrivateChannel;
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
         return [
            new PrivateChannel('vehicle.' . $this->vehicle->id),
            new PrivateChannel('vehicles')
        ];
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
            'last_seen' => $this->vehicle->last_seen,
            'route_name' => $this->vehicle->route_name,
        ];
    }
}