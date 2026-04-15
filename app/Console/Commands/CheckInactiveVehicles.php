<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;

use Illuminate\Console\Command;

class CheckInactiveVehicles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicles:check-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    
    public function handle()
    {
        \Log::info('COMMAND RUNNING NOW', [
            'time' => now()
        ]);
        $vehicles = Vehicle::all();

        foreach ($vehicles as $vehicle) {
            $isInactive = $vehicle->last_seen &&
              now()->greaterThan($vehicle->last_seen->addSeconds(60));
            
            \Log::info('CHECKING VEHICLE', [
                'id' => $vehicle->id,
                'last_seen' => $vehicle->last_seen,
                'diff' => $vehicle->last_seen ? now()->diffInSeconds($vehicle->last_seen) : null,
                'is_active_db' => $vehicle->is_active
            ]);
            if ($isInactive) {
                
                \Log::info('SETTING INACTIVE', [
                    'vehicle_id' => $vehicle->id
                ]);

                $vehicle->update(['is_active' => false]);

                $vehicle->refresh();

                broadcast(new VehicleStatusChanged($vehicle));
            }
        }
    }
}
