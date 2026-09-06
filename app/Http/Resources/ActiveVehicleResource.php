<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveVehicleResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Return only fields required by the active-vehicle tracking UI.
     * Assignment identifiers and the rest of the User model are not public
     * tracking data, so they are deliberately excluded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'route_name'       => $this->route_name,
            'is_full'          => $this->is_full,
            'user'             => $this->whenLoaded('user', fn () => $this->user
                ? ['name' => $this->user->name]
                : null),
            'latitude'         => $this->latitude,
            'longitude'        => $this->longitude,
            'speed_mps'        => $this->speed_mps,
            'speed_kph'        => $this->speed_kph,
            'last_seen'        => $this->last_seen?->toISOString(),
            'shift_active'     => $this->shift_active,
            'is_active'        => $this->is_active,
            'gps_status'       => $this->gps_status,
            'shift_started_at' => $this->shift_started_at?->toISOString(),
        ];
    }
}
