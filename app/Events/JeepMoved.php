<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class JeepMoved implements ShouldBroadcastNow
{
    public float $lat;
    public float $long;   
    /**
     * Create a new event instance.
     */
    public function __construct($lat, $long)
    {
        $this->lat = $lat;
        $this->long = $long;
    }

    public function broadcastOn(): array
    {
        return ['jeep_tracker'];
    }

    public function broadcastWith(): array
    {
        return [
            'lat' => $this->lat,
            'long' => $this->long,
        ];
    }

}
