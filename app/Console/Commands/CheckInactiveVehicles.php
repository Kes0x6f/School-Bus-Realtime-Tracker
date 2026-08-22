<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use App\Services\ShiftCompletionService;
use App\Enums\ShiftEndReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckInactiveVehicles extends Command
{
    protected $signature   = 'vehicles:check-inactive';
    protected $description = 'Marks vehicles as GPS-stale and auto-ends inactive shifts using the configured policy.';

    public function handle(ShiftCompletionService $shiftCompletion)
    {
        $now = now();
        $gpsStaleSeconds = (int) config('shifts.gps_stale_seconds');
        $shiftAutoEndSeconds = (int) config('shifts.auto_end_seconds');

        Log::info('[CheckInactiveVehicles] Running', [
            'time'             => $now,
            'gps_stale_seconds' => $gpsStaleSeconds,
            'auto_end_seconds'  => $shiftAutoEndSeconds,
        ]);

        $vehicles = Vehicle::where('shift_active', true)->get();

        foreach ($vehicles as $vehicle) {
            if (!$vehicle->shift_started_at) {
                Log::warning('[CheckInactiveVehicles] Recovering malformed active shift', [
                    'vehicle_id'     => $vehicle->id,
                    'last_seen'      => $vehicle->last_seen,
                    'is_active'      => $vehicle->is_active,
                    'shift_ended_at' => $vehicle->shift_ended_at,
                ]);

                // A missing start timestamp cannot produce a trustworthy
                // duration. ShiftCompletionService repairs it to the cleanup
                // time and records one zero-duration auto-ended shift.
                $shiftCompletion->complete($vehicle, ShiftEndReason::AUTO);
                continue;
            }

            // A GPS timestamp from before this shift belongs to the previous
            // shift. Before the first fix, the shift start is the inactivity
            // baseline so the scheduler can still end the shift.
            $lastUpdate = $vehicle->last_seen;
            if (!$lastUpdate || $lastUpdate->lt($vehicle->shift_started_at)) {
                $lastUpdate = $vehicle->shift_started_at;
            }

            $secondsSinceUpdate = max(0, (int) $lastUpdate->diffInSeconds($now, false));

            Log::info('[CheckInactiveVehicles] Vehicle check', [
                'id'            => $vehicle->id,
                'last_seen'     => $vehicle->last_seen,
                'last_update'   => $lastUpdate,
                'seconds_since' => $secondsSinceUpdate,
                'is_active'     => $vehicle->is_active,
                'shift_active'  => $vehicle->shift_active,
            ]);

            if ($secondsSinceUpdate >= $shiftAutoEndSeconds) {
                Log::info('[CheckInactiveVehicles] Auto-ending shift', [
                    'vehicle_id'    => $vehicle->id,
                    'seconds_since' => $secondsSinceUpdate,
                ]);

                $shiftCompletion->complete($vehicle, ShiftEndReason::AUTO);
                continue;
            }

            if ($secondsSinceUpdate >= $gpsStaleSeconds && $vehicle->is_active) {
                Log::info('[CheckInactiveVehicles] Marking GPS stale / disconnected', [
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
