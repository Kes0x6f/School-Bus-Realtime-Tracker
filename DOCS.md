# UDD E-Bus Tracker — Technical Documentation

This document covers the internal design of the system in detail. It is intended for developers maintaining or extending the project, not for end users.

---

## Table of Contents

1. [System Architecture](#1-system-architecture)  
2. [Database Schema](#2-database-schema)  
3. [Real-Time System](#3-real-time-system)  
4. [GPS State Machine](#4-gps-state-machine)  
5. [Announcements System](#5-announcements-system)  
6. [Authentication & Authorization](#6-authentication--authorization)  
7. [Frontend Module Guide](#7-frontend-module-guide)  
8. [Scheduled Tasks](#8-scheduled-tasks)  
9. [Environment Variable Reference](#9-environment-variable-reference)  
10. [API Reference](#10-api-reference)  
11. [Design Decisions](#11-design-decisions)  
12. [Known Limitations & Future Work](#12-known-limitations--future-work)

---

## 1. System Architecture

### Overview

The application follows a standard Laravel MVC structure with a real-time layer added via **Laravel Reverb** (a self-hosted WebSocket server). There is no SPA framework — each page is a Blade view with a dedicated JavaScript module that initialises on `DOMContentLoaded`. The `data-page` attribute on `#app` acts as a page router.

```
app.js
  └─ reads #app[data-page]
       ├─ "driver-dashboard"  → initDriverDashboard()
       ├─ "tracking"          → initTracking() + initAnnouncements(route)
       ├─ "active-jeeps"      → initActiveJeeps() + initAnnouncements(null)
       ├─ "admin-dashboard"   → initAdminDashboard()
       └─ "auth"              → initAuthPage()
```

### Request lifecycle

```
Browser
  │
  ├─ HTTP request
  │    └─ Laravel router (web.php / api.php)
  │         └─ Middleware stack (web, auth, EnsureRole)
  │              └─ Controller → Model → Response
  │
  └─ WebSocket connection (Reverb)
       └─ Echo.private / Echo.channel subscription
            └─ Receives broadcast events from server-side broadcast() calls
```

### Two-path GPS delivery

```
Driver browser
  │
  ├─ watchPosition fires
  │
  ├──► channel.whisper('location.update', payload)
  │       └─ Reverb relays peer-to-peer, zero DB touch
  │           └─ tracking.js: smooth marker animation  [< 100 ms]
  │
  └──► every 5 s: POST /api/gps/update
          └─ GpsController persists to DB
          └─ broadcasts VehicleLocationUpdated
              └─ tracking.js: fallback if whisper missed  [~ 1–2 s]
```

---

## 2. Database Schema

### `users`

|Column|Type|Constraints|Notes|
|---|---|---|---|
|`id`|bigint|PK, auto-increment||
|`name`|varchar(255)|NOT NULL||
|`email`|varchar(255)|NOT NULL, unique||
|`password`|varchar(255)|NOT NULL|Hashed via bcrypt|
|`role`|varchar(255)|NOT NULL|`driver` / `student` / `admin`|
|`is_active`|boolean|NOT NULL, default true|Soft enable/disable — no hard deletes|
|`remember_token`|varchar(100)|nullable||
|`created_at` / `updated_at`|timestamp|||

### `vehicles`

|Column|Type|Constraints|Notes|
|---|---|---|---|
|`id`|bigint|PK, auto-increment||
|`plate_number`|varchar(20)|NOT NULL, unique||
|`driver_name`|varchar(255)|nullable|Legacy — superseded by `user_id`|
|`user_id`|bigint|nullable, FK → users (`ON DELETE SET NULL`)|Assigned driver; null = unassigned; vehicle history is retained if the user is deleted|
|`route_name`|varchar(255)|nullable|Active route|
|`latitude`|decimal(10,7)|nullable|Last known GPS latitude|
|`longitude`|decimal(10,7)|nullable|Last known GPS longitude|
|`speed_mps`|float|nullable|Canonical speed from browser Geolocation API, in meters per second|
|`speed_kph`|computed|nullable|Presentation value derived as `speed_mps × 3.6`|
|`last_seen`|timestamp|nullable|Time of last GPS update|
|`is_active`|boolean|NOT NULL, default false|GPS fresh (within 3 min threshold)|
|`shift_active`|boolean|NOT NULL, default false|Driver is currently on shift|
|`shift_started_at`|timestamp|nullable||
|`shift_ended_at`|timestamp|nullable||
|`current_shift_id`|bigint|nullable, unique FK → shifts (`ON DELETE SET NULL`)|History row for the active shift|
|`is_full`|boolean|NOT NULL, default false|Passenger capacity status|
|`created_at` / `updated_at`|timestamp|||

**Computed accessor `gps_status`** — never stored in the database. Derived on read:

```php
if (!$this->shift_active) return 'shift_ended';
if (!$this->is_active)   return 'disconnected';
if (($this->speed_mps ?? 0) < 0.8333) return 'idle';
return 'moving';
```

The computed `gps_status` and `speed_kph` values are appended to every
serialised Vehicle instance via `$appends = ['gps_status', 'speed_kph']`.

Speed contract: `speed_mps` is the canonical stored/API value from the browser
Geolocation API. `speed_kph` is calculated as `speed_mps × 3.6` for display.
The provisional client boundaries are `0.1389 m/s` (`0.5 km/h`) for traffic
and `0.8333 m/s` (`3 km/h`) for moving; values are never converted before
persistence. A `null` speed means the browser did not provide a reading;
numeric `0` means a measured stationary reading.

### `shifts`

Every shift creates one row here at start. Completion updates that same row in
the transaction that clears the vehicle's active state. Legacy active vehicles
without `current_shift_id` are repaired by the completion service.

|Column|Type|Constraints|Notes|
|---|---|---|---|
|`id`|bigint|PK, auto-increment||
|`vehicle_id`|bigint|FK → vehicles||
|`user_id`|bigint|nullable, FK → users|Driver at time of shift; nullable in case driver is deleted later|
|`route_name`|varchar(255)|nullable|Snapshot of route at shift start|
|`started_at`|timestamp|NOT NULL|Shift start time|
|`ended_at`|timestamp|nullable|`now()` at time of logging|
|`duration_seconds`|integer|nullable|`ended_at - started_at` in seconds|
|`end_reason`|varchar(255)|nullable|`manual` / `logout` / `auto` / `account_deactivated`|
|`active_marker`|boolean|nullable|`true` only for an open shift; combined with `vehicle_id` to enforce one active shift per vehicle|
|`created_at` / `updated_at`|timestamp|||

All completion paths use `ShiftCompletionService`, which locks the vehicle,
updates the open history row, clears `current_shift_id`, and commits both
writes together. Repeated completion calls return an idempotent no-op.

### `announcements`

|Column|Type|Constraints|Notes|
|---|---|---|---|
|`id`|bigint|PK, auto-increment||
|`message`|varchar(300)|NOT NULL||
|`route`|varchar(100)|nullable|Scoped to a specific route; null = global|
|`is_active`|boolean|NOT NULL, default true||
|`expires_at`|timestamp|nullable|null = manual deactivation only|
|`created_by`|bigint|nullable, FK → users|Admin who created it|
|`created_at` / `updated_at`|timestamp|||

**`scopeActive()` Eloquent scope**

```php
$query->where('is_active', true)
      ->where(function ($q) {
          $q->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
      });
```

Used in `StudentController` to seed views and by the admin `index()` endpoint.

### `locations`

Historical GPS path log. Currently populated by the system but not yet consumed by any UI feature (intended for future route-history visualisation).

|Column|Type|Notes|
|---|---|---|
|`id`|bigint|PK|
|`vehicle_id`|bigint|FK → vehicles|
|`latitude` / `longitude`|decimal||
|`speed_mps`|float|nullable|Recorded in meters per second|
|`recorded_at`|timestamp||

### Relationships summary

```
User ──── has one ──── Vehicle       (user_id on vehicles)
User ──── has many ─── Shifts        (user_id on shifts)
Vehicle ─ has many ─── Shifts        (vehicle_id on shifts)
Vehicle ─ has many ─── Locations     (vehicle_id on locations)
Vehicle ─ has one ──── Location      (latestOfMany — latest recorded_at)
Announcement belongs to User         (created_by → users.id)
```

---

## 3. Real-Time System

### Laravel Reverb

The app uses **Laravel Reverb** as a self-hosted WebSocket server. It is compatible with the Pusher protocol, which means the standard `laravel-echo` + `pusher-js` client pair works out of the box.

`bootstrap.js` configures Echo:

```js
window.Echo = new Echo({
    broadcaster:      'reverb',
    key:              import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:           window.location.hostname,
    wsPort:           443,
    wssPort:          443,
    wsPath:           '/app',
    forceTLS:         true,
    withCredentials:  true,
    authEndpoint:     '/broadcasting/auth',
    auth: {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    }
});
```

For local development change `wsPort`/`wssPort` to `8080` and `forceTLS` to `false` in the env.

### Channels

Broadcasting is registered once from `bootstrap/app.php` through Laravel 12's
`withBroadcasting()` configuration. That registration loads
`routes/channels.php` and applies the `web`, `auth`, and `active` middleware to
`/broadcasting/auth`. Web routes and service providers do not register the
broadcast route or channel callbacks separately.

|Channel|Type|Auth|Used by|
|---|---|---|---|
|`vehicle.{id}`|Private|Active student/admin, or assigned active driver|Driver whispers; per-vehicle location + status events|
|`vehicles`|Private|Active student/admin/driver|Global status changes (shift start/end, disconnected)|
|`announcements`|Public|None|Admin → all students, no auth needed|

Channel authorization in `routes/channels.php` additionally checks active
account status and driver vehicle ownership. Students and administrators may
monitor vehicle channels; drivers may only subscribe to their assigned vehicle.

### Events and what they carry

**`VehicleLocationUpdated`** — fired on every successful GPS update (`GpsController`). Broadcast on both `vehicle.{id}` and `vehicles`.

```json
{
  "id": 1,
  "latitude": 16.0509,
  "longitude": 120.3412,
  "speed_mps": 3.0,
  "speed_kph": 10.8,
  "last_seen": "2024-11-01T08:23:11.000000Z",
  "route_name": "Route A – Mangaldan",
  "shift_active": true,
  "is_active": true,
  "gps_status": "moving",
  "is_full": false
}
```

**`VehicleStatusChanged`** — fired on shift start/end, auto-end, GPS stale/reconnect, capacity change, route change. Broadcast on both `vehicle.{id}` and `vehicles`.

```json
{
  "vehicle": {
    "id": 1,
    "shift_id": 1,
    "shift_active": false,
    "is_active": false,
    "gps_status": "shift_ended",
    "speed_mps": null,
    "speed_kph": null,
    "last_seen": "2024-11-01T08:23:11.000000Z",
    "shift_started_at": "2024-11-01T06:00:00.000000Z",
    "shift_ended_at": "2024-11-01T08:23:40.000000Z",
    "route_name": "Route A – Mangaldan",
    "is_full": false,
    "user": { "name": "Juan dela Cruz" }
  }
}
```

The `user` field is included so `active-jeeps.js` can render the operator name on cards added dynamically while the page is already open — without making an extra API call.

**`AnnouncementBroadcast`** — fired on create and deactivate. Broadcast on public `announcements` channel.

```json
{
  "id": 5,
  "message": "Route A suspended until further notice.",
  "route": "Route A – Mangaldan",
  "is_active": true,
  "expires_at": "2024-11-01T23:59:59.000000Z",
  "created_at": "2024-11-01T10:00:00.000000Z"
}
```

The `broadcastAs()` suffix is either `announcement.created` or `announcement.deactivated`, so clients can listen selectively.

### Whisper vs broadcast — when each fires

|Trigger|Whisper|Broadcast event|
|---|---|---|
|GPS position update (every second)|✅ `location.update`|Only every 5 s via HTTP|
|GPS persisted to DB (every 5 s)|❌|✅ `VehicleLocationUpdated`|
|GPS was stale, now reconnected|❌|✅ `VehicleStatusChanged`|
|Shift start / end|❌|✅ `VehicleStatusChanged`|
|Capacity toggle|❌|✅ `VehicleStatusChanged`|
|Route change|❌|✅ `VehicleStatusChanged`|
|Announcement sent / deactivated|❌|✅ `AnnouncementBroadcast`|

The tracking page handles both: whispers for live map animation, broadcast for fallback and status changes.

---

## 4. GPS State Machine

### States

|State|`shift_active`|`is_active`|`speed_mps`|Meaning|
|---|---|---|---|---|
|`shift_ended`|`false`|any|any|No active shift|
|`disconnected`|`true`|`false`|any|On shift, no recent GPS|
|`idle`|`true`|`true`|`< 0.8333 m/s`|On shift, GPS fresh, not moving|
|`moving`|`true`|`true`|`≥ 0.8333 m/s`|On shift, GPS fresh, moving|

### Transitions

```
[Off shift]
    │  POST /driver/shift/start
    ▼
[disconnected]  ◄─────────────────────────────────────────────┐
    │                                                          │
    │  GPS update received (POST /api/gps/update)             │
    │  → is_active = true                                     │
    ▼                                                          │
[idle]  ◄──────────────────────  speed_mps < 0.8333 m/s      │
    │                                                          │
    │  speed_mps ≥ 0.8333 m/s                                 │
    ▼                                                          │
[moving]                                                       │
    │                                                          │
    │  No GPS for GPS_STALE_SECONDS (180 s)                   │
    │  → CheckInactiveVehicles cron                           │
    │  → is_active = false, broadcast VehicleStatusChanged    │
    └──────────────────────────────────────────────────────────┘
    │
    │  No GPS for SHIFT_AUTO_END_SECONDS (1200 s)
    │  → CheckInactiveVehicles cron
    │  → ShiftCompletionService (transaction + row lock)
    │  → shift_active = false, is_active = false
    ▼
[shift_ended]
```

### Thresholds (defined in `CheckInactiveVehicles.php`)

```php
const GPS_STALE_SECONDS      = 180;   // 3 minutes
const SHIFT_AUTO_END_SECONDS = 1200;  // 20 minutes
```

### Client-side staleness checker (`tracking.js`)

The tracking page also runs a local staleness checker every 30 seconds independently of the server cron. If `lastWhisperTime` is more than 3 minutes old, it calls `applyGpsStatus('disconnected')` locally. This gives students immediate feedback without waiting for the server cron to fire.

```js
const GPS_STALE_MS   = 3 * 60 * 1000;  // mirrors server threshold
const STALE_CHECK_MS = 30 * 1000;
```

---

## 5. Announcements System

### Lifecycle

```
Admin sends alert
  │
  POST /admin/api/announcements
  { message, route?, expires_at? }
  │
  AnnouncementController::store()
    ├─ Announcement::create(...)
    └─ broadcast(new AnnouncementBroadcast($a, 'created'))
          └─ public channel 'announcements'
                ├─ active-jeeps page: showAnnouncement()  [all routes shown]
                └─ tracking page:     showAnnouncement()  [filtered by route]

Admin deactivates
  │
  POST /admin/api/announcements/{id}/deactivate
  │
  AnnouncementController::deactivate()
    ├─ $announcement->update(['is_active' => false])
    └─ broadcast(new AnnouncementBroadcast($a, 'deactivated'))
          └─ announcements.js: removeAnnouncement(id)
                └─ animate out + remove DOM element on all connected pages
```

### Route scoping logic (`announcements.js`)

```js
function showAnnouncement(announcement, routeFilter) {
    // routeFilter = null  on active-jeeps (show everything)
    // routeFilter = 'Route A – Mangaldan'  on tracking page

    if (routeFilter && announcement.route && announcement.route !== routeFilter) return;
    // → global announcements (route = null) always pass through
    // → route-specific ones only pass if they match the current page's route
}
```

### Server-side seeding vs WebSocket

On page load, `StudentController` passes active announcements directly into the Blade view as a `data-announcements` JSON attribute. `announcements.js` reads this and renders them immediately — before the WebSocket connection is even established — avoiding a flash of empty content.

```php
// StudentController::active()
$announcements = Announcement::active()
    ->orderByDesc('created_at')
    ->get(['id', 'message', 'route', 'expires_at']);

// StudentController::track()
$announcements = Announcement::active()
    ->where(function ($q) use ($vehicle) {
        $q->whereNull('route')->orWhere('route', $vehicle->route_name);
    })
    ->orderByDesc('created_at')
    ->get(['id', 'message', 'route', 'expires_at']);
```

### Session dismissal

Dismissed announcement IDs are stored in `sessionStorage['dismissed_announcements']` as a JSON array. This means:

- Dismissed banners don't reappear during the same browser session
- They do reappear after a full logout/login cycle (`sessionStorage` is cleared on tab close)
- Admin deactivation via WebSocket removes the element regardless of dismiss state

### Expiry presets (admin UI)

|Preset|`expires_at` value sent|
|---|---|
|Today only (default)|Midnight of the current day (local time)|
|In 2 hours|`Date.now() + 2 * 3600000`|
|In 4 hours|`Date.now() + 4 * 3600000`|
|Manual deactivation only|`null`|

Expired announcements are filtered out by `scopeActive()` on the server side. There is no cron job that deactivates them — they simply stop being returned by queries once `expires_at` is in the past.

---

## 6. Authentication & Authorization

### Login flow

```
POST /login  { email, password }
  │
  Auth::attempt()
    ├─ fails          → redirect back with error
    ├─ is_active=false → Auth::logout(), redirect with "deactivated" error
    └─ success        → session regenerate
                         match role:
                           driver  → /driver/dashboard
                           admin   → /admin/dashboard
                           default → /student/active-jeeps
```

### Logout flow (driver special case)

When a driver logs out with an active shift, the shift is automatically ended:

```
POST /logout
  │
if user.role === 'driver' && vehicle.shift_active
    ├─ ShiftCompletionService (transaction + row lock)
    └─ broadcast(VehicleStatusChanged) after commit
  │
  Auth::logout() + session invalidate
```

### `EnsureRole` middleware

```php
// Supports single role:
->middleware('role:driver')

// Supports OR logic (comma-separated):
->middleware('role:student,admin')
```

Applied via the middleware alias `role` registered in the application's middleware stack.

### API authentication

Protected API routes rely on the **web session** (cookie + CSRF token) rather
than tokens or Sanctum. First-party tracking reads additionally require the
`active` middleware and the `student,admin` role boundary. The frontend sends
`credentials: 'same-origin'` and `Accept: application/json` on tracking reads;
state-changing requests also send `X-CSRF-TOKEN`. This works because the SPA
pages are served by the same Laravel origin.

---

## 7. Frontend Module Guide

### Module initialisation pattern

Every module exports a single `init*()` function. `app.js` calls the correct one based on `#app[data-page]`. Modules are self-contained — they do not share global state with each other.

### `driver-dashboard.js`

|Responsibility|Implementation|
|---|---|
|Shift start/end|`bindShiftButtons()` — POST to web routes, updates UI state|
|GPS broadcast toggle|`bindBroadcastToggle()` — calls `startGPS()` / `stopGPS()`|
|GPS sending|`startGPS()` — `watchPosition` + whisper + HTTP every 5 s|
|Capacity toggle|`bindBusStatusButton()` — POST `/api/vehicles/occupancy`|
|UI sync|`syncShiftUI()`, `syncBroadcastUI()` — driven by `shiftActive` boolean|
|Channel|`private:vehicle.{id}` — subscribed for channel readiness check|

**State variables:**

```js
let broadcasting = false;    // GPS broadcast active
let watchId      = null;     // geolocation watchPosition ID
let channel      = null;     // Echo channel reference
let channelReady = false;    // channel.subscribed() has fired
let shiftActive  = false;    // seeded from data-shift-active attribute
let lastServerSync = 0;      // timestamp of last HTTP GPS sync
```

### `tracking.js`

|Responsibility|Implementation|
|---|---|
|Map init|`initMap()` — Leaflet, OSM tiles, invalidateSize after 500 ms|
|Initial state|`seedInfoPanel(container)` — reads `data-*` attrs to avoid flash|
|Initial vehicle fetch|`loadInitialVehicle()` — GET `/api/vehicles/{id}`|
|Whisper reception|`channel.listenForWhisper('location.update', ...)`|
|Broadcast fallback|`channel.listen('.location.updated', ...)` — only if whisper silent > 2 s|
|Status changes|`channel.listen('.vehicle.status.changed', ...)`|
|GPS status UI|`applyGpsStatus(status, lastSeen, silent)` — toasts only on state change|
|Staleness|`startStalenessChecker()` — local 30 s interval|
|Last-seen ticker|`startLastSeenTicker()` — updates `#infoLastSeen` every second|
|Marker animation|`updateMarker()` — 5-step lerp over 250 ms|

**Toast firing rules** — toasts only fire when `gpsStatus !== previousGpsStatus` and `silent !== true`. The `silent` flag is used on initial page load to avoid spurious notifications.

### `active-jeeps.js`

|Responsibility|Implementation|
|---|---|
|Per-vehicle channels|`subscribeToVehicleChannels()` — location updates on initial list|
|Global channel|`subscribeToGlobalChannel()` — shift start/end, status changes|
|Live card add|`addVehicle()` — creates DOM element + sets `data-route` + subscribes channel|
|Live card remove|`removeVehicle()` — fade-exit animation then `el.remove()`|
|Status badge update|`updateCardStatus()` — called by both event types|
|Route filter|`initRouteFilter()` — filters by `card.dataset.route`|

### `admin-dashboard.js`

|Responsibility|Implementation|
|---|---|
|Tab system|`initTabs()` + `activateTab()` — lazy loads each tab on first visit|
|Live map|`initLiveMap()` — Leaflet + Reverb listeners + sidebar|
|Users CRUD|`loadUsers()`, `renderUsersTable()`, modal forms|
|Vehicles CRUD|`loadVehicles()`, `renderVehicleCards()`, modal forms|
|Shifts log|`loadShifts()`, `fetchShifts()`, `renderShiftsTable()`|
|Analytics|`loadAnalytics()` — bar chart + donut chart rendered as inline CSS|
|Announcements|`openSendAlertModal()`, `openAnnouncementsListModal()`|
|Skeleton loaders|`showTableSkeleton()`, `skeletonCards()` — shimmer animation|
|Modal system|`openModal(html, onSubmit)`, `openConfirmModal()`, `closeModal()`|

**Lazy loading:** each tab sets `loaded[name] = true` on first activation. Subsequent tab switches skip the data fetch. This means data can go stale if the admin leaves a tab open for a long time — a page refresh resets it.

### `announcements.js`

Shared module used by both `active-jeeps` and `tracking` pages. Accepts an optional `routeFilter` string.

```js
initAnnouncements(null);           // active-jeeps — show all
initAnnouncements('Route A – Mangaldan');  // tracking — show global + this route
```

The container `#announcementStack` is injected into the DOM dynamically after `.pageLabelDark` if it exists, or prepended to `#app` otherwise.

---

## 8. Scheduled Tasks

Registered in `routes/console.php`:

```php
Schedule::command('vehicles:check-inactive')->everyMinute();
```

### `CheckInactiveVehicles` command

Runs every minute. Iterates all vehicles with `shift_active = true`.

```
For each active-shift vehicle:
  │
  ├─ Guard: skip if last_seen OR shift_started_at is null
  │         (prevents Carbon null method call crash)
  │
  ├─ secondsSinceUpdate       = last_seen->diffInSeconds(now())
  ├─ secondsSinceShiftStarted = shift_started_at->diffInSeconds(now())
  │
  ├─ if secondsSinceUpdate >= 1200 && secondsSinceShiftStarted >= 1200
  │     → ShiftCompletionService (transaction + row lock)
  │     → vehicle.update(shift_active=false, shift_ended_at=now(), is_active=false)
  │     → broadcast VehicleStatusChanged
  │     → continue  (skip threshold 1 check)
  │
  └─ else if secondsSinceUpdate >= 180 && vehicle.is_active == true
        → vehicle.update(is_active=false)
        → broadcast VehicleStatusChanged
```

The dual threshold on auto-end (`secondsSinceUpdate` AND `secondsSinceShiftStarted`) prevents a vehicle from being auto-ended immediately after starting a shift if it hasn't sent a GPS ping yet — `last_seen` could be null or old from a previous shift. The `shift_started_at` guard ensures the vehicle has been on shift for at least 20 minutes before auto-ending.

**Production crontab entry:**

```cron
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. Environment Variable Reference

|Variable|Required|Default|Description|
|---|---|---|---|
|`APP_NAME`|No|`Laravel`|Shown in browser tab / emails|
|`APP_ENV`|Yes|`production`|`local` / `production`|
|`APP_KEY`|Yes|—|Generated by `php artisan key:generate`|
|`APP_URL`|Yes|—|Full URL including scheme, e.g. `https://ebus.udd.edu.ph`|
|`DB_CONNECTION`|Yes|`mysql`|`mysql` or `sqlite`|
|`DB_HOST`|Yes|`127.0.0.1`||
|`DB_PORT`|Yes|`3306`||
|`DB_DATABASE`|Yes|—||
|`DB_USERNAME`|Yes|—||
|`DB_PASSWORD`|Yes|—||
|`BROADCAST_CONNECTION`|Yes|`reverb`|Must be `reverb`|
|`REVERB_APP_ID`|Yes|—|Any string; used for channel namespacing|
|`REVERB_APP_KEY`|Yes|—|Public key sent to the browser|
|`REVERB_APP_SECRET`|Yes|—|Server-side secret; never sent to browser|
|`REVERB_HOST`|Yes|—|Hostname Reverb listens on|
|`REVERB_PORT`|Yes|`8080`|Port Reverb listens on (use `443` behind a proxy)|
|`REVERB_SCHEME`|Yes|`https`|`http` locally, `https` in production|
|`VITE_REVERB_APP_KEY`|Yes|—|Should equal `"${REVERB_APP_KEY}"`|

### Production WebSocket proxy (nginx example)

If running Reverb on port `8080` behind nginx on `443`:

```nginx
location /app {
    proxy_pass         http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header   Upgrade $http_upgrade;
    proxy_set_header   Connection "upgrade";
    proxy_set_header   Host $host;
    proxy_set_header   X-Real-IP $remote_addr;
    proxy_read_timeout 60s;
}
```

Set `REVERB_PORT=443` and `REVERB_SCHEME=https` in `.env` so `bootstrap.js` connects to the proxied port.

---

## 10. API Reference

### Request format

All mutation endpoints expect `Content-Type: application/json`. All responses return `Content-Type: application/json`. Authenticated endpoints require:

- `Cookie: laravel_session=...` (handled automatically by the browser)
- `X-CSRF-TOKEN: ...` header (read from `<meta name="csrf-token">`)

### Response format conventions

**Success:**

```json
{ "status": "success", ... }
```

**Validation error (422):**

```json
{
  "message": "The message field is required.",
  "errors": { "message": ["The message field is required."] }
}
```

**Business logic error (422 / 403):**

```json
{ "status": "error", "message": "Human-readable reason." }
```

---

### `POST /api/gps/update`

**Auth:** driver role required

**Request:**

```json
{
  "vehicle_id": 1,
  "latitude":   16.0509,
  "longitude":  120.3412,
  "speed_mps":  3.0,
  "route_name": "Route A – Mangaldan"
}
```

`vehicle_id` must match the authenticated driver's assigned vehicle — prevents GPS spoofing. `speed_mps` and `route_name` are optional. `speed_mps` must be a non-negative browser GPS value no greater than `55.56` m/s.

The legacy `speed` request field is rejected so clients cannot send an
ambiguous unit.

**Response (200):**

```json
{
  "status": "success",
  "message": "GPS update received",
  "data": { ...full vehicle object... }
}
```

**Side effects:** updates `latitude`, `longitude`, `speed_mps`, `last_seen`, `is_active = true`, optionally `route_name`. If `is_active` was previously `false`, also broadcasts `VehicleStatusChanged`. Always broadcasts `VehicleLocationUpdated`. Broadcast payloads include `speed_mps` and derived `speed_kph`.

---

### `POST /driver/shift/start`

**Auth:** driver role required

**Request:**

```json
{ "route_name": "Route A – Mangaldan" }
```

`route_name` is optional. If omitted, the vehicle's existing `route_name` is kept.

**Response (200):**

```json
{
  "status": "success",
  "message": "Shift started.",
  "data": {
    "id": 1,
    "shift_id": 1,
    "shift_active": true,
    "shift_started_at": "2024-11-01T06:00:00.000000Z",
    "gps_status": "disconnected",
    "route_name": "Route A – Mangaldan"
  }
}
```

Note: `gps_status` is `disconnected` immediately after start because `is_active` is set to `false` — GPS hasn't been received yet.

---

### `POST /driver/shift/end`

**Auth:** driver role required

**Request:** empty body

**Response (200):**

```json
{
  "status": "success",
  "message": "Shift ended.",
  "data": {
    "id": 1,
    "shift_active": false,
    "shift_ended_at": "2024-11-01T08:23:40.000000Z",
    "shift_id": 1,
    "already_ended": false,
    "gps_status": "shift_ended"
  }
}
```

**Side effects:** completes the current `shifts` row and updates the vehicle
inside one locked transaction. A repeated request returns `200` with
`already_ended: true` and creates no duplicate history row.

---

### `POST /api/driver/route`

**Auth:** driver role required. Only works while `shift_active = true`.

**Request:**

```json
{ "route_name": "Route B – Calasiao" }
```

**Response (200):**

```json
{ "status": "success", "route_name": "Route B – Calasiao" }
```

**Side effects:** broadcasts `VehicleStatusChanged` so the active-jeeps card and the tracking page both update the route label immediately.

---

### `POST /api/vehicles/occupancy`

**Auth:** driver role required

**Request:**

```json
{ "is_full": true }
```

**Response (200):**

```json
{ "status": "success", "is_full": true }
```

---

### `GET /api/vehicles/active`

**Auth:** active student or admin session required

**Response (200):** array of vehicle objects with `shift_active = true`.

```json
[
  {
    "id": 1,
    "route_name": "Route A – Mangaldan",
    "is_full": false,
    "user": { "name": "Juan dela Cruz" },
    "latitude": 16.0509,
    "longitude": 120.3412,
    "speed_mps": 3.0,
    "speed_kph": 10.8,
    "last_seen": "2024-11-01T08:23:11.000000Z",
    "shift_active": true,
    "is_active": true,
    "gps_status": "moving",
    "shift_started_at": "2024-11-01T06:00:00.000000Z"
  }
]
```

---

### `GET /api/vehicles/{vehicle}`

**Auth:** active student or admin session required

Returns the selected vehicle's tracking fields. An ended vehicle may still be
retrieved by an authorized user so the tracking page can display its last
known position and the shift-ended state. The response does not expose the
assigned driver's internal `user_id`.

---

### `GET /admin/api/vehicles`

**Auth:** admin role required

Returns `{ vehicles: [...], drivers: [...] }`. The `drivers` array is included so the admin Vehicles tab can populate the assign-driver modal even if the Users tab has never been visited.

---

### `POST /admin/api/announcements`

**Auth:** admin role required

**Request:**

```json
{
  "message":    "Route A suspended until further notice.",
  "route":      "Route A – Mangaldan",
  "expires_at": "2024-11-01T23:59:59.000000Z"
}
```

`route` and `expires_at` are optional. Omitting `route` creates a global announcement. Omitting `expires_at` creates a manual-deactivation-only announcement.

**Response (201):**

```json
{ "status": "ok", "announcement": { ...announcement object... } }
```

**Side effects:** broadcasts `AnnouncementBroadcast` with `action = 'created'`.

---

## 11. Design Decisions

### Why whispers instead of broadcasting every GPS ping?

Broadcasting goes server → database → all subscribers. At one update per second per vehicle, that is 60 database writes per minute per vehicle, plus 60 broadcast events that Laravel has to serialise and push through Reverb. Whispers are peer-to-peer inside the existing WebSocket connection — the server never sees the payload. This gives sub-100 ms map updates with essentially zero server load for the realtime path. The HTTP fallback every 5 seconds handles persistence.

### Why `ShouldBroadcastNow` instead of `ShouldBroadcast`?

`ShouldBroadcast` queues the broadcast event through Laravel's queue worker. `ShouldBroadcastNow` fires it synchronously in the current request cycle. All events in this app use `ShouldBroadcastNow` because there is no queue worker configured, and the events are small and time-sensitive. If a queue worker is added in future, switching to `ShouldBroadcast` is a one-word change per event class.

### Why session-based auth for the API instead of Sanctum tokens?

All API consumers are the same Laravel origin's own Blade pages. There is no external consumer, no mobile app, and no third-party integration. Session auth with CSRF is simpler, equally secure for same-origin requests, and requires no token management. Sanctum would add complexity with no benefit here.

### Why is `gps_status` a computed accessor and not a stored column?

`gps_status` is always fully derivable from `shift_active`, `is_active`, and `speed_mps`. Storing it would create a redundancy that could desync — e.g. a direct database update to `is_active` would leave `gps_status` stale. The `$appends = ['gps_status', 'speed_kph']` directive ensures the computed values are fresh and present on serialised Vehicle objects.

### Why does `VehicleStatusChanged` include `user`?

When a driver starts a shift while a student's active-jeeps page is already open, `addVehicle()` in `active-jeeps.js` creates the card from the event payload alone — no extra API call. Without the `user` field, the operator name would show as "Unknown" until the next location update. Including it in the event payload eliminates the flash.

### Why does the admin vehicle API return drivers alongside vehicles?

`AdminController::vehicleList()` returns `{ vehicles, drivers }` in a single response. The admin dashboard loads vehicles lazily when the Vehicles tab is first clicked. The assign-driver modal needs the drivers list. If drivers were fetched separately (i.e. only when the Users tab is visited), clicking "Reassign driver" on the Vehicles tab before visiting the Users tab would open an empty dropdown. Co-loading eliminates the dependency.

---

## 12. Known Limitations & Future Work

### Current limitations

|Area|Limitation|
|---|---|
|Channel auth|Active students and administrators may monitor vehicle channels; active drivers are restricted to their assigned vehicle.|
|Route filter (live cards)|The route dropdown on active-jeeps is built from the server-rendered jeep list at page load. If a driver starts a shift on a new route after page load, that route won't appear in the dropdown (though the card will appear correctly).|
|Announcement route sync|`announcements.js` reads the route filter once at `initAnnouncements()` call time. If a vehicle changes route mid-shift, the tracking page won't update which announcements are shown until a full page reload.|
|GPS accuracy|Speed is reported in m/s by the browser Geolocation API and displayed in km/h. Values are device-dependent and can be unreliable at low speeds. The 0.8333 m/s (3 km/h) moving threshold may need tuning for slow urban traffic.|
|No offline queue|If the driver loses connectivity, GPS updates are lost. The staleness checker marks the vehicle disconnected after 3 minutes. There is no offline buffer or retry queue.|
|Shift history UI|The `locations` table records every GPS ping for future route-history replay, but no UI for this exists yet.|
|Admin tab staleness|Admin dashboard tabs load data once and don't refresh. Data shown in Users / Vehicles / Shifts tabs can become stale if the admin leaves the page open for an extended period.|

### Suggested future improvements

- **Location history playback** — replay a completed shift's GPS path on the admin map using the `locations` table
- **Push notifications** — use the Web Push API to deliver announcements to students even when the browser tab is closed
- **ETA estimation** — calculate and display estimated arrival time based on vehicle speed and remaining distance
- **Driver rating** — allow students to rate ride quality per shift
- **Multi-language support** — the UI is English-only; Tagalog/Filipino localisation would improve accessibility
- **Refresh token for admin tabs** — add a polling interval or a "Refresh" button to keep admin data current without a full page reload
