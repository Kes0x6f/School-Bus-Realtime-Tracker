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
        'user_id',          // ← was missing; needed for admin assign endpoints
        'is_active',
        'shift_active',
        'shift_started_at',
        'shift_ended_at',
        'latitude',
        'longitude',
        'speed',
        'last_seen',
        'route_name',
        'is_full',
    ];

    protected $casts = [
        'last_seen'        => 'datetime',
        'shift_started_at' => 'datetime',
        'shift_ended_at'   => 'datetime',
        'is_active'        => 'boolean',
        'shift_active'     => 'boolean',
        'is_full'          => 'boolean',
    ];

    protected $appends = ['gps_status'];

    public function getGpsStatusAttribute(): string
    {
        if (!$this->shift_active) return 'shift_ended';
        if (!$this->is_active)   return 'disconnected';
        if (($this->speed ?? 0) < 1) return 'idle';
        return 'moving';
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