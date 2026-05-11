/**
 * Announcements — announcements.js
 *
 * Handles both server-seeded announcements (passed via data-announcements
 * on the #app element) and real-time ones pushed via WebSocket.
 *
 * Dismissal is persisted in sessionStorage so a student won't see the
 * same announcement twice in one session, but will see it again on next login.
 *
 * Public API:
 *   initAnnouncements(routeFilter?)
 *     routeFilter — if provided, only show announcements for this route
 *                   or global ones (route === null).
 *                   Pass null/undefined to show all (active-jeeps page).
 */

const STORAGE_KEY = 'dismissed_announcements';

function getDismissed() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
    } catch {
        return [];
    }
}

function markDismissed(id) {
    const dismissed = getDismissed();
    if (!dismissed.includes(id)) {
        dismissed.push(id);
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(dismissed));
    }
}

function isDismissed(id) {
    return getDismissed().includes(id);
}

// ─── Init ─────────────────────────────────────────────────────────────────────

export function initAnnouncements(routeFilter = null) {
    ensureContainer();
    loadSeededAnnouncements(routeFilter);
    subscribeToChannel(routeFilter);
}

// ─── Container ────────────────────────────────────────────────────────────────

function ensureContainer() {
    if (document.getElementById('announcementStack')) return;

    const stack = document.createElement('div');
    stack.id = 'announcementStack';
    stack.className = 'announcement-stack';
    // Insert at the top of the page content, after the pageLabelDark if present
    const app = document.getElementById('app');
    const label = app?.querySelector('.pageLabelDark');
    if (label) {
        label.insertAdjacentElement('afterend', stack);
    } else {
        app?.prepend(stack);
    }
}

// ─── Load server-seeded announcements ─────────────────────────────────────────

function loadSeededAnnouncements(routeFilter) {
    const app = document.getElementById('app');
    if (!app?.dataset.announcements) return;

    try {
        const announcements = JSON.parse(app.dataset.announcements);
        announcements.forEach(a => showAnnouncement(a, routeFilter));
    } catch (e) {
        console.warn('Failed to parse announcements:', e);
    }
}

// ─── WebSocket subscription ───────────────────────────────────────────────────

function subscribeToChannel(routeFilter) {
    if (!window.Echo) return;

    // Public channel — no auth required
    Echo.channel('announcements')
        .listen('.announcement.created', (e) => {
            showAnnouncement(e, routeFilter);
        })
        .listen('.announcement.deactivated', (e) => {
            removeAnnouncement(e.id);
        });
}

// ─── Show / remove ────────────────────────────────────────────────────────────

function showAnnouncement(announcement, routeFilter) {
    // Already dismissed this session
    if (isDismissed(announcement.id)) return;

    // Route filtering: show if global (no route) or matches this page's route
    if (routeFilter && announcement.route && announcement.route !== routeFilter) return;

    // Don't double-render
    if (document.getElementById(`announcement-${announcement.id}`)) return;

    const stack = document.getElementById('announcementStack');
    if (!stack) return;

    const isRouteSpecific = !!announcement.route;
    const scopeLabel = isRouteSpecific ? `${announcement.route}` : 'All routes';

    const el = document.createElement('div');
    el.id = `announcement-${announcement.id}`;
    el.className = 'announcement-banner announcement-enter';
    el.innerHTML = `
        <div class="announcement-icon">📢</div>
        <div class="announcement-body">
            <span class="announcement-scope">${scopeLabel}</span>
            <p class="announcement-message">${escapeHtml(announcement.message)}</p>
        </div>
        <button class="announcement-dismiss" aria-label="Dismiss">✕</button>
    `;

    el.querySelector('.announcement-dismiss').addEventListener('click', () => {
        dismissAnnouncement(announcement.id, el);
    });

    stack.appendChild(el);

    // Animate in
    requestAnimationFrame(() => {
        el.classList.remove('announcement-enter');
        el.classList.add('announcement-visible');
    });
}

function dismissAnnouncement(id, el) {
    markDismissed(id);
    el.classList.add('announcement-exit');
    setTimeout(() => el.remove(), 300);
}

function removeAnnouncement(id) {
    // Admin deactivated it — remove for all currently connected students
    const el = document.getElementById(`announcement-${id}`);
    if (el) {
        el.classList.add('announcement-exit');
        setTimeout(() => el.remove(), 300);
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}