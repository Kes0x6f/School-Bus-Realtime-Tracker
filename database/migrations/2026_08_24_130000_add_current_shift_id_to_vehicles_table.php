<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('current_shift_id')
                ->nullable()
                ->after('shift_ended_at')
                ->unique()
                ->constrained('shifts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_shift_id');
        });
    }
};
