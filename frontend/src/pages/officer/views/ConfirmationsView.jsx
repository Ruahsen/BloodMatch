import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Check } from '@phosphor-icons/react'
import { api } from '../../../services/apiClient'
import { LoadingSpinner } from '../../../components/ui/LoadingSpinner'
import ConfirmationDialog from '../../../components/ConfirmationDialog'
import { normalizeRejectionReason, rejectionReasonError } from '../../../services/rejectionReason'
import { formatPhilippineDateTime } from '../../../services/dateTime'
import styles from './ConfirmationsView.module.css'

export default function ConfirmationsView() {
  const [queue, setQueue] = useState(null)
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [processingId, setProcessingId] = useState(null)
  const processingRef = useRef(false)
  const [rejectTarget, setRejectTarget] = useState(null)
  const [rejectionReason, setRejectionReason] = useState('')
  const [rejectionError, setRejectionError] = useState(null)
  const [sortOrder, setSortOrder] = useState('oldest')
  const reasonId = useId()
  const validationError = rejectionReasonError(rejectionReason)

  const load = useCallback((sort = sortOrder) => {
    return api
      .get(`/api/officer/donation-reports?sort=${sort}`)
      .then((data) => setQueue(data.pending_reports || []))
      .catch((err) => setErrorAlert(err.message))
  }, [sortOrder])

  useEffect(() => {
    load()
  }, [load])

  const sortedQueue = useMemo(() => {
    if (!queue) return []
    return [...queue].sort((a, b) => {
      const timeA = new Date(a.reported_at ? a.reported_at.replace(' ', 'T') + 'Z' : 0).getTime()
      const timeB = new Date(b.reported_at ? b.reported_at.replace(' ', 'T') + 'Z' : 0).getTime()
      if (timeA !== timeB) {
        return sortOrder === 'oldest' ? timeA - timeB : timeB - timeA
      }
      return sortOrder === 'oldest' ? a.id - b.id : b.id - a.id
    })
  }, [queue, sortOrder])

  const decide = async (reportId, action) => {
    if (processingRef.current) return
    if (action === 'reject' && validationError) { setRejectionError(validationError); return }
    processingRef.current = true
    setMessage(null)
    setErrorAlert(null)
    setProcessingId(reportId)
    try {
      await api.post(`/api/officer/donation-reports/${reportId}/${action}`,
        action === 'reject' ? { rejection_reason: normalizeRejectionReason(rejectionReason) } : {})
      setMessage(`Donation report #${reportId} has been ${action === 'confirm' ? 'CONFIRMED' : 'REJECTED'}.`)
      if (action === 'reject') { setRejectTarget(null); setRejectionReason(''); setRejectionError(null) }
      await load()
    } catch (err) {
      if (action === 'reject') setRejectionError(err.details?.rejection_reason?.[0] || err.message)
      else setErrorAlert(err.message)
      await load()
    } finally {
      processingRef.current = false
      setProcessingId(null)
    }
  }

  if (!queue) {
    return (
      <div className="container">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <LoadingSpinner text="Loading donation confirmation queue…" />
        </div>
      </div>
    )
  }

  return (
    <div className="container">
      <header className="app-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-3)' }}>
        <div>
          <h1 style={{ margin: 0 }}>Donation Confirmations: Chapter Queue</h1>
        </div>
        {queue.length > 0 && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <label htmlFor="confirmations-sort-order" style={{ fontSize: 'var(--text-sm)', fontWeight: 600, color: 'var(--color-text-secondary)', whiteSpace: 'nowrap' }}>
              Sort:
            </label>
            <select
              id="confirmations-sort-order"
              value={sortOrder}
              aria-label="Sort donation confirmations"
              onChange={(e) => {
                const newSort = e.target.value
                setSortOrder(newSort)
                load(newSort)
              }}
              className="select"
              style={{
                minHeight: '38px',
                padding: 'var(--space-1) var(--space-3)',
                borderRadius: 'var(--radius-control)',
                border: '1px solid var(--color-border)',
                background: 'var(--color-surface)',
                color: 'var(--color-text)',
                fontSize: 'var(--text-sm)',
                fontWeight: 500,
                cursor: 'pointer'
              }}
            >
              <option value="oldest">Oldest to Newest</option>
              <option value="newest">Newest to Oldest</option>
            </select>
          </div>
        )}
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
          {sortedQueue.map((r) => (
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
                    <p style={{ margin: 0 }}>{formatPhilippineDateTime(r.reported_at)}</p>
                  </div>
                </div>

                {r.report_note && (
                  <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)', fontSize: '0.875rem' }}>
                    <span className="muted" style={{ fontWeight: 600 }}>Donor Note:</span> {r.report_note}
                  </div>
                )}
                {r.can_confirm !== true && <p className={styles.closedNotice}>{r.can_reject === false ? 'You cannot review your own report.' : 'This report can no longer be confirmed. An authorized reviewer must reject it with a reason.'}</p>}

                <div className="button-group" style={{ marginTop: 'var(--space-3)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
                  <button
                    type="button"
                    className="btn"
                    disabled={processingId !== null || r.can_confirm !== true}
                    onClick={() => decide(r.id, 'confirm')}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                  >
                    {processingId === r.id ? 'Processing…' : <><Check size={14} weight="regular" aria-hidden="true" /> Confirm Completed Donation</>}
                  </button>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={processingId !== null || r.can_reject !== true}
                    onClick={() => { setRejectTarget(r); setRejectionReason(''); setRejectionError(null) }}
                  >
                    Reject Report
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
      <ConfirmationDialog open={Boolean(rejectTarget)} title="Reject this donation report?"
        confirmLabel="Reject report" cancelLabel="Keep report" destructive busy={processingId !== null}
        confirmDisabled={Boolean(validationError)} error={rejectionError}
        onConfirm={() => rejectTarget && decide(rejectTarget.id, 'reject')}
        onCancel={() => { if (!processingRef.current) setRejectTarget(null) }}>
        <div className={styles.reasonField}>
          <p>Explain why this report cannot be confirmed. The donor will see this reason in their history. Avoid unnecessary personal or medical details.</p>
          <label htmlFor={reasonId}>Rejection reason</label>
          <textarea id={reasonId} rows={4} required disabled={processingId !== null}
            value={rejectionReason} aria-describedby={`${reasonId}-hint`}
            aria-invalid={Boolean(rejectionError || (rejectionReason && validationError))}
            onChange={(event) => { setRejectionReason(event.target.value); setRejectionError(null) }} />
          <p id={`${reasonId}-hint`} className={styles.reasonHint}>Required, up to 500 characters. {Array.from(normalizeRejectionReason(rejectionReason)).length}/500</p>
          {rejectionReason && validationError && <p role="alert" className={styles.reasonError}>{validationError}</p>}
        </div>
      </ConfirmationDialog>
    </div>
  )
}
