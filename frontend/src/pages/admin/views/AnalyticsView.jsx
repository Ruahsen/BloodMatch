import { useEffect, useState } from 'react'
import { ArrowsClockwise } from '@phosphor-icons/react'
import { api } from '../../../services/apiClient'
import { useAuth } from '../../../context/AuthContext'
import DailyTrendChart from '../../../components/charts/DailyTrendChart'
import BloodGroupDemandChart from '../../../components/charts/BloodGroupDemandChart'
import DonorPoolBreakdownChart from '../../../components/charts/DonorPoolBreakdownChart'
import styles from './AnalyticsView.module.css'
import { LoadingSpinner } from '../../../components/ui/LoadingSpinner'
import { getPhilippineDateString } from '../../../services/dateTime'

export default function AnalyticsView() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [dateFrom, setDateFrom] = useState(() => getPhilippineDateString(-30))
  const [dateTo, setDateTo] = useState(() => getPhilippineDateString(0))
  const [chapterId, setChapterId] = useState('')
  const [activePreset, setActivePreset] = useState('monthly')

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

  const applyPreset = (presetKey, days) => {
    setActivePreset(presetKey)
    if (days === 1) {
      setDateFrom(getPhilippineDateString(-1))
    } else {
      setDateFrom(getPhilippineDateString(-days))
    }
    setDateTo(getPhilippineDateString(0))
  }

  const reqVolume = data?.request_volume || {}
  const demandByBt = data?.demand_by_blood_type || {}
  const demandByUrg = data?.demand_by_urgency || {}
  const donorPool = data?.donor_pool || {}
  const dailyTrend = data?.daily_request_trend || []

  // Normalized trend data for DailyTrendChart
  const chartTrend = dailyTrend.map((row) => ({
    date: row.date,
    total_requests: Number(row.requests_created || 0),
    fulfilled_requests: Number(row.fulfilled || 0)
  }))

  return (
    <div className="container" style={{ padding: 'var(--space-6) 0' }}>

      {/* 1-Click Periodic Preset Tabs */}
      <div className={styles.presetsBar}>
        <div className={styles.tabGroup} role="tablist" aria-label="Reporting Intervals">
          <button
            type="button"
            role="tab"
            aria-selected={activePreset === 'daily'}
            className={`btn btn-sm ${activePreset === 'daily' ? 'btn' : 'btn-secondary'}`}
            onClick={() => applyPreset('daily', 1)}
          >
            Daily Report
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activePreset === 'weekly'}
            className={`btn btn-sm ${activePreset === 'weekly' ? 'btn' : 'btn-secondary'}`}
            onClick={() => applyPreset('weekly', 7)}
          >
            Weekly Report
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activePreset === 'monthly'}
            className={`btn btn-sm ${activePreset === 'monthly' ? 'btn' : 'btn-secondary'}`}
            onClick={() => applyPreset('monthly', 30)}
          >
            Monthly Report
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activePreset === 'yearly'}
            className={`btn btn-sm ${activePreset === 'yearly' ? 'btn' : 'btn-secondary'}`}
            onClick={() => applyPreset('yearly', 365)}
          >
            Yearly Report
          </button>
        </div>
      </div>

      {/* Date Range & Chapter Filter Bar */}
      <section className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div style={{ display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div className="field">
            <label htmlFor="date-from">Date From</label>
            <input
              id="date-from"
              type="date"
              value={dateFrom}
              onChange={(e) => {
                setActivePreset('custom')
                setDateFrom(e.target.value)
              }}
            />
          </div>

          <div className="field">
            <label htmlFor="date-to">Date To</label>
            <input
              id="date-to"
              type="date"
              value={dateTo}
              onChange={(e) => {
                setActivePreset('custom')
                setDateTo(e.target.value)
              }}
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

          <div>
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={fetchAnalytics}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
            >
              <ArrowsClockwise size={14} weight="regular" aria-hidden="true" /> Refresh
            </button>
          </div>
        </div>
      </section>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {loading && (
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <LoadingSpinner text="Loading analytics summary…" />
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

          {/* Interactive Trend Chart Card */}
          <section className="card" style={{ marginBottom: 'var(--space-6)' }}>
            <div className="card-header">
              <div>
                <h3>Request Volume &amp; Fulfillment Trend</h3>
                <span className="muted" style={{ fontSize: '0.75rem' }}>Daily timeline activity curve</span>
              </div>
            </div>
            <DailyTrendChart trend={chartTrend} />
          </section>

          {/* Categorical Breakdowns Grid */}
          <div className="grid-2" style={{ marginBottom: 'var(--space-6)' }}>
            {/* Blood Type Demand Distribution with Bar Chart */}
            <section className="card">
              <div className="card-header">
                <h3>Demand by Blood Group</h3>
                <span className="muted" style={{ fontSize: '0.75rem' }}>Units Requested per Type</span>
              </div>
              <BloodGroupDemandChart demandByBt={demandByBt} />
            </section>

            {/* Donor Pool Status Distribution with Segmented Gauge */}
            <section className="card">
              <div className="card-header">
                <h3>Donor Pool Availability Status</h3>
                <span className="muted" style={{ fontSize: '0.75rem' }}>Active Enrolled Pool</span>
              </div>
              <DonorPoolBreakdownChart donorPool={donorPool} />

              <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', marginTop: 'var(--space-4)', paddingTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 'var(--text-xs)' }}>
                  <span>Total Enrolled Donors:</span>
                  <strong>{donorPool.total_enrolled || 0}</strong>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 'var(--text-xs)' }}>
                  <span className="muted">Emergency Urgency Demand:</span>
                  <strong>{demandByUrg.emergency?.requests || 0} req ({demandByUrg.emergency?.units || 0}u)</strong>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 'var(--text-xs)' }}>
                  <span className="muted">Urgent Category Demand:</span>
                  <strong>{demandByUrg.urgent?.requests_count || 0} req ({demandByUrg.urgent?.units_needed || 0}u)</strong>
                </div>
              </div>
            </section>
          </div>

          {/* Detailed Daily Table */}
          <section className="card">
            <div className="card-header">
              <h3>Tabular Daily Records</h3>
              <span className="muted" style={{ fontSize: '0.75rem' }}>Exact breakdown for selected interval</span>
            </div>

            {dailyTrend.length === 0 ? (
              <p className="muted" style={{ fontSize: '0.875rem' }}>No daily request activity recorded in this date range.</p>
            ) : (
              <div className="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>DATE</th>
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
