import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, ArrowRight } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function OfficerVerificationPage() {
  const [queue, setQueue] = useState(null)
  const [detail, setDetail] = useState(null)
  const [decision, setDecision] = useState('verified')
  const [reason, setReason] = useState('')
  const [acceptCard, setAcceptCard] = useState(false)
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const loadQueue = useCallback(() => {
    return api
      .get('/api/officer/verifications')
      .then((data) => setQueue(data))
      .catch((err) => setErrorAlert(err.message))
  }, [])

  useEffect(() => {
    loadQueue()
  }, [loadQueue])

  const openDetail = (userId) => {
    setErrorAlert(null)
    setMessage(null)
    api
      .get(`/api/officer/verifications/${userId}`)
      .then((data) => {
        setDetail(data.verification)
        setDecision('verified')
        setReason('')
        setAcceptCard(false)
      })
      .catch((err) => setErrorAlert(err.message))
  }

  const submitDecision = async (e) => {
    e.preventDefault()
    setErrorAlert(null)
    setMessage(null)
    setSubmitting(true)
    try {
      await api.post(`/api/officer/verifications/${detail.user.id}/decision`, {
        decision,
        reason: reason || null,
        accept_donor_card: acceptCard
      })
      setMessage(`Member ${detail.user.full_name} decision recorded: ${decision.toUpperCase()}.`)
      setDetail(null)
      await loadQueue()
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (!queue) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading verification queue…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="container">
      <header className="app-header">
        <div>
          <h1>Member Verifications: Chapter Queue</h1>
        </div>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      {!detail ? (
        <section className="card">
          <div className="card-header">
            <h3>Pending Verifications ({queue.queue.length})</h3>
          </div>

          {queue.queue.length === 0 ? (
            <div className="empty-state">
              <h3>All caught up!</h3>
              <p>There are no pending member verifications in your chapter queue.</p>
            </div>
          ) : (
            <div className="table-container">
              <table>
                <thead>
                  <tr>
                    <th>Member Name</th>
                    <th>Email Address</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {queue.queue.map((m) => (
                    <tr key={m.id}>
                      <td><strong>{m.full_name}</strong></td>
                      <td><code>{m.email}</code></td>
                      <td>
                        <button type="button" className="btn btn-sm" onClick={() => openDetail(m.id)} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                          Review Documents <ArrowRight size={14} weight="regular" aria-hidden="true" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-6)' }}>
          {/* Detail Review Card */}
          <section className="card">
            <div className="card-header">
              <div>
                <h2>Reviewing: {detail.user.full_name}</h2>
                <span className="muted"><code>{detail.user.email}</code></span>
              </div>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setDetail(null)} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                <ArrowLeft size={14} weight="regular" aria-hidden="true" /> Back to Queue
              </button>
            </div>

            <div className="grid-3" style={{ marginBottom: 'var(--space-4)' }}>
              <div>
                <span className="metric-label">Date of Birth</span>
                <p style={{ margin: 0, fontWeight: 600 }}>{detail.user.date_of_birth || 'Not provided'}</p>
              </div>
              <div>
                <span className="metric-label">Self-Reported Blood Type</span>
                <p style={{ margin: 0, fontWeight: 600 }}>{detail.user.blood_type || 'Unspecified'}</p>
              </div>
              <div>
                <span className="metric-label">Age Evaluation</span>
                <p style={{ margin: 0, fontWeight: 600 }}>
                  {detail.age_eligibility
                    ? `${detail.age_eligibility.age} yrs (${detail.age_eligibility.donor_path_allowed ? 'Eligible' : 'Restricted'})`
                    : '–'}
                </p>
              </div>
            </div>

            {detail.age_eligibility && !detail.age_eligibility.donor_path_allowed && (
              <div className="alert alert-error" style={{ marginBottom: 'var(--space-4)' }}>
                <strong>Age Notice:</strong> {detail.age_eligibility.reason}
              </div>
            )}

            <h3>Submitted Documents</h3>
            {detail.documents.length === 0 ? (
              <p className="muted">No documents uploaded by this member.</p>
            ) : (
              <div className="table-container" style={{ marginBottom: 'var(--space-6)' }}>
                <table>
                  <thead>
                    <tr>
                      <th>Document Type</th>
                      <th>Format</th>
                      <th>Size</th>
                      <th>File View</th>
                    </tr>
                  </thead>
                  <tbody>
                    {detail.documents.map((d) => (
                      <tr key={d.id}>
                        <td><strong>{d.doc_type.replace('_', ' ').toUpperCase()}</strong></td>
                        <td><code>{d.mime_type}</code></td>
                        <td>{Math.ceil(d.size_bytes / 1024)} KB</td>
                        <td>
                          <a href={`/api/officer/documents/${d.id}/file`} target="_blank" rel="noreferrer" className="btn btn-secondary btn-sm">
                            Inspect File ↗
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Decision Form */}
            <form onSubmit={submitDecision} className="form" style={{ borderTop: '1px solid var(--color-border)', paddingTop: 'var(--space-4)' }}>
              <h3>Officer Decision</h3>

              <div className="field">
                <label htmlFor="decision-select">Decision Action *</label>
                <select id="decision-select" value={decision} onChange={(e) => setDecision(e.target.value)} required>
                  <option value="verified">Approve Verification (Verified Member)</option>
                  <option value="rejected">Reject Submission (Request Resubmission)</option>
                </select>
              </div>

              {decision === 'verified' && (
                <div className="check-row">
                  <input
                    id="accept-card"
                    type="checkbox"
                    checked={acceptCard}
                    onChange={(e) => setAcceptCard(e.target.checked)}
                  />
                  <label htmlFor="accept-card">
                    Accept verified physical blood donor card (upgrades blood type provenance from self-reported to officer-verified donor card).
                  </label>
                </div>
              )}

              {decision === 'rejected' && (
                <div className="field">
                  <label htmlFor="decision-reason">Rejection Reason / Guidance for Member *</label>
                  <textarea
                    id="decision-reason"
                    placeholder="e.g. Uploaded document is blurry; please upload a clear scan of your government ID."
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    required
                  />
                </div>
              )}

              <div className="button-group" style={{ marginTop: 'var(--space-3)' }}>
                <button type="submit" className="btn" disabled={submitting}>
                  {submitting ? 'Submitting…' : `Submit Decision (${decision.toUpperCase()})`}
                </button>
                <button type="button" className="btn btn-secondary" onClick={() => setDetail(null)}>
                  Cancel
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
    </div>
  )
}
