import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, CheckCircle, Clock, PauseCircle, ShieldCheck } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function AdminDashboardPage() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const fetchDashboard = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await api.get('/api/admin/dashboard')
      setData(res)
    } catch (err) {
      setError(err.message || 'Failed to load system admin dashboard.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchDashboard()
  }, [])

  if (loading) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading system admin dashboard…</p>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="container">
        <div className="alert alert-error" role="alert">{error}</div>
      </div>
    )
  }

  const overview = data?.overview || {}
  const donorPool = data?.donor_pool || {}
  const chaptersSummary = data?.chapters_summary || []
  const recentLogs = data?.recent_system_activity || []

  return (
    <div className="container" style={{ maxWidth: '1040px' }}>
      <header className="app-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-1)' }}>
            <h1 style={{ margin: 0 }}>System Administration Dashboard</h1>
            <span className="badge badge-open">Global Overview</span>
          </div>
        </div>
        <div className="button-group">
          <Link to="/demand-map" className="btn btn-secondary btn-sm">
            Regional Demand Map
          </Link>
          <Link to="/analytics" className="btn btn-secondary btn-sm">
            Detailed Analytics
          </Link>
          <Link to="/admin/audit-logs" className="btn btn-secondary btn-sm">
            System Audit Logs
          </Link>
        </div>
      </header>

      {/* Primary KPI Grid */}
      <section className="grid-4" style={{ marginBottom: 'var(--space-6)' }}>
        <div className="metric-card">
          <span className="metric-label">Total Users</span>
          <span className="metric-value">{overview.total_users || 0}</span>
          <span className="metric-sub">{overview.total_members || 0} members · {overview.total_officers || 0} officers</span>
        </div>

        <div className="metric-card">
          <span className="metric-label">Enrolled Donors</span>
          <span className="metric-value">{overview.enrolled_donors || 0}</span>
          <span className="metric-sub">{overview.available_donors || 0} currently available</span>
        </div>

        <div className="metric-card">
          <span className="metric-label">Active OPEN Requests</span>
          <span className="metric-value">{overview.open_requests || 0}</span>
          <span className="metric-sub">{overview.total_units_needed || 0} units needed</span>
        </div>

        <div className="metric-card">
          <span className="metric-label">Fulfillment Rate</span>
          <span className="metric-value">{overview.fulfillment_rate !== undefined ? `${overview.fulfillment_rate}%` : '0%'}</span>
          <span className="metric-sub">Resolved requests</span>
        </div>
      </section>

      {/* Lifecycle Rates & Donor Pool Grid */}
      <div className="grid-2" style={{ marginBottom: 'var(--space-6)' }}>
        {/* Request Lifecycle Resolution Rates */}
        <section className="card">
          <div className="card-header">
            <h3>Request Lifecycle Rates</h3>
            <span className="muted" style={{ fontSize: '0.75rem' }}>Resolved Denominator</span>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-1)', fontSize: '0.875rem' }}>
                <span><strong>Fulfillment Rate</strong></span>
                <span style={{ fontWeight: 700 }}>{overview.fulfillment_rate || 0}% ({overview.fulfilled_requests || 0} fulfilled)</span>
              </div>
              <div style={{ height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(100, overview.fulfillment_rate || 0)}%`, height: '100%', background: 'var(--color-accent)' }} />
              </div>
            </div>

            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-1)', fontSize: '0.875rem' }}>
                <span><strong>Cancellation Rate</strong></span>
                <span style={{ fontWeight: 700 }}>{overview.cancellation_rate || 0}% ({overview.cancelled_requests || 0} cancelled)</span>
              </div>
              <div style={{ height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(100, overview.cancellation_rate || 0)}%`, height: '100%', background: 'var(--color-text-muted)' }} />
              </div>
            </div>

            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-1)', fontSize: '0.875rem' }}>
                <span><strong>Expiration Rate</strong></span>
                <span style={{ fontWeight: 700 }}>{overview.expiration_rate || 0}% ({overview.expired_requests || 0} expired)</span>
              </div>
              <div style={{ height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(100, overview.expiration_rate || 0)}%`, height: '100%', background: 'var(--color-danger)' }} />
              </div>
            </div>

            <p className="muted" style={{ fontSize: '0.75rem', margin: 'var(--space-2) 0 0' }}>
              Formula: Rates are computed against resolved requests only (<code>FULFILLED + CANCELLED + EXPIRED</code>). Active <code>OPEN</code> requests are excluded from the denominator.
            </p>
          </div>
        </section>

        {/* Global Donor Pool Status */}
        <section className="card">
          <div className="card-header">
            <h3>System Donor Pool Breakdown</h3>
            <span className="badge badge-verified">{donorPool.total_enrolled || 0} Total</span>
          </div>

          <div className="grid-2">
            <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="metric-label" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><CheckCircle size={14} weight="regular" aria-hidden="true" /> Available</span>
              <div style={{ fontSize: '1.5rem', fontWeight: 800 }}>{donorPool.available || 0}</div>
              <small className="muted">Ready for matching</small>
            </div>

            <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="metric-label" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><Clock size={14} weight="regular" aria-hidden="true" /> Standby</span>
              <div style={{ fontSize: '1.5rem', fontWeight: 800 }}>{donorPool.standby || 0}</div>
              <small className="muted">~42h recovery</small>
            </div>

            <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="metric-label" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><ShieldCheck size={14} weight="regular" aria-hidden="true" /> Cooldown</span>
              <div style={{ fontSize: '1.5rem', fontWeight: 800 }}>{donorPool.cooldown || 0}</div>
              <small className="muted">~90d safe interval</small>
            </div>

            <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="metric-label" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><PauseCircle size={14} weight="regular" aria-hidden="true" /> Unavailable</span>
              <div style={{ fontSize: '1.5rem', fontWeight: 800 }}>{donorPool.unavailable || 0}</div>
              <small className="muted">Paused by donor</small>
            </div>
          </div>
        </section>
      </div>

      {/* Cross-Chapter Comparison Table */}
      <section className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div className="card-header">
          <h3>Bataan 3-Chapter Comparative Matrix</h3>
          <span className="muted" style={{ fontSize: '0.75rem' }}>Fixed Reference Chapters</span>
        </div>

        <div className="table-container">
          <table>
            <thead>
              <tr>
                <th>Chapter & Municipality</th>
                <th>Members</th>
                <th>Officers</th>
                <th>Donors (Avail / Total)</th>
                <th>Active Requests</th>
                <th>Pending Triage</th>
                <th>Fulfillment Rate</th>
              </tr>
            </thead>
            <tbody>
              {chaptersSummary.map((ch) => (
                <tr key={ch.chapter_id}>
                  <td><strong>{ch.chapter_name}</strong> <span className="muted">({ch.municipality})</span></td>
                  <td>{ch.members_count}</td>
                  <td>{ch.officers_count}</td>
                  <td><strong>{ch.available_donors}</strong> / {ch.enrolled_donors}</td>
                  <td><span className="badge badge-open">{ch.open_requests} ({ch.units_needed}u)</span></td>
                  <td>{ch.pending_verifications} verif · {ch.pending_confirmations} conf</td>
                  <td><strong>{ch.fulfillment_rate}%</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {/* Recent System Activity Stream */}
      <section className="card">
        <div className="card-header">
          <h3>Recent System Activity</h3>
          <Link to="/admin/audit-logs" style={{ fontSize: '0.8125rem', display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>View Global Audit Trail <ArrowRight size={14} weight="regular" aria-hidden="true" /></Link>
        </div>

        {recentLogs.length === 0 ? (
          <p className="muted" style={{ fontSize: '0.875rem' }}>No recent system activity recorded.</p>
        ) : (
          <div className="table-container">
            <table>
              <thead>
                <tr>
                  <th>Timestamp (UTC)</th>
                  <th>Action Event</th>
                  <th>Actor</th>
                  <th>Target Type & ID</th>
                </tr>
              </thead>
              <tbody>
                {recentLogs.map((log) => (
                  <tr key={log.id}>
                    <td><code>{log.created_at}</code></td>
                    <td><strong>{log.action}</strong></td>
                    <td>{log.actor_name || (log.actor_id ? `User #${log.actor_id}` : 'System / Unauth')}</td>
                    <td>{log.target_type ? `${log.target_type} #${log.target_id}` : '–'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}
