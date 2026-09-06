<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Vehicle;

final class ShiftCompletionResult
{
    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly ?Shift $shift,
        public readonly bool $completed,
    ) {
    }
}
