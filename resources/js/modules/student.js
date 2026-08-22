/**
 * student.js
 * Shared JS for student-facing pages (active-jeeps, tracking).
 * Currently handles: self-service password change modal.
 */

const CSRF = () =>
    document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// ─── Password change modal ────────────────────────────────────────────────────

/**
 * Exposed for the shared modal handler below and for backwards compatibility
 * with pages that may still reference the function during a rolling deploy.
 */
window.submitStudentPasswordChange = async function () {
    const current   = document.getElementById('spwCurrent')?.value   ?? '';
    const newPw     = document.getElementById('spwNew')?.value        ?? '';
    const confirm   = document.getElementById('spwConfirm')?.value    ?? '';
    const submitBtn = document.getElementById('spwSubmitBtn');
    const errorEl   = document.getElementById('spwError');
    const successEl = document.getElementById('spwSuccess');
    const hintEl    = document.getElementById('spwHint');

    // Reset state
    errorEl.style.display   = 'none';
    errorEl.textContent     = '';
    hintEl.textContent      = '';

    // Client-side guards
    if (!current) {
        showStudentPwError('Please enter your current password.');
        return;
    }
    if (newPw.length < 8) {
        showStudentPwError('New password must be at least 8 characters.');
        return;
    }
    if (newPw !== confirm) {
        showStudentPwError('New passwords do not match.');
        return;
    }

    // Loading state
    submitBtn.disabled     = true;
    submitBtn.textContent  = 'Updating…';
    submitBtn.style.opacity = '0.7';

    try {
        const res  = await fetch('/student/change-password', {
            method:  'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': CSRF(),
            },
            body: JSON.stringify({
                current_password:      current,
                password:              newPw,
                password_confirmation: confirm,
            }),
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
            // Hide the form, show success message
            document.getElementById('studentPwForm').style.display  = 'none';
            successEl.style.display = 'block';

            // Auto-close after 2 s so the student doesn't have to
            setTimeout(() => {
                document.getElementById('studentPwOverlay')
                    ?.classList.add('hidden');
                // Reset the modal for next open
                setTimeout(resetStudentPwModal, 300);
            }, 2000);

        } else {
            // Server returned a validation or auth error
            showStudentPwError(data.message ?? 'Something went wrong. Please try again.');
        }

    } catch (err) {
        showStudentPwError('Network error. Check your connection and try again.');
    } finally {
        submitBtn.disabled      = false;
        submitBtn.textContent   = 'Update password';
        submitBtn.style.opacity = '';
    }
};

function showStudentPwError(message) {
    const el = document.getElementById('spwError');
    if (!el) return;
    el.textContent     = message;
    el.style.display   = 'block';
}

function resetStudentPwModal() {
    ['spwCurrent', 'spwNew', 'spwConfirm'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const errorEl   = document.getElementById('spwError');
    const successEl = document.getElementById('spwSuccess');
    const formEl    = document.getElementById('studentPwForm');
    const hintEl    = document.getElementById('spwHint');

    if (errorEl)   { errorEl.style.display   = 'none'; errorEl.textContent = ''; }
    if (successEl) { successEl.style.display = 'none'; }
    if (formEl)    { formEl.style.display    = ''; }
    if (hintEl)    { hintEl.textContent      = ''; }
}

// ─── Live match hint ──────────────────────────────────────────────────────────

/**
 * Wire up the confirm field to show a live match/mismatch hint.
 * Called once on DOMContentLoaded if the modal is present.
 */
export function initStudentPasswordModal() {
    const confirmInput = document.getElementById('spwConfirm');
    const newInput     = document.getElementById('spwNew');
    const hintEl       = document.getElementById('spwHint');

    if (!confirmInput || !newInput || !hintEl) return;

    document.querySelectorAll('[data-open-student-settings]').forEach(button => {
        button.addEventListener('click', () => {
            document.getElementById('studentPwOverlay')?.classList.remove('hidden');
        });
    });

    document.querySelectorAll('[data-close-student-settings]').forEach(button => {
        button.addEventListener('click', () => {
            document.getElementById('studentPwOverlay')?.classList.add('hidden');
            resetStudentPwModal();
        });
    });

    document.getElementById('spwSubmitBtn')
        ?.addEventListener('click', () => window.submitStudentPasswordChange());

    document.querySelector('[data-logout-link]')?.addEventListener('click', event => {
        event.preventDefault();
        document.getElementById('logout-form')?.submit();
    });

    confirmInput.addEventListener('input', () => {
        if (!confirmInput.value) { hintEl.textContent = ''; return; }
        if (confirmInput.value === newInput.value) {
            hintEl.textContent = '✓ Passwords match';
            hintEl.style.color = '#065F46';
        } else {
            hintEl.textContent = 'Passwords do not match';
            hintEl.style.color = '#DC2626';
        }
    });

    // Close modal when clicking the backdrop
    document.getElementById('studentPwOverlay')
        ?.addEventListener('click', e => {
            if (e.target.id === 'studentPwOverlay') {
                e.target.classList.add('hidden');
                resetStudentPwModal();
            }
        });
}
