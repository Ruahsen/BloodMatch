import { useEffect, useState } from 'react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

export default function AnalyticsPage() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [dateFrom, setDateFrom] = useState(() => {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().slice(0, 10)
  })
  const [dateTo, setDateTo] = useState(() => new Date().toISOString().slice(0, 10))
  const [chapterId, setChapterId] = useState('')

  const fetchAnalytics = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams()
      if (dateFrom) params.set('date_from', dateFrom)
      if (dateTo) params.set('date_to', dateTo)
      if (user?.role === 'admin' && chapterId) params.set('chapter_id', chapterId)

      const res = await api.get(`/api/analytics/summary?${params.toString()}`)
      setData(res)
    } catch (err) {
      setError(err.message || 'Failed to load analytics summary.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchAnalytics()
  }, [dateFrom, dateTo, chapterId])

  const setRangePreset = (days) => {
    const end = new Date()
    const start = new Date()
    start.setDate(end.getDate() - days)
    setDateFrom(start.toISOString().slice(0, 10))
    setDateTo(end.toISOString().slice(0, 10))
  }

  const reqVolume = data?.request_volume || {}
  const demandByBt = data?.demand_by_blood_type || {}
  const demandByUrg = data?.demand_by_urgency || {}
  const donorPool = data?.donor_pool || {}
  const verifActivity = data?.verification_activity || {}
  const donationEng = data?.donation_engagement || {}
  const dailyTrend = data?.daily_request_trend || []

  return (
    <div className="container" style={{ maxWidth: '1040px' }}>
      <header className="app-header">
        <div>
          <h1>Analytics & Operational Reporting</h1>
          <p className="muted" style={{ margin: 0 }}>
            Comprehensive lifecycle metrics, blood type demand distributions, and operational volume.
          </p>
        </div>
      </header>

      {/* Date Range & Chapter Filter Bar */}
      <section className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div style={{ display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div className="field">
            <label htmlFor="date-from">Date From</label>
            <input
              id="date-from"
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="date-to">Date To</label>
            <input
              id="date-to"
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
            />
          </div>

          {user?.role === 'admin' && (
            <div className="field" style={{ minWidth: '200px' }}>
              <label htmlFor="chapter-filter">Scope Chapter</label>
              <select
                id="chapter-filter"
                value={chapterId}
                onChange={(e) => setChapterId(e.target.value)}
              >
                <option value="">All Chapters (Global)</option>
                <option value="1">Mt. Samat (Orani)</option>
                <option value="2">Mt. Tarak (Mariveles)</option>
                <option value="3">Meridian Heights (Balanga City)</option>
              </select>
            </div>
          )}

          <div className="button-group">
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setRangePreset(7)}>
              Last 7 Days
            </button>
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setRangePreset(30)}>
              Last 30 Days
            </button>
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setRangePreset(90)}>
              Last 90 Days
            </button>
            <button type="button" className="btn btn-secondary btn-sm" onClick={fetchAnalytics}>
              ↻ Refresh
            </button>
          </div>
        </div>
      </section>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {loading && (
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading analytics summary…</p>
        </div>
      )}

      {!loading && !error && (
        <>
          {/* Request Lifecycle Volume & Resolution Rates */}
          <section className="grid-4" style={{ marginBottom: 'var(--space-6)' }}>
            <div className="metric-card">
              <span className="metric-label">Total Requests</span>
              <span className="metric-value">{reqVolume.total_requests || 0}</span>
              <span className="metric-sub">{reqVolume.total_units_needed || 0} units requested</span>
            </div>

            <div className="metric-card">
              <span className="metric-label">Fulfillment Rate</span>
              <span className="metric-value">{reqVolume.fulfillment_rate !== undefined ? `${reqVolume.fulfillment_rate}%` : '0%'}</span>
              <span className="metric-sub">{reqVolume.fulfilled || 0} of {reqVolume.resolved_denominator || 0} resolved</span>
            </div>

            <div className="metric-card">
              <span className="metric-label">Cancellation Rate</span>
              <span className="metric-value">{reqVolume.cancellation_rate !== undefined ? `${reqVolume.cancellation_rate}%` : '0%'}</span>
              <span className="metric-sub">{reqVolume.cancelled || 0} cancelled</span>
            </div>

            <div className="metric-card">
              <span className="metric-label">Expiration Rate</span>
              <span className="metric-value">{reqVolume.expiration_rate !== undefined ? `${reqVolume.expiration_rate}%` : '0%'}</span>
              <span className="metric-sub">{reqVolume.expired || 0} expired</span>
            </div>
          </section>

          {/* Categorical Breakdowns */}
          <div className="grid-2" style={{ marginBottom: 'var(--space-6)' }}>
            {/* Blood Type Demand Distribution */}
            <section className="card">
              <div className="card-header">
                <h3>Demand by Blood Group</h3>
                <span className="muted" style={{ fontSize: '0.75rem' }}>Units Requested</span>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-2)' }}>
                {['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((bt) => {
                  const stat = demandByBt[bt] || { requests_count: 0, units_needed: 0 }
                  const hasData = stat.requests_count > 0

                  return (
                    <div
                      key={bt}
                      style={{
                        padding: 'var(--space-2)',
                        borderRadius: 'var(--radius-md)',
                        border: hasData ? '1px solid var(--color-accent)' : '1px solid var(--color-border)',
                        background: hasData ? 'var(--color-surface-sunken)' : 'transparent',
                        textAlign: 'center'
                      }}
                    >
                      <div style={{ fontWeight: 800, fontSize: '1rem' }}>{bt}</div>
                      <div style={{ fontSize: '0.75rem', color: hasData ? 'var(--color-text)' : 'var(--color-text-subtle)' }}>
                        {stat.requests_count > 0 ? `${stat.requests_count} req (${stat.units_needed}u)` : '0 u'}
                      </div>
                    </div>
                  )
                })}
              </div>
            </section>

            {/* Urgency & Engagement Activity */}
            <section className="card">
              <div className="card-header">
                <h3>Urgency & Engagement</h3>
              </div>

              <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
                  <div>
                    <strong>Critical Urgency</strong>
                    <div className="muted" style={{ fontSize: '0.75rem' }}>Immediate trauma / emergency</div>
                  </div>
                  <span style={{ fontWeight: 800 }}>{demandByUrg.critical?.requests_count || 0} req ({demandByUrg.critical?.units_needed || 0}u)</span>
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
                  <div>
                    <strong>Urgent Category</strong>
                    <div className="muted" style={{ fontSize: '0.75rem' }}>Required within 24h</div>
                  </div>
                  <span style={{ fontWeight: 800 }}>{demandByUrg.urgent?.requests_count || 0} req ({demandByUrg.urgent?.units_needed || 0}u)</span>
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', padding: 'var(--space-2) var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
                  <div>
                    <strong>Routine Category</strong>
                    <div className="muted" style={{ fontSize: '0.75rem' }}>Scheduled clinical procedures</div>
                  </div>
                  <span style={{ fontWeight: 800 }}>{demandByUrg.routine?.requests_count || 0} req ({demandByUrg.routine?.units_needed || 0}u)</span>
                </div>
              </div>
            </section>
          </div>

          {/* Daily Request Trend Table */}
          <section className="card">
            <div className="card-header">
              <h3>Daily Request Volume & Trend</h3>
              <span className="muted" style={{ fontSize: '0.75rem' }}>Date range window</span>
            </div>

            {dailyTrend.length === 0 ? (
              <p className="muted" style={{ fontSize: '0.875rem' }}>No daily request activity recorded in this date range.</p>
            ) : (
              <div className="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>Date (UTC)</th>
                      <th>Requests Created</th>
                      <th>Units Requested</th>
                      <th>Fulfilled</th>
                      <th>Cancelled</th>
                      <th>Expired</th>
                    </tr>
                  </thead>
                  <tbody>
                    {dailyTrend.map((row) => (
                      <tr key={row.date}>
                        <td><code>{row.date}</code></td>
                        <td><strong>{row.requests_created}</strong></td>
                        <td>{row.units_requested}</td>
                        <td>{row.fulfilled}</td>
                        <td>{row.cancelled}</td>
                        <td>{row.expired}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}
