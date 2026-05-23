<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * last_moved_at — timestamp of the most recent GPS update where speed ≥ 3 km/h.
     *
     * Used by Vehicle::getGpsStatusAttribute() to distinguish:
     *   traffic — speed < 3 km/h but moved within the last 5 minutes
     *   idle    — speed < 3 km/h and stationary for more than 5 minutes
     *
     * Nullable: null means the jeep has not reached the movement threshold
     * since its current shift started (treated the same as idle).
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('last_moved_at')->nullable()->after('last_seen');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('last_moved_at');
        });
    }
};