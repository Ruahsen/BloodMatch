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
  if (n.type === 'verification.requested' && n.related_id) {
    return `/admin/verifications?user=${n.related_id}`
  }
  if (n.type === 'verification.decision') return '/profile'
  if (n.type === 'verification.resubmitted') return '/profile'
  if (n.type === 'account.status_changed') return '/profile'
  if (n.type === 'donation.confirmed' || n.type === 'donation.rejected') return '/profile'
  return null
}

/** Format a UTC timestamp or time string for notification display: 12-hour clock with AM/PM, no seconds. */
export function formatNotificationTime(createdAt) {
  if (!createdAt) return ''
  try {
    const raw = String(createdAt).trim()
    // Handle time-only strings like "8:00:00 AM" or "08:00:00"
    if (/^\d{1,2}:\d{2}:\d{2}(\s*[AaPp][Mm])?$/i.test(raw)) {
      if (/[AaPp][Mm]/i.test(raw)) {
        return raw.replace(/:\d{2}(\s*[AaPp][Mm])$/i, '$1')
      }
      const [h, m] = raw.split(':')
      const hour = parseInt(h, 10)
      const period = hour >= 12 ? 'PM' : 'AM'
      const h12 = hour % 12 || 12
      return `${h12}:${m} ${period}`
    }

    const iso = raw.includes('T') ? (raw.endsWith('Z') ? raw : raw + 'Z') : raw.replace(' ', 'T') + 'Z'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return raw

    return d.toLocaleTimeString('en-US', {
      timeZone: 'Asia/Manila',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    })
  } catch {
    return String(createdAt)
  }
}
