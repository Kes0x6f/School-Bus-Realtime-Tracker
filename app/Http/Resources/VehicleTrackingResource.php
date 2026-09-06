<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleTrackingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Return the contract consumed by the single-vehicle tracking page.
     * Coordinates and status remain available to authorized users so a page
     * can render the last known position after a shift has ended.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'plate_number'     => $this->plate_number,
            'latitude'         => $this->latitude,
            'longitude'        => $this->longitude,
            'speed_mps'        => $this->speed_mps,
            'speed_kph'        => $this->speed_kph,
            'last_seen'        => $this->last_seen?->toISOString(),
            'shift_active'     => $this->shift_active,
            'is_active'        => $this->is_active,
            'gps_status'       => $this->gps_status,
            'shift_started_at' => $this->shift_started_at?->toISOString(),
            'shift_ended_at'   => $this->shift_ended_at?->toISOString(),
            'route_name'       => $this->route_name,
        ];
    }
}
