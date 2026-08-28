import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/apiClient'

function deepLink(n) {
  if (n.related_type === 'blood_request' && n.related_id) {
    return `/requests/${n.related_id}/matches`
  }
  if (n.type === 'verification.decision') return '/profile'
  if (n.type === 'account.status_changed') return '/profile'
  if (n.type === 'donation.confirmed' || n.type === 'donation.rejected') return '/profile'
  return null
}

export default function NotificationsPage() {
  const [data, setData] = useState(null)
  const [page, setPage] = useState(1)
  const [errorAlert, setErrorAlert] = useState(null)
  const [filterType, setFilterType] = useState('')
  const [filterRead, setFilterRead] = useState('')
  const pageSize = 15

  const load = useCallback(() => {
    const params = new URLSearchParams({ page, page_size: pageSize })
    if (filterType) params.set('type', filterType)
    if (filterRead) params.set('read', filterRead)

    return api
      .get(`/api/notifications?${params.toString()}`)
      .then(setData)
      .catch((err) => setErrorAlert(err.message))
  }, [page, filterType, filterRead])

  useEffect(() => {
    load()
  }, [load])

  const markRead = async (id) => {
    try {
      await api.post(`/api/notifications/${id}/read`)
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  const markAll = async () => {
    try {
      await api.post('/api/notifications/read-all')
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  if (errorAlert && !data) {
    return (
      <div className="container">
        <div className="alert alert-error" role="alert">{errorAlert}</div>
      </div>
    )
  }

  if (!data) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading notifications…</p>
        </div>
      </div>
    )
  }

  const totalPages = Math.max(1, Math.ceil(data.total / pageSize))

  return (
    <div className="container">
      <header className="app-header">
        <div>
          <h1>Notifications</h1>
          <p className="muted" style={{ margin: 0 }}>
            Stay updated on match notifications, verification decisions, and donation confirmations.
          </p>
        </div>
        <button type="button" className="btn btn-secondary" onClick={markAll}>
          ✓ Mark All as Read
        </button>
      </header>

      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {/* Filter Bar */}
      <div className="card" style={{ marginBottom: 'var(--space-4)', padding: 'var(--space-3) var(--space-4)' }}>
        <div style={{ display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', alignItems: 'center' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <label htmlFor="read-filter" style={{ fontSize: '0.8125rem', fontWeight: 600 }}>Status:</label>
            <select
              id="read-filter"
              value={filterRead}
              onChange={(e) => { setFilterRead(e.target.value); setPage(1) }}
              style={{ fontSize: '0.8125rem', padding: 'var(--space-1) var(--space-2)' }}
            >
              <option value="">All Notifications</option>
              <option value="unread">Unread Only</option>
              <option value="read">Read Only</option>
            </select>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <label htmlFor="type-filter" style={{ fontSize: '0.8125rem', fontWeight: 600 }}>Category:</label>
            <select
              id="type-filter"
              value={filterType}
              onChange={(e) => { setFilterType(e.target.value); setPage(1) }}
              style={{ fontSize: '0.8125rem', padding: 'var(--space-1) var(--space-2)' }}
            >
              <option value="">All Categories</option>
              <option value="match.new">Match Alerts</option>
              <option value="verification.decision">Verification Updates</option>
              <option value="donation.confirmed">Donation Confirmations</option>
              <option value="account.status_changed">Account Alerts</option>
            </select>
          </div>

          <span className="muted" style={{ fontSize: '0.8125rem', marginLeft: 'auto' }}>
            Total: {data.total}
          </span>
        </div>
      </div>

      {data.notifications.length === 0 ? (
        <div className="empty-state">
          <h3>No Notifications</h3>
          <p>You have no notifications matching the selected filter criteria.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
          {data.notifications.map((n) => {
            const link = deepLink(n)
            return (
              <article
                key={n.id}
                className="card"
                style={{
                  borderLeft: n.read_at ? '1px solid var(--color-border)' : '4px solid var(--color-accent)',
                  backgroundColor: n.read_at ? 'var(--color-surface)' : 'var(--color-surface-sunken)'
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 'var(--space-3)', marginBottom: 'var(--space-2)' }}>
                  <div>
                    <h3 style={{ margin: '0 0 var(--space-1)', fontSize: '1rem' }}>{n.title}</h3>
                    <p style={{ margin: 0, fontSize: '0.875rem' }}>{n.body}</p>
                  </div>
                  {!n.read_at && (
                    <span className="badge badge-open">Unread</span>
                  )}
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)', fontSize: '0.75rem', marginTop: 'var(--space-2)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-2)' }}>
                  <span className="muted">
                    {new Date(n.created_at.replace(' ', 'T') + 'Z').toLocaleString()}
                  </span>

                  <div className="button-group">
                    {link && (
                      <Link to={link} className="btn btn-secondary btn-sm">
                        View Details ➔
                      </Link>
                    )}
                    {!n.read_at && (
                      <button type="button" className="btn btn-secondary btn-sm" onClick={() => markRead(n.id)}>
                        Mark Read
                      </button>
                    )}
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      )}

      {totalPages > 1 && (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 'var(--space-6)' }}>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            ← Previous Page
          </button>
          <span className="muted" style={{ fontSize: '0.875rem' }}>
            Page {page} of {totalPages}
          </span>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            disabled={page >= totalPages}
            onClick={() => setPage((p) => p + 1)}
          >
            Next Page →
          </button>
        </div>
      )}
    </div>
  )
}
