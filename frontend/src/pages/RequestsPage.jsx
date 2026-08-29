import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function RequestsPage() {
  const [requests, setRequests] = useState(null)
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [filter, setFilter] = useState('ALL')

  const load = useCallback(() => {
    return api
      .get('/api/my/requests')
      .then((data) => setRequests(data.requests || []))
      .catch((err) => setErrorAlert(err.message))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const onCancel = async (id) => {
    if (!window.confirm(`Are you sure you want to cancel request #${id}?`)) {
      return
    }
    setMessage(null)
    setErrorAlert(null)
    try {
      await api.post(`/api/requests/${id}/cancel`)
      setMessage(`Blood request #${id} has been cancelled.`)
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  if (!requests) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading blood requests…</p>
        </div>
      </div>
    )
  }

  const filteredRequests = filter === 'ALL'
    ? requests
    : requests.filter((r) => r.status === filter)

  return (
    <div className="container">
      <header className="app-header">
        <div>
          <h1>My Blood Requests</h1>
        </div>
        <Link to="/requests/new" className="btn">
          + Create New Request
        </Link>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {/* Filter Tabs */}
      <div style={{ display: 'flex', gap: 'var(--space-2)', marginBottom: 'var(--space-6)', flexWrap: 'wrap' }}>
        {['ALL', 'OPEN', 'FULFILLED', 'CANCELLED', 'EXPIRED'].map((st) => (
          <button
            key={st}
            type="button"
            className={filter === st ? 'btn btn-sm' : 'btn btn-secondary btn-sm'}
            onClick={() => setFilter(st)}
          >
            {st} ({st === 'ALL' ? requests.length : requests.filter((r) => r.status === st).length})
          </button>
        ))}
      </div>

      {filteredRequests.length === 0 ? (
        <div className="empty-state">
          <h3>No requests found</h3>
          <p>
            {filter === 'ALL'
              ? 'You have not submitted any blood requests yet. Click below to create your first request.'
              : `No blood requests currently in "${filter}" status.`}
          </p>
          {filter === 'ALL' && (
            <Link to="/requests/new" className="btn">
              Create Blood Request
            </Link>
          )}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          {filteredRequests.map((r) => (
            <article key={r.id} className="card">
              <div className="card-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                  <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{r.required_blood_type}</span>
                  <span className="muted">·</span>
                  <span style={{ fontWeight: 600 }}>{r.quantity_units} Unit(s)</span>
                  <span className="muted">·</span>
                  <span className={`badge ${r.urgency === 'critical' ? 'badge-critical' : (r.urgency === 'urgent' ? 'badge-urgent' : 'badge-routine')}`}>
                    {r.urgency.toUpperCase()}
                  </span>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                  <span className={`badge ${r.status === 'OPEN' ? 'badge-open' : (r.status === 'FULFILLED' ? 'badge-verified' : '')}`}>
                    {r.status}
                  </span>
                  {r.review_status === 'pending_review' && (
                    <span className="badge" title="Created by an account currently pending verification">
                      Pending Review
                    </span>
                  )}
                </div>
              </div>

              <div className="card-body">
                <div className="grid-3">
                  <div>
                    <span className="metric-label">Hospital / Facility</span>
                    <p style={{ margin: 0, fontWeight: 600 }}>{r.facility_name}</p>
                  </div>
                  <div>
                    <span className="metric-label">Needed By</span>
                    <p style={{ margin: 0, fontWeight: 600 }}>{new Date(r.needed_datetime.replace(' ', 'T') + 'Z').toLocaleString()}</p>
                  </div>
                  <div>
                    <span className="metric-label">Request ID</span>
                    <p style={{ margin: 0 }}><code>#{r.id}</code></p>
                  </div>
                </div>

                <div className="button-group" style={{ marginTop: 'var(--space-2)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
                  <Link to={`/requests/${r.id}/matches`} className="btn btn-sm" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                    View Potential Matches <ArrowRight size={14} weight="regular" aria-hidden="true" />
                  </Link>

                  {r.status === 'OPEN' && (
                    <>
                      <Link to={`/requests/${r.id}/edit`} className="btn btn-secondary btn-sm">
                        Edit Details
                      </Link>
                      <button type="button" className="btn btn-danger btn-sm" onClick={() => onCancel(r.id)}>
                        Cancel Request
                      </button>
                    </>
                  )}
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}
