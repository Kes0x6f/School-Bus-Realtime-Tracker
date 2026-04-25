<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use Illuminate\Console\Command;

class CheckInactiveVehicles extends Command
{
    protected $signature = 'vehicles:check-inactive';
    protected $description = 'Marks vehicles as GPS-stale after 3 min, auto-ends shift after 20 min of no GPS updates.';
    /**
     * Thresholds:
     *
     *  GPS_STALE_SECONDS    (3 min)  → GPS is considered lost. Set is_active=false.
     *                                  Broadcasts DISCONNECTED state.
     *                                  Vehicle stays on the active list (shift is still on).
     *                                  Covers dead zones — driver is likely still working.
     *
     *  SHIFT_AUTO_END_SECONDS (20 min) → If still no GPS after 20 min, assume driver
     *                                  has ended their shift (parked, turned off device).
     *                                  Sets shift_active=false, broadcasts SHIFT_ENDED.
     *                                  Vehicle is removed from the active list.
     */

    const GPS_STALE_SECONDS     = 30;   // 3 minutes
    const SHIFT_AUTO_END_SECONDS = 60;  // 20 minutes

    public function handle()
    {
        \Log::info('[CheckInactiveVehicles] Running', ['time' => now()]);
 
        // Only check vehicles that are currently on a shift.
        // No point evaluating vehicles that are already shift_ended.
        $vehicles = Vehicle::where('shift_active', true)->get();
        foreach ($vehicles as $vehicle) {
            if (!$vehicle->last_seen) {
                continue;
            }

            $secondsSinceUpdate = $vehicle->last_seen->diffInSeconds(now());
            $secondsSinceShiftStarted = $vehicle->shift_started_at->diffInSeconds(now());
 
            \Log::info('[CheckInactiveVehicles] Vehicle check', [
                'id'              => $vehicle->id,
                'last_seen'       => $vehicle->last_seen,
                'seconds_since'   => $secondsSinceUpdate,
                'is_active'       => $vehicle->is_active,
                'shift_active'    => $vehicle->shift_active,
            ]);
 
            // Threshold 2: Auto-end shift (20 min no GPS)
            // Check this first so we don't also fire the GPS-stale event
            // for a vehicle that's being fully ended.
            // Check if the shift has just started and make sure that it's not checking against last update from last shift
            if ($secondsSinceUpdate >= self::SHIFT_AUTO_END_SECONDS && $secondsSinceShiftStarted >= self::SHIFT_AUTO_END_SECONDS) {
 
                \Log::info('[CheckInactiveVehicles] Auto-ending shift', [
                    'vehicle_id'     => $vehicle->id,
                    'seconds_since'  => $secondsSinceUpdate,
                ]);

 
                $vehicle->update([
                    'shift_active'   => false,
                    'shift_ended_at' => now(),
                    'is_active'      => false,
                ]);
 
                $vehicle->refresh();
                broadcast(new VehicleStatusChanged($vehicle));
                continue;
            }
 
            //Threshold 1: GPS stale (3 min no GPS) 
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