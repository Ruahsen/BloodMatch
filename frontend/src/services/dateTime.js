/**
 * Shared timezone and datetime formatting helpers for Philippine Standard Time (Asia/Manila, UTC+8).
 * Stored database timestamps remain in UTC; display transforms are localized here.
 */

export const PHILIPPINE_TIMEZONE = 'Asia/Manila'

/**
 * Format a UTC timestamp (e.g. '2026-10-13 05:20:00' or ISO) into Philippine Standard Time.
 * Consistently uses a 12-hour clock with AM/PM and excludes seconds.
 * Example: "10/13/2026, 8:00 AM"
 */
export function formatPhilippineDateTime(value, options = {}) {
  if (!value) return '–'
  try {
    const raw = String(value).trim()
    const iso = raw.includes('T')
      ? (raw.endsWith('Z') ? raw : raw + 'Z')
      : raw.replace(' ', 'T') + 'Z'
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return raw

    return date.toLocaleString('en-US', {
      timeZone: PHILIPPINE_TIMEZONE,
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      ...options,
    })
  } catch {
    return String(value)
  }
}

/**
 * Format a date in Philippine calendar (e.g. 'YYYY-MM-DD' or timestamp).
 */
export function formatPhilippineDate(value, options = {}) {
  if (!value) return '–'
  try {
    const raw = String(value).trim()
    const iso = raw.includes('T')
      ? (raw.endsWith('Z') ? raw : raw + 'Z')
      : (raw.includes(' ') ? raw.replace(' ', 'T') + 'Z' : `${raw}T00:00:00Z`)
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return raw

    return date.toLocaleDateString('en-US', {
      timeZone: PHILIPPINE_TIMEZONE,
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      ...options,
    })
  } catch {
    return String(value)
  }
}

/**
 * Get current date string (YYYY-MM-DD) in Philippine Time.
 */
export function getPhilippineDateString(daysOffset = 0) {
  const d = new Date()
  if (daysOffset !== 0) {
    d.setDate(d.getDate() + daysOffset)
  }
  return new Intl.DateTimeFormat('en-CA', { timeZone: PHILIPPINE_TIMEZONE }).format(d)
}
