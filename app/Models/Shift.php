<?php

namespace App\Models;

use App\Enums\ShiftEndReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'active_marker',
    ];

    protected $casts = [
        'started_at'    => 'datetime',
        'ended_at'      => 'datetime',
        'end_reason'    => ShiftEndReason::class,
        'active_marker' => 'boolean',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDurationHumanAttribute(): string
    {
        if (!$this->duration_seconds) return '--';

        $hours = intdiv($this->duration_seconds, 3600);
        $minutes = intdiv($this->duration_seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }
}
