<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // driver shift status
            $table->boolean('shift_active')->default(false)->after('is_active');
 
            // shift start for display and history
            $table->timestamp('shift_started_at')->nullable()->after('shift_active');
 
            // shift end for display and auto-shift end tracking
            $table->timestamp('shift_ended_at')->nullable()->after('shift_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['shift_active', 'shift_started_at', 'shift_ended_at']);
        });
    }
};
