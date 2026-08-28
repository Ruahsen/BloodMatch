import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

export default function MatchesPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [matches, setMatches] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [message, setMessage] = useState(null)
  const [reportingMatchId, setReportingMatchId] = useState(null)
  const [reportNote, setReportNote] = useState('')
  const [submittingReport, setSubmittingReport] = useState(false)

  const load = useCallback(() => {
    return api
      .get(`/api/requests/${id}/matches`)
      .then((data) => setMatches(data.matches || []))
      .catch((err) => setErrorAlert(err.message))
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const onRespond = async (matchId) => {
    setMessage(null)
    setErrorAlert(null)
    try {
      await api.post(`/api/matches/${matchId}/respond`)
      setMessage('Your willingness to donate has been recorded. Thank you for stepping forward!')
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  const onSubmitReport = async (e) => {
    e.preventDefault()
    if (!reportingMatchId) return
    setMessage(null)
    setErrorAlert(null)
    setSubmittingReport(true)
    try {
      await api.post('/api/donation-reports', {
        match_id: reportingMatchId,
        note: reportNote || null
      })
      setMessage('Donation report submitted! A chapter officer will confirm your completed donation.')
      setReportingMatchId(null)
      setReportNote('')
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setSubmittingReport(false)
    }
  }

  if (errorAlert && !matches) {
    return (
      <div className="container">
        <div className="alert alert-error" role="alert">{errorAlert}</div>
        <Link to="/requests/mine" className="btn btn-secondary">← Back to My Requests</Link>
      </div>
    )
  }

  if (!matches) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading matched donors…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="container">
      <header className="app-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-1)' }}>
            <Link to="/requests/mine" style={{ fontSize: '0.875rem' }}>← My Requests</Link>
            <span className="muted">/</span>
            <span className="muted">Matches</span>
          </div>
          <h1>Potential Donors for Request #{id}</h1>
          <p className="muted" style={{ margin: 0 }}>
            Ranked among verified, enrolled, and available donors in compatible blood groups. Proximity is a ranking factor; red-cell compatibility is strictly enforced.
          </p>
        </div>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {/* Medical Disclaimer Banner */}
      <div className="medical-disclaimer" style={{ marginBottom: 'var(--space-6)' }}>
        <div style={{ fontSize: '1.25rem' }}>ℹ️</div>
        <div>
          <strong>Privacy & Medical Notice:</strong> Donor identities are anonymized for safety. Proximity calculations are approximate based on chapter centroids or opted-in coordinates. Clinical confirmation takes place at the destination facility.
        </div>
      </div>

      {matches.length === 0 ? (
        <div className="empty-state">
          <h3>No Eligible Donors Matched Yet</h3>
          <p>
            No active volunteer donors currently match this request&apos;s blood type and chapter availability. As new volunteer donors enroll or complete cooldown windows, the matching engine will re-evaluate eligible candidates.
          </p>
          <Link to="/requests/mine" className="btn btn-secondary">
            Return to Requests
          </Link>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          {matches.map((m) => {
            const isOwnMatch = user && m.donor_reference === `donor-${user.id}`

            return (
              <article key={m.match_id} className="card">
                <div className="card-header">
                  <div>
                    <h3 style={{ margin: '0 0 var(--space-1)' }}>{m.display_name}</h3>
                    <span className="muted" style={{ fontSize: '0.8125rem' }}>Reference: <code>{m.donor_reference}</code></span>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <span className={`badge ${m.status === 'RESPONDED' ? 'badge-verified' : (m.status === 'COMPLETED' ? 'badge-open' : 'badge-routine')}`}>
                      {m.status}
                    </span>
                    {isOwnMatch && <span className="badge badge-pill">You</span>}
                  </div>
                </div>

                <div className="card-body">
                  <div className="grid-4">
                    <div>
                      <span className="metric-label">Chapter</span>
                      <p style={{ margin: 0, fontWeight: 600 }}>{m.chapter_name ?? `Chapter #${m.chapter_id}`}</p>
                    </div>
                    <div>
                      <span className="metric-label">Verification</span>
                      <p style={{ margin: 0, fontWeight: 600, textTransform: 'capitalize' }}>{m.verification_status}</p>
                    </div>
                    <div>
                      <span className="metric-label">Availability</span>
                      <p style={{ margin: 0, fontWeight: 600, textTransform: 'capitalize' }}>{m.availability ?? '–'}</p>
                    </div>
                    <div>
                      <span className="metric-label">Approx. Distance</span>
                      <p style={{ margin: 0, fontWeight: 600 }}>
                        {m.approximate_distance_km !== null ? `~${m.approximate_distance_km} km` : 'Regional centroid'}
                      </p>
                    </div>
                  </div>

                  {/* Actions for the Matched Donor */}
                  {isOwnMatch && (
                    <div style={{ marginTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
                      {(m.status === 'POTENTIAL' || m.status === 'NOTIFIED') && (
                        <button type="button" className="btn" onClick={() => onRespond(m.match_id)}>
                          ✓ I Can Donate: Respond to Request
                        </button>
                      )}

                      {m.status === 'RESPONDED' && (
                        <div className="button-group">
                          <button type="button" className="btn" onClick={() => setReportingMatchId(m.match_id)}>
                            + Submit Donation Report
                          </button>
                          <span className="muted" style={{ fontSize: '0.8125rem', alignSelf: 'center' }}>
                            You have responded to this match. If you have completed the donation, submit a report for officer confirmation.
                          </span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </article>
            )
          })}
        </div>
      )}

      {/* Donation Report Modal */}
      {reportingMatchId && (
        <div className="modal-backdrop" role="dialog" aria-labelledby="report-title" aria-modal="true">
          <div className="modal-content">
            <h2 id="report-title" style={{ marginBottom: 'var(--space-2)' }}>Report Completed Donation</h2>
            <p className="muted" style={{ fontSize: '0.875rem', marginBottom: 'var(--space-4)' }}>
              Confirm that you presented at the healthcare facility and completed the blood donation. Your report will be placed in the Chapter Officer queue for administrative confirmation.
            </p>

            <form onSubmit={onSubmitReport} className="form">
              <div className="field">
                <label htmlFor="report-note">Optional Notes / Reference (e.g. Unit Bag #, Station Number)</label>
                <textarea
                  id="report-note"
                  placeholder="e.g. Donated 1 unit at Blood Bank station 2, attending nurse Santos."
                  value={reportNote}
                  onChange={(e) => setReportNote(e.target.value)}
                  maxLength={500}
                />
                <small className="field-hint">Max 500 characters.</small>
              </div>

              <div className="button-group" style={{ marginTop: 'var(--space-3)' }}>
                <button type="submit" className="btn" disabled={submittingReport}>
                  {submittingReport ? 'Submitting…' : 'Submit Donation Report'}
                </button>
                <button type="button" className="btn btn-secondary" onClick={() => setReportingMatchId(null)}>
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
