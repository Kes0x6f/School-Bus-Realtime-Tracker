<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The old values already came directly from navigator.geolocation in m/s,
     * so this migration renames the columns without multiplying the data.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->renameColumn('speed', 'speed_mps');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->renameColumn('speed', 'speed_mps');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->renameColumn('speed_mps', 'speed');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->renameColumn('speed_mps', 'speed');
        });
    }
};
