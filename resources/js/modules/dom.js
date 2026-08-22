/**
 * Escape untrusted text before it is used in an HTML string.
 *
 * Prefer textContent/createElement for new DOM code. This helper is kept for
 * existing template-style renderers that still need a small HTML shell.
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
