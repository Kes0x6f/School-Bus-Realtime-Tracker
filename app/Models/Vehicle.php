<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'plate_number',
        'driver_name',
        'user_id',
        'is_active',
        'shift_active',
        'shift_started_at',
        'shift_ended_at',
        'latitude',
        'longitude',
        'speed',
        'last_seen',
        'last_moved_at',     // updated whenever speed ≥ 3 km/h — used for traffic vs idle
        'route_name',
        'is_full',
    ];

    protected $casts = [
        'last_seen'        => 'datetime',
        'shift_started_at' => 'datetime',
        'shift_ended_at'   => 'datetime',
        'last_moved_at'    => 'datetime',
        'is_active'        => 'boolean',
        'shift_active'     => 'boolean',
        'is_full'          => 'boolean',
    ];

    protected $appends = ['gps_status'];

    /**
     * Compute the GPS status from current speed and movement history.
     *
     * moving      — speed ≥ 3 km/h  (clearly rolling)
     * traffic     — speed < 3 km/h but moved within the last 5 min
     *               (stopped at a light or crawling in a queue)
     * idle        — speed < 3 km/h AND no meaningful movement for > 5 min
     *               (waiting for passengers, parked at terminal, etc.)
     * disconnected — shift active but GPS stale (set by CheckInactiveVehicles)
     * shift_ended  — shift not active
     *
     * The 3 km/h threshold sits above typical GPS noise at low speeds so a
     * stationary jeep with jittery readings doesn't flicker into 'moving'.
     * The 5-minute window matches human intuition: a red light resolves in
     * seconds, a traffic jam in minutes; 5 min is a generous but fair cut-off.
     */
    public function getGpsStatusAttribute(): string
    {
        if (!$this->shift_active) return 'shift_ended';
        if (!$this->is_active)   return 'disconnected';

        $speed = $this->speed ?? 0;

        if ($speed >= 3) return 'moving';

        // Speed is low — determine whether this is a temporary stop (traffic)
        // or a prolonged wait (idle) using last_moved_at.
        $secondsSinceMove = $this->last_moved_at
            ? (int) $this->last_moved_at->diffInSeconds(now())
            : PHP_INT_MAX;   // never moved this shift → treat as idle

        return $secondsSinceMove < 300 ? 'traffic' : 'idle';
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function latestLocation()
    {
        return $this->hasOne(Location::class)->latestOfMany('recorded_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }
}