import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, useNavigate } from 'react-router-dom'
import { ArrowRight, Bell, Check, X } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { formatNotificationTime, notificationDeepLink } from '../services/notifications'

const PREVIEW_SIZE = 10

function formatBadge(count) {
  if (!count || count <= 0) return null
  return count > 99 ? '99+' : String(count)
}

/**
 * In-navbar notification center. Bell trigger + floating panel; no route
 * navigation. Reuses the existing notification API, read semantics, and
 * deep-link behavior. `variant` is `desktop` (anchored dropdown) or
 * `mobile` (bottom sheet inside the mobile navigation panel).
 */
export default function NotificationFlyout({ unread = 0, setUnread = () => {}, variant = 'desktop', onNavigate }) {
  const [open, setOpen] = useState(false)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const rootRef = useRef(null)
  const sheetOutsideRef = useRef(null)
  const closeRef = useRef(null)
  const navigate = useNavigate()

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const d = await api.get(`/api/notifications?page=1&page_size=${PREVIEW_SIZE}`)
      setData(d)
    } catch (err) {
      setError(err.message || 'Could not load notifications.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    if (open) {
      load()
      closeRef.current?.focus()
    } else {
      setData(null)
      setError(null)
    }
  }, [open, load])

  useEffect(() => {
    if (!open) return undefined
    const onPointerDown = (e) => {
      const inTrigger = rootRef.current && rootRef.current.contains(e.target)
      const inSheet = sheetOutsideRef.current && sheetOutsideRef.current.contains(e.target)
      if (!inTrigger && !inSheet) setOpen(false)
    }
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open ])

  const refreshUnread = useCallback(async () => {
    try {
      const d = await api.get('/api/notifications/unread-count')
      if (typeof d?.unread_count === 'number') setUnread(d.unread_count)
    } catch {
      // Unread badge keeps its last known value; list errors surface inline.
    }
  }, [setUnread])

  const openItem = async (n) => {
    const link = notificationDeepLink(n)
    if (!n.read_at) {
      try {
        const r = await api.post(`/api/notifications/${n.id}/read`)
        if (typeof r?.unread_count === 'number') {
          setUnread(r.unread_count)
        } else {
          await refreshUnread()
        }
        setData((d) =>
          d
            ? {
                ...d,
                notifications: d.notifications.map((x) =>
                  x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x
                ),
              }
            : d
        )
      } catch (err) {
        setError(err.message || 'Could not mark notification as read.')
        return
      }
    }
    if (link) {
      setOpen(false)
      onNavigate?.()
      navigate(link)
    }
  }

  const markAll = async () => {
    try {
      await api.post('/api/notifications/read-all')
      await load()
      await refreshUnread()
    } catch (err) {
      setError(err.message || 'Could not mark notifications as read.')
    }
  }

  const badge = formatBadge(unread)
  const label = `Notifications${unread > 0 ? `, ${unread} unread` : ''}`

  const panel = open && (
    <div
      className={variant === 'mobile' ? 'notif-sheet' : 'notif-flyout'}
      role="dialog"
      aria-label="Notifications"
    >
      <div className="notif-flyout-header">
        <div>
          <h2 className="notif-flyout-title">Notifications</h2>
          {data && (
            <p className="muted" style={{ margin: 0, fontSize: '0.75rem' }}>
              {unread > 0 ? `${unread} unread` : 'You are all caught up'}
              {typeof data.total === 'number' && data.total > data.notifications.length
                ? ` · showing latest ${data.notifications.length} of ${data.total}`
                : ''}
            </p>
          )}
        </div>
        <div className="notif-flyout-actions">
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={markAll}
            disabled={loading || unread === 0}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}
          >
            <Check size={14} weight="regular" aria-hidden="true" /> Mark all read
          </button>
          <button
            ref={closeRef}
            type="button"
            className="notif-close"
            onClick={() => setOpen(false)}
            aria-label="Close notifications"
          >
            <X size={16} weight="regular" aria-hidden="true" />
          </button>
        </div>
      </div>

      <div className="notif-list">
        {loading && !data && <p className="muted" style={{ margin: 0 }}>Loading notifications…</p>}
        {error && !data && (
          <div>
            <div className="alert alert-error" role="alert">{error}</div>
            <button type="button" className="btn btn-secondary btn-sm" onClick={load} style={{ marginTop: 'var(--space-2)' }}>
              Retry
            </button>
          </div>
        )}
        {data && data.notifications.length === 0 && (
          <div className="notif-empty">
            <p style={{ margin: 0, fontWeight: 600 }}>No notifications</p>
            <p className="muted" style={{ margin: 0, fontSize: '0.8125rem' }}>Match alerts and account updates will appear here.</p>
          </div>
        )}
        {data && data.notifications.length > 0 && (
          <ul className="notif-items">
            {data.notifications.map((n) => {
              const link = notificationDeepLink(n)
              const isUnread = !n.read_at
              return (
                <li key={n.id}>
                  <button
                    type="button"
                    className={`notif-item${isUnread ? ' unread' : ''}`}
                    onClick={() => openItem(n)}
                    aria-label={`${n.title}. ${isUnread ? 'Unread' : 'Read'}.${link ? ' Opens details.' : ''}`}
                  >
                    <span className="notif-item-dot" aria-hidden="true" />
                    <span className="notif-item-body">
                      <span className="notif-item-title">
                        {n.title}
                        {isUnread && <span className="badge badge-open notif-item-badge">Unread</span>}
                      </span>
                      <span className="notif-item-text">{n.body}</span>
                      <span className="notif-item-meta">
                        <span className="muted">{formatNotificationTime(n.created_at)}</span>
                        {link && (
                          <span className="notif-item-go">
                            View details <ArrowRight size={12} weight="regular" aria-hidden="true" />
                          </span>
                        )}
                      </span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
        {error && data && <div className="alert alert-error" role="alert" style={{ marginTop: 'var(--space-2)' }}>{error}</div>}
      </div>

      <div className="notif-flyout-footer">
        <Link
          to="/notifications"
          className="notif-view-all"
          onClick={() => {
            setOpen(false)
            onNavigate?.()
          }}
        >
          View all notifications <ArrowRight size={14} weight="regular" aria-hidden="true" />
        </Link>
      </div>
    </div>
  )

  if (variant === 'mobile') {
    return (
      <div ref={rootRef}>
        <button
          type="button"
          className="mobile-link"
          onClick={() => setOpen((o) => !o)}
          aria-haspopup="dialog"
          aria-expanded={open}
          aria-label={label}
        >
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <Bell size={16} weight="regular" aria-hidden="true" /> Notifications
          </span>
          {badge && <span className="nav-badge">{badge}</span>}
        </button>
        {/* Portaled: the sticky topbar's backdrop-filter would otherwise
            trap fixed positioning and hide the sheet. */}
        {panel &&
          createPortal(
            <div ref={sheetOutsideRef}>{panel}</div>,
            document.body
          )}
      </div>
    )
  }

  return (
    <div ref={rootRef} className="notif-wrap">
      <button
        type="button"
        className="notif-bell"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={label}
        title={label}
      >
        <Bell size={17} weight="regular" aria-hidden="true" />
        {badge && <span className="nav-badge notif-badge">{badge}</span>}
      </button>
      {panel}
    </div>
  )
}
