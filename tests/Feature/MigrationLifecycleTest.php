<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('reverses every migration one step at a time and rebuilds the schema', function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        $this->markTestSkipped('The destructive lifecycle audit only runs on the isolated in-memory database.');
    }

    $rollbackAssertions = [
        '2026_08_24_150000_enforce_one_active_shift_per_vehicle' => fn () => expect(
            Schema::hasColumn('shifts', 'active_marker')
        )->toBeFalse(),
        '2026_08_24_140000_change_vehicle_user_fk_to_set_null' => fn () => expect(
            Schema::hasColumn('vehicles', 'user_id')
        )->toBeTrue(),
        '2026_08_24_130000_add_current_shift_id_to_vehicles_table' => fn () => expect(
            Schema::hasColumn('vehicles', 'current_shift_id')
        )->toBeFalse(),
        '2026_08_24_120000_rename_speed_columns_to_speed_mps' => function (): void {
            expect(Schema::hasColumn('vehicles', 'speed'))->toBeTrue()
                ->and(Schema::hasColumn('vehicles', 'speed_mps'))->toBeFalse()
                ->and(Schema::hasColumn('locations', 'speed'))->toBeTrue()
                ->and(Schema::hasColumn('locations', 'speed_mps'))->toBeFalse();
        },
        '2026_05_22_235949_add_last_moved_at_to_vehicles_table' => fn () => expect(
            Schema::hasColumn('vehicles', 'last_moved_at')
        )->toBeFalse(),
        '2026_05_11_103513_create_announcements_table' => fn () => expect(
            Schema::hasTable('announcements')
        )->toBeFalse(),
        '2026_04_29_053332_add_is_active_to_users' => fn () => expect(
            Schema::hasColumn('users', 'is_active')
        )->toBeFalse(),
        '2026_04_29_053217_create_shifts_table' => fn () => expect(
            Schema::hasTable('shifts')
        )->toBeFalse(),
        '2026_04_15_051350_add_shift_fields_to_vehicles_table' => function (): void {
            expect(Schema::hasColumn('vehicles', 'shift_active'))->toBeFalse()
                ->and(Schema::hasColumn('vehicles', 'shift_started_at'))->toBeFalse()
                ->and(Schema::hasColumn('vehicles', 'shift_ended_at'))->toBeFalse();
        },
        '2026_04_06_082504_add_route_and_capacity_to_vehicles_table' => function (): void {
            expect(Schema::hasColumn('vehicles', 'route_name'))->toBeFalse()
                ->and(Schema::hasColumn('vehicles', 'is_full'))->toBeFalse();
        },
        '2026_03_29_043626_add_user_id_to_vehicles_table' => fn () => expect(
            Schema::hasColumn('vehicles', 'user_id')
        )->toBeFalse(),
        '2026_03_29_021356_add_role_to_users_table' => fn () => expect(
            Schema::hasColumn('users', 'role')
        )->toBeFalse(),
        '2026_03_04_075851_add_tracking_fields_to_vehicles_table' => function (): void {
            foreach (['latitude', 'longitude', 'speed', 'last_seen'] as $column) {
                expect(Schema::hasColumn('vehicles', $column))->toBeFalse();
            }
        },
        '2026_03_03_034301_create_locations_table' => fn () => expect(
            Schema::hasTable('locations')
        )->toBeFalse(),
        '2026_03_03_033532_create_vehicles_table' => fn () => expect(
            Schema::hasTable('vehicles')
        )->toBeFalse(),
        '0001_01_01_000002_create_jobs_table' => function (): void {
            expect(Schema::hasTable('jobs'))->toBeFalse()
                ->and(Schema::hasTable('job_batches'))->toBeFalse()
                ->and(Schema::hasTable('failed_jobs'))->toBeFalse();
        },
        '0001_01_01_000001_create_cache_table' => function (): void {
            expect(Schema::hasTable('cache'))->toBeFalse()
                ->and(Schema::hasTable('cache_locks'))->toBeFalse();
        },
        '0001_01_01_000000_create_users_table' => function (): void {
            expect(Schema::hasTable('sessions'))->toBeFalse()
                ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
                ->and(Schema::hasTable('users'))->toBeFalse();
        },
    ];

    foreach ($rollbackAssertions as $migration => $assertRolledBack) {
        expect(DB::table('migrations')->orderByDesc('migration')->value('migration'))
            ->toBe($migration);

        $this->artisan('migrate:rollback', [
            '--step' => 1,
            '--force' => true,
        ])->assertSuccessful();

        $assertRolledBack();
    }

    expect(DB::table('migrations')->count())->toBe(0);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('vehicles', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('vehicles', 'current_shift_id'))->toBeTrue()
        ->and(Schema::hasColumn('shifts', 'active_marker'))->toBeTrue();
});
