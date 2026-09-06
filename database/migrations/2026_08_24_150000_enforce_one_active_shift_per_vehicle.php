<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            // Completed rows use NULL, while the single open row uses TRUE.
            // The composite unique key works on both MySQL and SQLite because
            // both permit multiple NULL values in a unique index.
            $table->boolean('active_marker')->nullable()->after('end_reason');
            $table->unique(
                ['vehicle_id', 'active_marker'],
                'shifts_one_active_per_vehicle',
            );
        });

        // Preserve any current-shift pointers created before this invariant
        // was deployed. Legacy completed rows intentionally remain NULL.
        DB::table('vehicles')
            ->whereNotNull('current_shift_id')
            ->select(['id', 'current_shift_id'])
            ->orderBy('id')
            ->eachById(function (object $vehicle): void {
                DB::table('shifts')
                    ->where('id', $vehicle->current_shift_id)
                    ->where('vehicle_id', $vehicle->id)
                    ->whereNull('ended_at')
                    ->update(['active_marker' => true]);
            });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropUnique('shifts_one_active_per_vehicle');
            $table->dropColumn('active_marker');
        });
    }
};
