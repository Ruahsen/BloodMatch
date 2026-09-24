import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowsClockwise } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

const BLOOD_TYPES = ['All', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const URGENCIES = ['All', 'routine', 'urgent', 'critical']

export default function DemandMapPage() {
  const { user } = useAuth()
  const [chapters, setChapters] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [selectedBloodType, setSelectedBloodType] = useState('All')
  const [selectedUrgency, setSelectedUrgency] = useState('All')
  const [selectedDays, setSelectedDays] = useState('')

  const fetchMapData = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams()
      if (selectedBloodType !== 'All') params.set('blood_type', selectedBloodType)
      if (selectedUrgency !== 'All') params.set('urgency', selectedUrgency)
      if (selectedDays) params.set('days', selectedDays)

      const res = await api.get(`/api/demand-map?${params.toString()}`)
      setChapters(res.chapters || [])
    } catch (err) {
      setError(err.message || 'Failed to load regional demand map.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchMapData()
  }, [selectedBloodType, selectedUrgency, selectedDays])

  const totalDemand = chapters.reduce((acc, c) => acc + (c.open_requests_count || 0), 0)
  const totalUnits = chapters.reduce((acc, c) => acc + (c.total_units_needed || 0), 0)

  return (
    <div className="container" style={{ maxWidth: '960px' }}>
      <header className="app-header">
        <div>
          <h1>Regional Blood Demand Map</h1>
        </div>
        <div className="button-group">
          {user?.role === 'officer' && (
            <Link to="/officer/dashboard" className="btn btn-secondary btn-sm">
              Officer Dashboard
            </Link>
          )}
          {user?.role === 'admin' && (
            <Link to="/admin/dashboard" className="btn btn-secondary btn-sm">
              Admin Dashboard
            </Link>
          )}
        </div>
      </header>

      {/* Filter Bar */}
      <section className="card" style={{ marginBottom: 'var(--space-6)' }}>
        <div style={{ display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div className="field" style={{ minWidth: '140px', flex: 1 }}>
            <label htmlFor="bt-filter">Blood Type</label>
            <select
              id="bt-filter"
              value={selectedBloodType}
              onChange={(e) => setSelectedBloodType(e.target.value)}
            >
              {BLOOD_TYPES.map((bt) => (
                <option key={bt} value={bt}>{bt}</option>
              ))}
            </select>
          </div>

          <div className="field" style={{ minWidth: '140px', flex: 1 }}>
            <label htmlFor="urgency-filter">Urgency</label>
            <select
              id="urgency-filter"
              value={selectedUrgency}
              onChange={(e) => setSelectedUrgency(e.target.value)}
            >
              {URGENCIES.map((u) => (
                <option key={u} value={u}>{u.charAt(0).toUpperCase() + u.slice(1)}</option>
              ))}
            </select>
          </div>

          <div className="field" style={{ minWidth: '160px', flex: 1 }}>
            <label htmlFor="days-filter">Time Window</label>
            <select
              id="days-filter"
              value={selectedDays}
              onChange={(e) => setSelectedDays(e.target.value)}
            >
              <option value="">All Active OPEN</option>
              <option value="7">Created Last 7 Days</option>
              <option value="30">Created Last 30 Days</option>
              <option value="90">Created Last 90 Days</option>
            </select>
          </div>

          <div>
            <button type="button" className="btn btn-secondary" onClick={fetchMapData} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
              <ArrowsClockwise size={14} weight="regular" aria-hidden="true" /> Refresh
            </button>
          </div>
        </div>
      </section>

      {/* Status Indicators */}
      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {loading && (
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading regional demand map data…</p>
        </div>
      )}

      {!loading && !error && (
        <>
          {/* Summary Overview */}
          <div className="grid-3" style={{ marginBottom: 'var(--space-6)' }}>
            <div className="metric-card">
              <span className="metric-label">Active Open Requests</span>
              <span className="metric-value">{totalDemand}</span>
              <span className="metric-sub">Current unmet demand</span>
            </div>
            <div className="metric-card">
              <span className="metric-label">Total Units Needed</span>
              <span className="metric-value">{totalUnits}</span>
              <span className="metric-sub">Across active requests</span>
            </div>
            <div className="metric-card">
              <span className="metric-label">Reporting Chapters</span>
              <span className="metric-value">{chapters.length}</span>
              <span className="metric-sub">Bataan regional coverage</span>
            </div>
          </div>

          {/* Chapter Centroid Regional Demand Cards */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {chapters.length === 0 ? (
              <div className="empty-state">
                <h3>No Active Demand</h3>
                <p>No active blood requests match the selected blood type and urgency filters.</p>
              </div>
            ) : (
              chapters.map((ch) => (
                <article key={ch.chapter_id} className="card" style={{ borderLeft: '4px solid var(--color-accent)' }}>
                  <div className="card-header">
                    <div>
                      <h2 style={{ fontSize: '1.25rem', margin: '0 0 var(--space-1)' }}>
                        {ch.chapter_name} ({ch.municipality})
                      </h2>
                      <span className="muted" style={{ fontSize: '0.8125rem' }}>
                        Canonical Centroid: <code>{ch.latitude?.toFixed(4)}, {ch.longitude?.toFixed(4)}</code>
                      </span>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>
                        {ch.open_requests_count} {ch.open_requests_count === 1 ? 'request' : 'requests'}
                      </span>
                      <div className="muted" style={{ fontSize: '0.8125rem' }}>
                        {ch.total_units_needed} {ch.total_units_needed === 1 ? 'unit' : 'units'} needed
                      </div>
                    </div>
                  </div>

                  {/* Urgency breakdown */}
                  <div style={{ display: 'flex', gap: 'var(--space-2)', marginBottom: 'var(--space-4)', flexWrap: 'wrap' }}>
                    <span className="badge badge-routine">
                      Routine: {ch.urgency_counts?.routine || 0}
                    </span>
                    <span className="badge badge-urgent">
                      Urgent: {ch.urgency_counts?.urgent || 0}
                    </span>
                    <span className="badge badge-critical">
                      Critical: {ch.urgency_counts?.critical || 0}
                    </span>
                  </div>

                  {/* Blood type demand breakdown */}
                  <div>
                    <h4 style={{ fontSize: '0.875rem', marginBottom: 'var(--space-2)' }}>Demand by Blood Group</h4>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(100px, 1fr))', gap: 'var(--space-2)' }}>
                      {['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((bt) => {
                        const count = ch.blood_type_demand?.[bt]?.requests_count || 0
                        const units = ch.blood_type_demand?.[bt]?.units_needed || 0
                        const hasDemand = count > 0

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
                            <div style={{ fontWeight: 800, fontSize: '0.9375rem' }}>{bt}</div>
                            <div style={{ fontSize: '0.75rem', color: hasDemand ? 'var(--color-text)' : 'var(--color-text-subtle)' }}>
                              {count > 0 ? `${count} req (${units}u)` : '–'}
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </article>
              ))
            )}
          </div>
        </>
      )}
    </div>
  )
}
