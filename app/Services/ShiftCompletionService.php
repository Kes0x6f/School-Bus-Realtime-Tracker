<?php

namespace App\Services;

use App\Enums\ShiftEndReason;
use App\Enums\VehicleRoute;
use App\Events\VehicleStatusChanged;
use App\Models\Shift;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShiftCompletionService
{
    /**
     * Start a driver's shift in one transaction and broadcast committed state.
     */
    public function startForDriver(int $userId, ?VehicleRoute $route = null): ?Vehicle
    {
        $vehicle = DB::transaction(function () use ($userId, $route): ?Vehicle {
            $lockedVehicle = Vehicle::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedVehicle->shift_active) {
                return null;
            }

            $startedAt = now();
            $routeName = $route?->value
                ?? VehicleRoute::tryFrom((string) $lockedVehicle->route_name)?->value;

            $shift = Shift::create([
                'vehicle_id'    => $lockedVehicle->id,
                'user_id'       => $lockedVehicle->user_id,
                'route_name'    => $routeName,
                'started_at'    => $startedAt,
                'active_marker' => true,
            ]);

            $lockedVehicle->update([
                'shift_active'     => true,
                'shift_started_at' => $startedAt,
                'shift_ended_at'   => null,
                'current_shift_id' => $shift->id,
                'is_active'        => false,
                'latitude'         => null,
                'longitude'        => null,
                'speed_mps'        => null,
                'last_seen'        => null,
                'last_moved_at'    => null,
                'route_name'       => $routeName,
            ]);

            return $lockedVehicle->fresh();
        });

        $this->broadcastStatus($vehicle, $vehicle?->current_shift_id);

        return $vehicle;
    }

    /**
     * Complete a shift in its own transaction and broadcast after commit.
     */
    public function complete(Vehicle $vehicle, ShiftEndReason|string $reason): ShiftCompletionResult
    {
        $result = DB::transaction(
            fn (): ShiftCompletionResult => $this->completeWithinTransaction($vehicle, $reason)
        );

        if ($result->completed) {
            $this->broadcastStatus($result->vehicle, $result->shift?->id);
        }

        return $result;
    }

    /**
     * Complete a shift while the caller owns an open transaction.
     *
     * The vehicle row is locked here so deactivation, logout, cron, and a
     * concurrent driver request cannot create duplicate shift records.
     */
    public function completeWithinTransaction(
        Vehicle $vehicle,
        ShiftEndReason|string $reason,
    ): ShiftCompletionResult
    {
        $endReason = $reason instanceof ShiftEndReason
            ? $reason->value
            : ShiftEndReason::from($reason)->value;

        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (!$lockedVehicle->shift_active) {
            return new ShiftCompletionResult(
                $lockedVehicle->fresh(),
                $this->findCompletedShift($lockedVehicle),
                false,
            );
        }

        if (!$lockedVehicle->shift_started_at) {
            // Active rows without a start timestamp are malformed and cannot
            // produce a meaningful duration. Repair them at completion time
            // so every completion still has one valid history row.
            Log::warning('[ShiftCompletionService] Repairing active shift without start timestamp', [
                'vehicle_id' => $lockedVehicle->id,
                'last_seen'  => $lockedVehicle->last_seen,
                'is_active'  => $lockedVehicle->is_active,
                'end_reason' => $endReason,
            ]);

            $lockedVehicle->update(['shift_started_at' => now()]);
            $lockedVehicle->refresh();
        }

        $endedAt = now();
        $shift = $this->findCurrentShift($lockedVehicle);

        if (!$shift) {
            // Legacy active rows may predate current_shift_id. Reconstruct one
            // history row inside this same transaction before ending the row.
            $shift = Shift::create([
                'vehicle_id' => $lockedVehicle->id,
                'user_id'    => $lockedVehicle->user_id,
                'route_name' => $lockedVehicle->route_name,
                'started_at' => $lockedVehicle->shift_started_at,
            ]);
        }

        $shift->forceFill([
            'ended_at'         => $endedAt,
            'duration_seconds' => (int) $lockedVehicle->shift_started_at->diffInSeconds($endedAt),
            'end_reason'       => $endReason,
            'active_marker'    => null,
        ])->save();

        $lockedVehicle->update([
            'shift_active'     => false,
            'shift_ended_at'   => $endedAt,
            'current_shift_id' => null,
            'is_active'        => false,
        ]);

        return new ShiftCompletionResult(
            $lockedVehicle->fresh(),
            $shift->fresh(),
            true,
        );
    }

    private function findCurrentShift(Vehicle $vehicle): ?Shift
    {
        if ($vehicle->current_shift_id) {
            $shift = Shift::query()
                ->whereKey($vehicle->current_shift_id)
                ->lockForUpdate()
                ->first();

            if ($shift && !$shift->ended_at) {
                return $shift;
            }
        }

        return Shift::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('ended_at')
            ->where('started_at', $vehicle->shift_started_at)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function findCompletedShift(Vehicle $vehicle): ?Shift
    {
        return Shift::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('ended_at')
            ->where('started_at', $vehicle->shift_started_at)
            ->latest('id')
            ->first();
    }

    public function broadcastStatus(?Vehicle $vehicle, ?int $shiftId = null): void
    {
        if ($vehicle) {
            broadcast(new VehicleStatusChanged($vehicle, $shiftId));
        }
    }
}
