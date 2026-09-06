<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    /**
     * Students and administrators may list vehicles visible to the tracker.
     * Middleware remains the first authorization boundary for these routes;
     * this policy keeps the rule explicit for controller-level checks too.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['student', 'admin'], true);
    }

    /**
     * A tracking page may request an ended vehicle so the client can display
     * its last known position and the shift-ended state. The active collection
     * itself is restricted to vehicles whose shift is currently active.
     */
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->is_active && in_array($user->role, ['student', 'admin'], true);
    }
}
