<?php

namespace App\Models;

use App\Enums\ShiftEndReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'route_name',
        'started_at',
        'ended_at',
        'duration_seconds',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function getDurationHumanAttribute(): string
    {
        if (!$this->duration_seconds) return '--';
        $h = intdiv($this->duration_seconds, 3600);
        $m = intdiv($this->duration_seconds % 3600, 60);
        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }

    // ─── Static helper ────────────────────────────────────────────────────────

    /**
     * Write a completed shift record.
     * Call this at every shift-ending point before updating the vehicle row.
     *
     * end_reason: 'manual' | 'logout' | 'auto' | 'account_deactivated'
     */
    public static function log(Vehicle $vehicle, ShiftEndReason|string $endReason): self
    {
        $endReason = $endReason instanceof ShiftEndReason
            ? $endReason->value
            : ShiftEndReason::from($endReason)->value;

        return self::create([
            'vehicle_id'       => $vehicle->id,
            'user_id'          => $vehicle->user_id,
            'route_name'       => $vehicle->route_name,
            'started_at'       => $vehicle->shift_started_at,
            'ended_at'         => now(),
            'duration_seconds' => $vehicle->shift_started_at
                                    ? (int) $vehicle->shift_started_at->diffInSeconds(now())
                                    : null,
            'end_reason'       => $endReason,
        ]);
    }
}
