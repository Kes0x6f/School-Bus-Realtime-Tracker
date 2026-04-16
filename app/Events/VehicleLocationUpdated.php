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
    public Vehicle $vehicle;

    public function __construct(Vehicle $vehicle)
    {
        $this->vehicle = $vehicle;
    }

    public function broadcastOn(): array
    {
         return [
            new PrivateChannel('vehicle.' . $this->vehicle->id),
            new PrivateChannel('vehicles'),
        ];
    }
    
    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array 
    {
        return [
            'id' => $this->vehicle->id,
            'latitude' => $this->vehicle->latitude,
            'longitude' => $this->vehicle->longitude,
            'speed' => $this->vehicle->speed,
            'last_seen' => $this->vehicle->last_seen,
            'route_name' => $this->vehicle->route_name,
            'shift_active' => $this->vehicle->shift_active,
            'is_active'    => $this->vehicle->is_active,
            'gps_status'   => $this->vehicle->gps_status, // moving|idle|disconnected|shift_ended
            'is_full' => $this->vehicle->is_full,
        ];
    }
}