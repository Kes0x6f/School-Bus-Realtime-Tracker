<?php

namespace App\Http\Controllers;

use App\Enums\VehicleRoute;
use App\Enums\ShiftEndReason;
use App\Events\UserAccessRevoked;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

class AdminController extends Controller
{
    // ─── Dashboard view ───────────────────────────────────────────────────────

    public function index()
    {
        return view('admin.dashboard');
    }

    // ─── Users ────────────────────────────────────────────────────────────────

    public function users()
    {
        $users = User::with('vehicle')
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'is_active'  => $u->is_active,
                'vehicle_id' => $u->vehicle?->id,
                'vehicle'    => $u->vehicle
                                  ? ['id' => $u->vehicle->id, 'plate_number' => $u->vehicle->plate_number]
                                  : null,
            ]);

        return response()->json($users);
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:driver,student,admin',
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        return response()->json(['status' => 'success', 'user' => $user], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|in:driver,student,admin',
        ]);

        $user->update($validated);

        return response()->json(['status' => 'success', 'user' => $user->fresh()]);
    }

    public function deactivateUser(User $user, ShiftCompletionService $shiftCompletion)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'Cannot deactivate yourself.'], 422);
        }

        $completedVehicle = null;
        $completedShiftId = null;
        $revokedUserId = null;

        DB::transaction(function () use (
            $user,
            $shiftCompletion,
            &$completedVehicle,
            &$completedShiftId,
            &$revokedUserId,
        ): void {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $target->update(['is_active' => false]);
            $revokedUserId = $target->id;

            if ($target->role === 'driver') {
                $vehicle = Vehicle::query()
                    ->where('user_id', $target->id)
                    ->lockForUpdate()
                    ->first();

                if ($vehicle?->shift_active) {
                    $result = $shiftCompletion->completeWithinTransaction(
                        $vehicle,
                        ShiftEndReason::ACCOUNT_DEACTIVATED,
                    );

                    if ($result->completed) {
                        $completedVehicle = $result->vehicle;
                        $completedShiftId = $result->shift?->id;
                    }
                }
            }

            DB::connection(config('session.connection') ?: config('database.default'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $target->id)
                ->delete();
        });

        // Broadcast only after the transaction commits so connected clients
        // never receive a revocation for state that was rolled back.
        if ($revokedUserId) {
            broadcast(new UserAccessRevoked($revokedUserId));
        }

        // VehicleStatusChanged must only be emitted after the deactivation
        // transaction commits, so clients never see a rolled-back shift end.
        $shiftCompletion->broadcastStatus($completedVehicle, $completedShiftId);

        return response()->json(['status' => 'success']);
    }

    public function reactivateUser(User $user)
    {
        $user->update(['is_active' => true]);

        return response()->json(['status' => 'success']);
    }

    public function assignVehicle(Request $request, User $user)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
        ]);

        if ($user->role !== 'driver') {
            return response()->json(['status' => 'error', 'message' => 'User is not a driver.'], 422);
        }

        Vehicle::where('id', $validated['vehicle_id'])
               ->where('user_id', '!=', $user->id)
               ->update(['user_id' => null]);

        Vehicle::where('user_id', $user->id)
               ->where('id', '!=', $validated['vehicle_id'])
               ->update(['user_id' => null]);

        Vehicle::where('id', $validated['vehicle_id'])->update(['user_id' => $user->id]);

        return response()->json(['status' => 'success']);
    }

    // ─── Vehicles ─────────────────────────────────────────────────────────────

    /**
     * FIX 4: now returns users alongside vehicles in a single response.
     * The admin JS uses this to populate the "assign driver" dropdown in the
     * Vehicles tab even when the Users tab has never been visited.
     * The JS stores data.vehicles and data.users separately on arrival.
     */
    public function vehicleList()
    {
        $vehicles = Vehicle::with('user')->get()->map(fn($v) => [
            'id'           => $v->id,
            'plate_number' => $v->plate_number,
            'route_name'   => $v->route_name,
            'gps_status'   => $v->gps_status,
            'shift_active' => $v->shift_active,
            'is_active'    => $v->is_active,
            'last_seen'    => $v->last_seen?->toISOString(),
            'is_full'      => $v->is_full,
            'user_id'      => $v->user_id,
            'user'         => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name] : null,
        ]);

        // Include active drivers so the assign-driver modal has data
        // regardless of whether the Users tab has been visited.
        $drivers = User::where('role', 'driver')
            ->where('is_active', true)
            ->with('vehicle')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'role'       => $u->role,
                'is_active'  => $u->is_active,
                'vehicle_id' => $u->vehicle?->id,
                'vehicle'    => $u->vehicle
                                  ? ['id' => $u->vehicle->id, 'plate_number' => $u->vehicle->plate_number]
                                  : null,
            ]);

        return response()->json([
            'vehicles' => $vehicles,
            'drivers'  => $drivers,
        ]);
    }

    public function storeVehicle(Request $request)
    {
        $validated = $request->validate([
            'plate_number' => 'required|string|max:20|unique:vehicles,plate_number',
            'route_name'   => ['nullable', new Enum(VehicleRoute::class)],
        ]);

        $vehicle = Vehicle::create($validated);

        return response()->json(['status' => 'success', 'vehicle' => $vehicle], 201);
    }

    public function updateVehicle(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'plate_number' => 'required|string|max:20|unique:vehicles,plate_number,' . $vehicle->id,
            'route_name'   => ['nullable', new Enum(VehicleRoute::class)],
        ]);

        $vehicle->update($validated);

        return response()->json(['status' => 'success', 'vehicle' => $vehicle->fresh()]);
    }

    public function destroyVehicle(Vehicle $vehicle)
    {
        if ($vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot remove a vehicle with an active shift.',
            ], 422);
        }

        $vehicle->delete();

        return response()->json(['status' => 'success']);
    }

    public function assignDriver(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $driver = User::findOrFail($validated['user_id']);

        if ($driver->role !== 'driver') {
            return response()->json(['status' => 'error', 'message' => 'Selected user is not a driver.'], 422);
        }

        Vehicle::where('user_id', $driver->id)
               ->where('id', '!=', $vehicle->id)
               ->update(['user_id' => null]);

        Vehicle::where('id', $vehicle->id)->update(['user_id' => $driver->id]);

        return response()->json(['status' => 'success']);
    }

    // ─── Shifts ───────────────────────────────────────────────────────────────

    public function shifts(Request $request)
    {
        $range = $request->query('range', 'today');
        $q     = $request->query('q', '');

        $query = Shift::with(['vehicle', 'user'])->orderByDesc('started_at');

        match ($range) {
            'week'  => $query->whereBetween('started_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereMonth('started_at', now()->month)
                             ->whereYear('started_at', now()->year),
            default => $query->whereDate('started_at', today()),
        };

        if ($q) {
            $query->where(function ($q2) use ($q) {
                $q2->whereHas('user',    fn($u) => $u->where('name', 'like', "%{$q}%"))
                   ->orWhereHas('vehicle', fn($v) => $v->where('plate_number', 'like', "%{$q}%"));
            });
        }

        $shifts = $query->limit(100)->get()->map(fn($s) => [
            'id'             => $s->id,
            'driver'         => $s->user?->name ?? '—',
            'plate'          => $s->vehicle?->plate_number ?? '—',
            'route'          => $s->route_name ?? '—',
            'started_at'     => $s->started_at?->toISOString(),
            'ended_at'       => $s->ended_at?->toISOString(),
            'duration_human' => $s->duration_human,
            'end_reason'     => $s->end_reason?->value,
        ]);

        return response()->json($shifts);
    }

    // ─── Analytics ────────────────────────────────────────────────────────────

    public function analytics()
    {
        $month = now()->month;
        $year  = now()->year;

        $monthShifts = Shift::whereNotNull('ended_at')
                            ->whereMonth('started_at', $month)
                            ->whereYear('started_at', $year);

        // Shifts per day this week
        $perDay = Shift::whereNotNull('ended_at')
            ->whereBetween('started_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->select(DB::raw('DATE(started_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date   = now()->startOfWeek()->addDays($i)->toDateString();
            $days[] = [
                'label' => now()->startOfWeek()->addDays($i)->format('D'),
                'count' => $perDay[$date]->count ?? 0,
            ];
        }
        $avgDuration = (clone $monthShifts)->avg('duration_seconds');
        $avgH = $avgDuration ? intdiv((int) $avgDuration, 3600) : 0;
        $avgM = $avgDuration ? intdiv((int) $avgDuration % 3600, 60) : 0;

        return response()->json([
            'total_shifts_month' => (clone $monthShifts)->count(),
            'avg_duration_human' => $avgDuration ? "{$avgH}h {$avgM}m" : '--',
            'auto_ended_month'   => (clone $monthShifts)->where('end_reason', 'auto')->count(),
            'manual_ended_month' => (clone $monthShifts)->where('end_reason', 'manual')->count(),
            'logout_ended_month' => (clone $monthShifts)->where('end_reason', 'logout')->count(),
            'deactivated_ended_month' => (clone $monthShifts)
                ->where('end_reason', ShiftEndReason::ACCOUNT_DEACTIVATED->value)
                ->count(),
            'shifts_per_day'     => $days,
            'total_vehicles'     => Vehicle::count(),
            'total_drivers'      => User::where('role', 'driver')->where('is_active', true)->count(),
        ]);
    }

    // ─── Batch Import ────────────────────────────────────────────────────────────
    public function importTemplate()
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="users-import-template.csv"',
        ];

        $rows = [
            ['name', 'email', 'password', 'role'],
            ['Juan dela Cruz', 'juan@example.com', 'Password123', 'student'],
            ['Maria Santos',   'maria@example.com', 'Password123', 'driver'],
        ];

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importUsers(Request $request)
    {
        // Validate that a file was uploaded and is a CSV/plain-text type.
        // The MIME check is intentionally broad because browsers report CSV
        // as different types on different operating systems.
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'max:2048', // 2 MB ceiling — a CSV of 10,000 users is well under this
                function ($attribute, $value, $fail) {
                    $allowed = ['text/csv', 'text/plain', 'application/csv',
                                'application/vnd.ms-excel', 'application/octet-stream'];
                    if (!in_array($value->getMimeType(), $allowed)) {
                        $fail('The file must be a CSV.');
                    }
                },
            ],
        ]);

        $file    = $request->file('csv_file');
        $handle  = fopen($file->getRealPath(), 'r');

        $created = 0;
        $errors  = [];
        $rowNum  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            // Skip the header row
            if ($rowNum === 1) {
                continue;
            }

            // Skip completely blank rows (common when exporting from Excel)
            if (empty(array_filter($row))) {
                continue;
            }

            // Expect exactly 4 columns
            if (count($row) < 4) {
                $errors[] = "Row {$rowNum}: Expected 4 columns (name, email, password, role) — got " . count($row) . '.';
                continue;
            }

            [$name, $email, $password, $role] = array_map('trim', $row);

            // Per-row validation
            $rowErrors = [];

            if (empty($name) || strlen($name) > 255) {
                $rowErrors[] = 'name is required and must be under 255 characters';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = "email \"{$email}\" is not valid";
            } elseif (User::where('email', $email)->exists()) {
                $rowErrors[] = "email \"{$email}\" is already registered";
            }

            if (strlen($password) < 8) {
                $rowErrors[] = 'password must be at least 8 characters';
            }

            $role = strtolower($role);
            if (!in_array($role, ['student', 'driver', 'admin'])) {
                $rowErrors[] = "role \"{$role}\" is invalid — must be student, driver, or admin";
            }

            if (!empty($rowErrors)) {
                $errors[] = "Row {$rowNum} ({$email}): " . implode('; ', $rowErrors);
                continue;
            }

            User::create([
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make($password),
                'role'      => $role,
                'is_active' => true,
            ]);

            $created++;
        }

        fclose($handle);

        return response()->json([
            'status'  => 'ok',
            'created' => $created,
            'errors'  => $errors,
        ]);
    }
public function changePassword(Request $request, User $user)
{
    $validated = $request->validate([
        'password' => [
            'required',
            'string',
            'min:8',
            'confirmed', // expects password_confirmation field
        ],
    ]);

    $user->update([
        'password' => Hash::make($validated['password']),
    ]);

    return response()->json(['status' => 'success']);
}
}
