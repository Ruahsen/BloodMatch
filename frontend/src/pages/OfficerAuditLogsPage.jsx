import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, X } from '@phosphor-icons/react'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/apiClient'

export default function OfficerAuditLogsPage() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [action, setAction] = useState('')
  const [actorId, setActorId] = useState('')
  const [targetType, setTargetType] = useState('')
  const [targetId, setTargetId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)
  const [selectedContext, setSelectedContext] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    const params = new URLSearchParams()
    params.set('page', page.toString())
    params.set('page_size', pageSize.toString())
    if (action.trim()) params.set('action', action.trim())
    if (actorId.trim()) params.set('actor_id', actorId.trim())
    if (targetType.trim()) params.set('target_type', targetType.trim())
    if (targetId.trim()) params.set('target_id', targetId.trim())
    if (dateFrom.trim()) params.set('date_from', dateFrom.trim())
    if (dateTo.trim()) params.set('date_to', dateTo.trim())

    api.get(`/api/officer/audit-logs?${params.toString()}`)
      .then((res) => {
        setData(res)
        setLoading(false)
      })
      .catch((err) => {
        setError(err.message)
        setLoading(false)
      })
  }, [page, pageSize, action, actorId, targetType, targetId, dateFrom, dateTo])

  useEffect(() => {
    load()
  }, [load])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    load()
  }

  const handleReset = () => {
    setAction('')
    setActorId('')
    setTargetType('')
    setTargetId('')
    setDateFrom('')
    setDateTo('')
    setPage(1)
  }

  return (
    <div className="container wide">
      <header className="app-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-1)' }}>
            <h1 style={{ margin: 0 }}>Chapter Audit Trail</h1>
            <span className="badge badge-open">Chapter #{user?.chapter_id || '–'}</span>
          </div>
        </div>
        <Link to="/officer/dashboard" className="btn btn-secondary btn-sm" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
          <ArrowLeft size={14} weight="regular" aria-hidden="true" /> Officer Dashboard
        </Link>
      </header>

      {error && <div className="alert alert-error" role="alert">{error}</div>}

      {/* Filter Toolbar */}
      <form onSubmit={handleSearch} className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 'var(--space-3)', marginBottom: 'var(--space-4)' }}>
          <div className="field">
            <label htmlFor="filter-action">Action Code</label>
            <input
              id="filter-action"
              type="text"
              placeholder="e.g. request.*, verification.*"
              value={action}
              onChange={(e) => setAction(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="filter-target-type">Target Entity</label>
            <select
              id="filter-target-type"
              value={targetType}
              onChange={(e) => setTargetType(e.target.value)}
            >
              <option value="">All Entity Types</option>
              <option value="user">User</option>
              <option value="blood_request">Blood Request</option>
              <option value="member_document">Member Document</option>
              <option value="donation_report">Donation Report</option>
              <option value="chapter">Chapter</option>
            </select>
          </div>

          <div className="field">
            <label htmlFor="filter-actor-id">Actor User ID</label>
            <input
              id="filter-actor-id"
              type="text"
              placeholder="e.g. 42"
              value={actorId}
              onChange={(e) => setActorId(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="filter-target-id">Target ID</label>
            <input
              id="filter-target-id"
              type="text"
              placeholder="e.g. 101"
              value={targetId}
              onChange={(e) => setTargetId(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="filter-date-from">Date From</label>
            <input
              id="filter-date-from"
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="filter-date-to">Date To</label>
            <input
              id="filter-date-to"
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
            />
          </div>
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
          <div className="button-group">
            <button type="submit" className="btn btn-sm">
              Filter Logs
            </button>
            <button type="button" className="btn btn-secondary btn-sm" onClick={handleReset}>
              Reset Filters
            </button>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <label htmlFor="page-size-select" style={{ fontSize: '0.8125rem' }}>Rows per page:</label>
            <select
              id="page-size-select"
              value={pageSize}
              onChange={(e) => { setPageSize(Number(e.target.value)); setPage(1) }}
              style={{ fontSize: '0.8125rem', padding: 'var(--space-1) var(--space-2)', width: 'auto' }}
            >
              <option value={10}>10</option>
              <option value={25}>25</option>
              <option value={50}>50</option>
            </select>
          </div>
        </div>
      </form>

      {/* Audit Event Table */}
      {loading ? (
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading audit log records…</p>
        </div>
      ) : !data || data.logs?.length === 0 ? (
        <div className="empty-state">
          <h3>No Audit Records Found</h3>
          <p>No audit events match your selected filters within your chapter scope.</p>
        </div>
      ) : (
        <section className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <div className="table-container" style={{ border: 'none', borderRadius: 0 }}>
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Timestamp (UTC)</th>
                  <th>Action</th>
                  <th>Actor</th>
                  <th>Target Type & ID</th>
                  <th>Context Data</th>
                </tr>
              </thead>
              <tbody>
                {data.logs.map((log) => (
                  <tr key={log.id}>
                    <td><code>#{log.id}</code></td>
                    <td><code>{log.created_at}</code></td>
                    <td><strong>{log.action}</strong></td>
                    <td>{log.actor_name || (log.actor_id ? `User #${log.actor_id}` : 'System')}</td>
                    <td>{log.target_type ? `${log.target_type} #${log.target_id}` : '–'}</td>
                    <td>
                      {log.context ? (
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => setSelectedContext(log)}
                        >
                          View Context
                        </button>
                      ) : (
                        <span className="muted">–</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderTop: '1px solid var(--color-border)' }}>
            <span className="muted" style={{ fontSize: '0.8125rem' }}>
              Showing {data.logs.length} of {data.total} event(s) · Page {data.page} of {data.total_pages || 1}
            </span>

            <div className="button-group">
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={data.page <= 1}
                onClick={() => setPage((p) => p - 1)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}
              >
                <ArrowLeft size={14} weight="regular" aria-hidden="true" /> Prev
              </button>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={data.page >= (data.total_pages || 1)}
                onClick={() => setPage((p) => p + 1)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}
              >
                Next <ArrowRight size={14} weight="regular" aria-hidden="true" />
              </button>
            </div>
          </div>
        </section>
      )}

      {/* JSON Context Inspector Modal */}
      {selectedContext && (
        <div className="modal-backdrop" role="dialog" aria-labelledby="context-modal-title" aria-modal="true">
          <div className="modal-content" style={{ maxWidth: '640px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--space-3)' }}>
              <h3 id="context-modal-title" style={{ margin: 0 }}>
                Audit Event #{selectedContext.id} Context
              </h3>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={() => setSelectedContext(null)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}
              >
                <X size={14} weight="regular" aria-hidden="true" /> Close
              </button>
            </div>

            <p className="muted" style={{ fontSize: '0.8125rem', marginBottom: 'var(--space-3)' }}>
              Action: <strong>{selectedContext.action}</strong> · Timestamp: <code>{selectedContext.created_at}</code>
            </p>

            <pre style={{ padding: 'var(--space-4)', background: 'var(--color-surface-sunken)', border: '1px solid var(--color-border)', borderRadius: 'var(--radius-md)', overflowX: 'auto', maxHeight: '350px' }}>
              {JSON.stringify(selectedContext.context, null, 2)}
            </pre>
          </div>
        </div>
      )}
    </div>
  )
}
