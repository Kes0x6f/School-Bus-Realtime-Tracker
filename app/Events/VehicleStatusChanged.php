<?php

namespace App\Events;


use App\Models\Vehicle;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VehicleStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Vehicle $vehicle;

    public function __construct(Vehicle $vehicle)
    {
        $this->vehicle = $vehicle;
    }

    public function broadcastOn(): array
    {
        return [
            // Global channel — active-jeeps list listens here
            new PrivateChannel('vehicles'),
            // Per-vehicle channel — tracking page listens here
            new PrivateChannel('vehicle.' . $this->vehicle->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'vehicle.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'vehicle' => [
                'id'               => $this->vehicle->id,
                'shift_active'     => $this->vehicle->shift_active,
                'is_active'        => $this->vehicle->is_active,
                'gps_status'       => $this->vehicle->gps_status, // moving|idle|disconnecte shift_ended
                'speed'            => $this->vehicle->speed,
                'last_seen'        => $this->vehicle->last_seen?->toISOString(),
                'shift_started_at' => $this->vehicle->shift_started_at?->toISOString(),
                'shift_ended_at'   => $this->vehicle->shift_ended_at?->toISOString(),
                'route_name'       => $this->vehicle->route_name,
                'is_full'          => $this->vehicle->is_full,
                // Included so active-jeeps.js addVehicle() can render the
                // operator name on cards added while the page is already open.
                'user'             => $this->vehicle->user
                                        ? ['name' => $this->vehicle->user->name]
                                        : null,
            ]
        ];
    }
}