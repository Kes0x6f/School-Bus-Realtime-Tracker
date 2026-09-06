<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    use HasFactory;

    /**
     * Browser Geolocation API speed arrives and is stored in meters per
     * second. Convert to km/h only at presentation boundaries.
     */
    public const MOVING_THRESHOLD_MPS = 3 / 3.6;
    public const TRAFFIC_THRESHOLD_MPS = 0.5 / 3.6;
    public const MAX_SPEED_MPS = 55.5555555556;
    public const KILOMETERS_PER_HOUR_PER_MPS = 3.6;

    protected $fillable = [
        'plate_number',
        'driver_name',
        'user_id',
        'is_active',
        'shift_active',
        'shift_started_at',
        'shift_ended_at',
        'current_shift_id',
        'latitude',
        'longitude',
        'speed_mps',
        'last_seen',
        'last_moved_at',     // updated when speed_mps reaches the moving threshold
        'route_name',
        'is_full',
    ];

    protected $casts = [
        'last_seen'        => 'datetime',
        'speed_mps'        => 'float',
        'shift_started_at' => 'datetime',
        'shift_ended_at'   => 'datetime',
        'current_shift_id' => 'integer',
        'last_moved_at'    => 'datetime',
        'is_active'        => 'boolean',
        'shift_active'     => 'boolean',
        'is_full'          => 'boolean',
    ];

    protected $appends = ['gps_status', 'speed_kph'];

    public static function speedMpsToKph(?float $speedMps): ?float
    {
        return $speedMps === null
            ? null
            : $speedMps * self::KILOMETERS_PER_HOUR_PER_MPS;
    }

    public function getSpeedKphAttribute(): ?float
    {
        return self::speedMpsToKph($this->speed_mps === null
            ? null
            : (float) $this->speed_mps);
    }

    /**
     * Compute the GPS status from current speed and movement history.
     *
     * moving      — speed_mps ≥ MOVING_THRESHOLD_MPS (3 km/h)
     * traffic     — speed_mps below the moving threshold but moved within
     *               the last 5 min
     *               (stopped at a light or crawling in a queue)
     * idle        — speed_mps below the moving threshold AND no meaningful
     *               movement for > 5 min
     *               (waiting for passengers, parked at terminal, etc.)
     * disconnected — shift active but GPS stale (set by CheckInactiveVehicles)
     * shift_ended  — shift not active
     *
     * The 3 km/h threshold is expressed as MOVING_THRESHOLD_MPS so a
     * stationary jeep with jittery readings doesn't flicker into 'moving'.
     * The 5-minute window matches human intuition: a red light resolves in
     * seconds, a traffic jam in minutes; 5 min is a generous but fair cut-off.
     */
    public function getGpsStatusAttribute(): string
    {
        if (!$this->shift_active) return 'shift_ended';
        if (!$this->is_active)   return 'disconnected';

        $speedMps = $this->speed_mps ?? 0;

        if ($speedMps >= self::MOVING_THRESHOLD_MPS) return 'moving';

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

    public function currentShift()
    {
        return $this->belongsTo(Shift::class, 'current_shift_id');
    }
}
