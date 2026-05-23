/**
 * Admin Dashboard — admin-dashboard.js
 *
 * Tabs: live-map | users | vehicles | shifts | analytics
 * Each tab fetches its own data lazily on first activation.
 */

const CSRF = () => document.querySelector('meta[name="csrf-token"]').content;

const loaded = { 'live-map': false, users: false, vehicles: false, shifts: false, analytics: false };

let allUsers    = [];
let allVehicles = [];
let markers     = {};
let adminMap    = null;

let shiftRange = 'today';
let shiftQ     = '';
let shiftTimer = null;

let userRoleFilter = 'all';
let userSearchQ    = '';

export function initAdminDashboard() {
    initTabs();
    initModal();
    activateTab('live-map');
}

// ─── Tab system ───────────────────────────────────────────────────────────────

function initTabs() {
    document.querySelectorAll('.admin-tab').forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });
}

function activateTab(name) {
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.admin-panel').forEach(p => p.classList.toggle('hidden', p.id !== `panel-${name}`));

    if (!loaded[name]) {
        loaded[name] = true;
        switch (name) {
            case 'live-map':  initLiveMap();   break;
            case 'users':     loadUsers();     break;
            case 'vehicles':  loadVehicles();  break;
            case 'shifts':    loadShifts();    break;
            case 'analytics': loadAnalytics(); break;
        }
    }

    if (name === 'live-map' && adminMap) {
        setTimeout(() => adminMap.invalidateSize(), 50);
    }
}

// ─── Live map ─────────────────────────────────────────────────────────────────

function initLiveMap() {
    adminMap = L.map('adminMap').setView([16.050889, 120.341236], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
    }).addTo(adminMap);

    // Inject the Send Alert button above the map
    const panel = document.getElementById('panel-live-map');
    if (panel && !document.getElementById('sendAlertBtn')) {
        const toolbar = document.createElement('div');
        toolbar.style.cssText = 'display:flex;justify-content:flex-end;margin-bottom:12px;gap:8px;';
        toolbar.innerHTML = `
            <button id="viewAnnouncementsBtn" class="admin-btn-sm">📋 Announcements</button>
            <button id="sendAlertBtn" class="admin-btn-primary" style="background:#C2410C;">
                📢 Send Alert
            </button>`;
        panel.insertBefore(toolbar, panel.querySelector('.admin-stat-row'));

        document.getElementById('sendAlertBtn')
            .addEventListener('click', openSendAlertModal);
        document.getElementById('viewAnnouncementsBtn')
            .addEventListener('click', openAnnouncementsListModal);
    }

    fetch('/admin/api/vehicles')
        .then(r => r.json())
        .then(data => {
            allVehicles = data.vehicles;
            if (!loaded.users && data.drivers) allUsers = data.drivers;
            allVehicles.forEach(v => placeOrUpdateMarker(v));
            renderSidebar(allVehicles.filter(v => v.shift_active));
            updateMapStats(allVehicles);
        });

    Echo.private('vehicles')
        .listen('.vehicle.status.changed', e => {
            const v = e.vehicle;
            const i = allVehicles.findIndex(x => x.id === v.id);
            if (i >= 0) allVehicles[i] = { ...allVehicles[i], ...v };
            else allVehicles.push(v);

            if (v.gps_status === 'shift_ended') removeMarker(v.id);
            else placeOrUpdateMarker(allVehicles.find(x => x.id === v.id));

            renderSidebar(allVehicles.filter(x => x.shift_active));
            updateMapStats(allVehicles);
        })
        .listen('.location.updated', e => {
            const i = allVehicles.findIndex(x => x.id === e.id);
            if (i >= 0) {
                allVehicles[i] = { ...allVehicles[i], ...e };
                if (e.latitude && e.longitude) placeOrUpdateMarker(allVehicles[i]);
            }
        });
}

function placeOrUpdateMarker(v) {
    if (!v.latitude || !v.longitude) return;

    const color = v.gps_status === 'moving'      ? '#002D62'
                : v.gps_status === 'traffic'     ? '#F57C00'
                : v.gps_status === 'idle'         ? '#FBC02D'
                : v.gps_status === 'disconnected' ? '#E64A19'
                : '#9E9E9E';

    const icon = L.divIcon({
        className: '',
        html: `<div style="width:12px;height:12px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></div>`,
        iconAnchor: [6, 6],
    });

    if (markers[v.id]) {
        markers[v.id].setLatLng([v.latitude, v.longitude]).setIcon(icon);
    } else {
        markers[v.id] = L.marker([v.latitude, v.longitude], { icon })
            .bindPopup(`<strong>${v.plate_number ?? 'Vehicle ' + v.id}</strong><br>${v.route_name ?? '—'}<br>${v.user?.name ?? '—'}`)
            .addTo(adminMap);
    }
}

function removeMarker(id) {
    if (markers[id]) { adminMap.removeLayer(markers[id]); delete markers[id]; }
}

function renderSidebar(activeVehicles) {
    const list = document.getElementById('sidebarList');
    if (!list) return;

    if (!activeVehicles.length) {
        list.innerHTML = '<p style="font-size:12px;color:#9CA3AF;padding:8px 0;">No active vehicles</p>';
        return;
    }

    list.innerHTML = activeVehicles.map(v => {
        const dot = v.gps_status === 'moving'       ? '#43A047'
                  : v.gps_status === 'traffic'      ? '#F57C00'
                  : v.gps_status === 'idle'          ? '#FBC02D'
                  : v.gps_status === 'disconnected'  ? '#E64A19'
                  : '#9E9E9E';
        return `
            <div class="sidebar-vrow" data-id="${v.id}" style="cursor:pointer;">
                <div>
                    <div class="sidebar-vname">${v.plate_number ?? 'Vehicle ' + v.id}</div>
                    <div class="sidebar-vsub">${v.route_name ?? '—'} · ${v.user?.name ?? '—'}</div>
                </div>
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${dot};flex-shrink:0;"></span>
            </div>`;
    }).join('');

    list.querySelectorAll('.sidebar-vrow').forEach(row => {
        row.addEventListener('click', () => {
            const v = activeVehicles.find(x => x.id == row.dataset.id);
            if (v?.latitude && adminMap) adminMap.setView([v.latitude, v.longitude], 16);
        });
    });
}

function updateMapStats(vehicles) {
    const active   = vehicles.filter(v => v.shift_active);
    const moving   = active.filter(v => v.gps_status === 'moving' || v.gps_status === 'traffic').length;
    const noSignal = active.filter(v => v.gps_status === 'disconnected').length;
    setText('statOnShift',  active.length);
    setText('statMoving',   moving);
    setText('statNoSignal', noSignal);
    setText('statDrivers',  active.length);
}

// ─── Users tab ────────────────────────────────────────────────────────────────

async function loadUsers() {
    const container = document.getElementById('usersTable');
    showTableSkeleton(container, 6);
    const res = await fetch('/admin/api/users');
    allUsers  = await res.json();
    renderUsersTable();
    bindUserFilters();
    bindUserSearch();
    bindAddUserBtn();
}

function renderUsersTable() {
    const container = document.getElementById('usersTable');
    if (!container) return;

    let filtered = allUsers;
    if (userRoleFilter !== 'all') filtered = filtered.filter(u => u.role === userRoleFilter);
    if (userSearchQ) {
        const q = userSearchQ.toLowerCase();
        filtered = filtered.filter(u =>
            u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
        );
    }

    if (!filtered.length) {
        container.innerHTML = '<p style="font-size:13px;color:#9CA3AF;padding:12px 0;">No users found.</p>';
        return;
    }

    container.innerHTML = `
        <table class="admin-table">
            <thead><tr>
                <th>Name</th><th>Email</th><th>Role</th><th>Vehicle</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
                ${filtered.map(u => `
                    <tr data-user-id="${u.id}">
                        <td>
                            <span class="admin-avatar" style="background:${avatarBg(u.role)};color:${avatarColor(u.role)};">
                                ${initials(u.name)}
                            </span>${u.name}
                        </td>
                        <td class="muted">${u.email}</td>
                        <td>${roleBadge(u.role)}</td>
                        <td>${u.vehicle ? u.vehicle.plate_number : '<span class="muted">—</span>'}</td>
                        <td>${u.is_active
                            ? '<span class="badge b-green">Active</span>'
                            : '<span class="badge b-red">Inactive</span>'}</td>
                        <td class="table-actions">
                            <span class="action-link" data-action="edit-user" data-id="${u.id}">Edit</span>
                            <span class="action-link" data-action="change-password" data-id="${u.id}">Change password</span>
                            ${u.role === 'driver'
                                ? `<span class="action-link" data-action="assign-vehicle" data-id="${u.id}">Reassign vehicle</span>`
                                : ''}
                            ${u.is_active
                                ? `<span class="action-link danger" data-action="deactivate-user" data-id="${u.id}">Deactivate</span>`
                                : `<span class="action-link success" data-action="reactivate-user" data-id="${u.id}">Reactivate</span>`}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;

    container.querySelectorAll('[data-action]').forEach(el => {
        el.addEventListener('click', () => handleUserAction(el.dataset.action, parseInt(el.dataset.id), el));
    });
}

function bindUserFilters() {
    document.querySelectorAll('#userFilters .admin-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#userFilters .admin-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            userRoleFilter = btn.dataset.role;
            renderUsersTable();
        });
    });
}

function bindUserSearch() {
    document.getElementById('userSearch')?.addEventListener('input', e => {
        userSearchQ = e.target.value;
        renderUsersTable();
    });
}

function bindAddUserBtn() {
    document.getElementById('addUserBtn')?.addEventListener('click', () => openAddUserModal());
    document.getElementById('importUsersBtn')?.addEventListener('click', () => openImportUsersModal());
}

function handleUserAction(action, userId, triggerEl) {
    const user = allUsers.find(u => u.id === userId);
    if (!user) return;

    switch (action) {
        case 'edit-user':       return openEditUserModal(user);
        case 'assign-vehicle':  return openAssignVehicleModal(user);
        case 'deactivate-user': return confirmDeactivateUser(user);
        case 'reactivate-user': return reactivateUser(user, triggerEl);
        case 'change-password': return openChangePasswordModal(user);
    }
}

async function reactivateUser(user, triggerEl) {
    // Show inline loading on the action link itself — no modal needed for this one.
    const original = triggerEl?.textContent ?? 'Reactivate';
    if (triggerEl) { triggerEl.textContent = 'Saving…'; triggerEl.style.pointerEvents = 'none'; }

    try {
        await apiFetch(`/admin/api/users/${user.id}/reactivate`, 'POST');
        await refreshUsers();
    } finally {
        if (triggerEl) { triggerEl.textContent = original; triggerEl.style.pointerEvents = ''; }
    }
}

async function confirmDeactivateUser(user) {
    openConfirmModal(
        `Deactivate ${user.name}?`,
        'This user will not be able to log in until reactivated.',
        async (confirmBtn) => {
            setLoading(confirmBtn, true, 'Deactivating…');
            try {
                await apiFetch(`/admin/api/users/${user.id}/deactivate`, 'POST');
                await refreshUsers();
                closeModal();
            } finally {
                setLoading(confirmBtn, false, 'Confirm');
            }
        }
    );
}

async function refreshUsers() {
    const res = await fetch('/admin/api/users');
    allUsers  = await res.json();
    renderUsersTable();
}

// ─── Vehicles tab ─────────────────────────────────────────────────────────────

async function loadVehicles() {
    const grid = document.getElementById('vehiclesGrid');
    if (grid) grid.innerHTML = skeletonCards(3);

    const res  = await fetch('/admin/api/vehicles');
    const data = await res.json();

    // FIX 4: vehicleList() now returns { vehicles, drivers } so the
    // assign-driver modal has data even when the Users tab hasn't been visited.
    allVehicles = data.vehicles;

    // Seed allUsers with drivers if the Users tab hasn't loaded them yet.
    // If the Users tab has already loaded the full list, don't overwrite it
    // since that list includes students and admins too.
    if (!loaded.users && data.drivers) {
        allUsers = data.drivers;
    }

    renderVehicleCards();
    document.getElementById('addVehicleBtn')?.addEventListener('click', openAddVehicleModal);
}

function renderVehicleCards() {
    const grid = document.getElementById('vehiclesGrid');
    if (!grid) return;

    setText('vehicleCount', `${allVehicles.length} vehicle${allVehicles.length !== 1 ? 's' : ''} registered`);

    if (!allVehicles.length) {
        grid.innerHTML = '<p style="font-size:13px;color:#9CA3AF;">No vehicles yet.</p>';
        return;
    }

    grid.innerHTML = allVehicles.map(v => `
        <div class="admin-vcard" data-vehicle-id="${v.id}">
            <div class="admin-vcard-header">
                <span class="admin-plate">${v.plate_number}</span>
                ${gpsStatusBadge(v.gps_status)}
            </div>
            <div class="admin-vcard-body">
                <div><div class="vfield-lbl">Assigned driver</div><div class="vfield-val">${v.user?.name ?? '<span class="muted">Unassigned</span>'}</div></div>
                <div><div class="vfield-lbl">Route</div><div class="vfield-val">${v.route_name ?? '<span class="muted">—</span>'}</div></div>
                <div><div class="vfield-lbl">Last seen</div><div class="vfield-val">${v.last_seen ? formatTimeAgo(v.last_seen) : '<span class="muted">—</span>'}</div></div>
                <div><div class="vfield-lbl">Capacity</div><div class="vfield-val">${v.shift_active ? (v.is_full ? 'Full' : 'Available') : '<span class="muted">—</span>'}</div></div>
            </div>
            <div class="admin-vcard-actions">
                <button class="admin-btn-sm" data-action="edit-vehicle"   data-id="${v.id}">Edit</button>
                <button class="admin-btn-sm" data-action="assign-driver"  data-id="${v.id}">Reassign driver</button>
                <button class="admin-btn-sm danger" data-action="remove-vehicle" data-id="${v.id}">Remove</button>
            </div>
        </div>`).join('');

    grid.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => handleVehicleAction(btn.dataset.action, parseInt(btn.dataset.id)));
    });
}

function handleVehicleAction(action, vehicleId) {
    const v = allVehicles.find(x => x.id === vehicleId);
    if (!v) return;

    switch (action) {
        case 'edit-vehicle':   return openEditVehicleModal(v);
        case 'assign-driver':  return openAssignDriverModal(v);
        case 'remove-vehicle': return confirmRemoveVehicle(v);
    }
}

async function confirmRemoveVehicle(v) {
    if (v.shift_active) {
        openConfirmModal(
            `Cannot remove ${v.plate_number}`,
            'This vehicle has an active shift. End the shift first before removing.',
            null,
            true   // disableConfirm
        );
        return;
    }

    openConfirmModal(
        `Remove ${v.plate_number}?`,
        'This action cannot be undone.',
        async (confirmBtn) => {
            setLoading(confirmBtn, true, 'Removing…');
            try {
                await apiFetch(`/admin/api/vehicles/${v.id}`, 'DELETE');
                await refreshVehicles();
                closeModal();
            } finally {
                setLoading(confirmBtn, false, 'Confirm');
            }
        }
    );
}

async function refreshVehicles() {
    const res  = await fetch('/admin/api/vehicles');
    const data = await res.json();
    allVehicles = data.vehicles;
    if (!loaded.users && data.drivers) allUsers = data.drivers;
    renderVehicleCards();
}

// ─── Shifts tab ───────────────────────────────────────────────────────────────

function loadShifts() {
    bindShiftFilters();
    bindShiftSearch();
    fetchShifts();
}

function bindShiftFilters() {
    document.querySelectorAll('#shiftFilters .admin-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#shiftFilters .admin-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            shiftRange = btn.dataset.range;
            fetchShifts();
        });
    });
}

function bindShiftSearch() {
    document.getElementById('shiftSearch')?.addEventListener('input', e => {
        shiftQ = e.target.value;
        clearTimeout(shiftTimer);
        shiftTimer = setTimeout(fetchShifts, 350);
    });
}

async function fetchShifts() {
    const container = document.getElementById('shiftsTable');
    showTableSkeleton(container, 5);

    const params = new URLSearchParams({ range: shiftRange, q: shiftQ });
    const res    = await fetch(`/admin/api/shifts?${params}`);
    const shifts = await res.json();
    renderShiftsTable(shifts);
}

function renderShiftsTable(shifts) {
    const container = document.getElementById('shiftsTable');
    if (!container) return;

    if (!shifts.length) {
        container.innerHTML = '<p style="font-size:13px;color:#9CA3AF;padding:12px 0;">No shifts found for this period.</p>';
        return;
    }

    container.innerHTML = `
        <table class="admin-table">
            <thead><tr>
                <th>Driver</th><th>Vehicle</th><th>Route</th>
                <th>Started</th><th>Ended</th><th>Duration</th><th>End reason</th>
            </tr></thead>
            <tbody>
                ${shifts.map(s => `
                    <tr>
                        <td>${s.driver}</td>
                        <td>${s.plate}</td>
                        <td>${s.route}</td>
                        <td class="muted">${formatDatetime(s.started_at)}</td>
                        <td class="muted">${s.ended_at
                            ? formatDatetime(s.ended_at)
                            : '<span style="color:#43A047;font-weight:500;">Ongoing</span>'}</td>
                        <td>${s.ended_at ? s.duration_human : '<span class="muted">—</span>'}</td>
                        <td>${endReasonBadge(s.end_reason)}</td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
}

// ─── Analytics tab ────────────────────────────────────────────────────────────

async function loadAnalytics() {
    // Show skeleton nums while loading
    ['aStatShifts','aStatAvg','aStatAuto','aStatDrivers'].forEach(id => setText(id, '…'));
    const charts = document.getElementById('analyticsCharts');
    if (charts) charts.innerHTML = skeletonCards(2);

    const res  = await fetch('/admin/api/analytics');
    const data = await res.json();

    setText('aStatShifts',  data.total_shifts_month);
    setText('aStatAvg',     data.avg_duration_human);
    setText('aStatAuto',    data.auto_ended_month);
    setText('aStatDrivers', data.total_drivers);

    if (!charts) return;

    const maxDay = Math.max(...data.shifts_per_day.map(d => d.count), 1);
    const total  = (data.manual_ended_month || 0) + (data.logout_ended_month || 0) + (data.auto_ended_month || 0);
    const pct    = n => total ? Math.round((n / total) * 100) : 0;
    const pM     = pct(data.manual_ended_month);
    const pL     = pct(data.logout_ended_month);
    const pA     = pct(data.auto_ended_month);

    charts.innerHTML = `
        <div class="analytics-card">
            <div class="analytics-card-title">Shifts per day — this week</div>
            <div class="bar-chart">
                ${data.shifts_per_day.map(d => `
                    <div class="bar-wrap">
                        <div class="bar-val">${d.count || ''}</div>
                        <div class="bar" style="height:${Math.max(Math.round((d.count / maxDay) * 80), d.count ? 4 : 0)}px;"></div>
                        <div class="bar-lbl">${d.label}</div>
                    </div>`).join('')}
            </div>
        </div>
        <div class="analytics-card">
            <div class="analytics-card-title">Shift end reason — this month</div>
            ${total === 0
                ? '<p style="font-size:12px;color:#9CA3AF;padding-top:8px;">No completed shifts this month.</p>'
                : `<div style="display:flex;align-items:center;gap:20px;padding-top:8px;">
                    <div class="donut" style="background:conic-gradient(#002D62 0% ${pM}%, #378ADD ${pM}% ${pM+pL}%, #E64A19 ${pM+pL}% 100%);"></div>
                    <div>
                        <div class="legend-row"><span class="legend-dot" style="background:#002D62;"></span>Manual — ${data.manual_ended_month} (${pM}%)</div>
                        <div class="legend-row"><span class="legend-dot" style="background:#378ADD;"></span>Logout — ${data.logout_ended_month} (${pL}%)</div>
                        <div class="legend-row"><span class="legend-dot" style="background:#E64A19;"></span>Auto-ended — ${data.auto_ended_month} (${pA}%)</div>
                    </div>
                   </div>`}
        </div>`;
}

// ─── Modal system ─────────────────────────────────────────────────────────────

function initModal() {
    document.getElementById('modalOverlay')?.addEventListener('click', e => {
        if (e.target.id === 'modalOverlay') closeModal();
    });
}

/**
 * Opens a modal with arbitrary HTML.
 * If onSubmit is provided, it is called with (form, submitBtn) so the
 * caller can manage loading state via setLoading(submitBtn, ...).
 * The submit button is automatically disabled while onSubmit runs and
 * re-enabled if it throws.
 */
function openModal(html, onSubmit) {
    const box     = document.getElementById('modalBox');
    const overlay = document.getElementById('modalOverlay');
    if (!box || !overlay) return;

    box.innerHTML = html;
    overlay.classList.remove('hidden');

    box.querySelector('.modal-close')?.addEventListener('click', closeModal);

    if (onSubmit) {
        const form      = box.querySelector('form');
        const submitBtn = form?.querySelector('[type="submit"]');

        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const originalText = submitBtn?.textContent ?? '';
            setLoading(submitBtn, true, 'Saving…');
            try {
                await onSubmit(form, submitBtn);
            } finally {
                // Only restore if modal is still open (i.e. there was an error)
                if (!document.getElementById('modalOverlay')?.classList.contains('hidden')) {
                    setLoading(submitBtn, false, originalText);
                }
            }
        });
    }
}

function closeModal() {
    document.getElementById('modalOverlay')?.classList.add('hidden');
}

// ─── Modal templates ──────────────────────────────────────────────────────────

function openAddUserModal() {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Add user</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Name</label>
            <input class="modal-input" name="name" required>
            <label class="modal-label">Email</label>
            <input class="modal-input" name="email" type="email" required>
            <label class="modal-label">Password</label>
            <input class="modal-input" name="password" type="password" required minlength="8">
            <label class="modal-label">Role</label>
            <select class="modal-input" name="role">
                <option value="student">Student</option>
                <option value="driver">Driver</option>
                <option value="admin">Admin</option>
            </select>
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:4px;">Create user</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch('/admin/api/users', 'POST', data);
        if (res.status === 'success') { await refreshUsers(); closeModal(); }
    });
}

function openEditUserModal(user) {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Edit user</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Name</label>
            <input class="modal-input" name="name" value="${user.name}" required>
            <label class="modal-label">Email</label>
            <input class="modal-input" name="email" type="email" value="${user.email}" required>
            <label class="modal-label">Role</label>
            <select class="modal-input" name="role">
                <option value="student" ${user.role === 'student' ? 'selected' : ''}>Student</option>
                <option value="driver"  ${user.role === 'driver'  ? 'selected' : ''}>Driver</option>
                <option value="admin"   ${user.role === 'admin'   ? 'selected' : ''}>Admin</option>
            </select>
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:4px;">Save changes</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch(`/admin/api/users/${user.id}`, 'PUT', data);
        if (res.status === 'success') { await refreshUsers(); closeModal(); }
    });
}

function openAssignVehicleModal(user) {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Assign vehicle to ${user.name}</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Vehicle</label>
            <select class="modal-input" name="vehicle_id" required>
                <option value="">— Select vehicle —</option>
                ${allVehicles.map(v => `
                    <option value="${v.id}" ${v.user_id === user.id ? 'selected' : ''}>
                        ${v.plate_number}${v.user_id && v.user_id !== user.id ? ' (currently assigned)' : ''}
                    </option>`).join('')}
            </select>
            <p style="font-size:11px;color:#9CA3AF;margin-top:4px;">
                Assigning a new vehicle will remove any existing assignment.
            </p>
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:8px;">Assign</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch(`/admin/api/users/${user.id}/assign-vehicle`, 'POST', data);
        if (res.status === 'success') { await refreshUsers(); await refreshVehicles(); closeModal(); }
    });
}

function openAddVehicleModal() {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Add vehicle</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Plate number</label>
            <input class="modal-input" name="plate_number" required placeholder="e.g. ABC 123">
            <label class="modal-label">Route (optional)</label>
            <input class="modal-input" name="route_name" placeholder="e.g. Route A – Mangaldan">
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:4px;">Add vehicle</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch('/admin/api/vehicles', 'POST', data);
        if (res.status === 'success') { await refreshVehicles(); closeModal(); }
    });
}

function openEditVehicleModal(v) {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Edit vehicle</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Plate number</label>
            <input class="modal-input" name="plate_number" value="${v.plate_number}" required>
            <label class="modal-label">Route</label>
            <input class="modal-input" name="route_name" value="${v.route_name ?? ''}">
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:4px;">Save changes</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch(`/admin/api/vehicles/${v.id}`, 'PUT', data);
        if (res.status === 'success') { await refreshVehicles(); closeModal(); }
    });
}

function openAssignDriverModal(v) {
    const drivers = allUsers.filter(u => u.role === 'driver' && u.is_active);

    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Assign driver to ${v.plate_number}</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Driver</label>
            <select class="modal-input" name="user_id" required>
                <option value="">— Select driver —</option>
                ${drivers.map(u => `
                    <option value="${u.id}" ${u.id === v.user_id ? 'selected' : ''}>
                        ${u.name}${u.vehicle && u.vehicle.id !== v.id ? ' (has another vehicle)' : ''}
                    </option>`).join('')}
            </select>
            <p style="font-size:11px;color:#9CA3AF;margin-top:4px;">
                The driver's previous vehicle assignment will be removed.
            </p>
            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:8px;">Assign</button>
        </form>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const res  = await apiFetch(`/admin/api/vehicles/${v.id}/assign-driver`, 'POST', data);
        if (res.status === 'success') { await refreshVehicles(); await refreshUsers(); closeModal(); }
    });
}

/**
 * openConfirmModal
 * onConfirm receives the confirm button element so it can call setLoading() on it.
 */
function openConfirmModal(title, body, onConfirm, disableConfirm = false) {
    const box     = document.getElementById('modalBox');
    const overlay = document.getElementById('modalOverlay');
    if (!box || !overlay) return;

    box.innerHTML = `
        <div class="modal-header">
            <h2 class="modal-title">${title}</h2>
            <button class="modal-close">✕</button>
        </div>
        <p style="font-size:13px;color:#6B7280;margin-bottom:16px;">${body}</p>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="admin-btn-sm modal-close-btn">Cancel</button>
            ${!disableConfirm
                ? `<button class="admin-btn-primary modal-confirm-btn" style="background:#dc3545;">Confirm</button>`
                : ''}
        </div>`;

    overlay.classList.remove('hidden');
    box.querySelector('.modal-close')?.addEventListener('click', closeModal);
    box.querySelector('.modal-close-btn')?.addEventListener('click', closeModal);

    const confirmBtn = box.querySelector('.modal-confirm-btn');
    if (onConfirm && confirmBtn) {
        confirmBtn.addEventListener('click', () => onConfirm(confirmBtn));
    }
}

// ─── Loading helpers ──────────────────────────────────────────────────────────

/**
 * Toggle a button between its normal state and a loading state.
 * Disables the button and swaps the text while loading.
 */
function setLoading(btn, isLoading, loadingText = 'Saving…') {
    if (!btn) return;
    if (isLoading) {
        btn.dataset.originalText = btn.textContent;
        btn.textContent          = loadingText;
        btn.disabled             = true;
        btn.style.opacity        = '0.7';
        btn.style.cursor         = 'not-allowed';
    } else {
        btn.textContent   = btn.dataset.originalText ?? loadingText;
        btn.disabled      = false;
        btn.style.opacity = '';
        btn.style.cursor  = '';
    }
}

// ─── Skeleton loaders ─────────────────────────────────────────────────────────

/**
 * Render a pulsing skeleton table while data is loading.
 */
function showTableSkeleton(container, rows = 4) {
    if (!container) return;
    const skRow = `
        <tr>${Array(6).fill(`
            <td><div style="height:12px;border-radius:4px;background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:shimmer 1.2s infinite;"></div></td>
        `).join('')}</tr>`;
    container.innerHTML = `
        <style>
            @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
        </style>
        <table class="admin-table">
            <tbody>${Array(rows).fill(skRow).join('')}</tbody>
        </table>`;
}

/**
 * Render skeleton cards while vehicle data is loading.
 */
function skeletonCards(n = 3) {
    const card = `
        <div class="admin-vcard" style="opacity:0.6;">
            <div style="height:16px;width:40%;border-radius:4px;background:#e0e0e0;margin-bottom:12px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                ${Array(4).fill(`<div style="height:28px;border-radius:4px;background:#e0e0e0;"></div>`).join('')}
            </div>
            <div style="height:30px;border-radius:6px;background:#e0e0e0;"></div>
        </div>`;
    return Array(n).fill(card).join('');
}

// ─── General helpers ──────────────────────────────────────────────────────────

async function apiFetch(url, method, body) {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': CSRF(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json();
    if (!res.ok) {
        const msg = data.message ?? 'Something went wrong.';
        alert(msg);
        throw new Error(msg);
    }
    return data;
}

function openChangePasswordModal(user) {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Change password — ${escapeHtml(user.name)}</h2>
            <button class="modal-close">✕</button>
        </div>
        <p style="font-size:12px;color:#6B7280;margin-bottom:12px;">
            The user will need to use this new password on their next login.
        </p>
        <form class="modal-form">
            <label class="modal-label">New password</label>
            <input class="modal-input"
                   name="password"
                   type="password"
                   minlength="8"
                   placeholder="At least 8 characters"
                   required
                   autocomplete="new-password">

            <label class="modal-label">Confirm new password</label>
            <input class="modal-input"
                   name="password_confirmation"
                   type="password"
                   minlength="8"
                   placeholder="Repeat the password"
                   required
                   autocomplete="new-password">

            <p id="passwordMatchHint"
               style="font-size:11px;color:#9CA3AF;margin-top:-8px;">
            </p>

            <button type="submit"
                    class="admin-btn-primary"
                    style="width:100%;margin-top:4px;">
                Set new password
            </button>
        </form>
    `, async (form) => {
        const password              = form.password.value;
        const password_confirmation = form.password_confirmation.value;

        if (password !== password_confirmation) {
            document.getElementById('passwordMatchHint').textContent = 'Passwords do not match.';
            document.getElementById('passwordMatchHint').style.color = '#DC2626';
            throw new Error('Passwords do not match.');
        }

        const res = await apiFetch(
            `/admin/api/users/${user.id}/change-password`,
            'POST',
            { password, password_confirmation }
        );

        if (res.status === 'success') {
            closeModal();
            showAdminToast(`Password updated for ${user.name}.`);
        }
    });

    const modal        = document.getElementById('modalBox');
    const confirmInput = modal?.querySelector('[name="password_confirmation"]');
    const hint         = modal?.querySelector('#passwordMatchHint');

    confirmInput?.addEventListener('input', () => {
        const pw = modal.querySelector('[name="password"]').value;
        if (!confirmInput.value) { hint.textContent = ''; return; }
        if (confirmInput.value === pw) {
            hint.textContent = '✓ Passwords match';
            hint.style.color = '#065F46';
        } else {
            hint.textContent = 'Passwords do not match';
            hint.style.color = '#DC2626';
        }
    });
}

function openImportUsersModal() {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">Import Users from CSV</h2>
            <button class="modal-close">✕</button>
        </div>

        <p style="font-size:12px;color:#6B7280;margin-bottom:12px;">
            Upload a CSV file with columns: <strong>name, email, password, role</strong>.
            Rows with errors are skipped — the rest still import.
        </p>

        <a id="downloadTemplateLink"
           href="/admin/api/users/import/template"
           style="display:inline-block;font-size:12px;color:#185FA5;margin-bottom:16px;text-decoration:underline;">
            ⬇ Download template CSV
        </a>

        <form class="modal-form" id="importForm" enctype="multipart/form-data">
            <label class="modal-label">CSV File</label>

            <div id="dropZone" style="
                border: 2px dashed #D1D5DB;
                border-radius: 8px;
                padding: 24px;
                text-align: center;
                cursor: pointer;
                transition: border-color 0.2s, background 0.2s;
                margin-bottom: 4px;
            ">
                <p style="font-size:13px;color:#6B7280;margin:0;" id="dropLabel">
                    Drag and drop your CSV here, or click to browse
                </p>
                <input type="file" name="csv_file" id="csvFileInput"
                       accept=".csv,text/csv" style="display:none;">
            </div>

            <p id="selectedFileName"
               style="font-size:11px;color:#9CA3AF;margin-top:4px;"></p>

            <button type="submit" class="admin-btn-primary"
                    style="width:100%;margin-top:12px;">
                Import Users
            </button>
        </form>

        <div id="importResults" style="display:none;margin-top:16px;"></div>
    `);

    initDropZone();
}

function initDropZone() {
    const zone   = document.getElementById('dropZone');
    const input  = document.getElementById('csvFileInput');
    const label  = document.getElementById('dropLabel');
    const nameEl = document.getElementById('selectedFileName');
    const form   = document.getElementById('importForm');

    zone.addEventListener('click', () => input.click());

    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.style.borderColor = '#185FA5';
        zone.style.background  = '#EFF6FF';
    });

    zone.addEventListener('dragleave', () => {
        zone.style.borderColor = '#D1D5DB';
        zone.style.background  = '';
    });

    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.style.borderColor = '#D1D5DB';
        zone.style.background  = '';
        const file = e.dataTransfer.files[0];
        if (file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            nameEl.textContent = `Selected: ${file.name}`;
            label.textContent  = file.name;
        }
    });

    input.addEventListener('change', () => {
        if (input.files[0]) {
            nameEl.textContent = `Selected: ${input.files[0].name}`;
            label.textContent  = input.files[0].name;
        }
    });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        await submitImport(form);
    });
}

async function submitImport(form) {
    const submitBtn = form.querySelector('[type="submit"]');
    const results   = document.getElementById('importResults');

    if (!form.querySelector('#csvFileInput').files[0]) {
        alert('Please select a CSV file first.');
        return;
    }

    setLoading(submitBtn, true, 'Importing…');

    const formData = new FormData(form);
    formData.append('_token', CSRF());

    try {
        const res  = await fetch('/admin/api/users/import', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': CSRF() },
            body:        formData,
        });

        const data = await res.json();

        if (!res.ok) throw new Error(data.message ?? 'Import failed.');

        results.style.display = 'block';

        const errorHtml = data.errors.length
            ? `<div style="margin-top:10px;">
                   <p style="font-size:12px;font-weight:600;color:#991B1B;margin-bottom:6px;">
                       ${data.errors.length} row(s) had errors and were skipped:
                   </p>
                   <ul style="font-size:11px;color:#6B7280;padding-left:16px;max-height:160px;overflow-y:auto;">
                       ${data.errors.map(e => `<li style="margin-bottom:4px;">${escapeHtml(e)}</li>`).join('')}
                   </ul>
               </div>`
            : '';

        results.innerHTML = `
            <div style="background:${data.created > 0 ? '#D1FAE5' : '#FEF3C7'};
                        border-radius:6px;padding:12px;">
                <p style="font-size:13px;font-weight:600;
                           color:${data.created > 0 ? '#065F46' : '#92400E'};margin:0;">
                    ${data.created > 0
                        ? `✓ ${data.created} user${data.created !== 1 ? 's' : ''} imported successfully`
                        : 'No users were imported'}
                </p>
            </div>
            ${errorHtml}`;

        if (data.created > 0) await refreshUsers();

    } catch (err) {
        alert(err.message ?? 'Something went wrong during import.');
    } finally {
        setLoading(submitBtn, false, 'Import Users');
    }
}

function showAdminToast(message) {
    const toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #111827;
        color: #fff;
        font-size: 13px;
        padding: 10px 16px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        z-index: 9999;
        opacity: 0;
        transition: opacity 0.2s;
    `;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.style.opacity = '1');
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 250);
    }, 3000);
}

function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function initials(name) {
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

function avatarBg(role) {
    return role === 'driver' ? '#E6F1FB' : role === 'admin' ? '#EEEDFE' : '#FAEEDA';
}

function avatarColor(role) {
    return role === 'driver' ? '#185FA5' : role === 'admin' ? '#534AB7' : '#854F0B';
}

function roleBadge(role) {
    const map = {
        driver:  ['#E6F1FB', '#185FA5', 'Driver'],
        student: ['#FAEEDA', '#854F0B', 'Student'],
        admin:   ['#EEEDFE', '#534AB7', 'Admin'],
    };
    const [bg, color, label] = map[role] ?? ['#eee', '#555', role];
    return `<span class="badge" style="background:${bg};color:${color};">${label}</span>`;
}

function gpsStatusBadge(status) {
    const map = {
        moving:       ['#D1FAE5', '#065F46', 'Moving'],
        traffic:      ['#FFF3E0', '#E65100', 'In traffic'],
        idle:         ['#FEF3C7', '#92400E', 'Idle'],
        disconnected: ['#FEE2E2', '#991B1B', 'No signal'],
        shift_ended:  ['#F3F4F6', '#4B5563', 'Off shift'],
    };
    const [bg, color, label] = map[status] ?? ['#eee', '#555', status];
    return `<span class="badge" style="background:${bg};color:${color};">${label}</span>`;
}

function endReasonBadge(reason) {
    if (!reason) return '<span class="muted">—</span>';
    const map = {
        manual: ['#D1FAE5', '#065F46', 'Manual'],
        logout: ['#DBEAFE', '#1E40AF', 'Logout'],
        auto:   ['#FEF3C7', '#92400E', 'Auto-ended'],
    };
    const [bg, color, label] = map[reason] ?? ['#eee', '#555', reason];
    return `<span class="badge" style="background:${bg};color:${color};">${label}</span>`;
}

function formatTimeAgo(iso) {
    const sec = Math.floor((Date.now() - new Date(iso)) / 1000);
    if (sec < 60)   return `${sec}s ago`;
    if (sec < 3600) return `${Math.floor(sec / 60)}m ago`;
    return `${Math.floor(sec / 3600)}h ago`;
}

function formatDatetime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString([], {
        month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
// ─── Announcements ────────────────────────────────────────────────────────────

const ROUTES = [
    'Route A – Mangaldan',
    'Route B – Calasiao',
    'Route C – San Fabian',
];

function openSendAlertModal() {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">📢 Send Alert to Students</h2>
            <button class="modal-close">✕</button>
        </div>
        <form class="modal-form">
            <label class="modal-label">Message</label>
            <textarea
                class="modal-input"
                name="message"
                rows="3"
                maxlength="300"
                placeholder="e.g. Route A will not be available until further notice."
                required
                style="resize:vertical;font-family:inherit;"></textarea>
            <p id="charCount" style="font-size:11px;color:#9CA3AF;text-align:right;margin-top:-8px;">0 / 300</p>

            <label class="modal-label">Scope</label>
            <select class="modal-input" name="route">
                <option value="">All routes</option>
                ${ROUTES.map(r => `<option value="${r}">${r}</option>`).join('')}
            </select>

            <label class="modal-label">Expires</label>
            <select class="modal-input" name="expires_preset">
                <option value="">Today only (midnight)</option>
                <option value="2h">In 2 hours</option>
                <option value="4h">In 4 hours</option>
                <option value="manual">Manual deactivation only</option>
            </select>

            <button type="submit" class="admin-btn-primary" style="width:100%;margin-top:4px;background:#C2410C;">
                Send Alert
            </button>
        </form>
    `, async (form) => {
        const fd      = new FormData(form);
        const message = fd.get('message').trim();
        const route   = fd.get('route') || null;
        const preset  = fd.get('expires_preset');

        let expires_at = null;
        if (preset === '2h')    expires_at = new Date(Date.now() + 2 * 3600000).toISOString();
        if (preset === '4h')    expires_at = new Date(Date.now() + 4 * 3600000).toISOString();
        if (!preset || preset === '') {
            // Today only — expires at midnight local time
            const midnight = new Date();
            midnight.setHours(23, 59, 59, 999);
            expires_at = midnight.toISOString();
        }
        // 'manual' leaves expires_at null

        await apiFetch('/admin/api/announcements', 'POST', { message, route, expires_at });
        closeModal();
    });

    // Live char counter
    const box = document.getElementById('modalBox');
    const textarea = box?.querySelector('textarea[name="message"]');
    const counter  = box?.querySelector('#charCount');
    textarea?.addEventListener('input', () => {
        if (counter) counter.textContent = `${textarea.value.length} / 300`;
    });
}

async function openAnnouncementsListModal() {
    openModal(`
        <div class="modal-header">
            <h2 class="modal-title">📋 Active Announcements</h2>
            <button class="modal-close">✕</button>
        </div>
        <div id="announcementsList" style="display:flex;flex-direction:column;gap:10px;margin-top:4px;">
            <p style="color:#9CA3AF;font-size:13px;">Loading…</p>
        </div>
    `);

    const announcements = await apiFetch('/admin/api/announcements', 'GET');
    const list = document.getElementById('announcementsList');
    if (!list) return;

    const active = announcements.filter(a => a.is_active);

    if (!active.length) {
        list.innerHTML = '<p style="font-size:13px;color:#9CA3AF;text-align:center;padding:16px 0;">No active announcements.</p>';
        return;
    }

    list.innerHTML = active.map(a => `
        <div class="admin-announcement-row" data-id="${a.id}">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:700;color:#C2410C;margin-bottom:3px;">
                    ${a.route ? a.route : 'All routes'}
                    ${a.expires_at ? `· expires ${formatDatetime(a.expires_at)}` : '· manual deactivation'}
                </div>
                <p style="font-size:13px;color:#111827;margin:0;line-height:1.4;">${escapeHtml(a.message)}</p>
                <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">Sent by ${a.created_by} · ${formatTimeAgo(a.created_at)}</div>
            </div>
            <button class="admin-btn-sm danger deactivate-btn" data-id="${a.id}" style="flex-shrink:0;">
                Deactivate
            </button>
        </div>
    `).join('');

    list.querySelectorAll('.deactivate-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            setLoading(btn, true, 'Deactivating…');
            try {
                await apiFetch(`/admin/api/announcements/${btn.dataset.id}/deactivate`, 'POST');
                btn.closest('.admin-announcement-row').remove();
                if (!list.querySelector('.admin-announcement-row')) {
                    list.innerHTML = '<p style="font-size:13px;color:#9CA3AF;text-align:center;padding:16px 0;">No active announcements.</p>';
                }
            } finally {
                setLoading(btn, false, 'Deactivate');
            }
        });
    });
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}