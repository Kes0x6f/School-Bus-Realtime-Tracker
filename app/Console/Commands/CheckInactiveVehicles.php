<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use Illuminate\Console\Command;

class CheckInactiveVehicles extends Command
{
    protected $signature   = 'vehicles:check-inactive';
    protected $description = 'Marks vehicles as GPS-stale after 3 min, auto-ends shift after 20 min of no GPS updates.';

    const GPS_STALE_SECONDS      = 180;  // 3 minutes
    const SHIFT_AUTO_END_SECONDS = 1200; // 20 minutes

    public function handle()
    {
        \Log::info('[CheckInactiveVehicles] Running', ['time' => now()]);

        $vehicles = Vehicle::where('shift_active', true)->get();

        foreach ($vehicles as $vehicle) {
            // FIX: guard both last_seen AND shift_started_at.
            // Previously only last_seen was guarded — a null shift_started_at
            // would throw "Call to member function diffInSeconds() on null"
            // and crash the entire cron run, skipping all remaining vehicles.
            if (!$vehicle->last_seen || !$vehicle->shift_started_at) {
                continue;
            }

            $secondsSinceUpdate       = $vehicle->last_seen->diffInSeconds(now());
            $secondsSinceShiftStarted = $vehicle->shift_started_at->diffInSeconds(now());

            \Log::info('[CheckInactiveVehicles] Vehicle check', [
                'id'            => $vehicle->id,
                'last_seen'     => $vehicle->last_seen,
                'seconds_since' => $secondsSinceUpdate,
                'is_active'     => $vehicle->is_active,
                'shift_active'  => $vehicle->shift_active,
            ]);

            // Threshold 2: Auto-end shift (20 min no GPS).
            if (
                $secondsSinceUpdate       >= self::SHIFT_AUTO_END_SECONDS &&
                $secondsSinceShiftStarted >= self::SHIFT_AUTO_END_SECONDS
            ) {
                \Log::info('[CheckInactiveVehicles] Auto-ending shift', [
                    'vehicle_id'    => $vehicle->id,
                    'seconds_since' => $secondsSinceUpdate,
                ]);

                Shift::log($vehicle, 'auto');

                $vehicle->update([
                    'shift_active'   => false,
                    'shift_ended_at' => now(),
                    'is_active'      => false,
                ]);

                $vehicle->refresh();
                broadcast(new VehicleStatusChanged($vehicle));
                continue;
            }

            // Threshold 1: GPS stale (3 min no GPS).
            if ($secondsSinceUpdate >= self::GPS_STALE_SECONDS && $vehicle->is_active) {
                \Log::info('[CheckInactiveVehicles] Marking GPS stale / disconnected', [
                    'vehicle_id'    => $vehicle->id,
                    'seconds_since' => $secondsSinceUpdate,
                ]);

                $vehicle->update(['is_active' => false]);
                $vehicle->refresh();
                broadcast(new VehicleStatusChanged($vehicle));
            }
        }
    }
}