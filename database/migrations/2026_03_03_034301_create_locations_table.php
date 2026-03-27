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
         Schema::create('locations', function (Blueprint $table) {
        $table->id();

        $table->foreignId('vehicle_id')
              ->constrained()
              ->onDelete('cascade');

        $table->decimal('latitude', 10, 7);
        $table->decimal('longitude', 10, 7);

        $table->float('speed')->nullable();

        $table->timestamp('recorded_at');

        $table->timestamps();

        $table->index(['vehicle_id', 'recorded_at']);
    }); 
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
