import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

export default function OfficerDashboardPage() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const fetchDashboard = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await api.get('/api/officer/dashboard')
      setData(res)
    } catch (err) {
      setError(err.message || 'Failed to load officer dashboard.')
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
          <p className="muted">Loading chapter officer dashboard…</p>
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

  const metrics = data?.metrics || {}
  const chapter = data?.chapter || {}
  const donorPool = data?.donor_pool || {}
  const demandByBt = data?.demand_by_blood_type || {}
  const recentLogs = data?.recent_activity || []

  return (
    <div className="container" style={{ maxWidth: '960px' }}>
      <header className="app-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-1)' }}>
            <h1 style={{ margin: 0 }}>Chapter Officer Dashboard</h1>
            <span className="badge badge-open">
              {chapter.name} ({chapter.municipality})
            </span>
          </div>
          <p className="muted" style={{ margin: 0 }}>
            Operational triage queues, active local blood demand, and volunteer donor pool readiness.
          </p>
        </div>
        <div className="button-group">
          <Link to="/demand-map" className="btn btn-secondary btn-sm">
            Regional Demand Map
          </Link>
          <Link to="/analytics" className="btn btn-secondary btn-sm">
            Analytics & Reports
          </Link>
        </div>
      </header>

      {/* Primary Action Queues */}
      <section className="grid-3" style={{ marginBottom: 'var(--space-6)' }}>
        <div className="card" style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
          <div>
            <span className="metric-label">Pending Verifications</span>
            <div className="metric-value" style={{ margin: 'var(--space-1) 0' }}>
              {metrics.pending_verifications || 0}
            </div>
            <p className="metric-sub">Members awaiting document review</p>
          </div>
          <Link to="/officer/verifications" className="btn btn-sm" style={{ marginTop: 'var(--space-3)' }}>
            Open Verification Queue ➔
          </Link>
        </div>

        <div className="card" style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
          <div>
            <span className="metric-label">Pending Confirmations</span>
            <div className="metric-value" style={{ margin: 'var(--space-1) 0' }}>
              {metrics.pending_donation_confirmations || 0}
            </div>
            <p className="metric-sub">Completed donation reports to confirm</p>
          </div>
          <Link to="/officer/confirmations" className="btn btn-sm" style={{ marginTop: 'var(--space-3)' }}>
            Open Confirmation Queue ➔
          </Link>
        </div>

        <div className="card" style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
          <div>
            <span className="metric-label">Active OPEN Requests</span>
            <div className="metric-value" style={{ margin: 'var(--space-1) 0' }}>
              {metrics.open_requests || 0}
            </div>
            <p className="metric-sub">
              {metrics.total_units_needed || 0} total units currently needed
            </p>
          </div>
          <Link to="/demand-map" className="btn btn-secondary btn-sm" style={{ marginTop: 'var(--space-3)' }}>
            View Chapter Demand Map ➔
          </Link>
        </div>
      </section>

      {/* Grid: Local Demand & Donor Pool */}
      <div className="grid-2" style={{ marginBottom: 'var(--space-6)' }}>
        {/* Local Chapter Demand by Blood Group */}
        <section className="card">
          <div className="card-header">
            <h3>Active Demand by Blood Group</h3>
            <span className="muted" style={{ fontSize: '0.75rem' }}>OPEN Requests</span>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-2)' }}>
            {['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((bt) => {
              const reqCount = demandByBt[bt]?.requests_count || 0
              const unitsNeeded = demandByBt[bt]?.units_needed || 0
              const hasDemand = reqCount > 0

              return (
                <div
                  key={bt}
                  style={{
                    padding: 'var(--space-2)',
                    borderRadius: 'var(--radius-md)',
                    border: hasDemand ? '1px solid var(--color-accent)' : '1px solid var(--color-border)',
                    background: hasDemand ? 'var(--color-surface-sunken)' : 'transparent',
                    textAlign: 'center'
                  }}
                >
                  <div style={{ fontWeight: 800, fontSize: '1rem' }}>{bt}</div>
                    <div style={{ fontSize: '0.75rem', color: hasDemand ? 'var(--color-text)' : 'var(--color-text-subtle)' }}>
                    {reqCount > 0 ? `${reqCount} req (${unitsNeeded}u)` : '–'}
                  </div>
                </div>
              )
            })}
          </div>
        </section>

        {/* Donor Pool Status Breakdown */}
        <section className="card">
          <div className="card-header">
            <h3>Chapter Donor Pool Status</h3>
            <span className="badge badge-verified">
              {donorPool.total_enrolled || 0} Enrolled
            </span>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <div>
                <strong>🟢 Available Donors</strong>
                <div className="muted" style={{ fontSize: '0.75rem' }}>Eligible to receive match notifications</div>
              </div>
              <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{donorPool.available || 0}</span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <div>
                <strong>⏱️ Standby Window</strong>
                <div className="muted" style={{ fontSize: '0.75rem' }}>~42 hours post-donation recovery</div>
              </div>
              <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{donorPool.standby || 0}</span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <div>
                <strong>🛡️ Cooldown Window</strong>
                <div className="muted" style={{ fontSize: '0.75rem' }}>~90 days safe interval protection</div>
              </div>
              <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{donorPool.cooldown || 0}</span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <div>
                <strong>⏸️ Unavailable / Paused</strong>
                <div className="muted" style={{ fontSize: '0.75rem' }}>Voluntarily paused participation</div>
              </div>
              <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{donorPool.unavailable || 0}</span>
            </div>
          </div>
        </section>
      </div>

      {/* Recent Chapter Activity Log */}
      <section className="card">
        <div className="card-header">
          <h3>Recent Chapter Activity</h3>
          <Link to="/officer/audit-logs" style={{ fontSize: '0.8125rem' }}>View Full Audit Trail ➔</Link>
        </div>

        {recentLogs.length === 0 ? (
          <p className="muted" style={{ fontSize: '0.875rem' }}>No recent chapter activity recorded.</p>
        ) : (
          <div className="table-container">
            <table>
              <thead>
                <tr>
                  <th>Timestamp (UTC)</th>
                  <th>Action Event</th>
                  <th>Actor</th>
                  <th>Target</th>
                </tr>
              </thead>
              <tbody>
                {recentLogs.map((log) => (
                  <tr key={log.id}>
                    <td><code>{log.created_at}</code></td>
                    <td><strong>{log.action}</strong></td>
                    <td>{log.actor_name || (log.actor_id ? `User #${log.actor_id}` : 'System')}</td>
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
