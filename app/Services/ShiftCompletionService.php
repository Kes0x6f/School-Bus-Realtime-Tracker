<?php

namespace App\Services;

use App\Enums\ShiftEndReason;
use App\Events\VehicleStatusChanged;
use App\Models\Shift;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class ShiftCompletionService
{
    /**
     * Complete a shift in its own transaction and broadcast after commit.
     */
    public function complete(Vehicle $vehicle, ShiftEndReason|string $reason): ?Vehicle
    {
        $completedVehicle = DB::transaction(
            fn (): ?Vehicle => $this->completeWithinTransaction($vehicle, $reason)
        );

        $this->broadcastStatus($completedVehicle);

        return $completedVehicle;
    }

    /**
     * Complete a shift while the caller owns an open transaction.
     *
     * The vehicle row is locked here so deactivation, logout, cron, and a
     * concurrent driver request cannot create duplicate shift records.
     */
    public function completeWithinTransaction(Vehicle $vehicle, ShiftEndReason|string $reason): ?Vehicle
    {
        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (!$lockedVehicle->shift_active) {
            return null;
        }

        $endReason = $reason instanceof ShiftEndReason
            ? $reason->value
            : ShiftEndReason::from($reason)->value;

        Shift::log($lockedVehicle, $endReason);

        $lockedVehicle->update([
            'shift_active'   => false,
            'shift_ended_at' => now(),
            'is_active'      => false,
        ]);

        return $lockedVehicle->fresh();
    }

    public function broadcastStatus(?Vehicle $vehicle): void
    {
        if ($vehicle) {
            broadcast(new VehicleStatusChanged($vehicle));
        }
    }
}
