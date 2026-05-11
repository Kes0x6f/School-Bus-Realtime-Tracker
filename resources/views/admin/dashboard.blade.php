@extends('layouts.app')

@section('content')
<div id="app" data-page="admin-dashboard">

  {{-- TOP BAR --}}
  <div class="admin-topbar">
    <div>
      <span class="admin-topbar-brand">UDD E-Bus</span>
      <span class="admin-topbar-sep">·</span>
      <span class="admin-topbar-sub">Admin panel</span>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
      <span class="admin-topbar-user">{{ auth()->user()->email }}</span>
      <form method="POST" action="/logout" style="margin:0;">
        @csrf
        <button class="admin-logout-btn">Logout</button>
      </form>
    </div>
  </div>

  {{-- TAB BAR --}}
  <div class="admin-tabs">
    <button class="admin-tab active" data-tab="live-map">Live map</button>
    <button class="admin-tab" data-tab="users">Users</button>
    <button class="admin-tab" data-tab="vehicles">Vehicles</button>
    <button class="admin-tab" data-tab="shifts">Shifts</button>
    <button class="admin-tab" data-tab="analytics">Analytics</button>
  </div>

  {{-- PANELS --}}
  <div class="admin-body">

    {{-- ── LIVE MAP ─────────────────────────────────────────────────── --}}
    <div id="panel-live-map" class="admin-panel">
      <div class="admin-stat-row" id="mapStats">
        <div class="admin-stat-card"><span class="admin-stat-num" id="statOnShift">—</span><span class="admin-stat-lbl">On shift</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="statMoving">—</span><span class="admin-stat-lbl">Moving</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="statNoSignal">—</span><span class="admin-stat-lbl">No signal</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="statDrivers">—</span><span class="admin-stat-lbl">Active drivers</span></div>
      </div>
      <div class="admin-map-layout">
        <div id="adminMap" class="admin-map"></div>
        <div class="admin-vehicle-sidebar" id="vehicleSidebar">
          <p class="admin-sidebar-label">Active vehicles</p>
          <div id="sidebarList"></div>
        </div>
      </div>
    </div>

    {{-- ── USERS ────────────────────────────────────────────────────── --}}
    <div id="panel-users" class="admin-panel hidden">
      <div class="admin-toolbar">
        <div class="admin-filter-row" id="userFilters">
          <button class="admin-chip active" data-role="all">All</button>
          <button class="admin-chip" data-role="driver">Drivers</button>
          <button class="admin-chip" data-role="student">Students</button>
          <button class="admin-chip" data-role="admin">Admins</button>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <input id="userSearch" class="admin-search" placeholder="Search name or email…">
          <button class="admin-btn-primary" id="addUserBtn">+ Add user</button>
        </div>
      </div>
      <div id="usersTable"></div>
    </div>

    {{-- ── VEHICLES ─────────────────────────────────────────────────── --}}
    <div id="panel-vehicles" class="admin-panel hidden">
      <div class="admin-toolbar">
        <span class="admin-section-title" id="vehicleCount">Vehicles</span>
        <button class="admin-btn-primary" id="addVehicleBtn">+ Add vehicle</button>
      </div>
      <div id="vehiclesGrid" class="admin-vehicle-grid"></div>
    </div>

    {{-- ── SHIFTS ───────────────────────────────────────────────────── --}}
    <div id="panel-shifts" class="admin-panel hidden">
      <div class="admin-toolbar">
        <div class="admin-filter-row" id="shiftFilters">
          <button class="admin-chip active" data-range="today">Today</button>
          <button class="admin-chip" data-range="week">This week</button>
          <button class="admin-chip" data-range="month">This month</button>
        </div>
        <input id="shiftSearch" class="admin-search" placeholder="Filter by driver or plate…">
      </div>
      <div id="shiftsTable"></div>
    </div>

    {{-- ── ANALYTICS ────────────────────────────────────────────────── --}}
    <div id="panel-analytics" class="admin-panel hidden">
      <div class="admin-stat-row" id="analyticsStats" style="grid-template-columns:repeat(4,1fr);">
        <div class="admin-stat-card"><span class="admin-stat-num" id="aStatShifts">—</span><span class="admin-stat-lbl">Shifts this month</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="aStatAvg">—</span><span class="admin-stat-lbl">Avg shift duration</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="aStatAuto">—</span><span class="admin-stat-lbl">Auto-ended</span></div>
        <div class="admin-stat-card"><span class="admin-stat-num" id="aStatDrivers">—</span><span class="admin-stat-lbl">Active drivers</span></div>
      </div>
      <div class="admin-analytics-grid" id="analyticsCharts"></div>
    </div>

  </div>{{-- /.admin-body --}}

  {{-- MODAL --}}
  <div id="modalOverlay" class="modal-overlay hidden">
    <div id="modalBox" class="modal-box"></div>
  </div>

</div>
@endsection