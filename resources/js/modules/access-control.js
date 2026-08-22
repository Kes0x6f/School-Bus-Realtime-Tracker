/**
 * Disconnect a browser as soon as the server revokes its account access.
 *
 * This is only a user-experience improvement. EnsureAccountIsActive remains
 * the security boundary for every subsequent HTTP and broadcast-auth request.
 */
export function initAccessRevocation() {
    const userId = document.querySelector('meta[name="auth-user-id"]')?.content;

    if (!userId || !window.Echo) return;

    window.Echo.private(`user.${userId}`)
        .listen('.access.revoked', () => {
            window.Echo.disconnect();
            window.location.assign('/?deactivated=1');
        });
}
