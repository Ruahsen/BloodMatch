/**
 * Shared notification helpers used by the navbar flyout and the
 * Notifications page fallback. Business semantics live here once so both
 * surfaces resolve the same deep links and timestamps.
 */

/** Resolve the in-app destination for a notification, if it is actionable. */
export function notificationDeepLink(n) {
  if (!n) return null
  if (n.related_type === 'blood_request' && n.related_id) {
    return `/requests/${n.related_id}/matches`
  }
  if (n.type === 'verification.decision') return '/profile'
  if (n.type === 'account.status_changed') return '/profile'
  if (n.type === 'donation.confirmed' || n.type === 'donation.rejected') return '/profile'
  return null
}

/** Format a `YYYY-MM-DD HH:MM:SS` UTC timestamp for display. */
export function formatNotificationTime(createdAt) {
  if (!createdAt) return ''
  try {
    return new Date(String(createdAt).replace(' ', 'T') + 'Z').toLocaleString()
  } catch {
    return String(createdAt)
  }
}
