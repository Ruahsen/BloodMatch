import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, CheckCircle, Clock, PauseCircle, ShieldCheck } from '@phosphor-icons/react'
import { api } from '../../../services/apiClient'
import { LoadingSpinner } from '../../../components/ui/LoadingSpinner'
import { formatPhilippineDateTime } from '../../../services/dateTime'

export default function DashboardView() {
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
      <div className="container" style={{ padding: 'var(--space-8) 0' }}>
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <LoadingSpinner text="Loading dashboard…" />
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="container" style={{ padding: 'var(--space-8) 0' }}>
        <div className="alert alert-error" role="alert">{error}</div>
      </div>
    )
  }

  const overview = data?.overview || {}
  const donorPool = data?.donor_pool || {}
  const recentLogs = data?.recent_system_activity || []

  return (
    <div className="container" style={{ padding: 'var(--space-6) 0' }}>
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
            <span className="muted" style={{ fontSize: 'var(--text-xs)' }}>Resolved Denominator</span>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-2)', fontSize: 'var(--text-sm)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                  <CheckCircle size={16} weight="regular" aria-hidden="true" style={{ color: 'var(--color-accent)' }} />
                  <strong>Fulfilled:</strong>
                </span>
                <span>{overview.fulfillment_rate !== undefined ? `${overview.fulfillment_rate}%` : '0%'} ({overview.fulfilled_requests || 0})</span>
              </div>
              <div style={{ width: '100%', height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(overview.fulfillment_rate || 0, 100)}%`, height: '100%', background: 'var(--color-accent)' }} />
              </div>
            </div>

            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-2)', fontSize: 'var(--text-sm)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                  <Clock size={16} weight="regular" aria-hidden="true" style={{ color: 'var(--color-text-muted)' }} />
                  <span>Expired:</span>
                </span>
                <span>{overview.expiry_rate !== undefined ? `${overview.expiry_rate}%` : '0%'} ({overview.expired_requests || 0})</span>
              </div>
              <div style={{ width: '100%', height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(overview.expiry_rate || 0, 100)}%`, height: '100%', background: 'var(--color-text-muted)' }} />
              </div>
            </div>

            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-2)', fontSize: 'var(--text-sm)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                  <PauseCircle size={16} weight="regular" aria-hidden="true" style={{ color: 'var(--color-text-subtle)' }} />
                  <span>Cancelled:</span>
                </span>
                <span>{overview.cancellation_rate !== undefined ? `${overview.cancellation_rate}%` : '0%'} ({overview.cancelled_requests || 0})</span>
              </div>
              <div style={{ width: '100%', height: '8px', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-pill)', overflow: 'hidden' }}>
                <div style={{ width: `${Math.min(overview.cancellation_rate || 0, 100)}%`, height: '100%', background: 'var(--color-border)' }} />
              </div>
            </div>
          </div>
        </section>

        {/* Global Donor Pool Availability Status */}
        <section className="card">
          <div className="card-header">
            <h3>Global Donor Pool Status</h3>
            <span className="muted" style={{ fontSize: 'var(--text-xs)' }}>{donorPool.total_enrolled || 0} Total Enrolled</span>
          </div>

          <div className="grid-3 text-center" style={{ gap: 'var(--space-3)' }}>
            <div style={{ padding: 'var(--space-4)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="badge badge-open" style={{ marginBottom: 'var(--space-2)' }}>Available</span>
              <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>{donorPool.available || 0}</div>
            </div>

            <div style={{ padding: 'var(--space-4)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="badge badge-standby" style={{ marginBottom: 'var(--space-2)' }}>Standby</span>
              <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>{donorPool.standby || 0}</div>
            </div>

            <div style={{ padding: 'var(--space-4)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
              <span className="badge badge-cooldown" style={{ marginBottom: 'var(--space-2)' }}>Cooldown</span>
              <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>{donorPool.cooldown || 0}</div>
            </div>
          </div>

          <div style={{ marginTop: 'var(--space-5)', padding: 'var(--space-3)', border: '1px solid var(--color-border-subtle)', borderRadius: 'var(--radius-md)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
              <ShieldCheck size={20} weight="regular" aria-hidden="true" style={{ color: 'var(--color-accent)' }} />
              <div>
                <strong style={{ fontSize: 'var(--text-sm)' }}>Verified Donors:</strong>
                <span className="muted" style={{ fontSize: 'var(--text-xs)', marginLeft: 'var(--space-2)' }}>{donorPool.verified_donors || 0}</span>
              </div>
            </div>
            <span className="badge" style={{ fontSize: 'var(--text-xs)' }}>
              {donorPool.total_enrolled ? `${Math.round(((donorPool.verified_donors || 0) / donorPool.total_enrolled) * 100)}%` : '0%'} Verified
            </span>
          </div>
        </section>
      </div>

      {/* Recent System Activity Log Snapshot */}
      <section className="card">
        <div className="card-header">
          <h3>Recent System Activity</h3>
          <Link to="/admin/audit-logs" style={{ fontSize: 'var(--text-xs)', display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)' }}>
            View Global Audit Trail <ArrowRight size={14} weight="regular" aria-hidden="true" />
          </Link>
        </div>

        {recentLogs.length === 0 ? (
          <p className="muted" style={{ fontSize: 'var(--text-sm)', padding: 'var(--space-4)' }}>No recent system activity recorded.</p>
        ) : (
          <div className="table-container">
            <table>
              <thead>
                <tr>
                  <th>Timestamp</th>
                  <th>Action Event</th>
                  <th>Actor</th>
                  <th>Target Type & ID</th>
                </tr>
              </thead>
              <tbody>
                {recentLogs.map((log) => (
                  <tr key={log.id}>
                    <td style={{ fontSize: 'var(--text-xs)' }}><code>{formatPhilippineDateTime(log.created_at)}</code></td>
                    <td style={{ fontSize: 'var(--text-sm)' }}><strong>{log.action}</strong></td>
                    <td style={{ fontSize: 'var(--text-sm)' }}>{log.actor_name || (log.actor_id ? `User #${log.actor_id}` : 'System / Unauth')}</td>
                    <td style={{ fontSize: 'var(--text-sm)' }}>{log.target_type ? `${log.target_type} #${log.target_id}` : '–'}</td>
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
