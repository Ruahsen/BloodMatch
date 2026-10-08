import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Check, Info } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

function statusBadge(status) {
  if (status === 'RESPONDED') return 'badge-verified'
  if (status === 'COMPLETED' || status === 'ACCEPTED') return 'badge-open'
  if (status === 'WITHDRAWN' || status === 'CLOSED') return 'badge-critical'
  return 'badge-routine'
}

function ContactPanel({ matchId, side, onError }) {
  const [contact, setContact] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  // Own email-sharing consent: null = unknown yet, true = ON, false = OFF.
  // The contact endpoint discloses addresses only when BOTH parties consent,
  // so a successful load proves our own flag is ON.
  const [ownShare, setOwnShare] = useState(null)
  const isRequesterSide = side === 'requester'

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      const d = await api.get(`/api/matches/${matchId}/contact`)
      setContact(d.contact)
      setOwnShare(true)
    } catch (err) {
      setError(err.message || 'Contact details are not available for this match.')
      onError?.(err.message)
    } finally {
      setLoading(false)
    }
  }

  const setSharing = async (share) => {
    setError(null)
    try {
      const d = await api.post(`/api/matches/${matchId}/consent`, { share })
      setOwnShare(d && typeof d.share === 'boolean' ? d.share : share)
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  if (!contact) {
    return (
      <div style={{ marginTop: 'var(--space-3)' }}>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
          {loading ? 'Loading…' : 'View contact details'}
        </button>
        {error && <p className="muted" style={{ fontSize: '0.8125rem', margin: 'var(--space-2) 0 0' }}>{error}</p>}
      </div>
    )
  }

  return (
    <div style={{ marginTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
      <span className="metric-label">Direct contact</span>
      <div style={{ display: 'flex', gap: 'var(--space-3)', flexWrap: 'wrap', marginTop: 'var(--space-1)' }}>
        {isRequesterSide ? (
          <a className="btn btn-secondary btn-sm" href={`mailto:${contact.donor_email}`}>Email donor</a>
        ) : (
          <a className="btn btn-secondary btn-sm" href={`mailto:${contact.requester_email}`}>Email requester</a>
        )}
      </div>
      <p className="muted" style={{ fontSize: '0.75rem', margin: 'var(--space-2) 0 0' }}>
        Donor: {contact.donor_email} · Requester: {contact.requester_email}. Revoking access here cannot unsend an address already saved elsewhere.
      </p>
      {error && <p className="muted" style={{ fontSize: '0.8125rem', margin: 'var(--space-2) 0 0' }}>{error}</p>}
      <div className="button-group" style={{ marginTop: 'var(--space-2)' }}>
        {ownShare === false ? (
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSharing(true)}>
            Re-enable my email sharing
          </button>
        ) : (
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSharing(false)}>
            Revoke my email sharing
          </button>
        )}
      </div>
    </div>
  )
}

export default function MatchesPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [matches, setMatches] = useState(null)
  const [history, setHistory] = useState([])
  const [isRequester, setIsRequester] = useState(false)
  const [errorAlert, setErrorAlert] = useState(null)
  const [message, setMessage] = useState(null)
  const [reportingMatchId, setReportingMatchId] = useState(null)
  const [reportNote, setReportNote] = useState('')
  const [submittingReport, setSubmittingReport] = useState(false)
  const [respondConsent, setRespondConsent] = useState({})
  const [acceptConsent, setAcceptConsent] = useState({})
  const [busyId, setBusyId] = useState(null)
  const [matchPage, setMatchPage] = useState(1)
  const [matchTotal, setMatchTotal] = useState(0)
  const MATCH_PAGE_SIZE = 50

  const load = useCallback((p = matchPage) => {
    setErrorAlert(null)
    return Promise.all([
      api.get(`/api/requests/${id}/matches?page=${p}&page_size=${MATCH_PAGE_SIZE}`).catch((err) => { throw err }),
      api.get(`/api/requests/${id}`).then((d) => d.request).catch(() => null)
    ])
      .then(([m, req]) => {
        setMatches(m.matches || [])
        setHistory(m.history || [])
        setMatchTotal(m.total ?? (m.matches || []).length)
        setIsRequester(!!req && !!user && req.requester_id === user.id)
        setErrorAlert(null)
      })
      .catch((err) => setErrorAlert(err.message))
  }, [id, user, matchPage])

  useEffect(() => {
    load()
  }, [load])

  const runMatchAction = async (matchId, fn, okMessage) => {
    setMessage(null)
    setErrorAlert(null)
    setBusyId(matchId)
    try {
      await fn()
      setMessage(okMessage)
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setBusyId(null)
    }
  }

  const onRespond = (matchId) => runMatchAction(
    matchId,
    () => api.post(`/api/matches/${matchId}/respond`, { donor_share_consent: true }),
    'Your willingness to donate has been recorded. Thank you for stepping forward!'
  )

  const onAccept = (matchId) => runMatchAction(
    matchId,
    () => api.post(`/api/matches/${matchId}/accept`, { requester_share_consent: true }),
    'Donor accepted. Contact details are now available to both of you.'
  )

  const onUnaccept = (matchId) => {
    if (!window.confirm('Stop holding this donor as accepted? Their response stays recorded.')) return
    return runMatchAction(
      matchId,
      () => api.post(`/api/matches/${matchId}/unaccept`),
      'Acceptance withdrawn. The donor response remains recorded.'
    )
  }

  const onWithdraw = (matchId) => {
    if (!window.confirm('Withdraw your response to this request?')) return
    return runMatchAction(
      matchId,
      () => api.post(`/api/matches/${matchId}/withdraw`),
      'Your response has been withdrawn.'
    )
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
        <Link to="/requests/mine" className="btn btn-secondary" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}><ArrowLeft size={14} weight="regular" aria-hidden="true" /> Back to My Requests</Link>
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
            <Link to="/requests/mine" style={{ fontSize: '0.875rem', display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}><ArrowLeft size={12} weight="regular" aria-hidden="true" /> My Requests</Link>
            <span className="muted">/</span>
            <span className="muted">Matches</span>
          </div>
          <h1 className="sr-only">Potential Donors for Request #{id}</h1>
        </div>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {/* Medical Disclaimer Banner */}
      <div className="medical-disclaimer" style={{ marginBottom: 'var(--space-6)' }}>
        <div aria-hidden="true"><Info size={20} weight="regular" /></div>
        <div>
          <strong>Privacy & Medical Notice:</strong> Donor identities are anonymized for safety. Proximity calculations are approximate based on Bataan municipality reference points. Clinical confirmation takes place at the destination facility.
        </div>
      </div>

      {matches.length === 0 && history.length === 0 ? (
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
            const busy = busyId === m.match_id

            return (
              <article key={m.match_id} className="card">
                <div className="card-header">
                  <div>
                    <h3 style={{ margin: '0 0 var(--space-1)' }}>{m.display_name}</h3>
                    <span className="muted" style={{ fontSize: '0.8125rem' }}>Reference: <code>{m.donor_reference}</code></span>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <span className={`badge ${statusBadge(m.status)}`}>
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
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                          <label style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'flex-start', fontSize: '0.8125rem' }}>
                            <input
                              type="checkbox"
                              checked={!!respondConsent[m.match_id]}
                              onChange={(e) => setRespondConsent((c) => ({ ...c, [m.match_id]: e.target.checked }))}
                              style={{ marginTop: '0.2rem' }}
                            />
                            <span>I agree to share my email address with this requester if my response is accepted.</span>
                          </label>
                          <div>
                            <button
                              type="button"
                              className="btn"
                              disabled={!respondConsent[m.match_id] || busy}
                              onClick={() => onRespond(m.match_id)}
                              style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                            >
                              <Check size={14} weight="regular" aria-hidden="true" /> I Can Donate: Respond to Request
                            </button>
                          </div>
                        </div>
                      )}

                      {m.status === 'RESPONDED' && (
                        <div className="button-group">
                          <button type="button" className="btn" onClick={() => setReportingMatchId(m.match_id)}>
                            + Submit Donation Report
                          </button>
                          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => onWithdraw(m.match_id)}>
                            Withdraw Response
                          </button>
                          <span className="muted" style={{ fontSize: '0.8125rem', alignSelf: 'center' }}>
                            You have responded to this match. If you have completed the donation, submit a report for officer confirmation.
                          </span>
                        </div>
                      )}

                      {m.status === 'ACCEPTED' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                          <p style={{ margin: 0, fontWeight: 600 }}>You are connected with the requester.</p>
                          <div className="button-group">
                            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => onWithdraw(m.match_id)}>
                              Withdraw Response
                            </button>
                          </div>
                          <ContactPanel matchId={m.match_id} side="donor" onError={setErrorAlert} />
                        </div>
                      )}

                      {m.status === 'COMPLETED' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                          <p style={{ margin: 0, fontWeight: 600 }}>Donation completed. Thank you.</p>
                          <ContactPanel matchId={m.match_id} side="donor" onError={setErrorAlert} />
                        </div>
                      )}
                    </div>
                  )}

                  {/* Actions for the Requester */}
                  {isRequester && !isOwnMatch && (
                    <div style={{ marginTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
                      {m.status === 'RESPONDED' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                          <label style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'flex-start', fontSize: '0.8125rem' }}>
                            <input
                              type="checkbox"
                              checked={!!acceptConsent[m.match_id]}
                              onChange={(e) => setAcceptConsent((c) => ({ ...c, [m.match_id]: e.target.checked }))}
                              style={{ marginTop: '0.2rem' }}
                            />
                            <span>I agree to share my email address with this donor.</span>
                          </label>
                          <div>
                            <button
                              type="button"
                              className="btn btn-sm"
                              disabled={!acceptConsent[m.match_id] || busy}
                              onClick={() => onAccept(m.match_id)}
                            >
                              Accept Donor
                            </button>
                          </div>
                        </div>
                      )}

                      {m.status === 'ACCEPTED' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                          <p style={{ margin: 0, fontWeight: 600 }}>Connected donor.</p>
                          <div className="button-group">
                            <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => onUnaccept(m.match_id)}>
                              Withdraw Acceptance
                            </button>
                          </div>
                          <ContactPanel matchId={m.match_id} side="requester" onError={setErrorAlert} />
                        </div>
                      )}

                      {m.status === 'COMPLETED' && (
                        <ContactPanel matchId={m.match_id} side="requester" onError={setErrorAlert} />
                      )}
                    </div>
                  )}
                </div>
              </article>
            )
          })}

          {history.length > 0 && (
            <section aria-label="Withdrawn history" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
              <h2 className="muted" style={{ fontSize: '0.875rem' }}>Withdrawn history</h2>
              {history.map((h) => (
                <div key={h.match_id} className="card" style={{ padding: 'var(--space-3)' }}>
                  <span className="muted" style={{ fontSize: '0.8125rem' }}>
                    {h.donor_reference ? <><code>{h.donor_reference}</code> · </> : null}
                    <span className={`badge ${statusBadge(h.status)}`}>{h.status}</span> · {new Date(String(h.updated_at).replace(' ', 'T') + 'Z').toLocaleString()}
                  </span>
                </div>
              ))}
            </section>
          )}

          {matchTotal > MATCH_PAGE_SIZE && (
            <nav aria-label="Match pages" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', marginTop: 'var(--space-4)', flexWrap: 'wrap' }}>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={matchPage <= 1}
                onClick={() => setMatchPage((p) => p - 1)}
              >
                ← Newer
              </button>
              <span className="muted" style={{ fontSize: '0.8125rem' }} aria-live="polite">
                Page {matchPage} of {Math.max(1, Math.ceil(matchTotal / MATCH_PAGE_SIZE))} · {matchTotal} matched donor{matchTotal === 1 ? '' : 's'}
              </span>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={matchPage >= Math.ceil(matchTotal / MATCH_PAGE_SIZE)}
                onClick={() => setMatchPage((p) => p + 1)}
              >
                Older →
              </button>
            </nav>
          )}
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
