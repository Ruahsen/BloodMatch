import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Check } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function OfficerConfirmationsPage() {
  const [queue, setQueue] = useState(null)
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [processingId, setProcessingId] = useState(null)

  const load = useCallback(() => {
    return api
      .get('/api/officer/donation-reports')
      .then((data) => setQueue(data.pending_reports || []))
      .catch((err) => setErrorAlert(err.message))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const decide = async (reportId, action) => {
    setMessage(null)
    setErrorAlert(null)
    setProcessingId(reportId)
    try {
      await api.post(`/api/officer/donation-reports/${reportId}/${action}`)
      setMessage(`Donation report #${reportId} has been ${action === 'confirm' ? 'CONFIRMED' : 'REJECTED'}.`)
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setProcessingId(null)
    }
  }

  if (!queue) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading donation confirmation queue…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="container">
      <header className="app-header">
        <div>
          <h1>Donation Confirmations: Chapter Queue</h1>
        </div>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {queue.length === 0 ? (
        <div className="empty-state">
          <h3>No Pending Confirmations</h3>
          <p>There are no completed donation reports waiting for officer confirmation in your chapter.</p>
          <Link to="/officer/dashboard" className="btn btn-secondary">
            Return to Dashboard
          </Link>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          {queue.map((r) => (
            <article key={r.id} className="card">
              <div className="card-header">
                <div>
                  <h3 style={{ margin: '0 0 var(--space-1)' }}>{r.donor_name}</h3>
                  <span className="muted" style={{ fontSize: '0.8125rem' }}>Report <code>#{r.id}</code> · Match <code>#{r.match_id}</code></span>
                </div>
                <span className="badge badge-open">Pending Confirmation</span>
              </div>

              <div className="card-body">
                <div className="grid-3">
                  <div>
                    <span className="metric-label">Blood Type</span>
                    <p style={{ margin: 0, fontWeight: 700, fontSize: '1.125rem' }}>{r.required_blood_type}</p>
                  </div>
                  <div>
                    <span className="metric-label">Healthcare Facility</span>
                    <p style={{ margin: 0, fontWeight: 600 }}>{r.facility_name}</p>
                  </div>
                  <div>
                    <span className="metric-label">Reported At</span>
                    <p style={{ margin: 0 }}>{new Date(r.reported_at.replace(' ', 'T') + 'Z').toLocaleString()}</p>
                  </div>
                </div>

                {r.report_note && (
                  <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)', fontSize: '0.875rem' }}>
                    <span className="muted" style={{ fontWeight: 600 }}>Donor Note:</span> {r.report_note}
                  </div>
                )}

                <div className="button-group" style={{ marginTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
                  <button
                    type="button"
                    className="btn"
                    disabled={processingId === r.id}
                    onClick={() => decide(r.id, 'confirm')}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                  >
                    {processingId === r.id ? 'Processing…' : <><Check size={14} weight="regular" aria-hidden="true" /> Confirm Completed Donation</>}
                  </button>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={processingId === r.id}
                    onClick={() => decide(r.id, 'reject')}
                  >
                    Reject Report
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}
