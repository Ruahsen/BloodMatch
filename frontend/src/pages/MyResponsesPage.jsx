import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowRight,
  CalendarBlank,
  CheckCircle,
  Clock,
  Drop,
  HandHeart,
  MapPin,
  User,
  X,
  ChatCircleDots
} from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'
import { useChat } from '../context/ChatContext'
import { LoadingSpinner } from '../components/ui/LoadingSpinner'
import { Button } from '../components/ui/Button'
import { Badge } from '../components/ui/Badge'
import { BloodTypeBlock } from '../components/ui/BloodTypeBlock'
import ConfirmationDialog from '../components/ConfirmationDialog'
import styles from './MyResponsesPage.module.css'

function formatDate(value) {
  if (!value) return 'Not provided'
  return new Date(value.replace(' ', 'T') + 'Z').toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  })
}

function RequesterAvatar({ src, name }) {
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    setFailed(false)
  }, [src])

  if (!src || failed) {
    return <User size={22} aria-hidden="true" />
  }

  return (
    <img
      src={src}
      alt={name ? `${name}'s profile picture` : ''}
      onError={() => setFailed(true)}
    />
  )
}

export default function MyResponsesPage() {
  const { user } = useAuth()
  const { openChatWithUser } = useChat()
  const [successMessage, setSuccessMessage] = useState(null)
  const [errorMessage, setErrorMessage] = useState(null)
  const [actionLoading, setActionLoading] = useState(false)
  const [confirmWithdrawMatchId, setConfirmWithdrawMatchId] = useState(null)
  const [reportingMatchId, setReportingMatchId] = useState(null)
  const [reportNote, setReportNote] = useState('')
  const [submittingReport, setSubmittingReport] = useState(false)

  const {
    data: responses = [],
    isLoading,
    error,
    refetch
  } = useQuery({
    queryKey: ['my-responses', user?.id],
    queryFn: async () => (await api.get('/api/my/responses')).responses || [],
    enabled: Boolean(user?.id)
  })

  useEffect(() => {
    if (!successMessage) return undefined
    const timer = window.setTimeout(() => setSuccessMessage(null), 5000)
    return () => window.clearTimeout(timer)
  }, [successMessage])

  const handleWithdraw = async () => {
    if (!confirmWithdrawMatchId) return
    setActionLoading(true)
    setErrorMessage(null)
    setSuccessMessage(null)
    try {
      await api.post(`/api/matches/${confirmWithdrawMatchId}/withdraw`)
      setConfirmWithdrawMatchId(null)
      setSuccessMessage('Your offer to help has been cancelled. You can offer again while the request remains open.')
      await refetch()
    } catch (err) {
      setErrorMessage(err.message || 'Could not cancel your offer to help.')
    } finally {
      setActionLoading(false)
    }
  }

  const handleSubmitReport = async (event) => {
    event.preventDefault()
    if (!reportingMatchId) return
    setSubmittingReport(true)
    setErrorMessage(null)
    setSuccessMessage(null)
    try {
      await api.post('/api/donation-reports', {
        match_id: reportingMatchId,
        note: reportNote || null
      })
      setSuccessMessage('Donation report sent for chapter officer confirmation.')
      setReportingMatchId(null)
      setReportNote('')
      await refetch()
    } catch (err) {
      setErrorMessage(err.message || 'Could not send donation report.')
    } finally {
      setSubmittingReport(false)
    }
  }

  if (isLoading) {
    return (
      <div className={styles.container}>
        <LoadingSpinner text="Loading your responses…" minHeight="24rem" />
      </div>
    )
  }

  if (error) {
    return (
      <div className={styles.container}>
        <div className="alert alert-error" role="alert" style={{ marginBottom: 'var(--space-4)' }}>
          <p>{error.message || 'Could not load your responses.'}</p>
        </div>
        <Button variant="secondary" onClick={() => refetch()}>Try again</Button>
      </div>
    )
  }

  return (
    <div className={styles.container}>
      <header className={styles.pageHeader}>
        <h1 className={styles.pageTitle}>My Responses</h1>
        <p className={styles.pageSubtitle}>
          Blood requests you have offered to help with as a donor. Revisit and manage your commitments here.
        </p>
      </header>

      {successMessage && (
        <div className="alert alert-success" role="status" style={{ marginBottom: 'var(--space-4)' }}>
          {successMessage}
        </div>
      )}

      {errorMessage && (
        <div className="alert alert-error" role="alert" style={{ marginBottom: 'var(--space-4)' }}>
          {errorMessage}
        </div>
      )}

      {responses.length === 0 ? (
        <section className={styles.emptyState}>
          <div className={styles.emptyIcon} aria-hidden="true">
            <HandHeart size={36} weight="regular" />
          </div>
          <h2>You haven't responded to any blood requests yet</h2>
          <p>
            When you offer to help with compatible blood requests from the Home feed, they will appear here so you can easily track and manage them.
          </p>
          <div className={styles.emptyActions}>
            <Button to="/" variant="primary">Browse compatible requests</Button>
          </div>
        </section>
      ) : (
        <div className={styles.responseList}>
          {responses.map((req) => {
            const urgencyLevel = req.urgency === 'emergency' ? 'emergency' : req.urgency === 'urgent' ? 'urgent' : 'normal'
            const isCompleted = req.match_status === 'COMPLETED'
            const isResponded = req.match_status === 'RESPONDED'

            return (
              <article key={req.id} className={styles.responseCard} aria-label={`Response for ${req.required_blood_type} blood request at ${req.facility_name}`}>
                <div className={styles.cardHeader}>
                  <div className={styles.requesterInfo}>
                    <div className={styles.avatar}>
                      <RequesterAvatar src={req.requester_profile_picture_url} name={req.requester_name} />
                    </div>
                    <div className={styles.requesterMeta}>
                      <div className={styles.requesterNameLine}>
                        <h2 className={styles.requesterName}>{req.requester_name || 'Community Member'}</h2>
                        {req.requester_verification_status === 'verified' && (
                          <CheckCircle size={16} weight="fill" className={styles.verifiedIcon} aria-label="Verified account" />
                        )}
                      </div>
                      <p className={styles.requestDate}>
                        {req.requester_chapter_name ? `${req.requester_chapter_name} • ` : ''}Needed {formatDate(req.needed_datetime)}
                      </p>
                    </div>
                  </div>

                  <div className={styles.statusBadges}>
                    <Badge variant={req.urgency === 'emergency' ? 'emergency' : req.urgency === 'urgent' ? 'urgent' : 'neutral'}>
                      {req.urgency}
                    </Badge>
                    <Badge variant={isCompleted ? 'success' : isResponded ? 'brand' : 'neutral'}>
                      {req.match_status || 'RESPONDED'}
                    </Badge>
                  </div>
                </div>

                <div className={styles.cardBody}>
                  <div className={styles.bloodBlockWrap}>
                    <BloodTypeBlock bloodType={req.required_blood_type} level={urgencyLevel} />
                  </div>

                  <dl className={styles.factsGrid}>
                    <div className={styles.factItem}>
                      <dt className={styles.factLabel}>
                        <Drop size={14} weight="fill" /> Blood needed
                      </dt>
                      <dd className={styles.factValue}>
                        {req.quantity_units} {req.quantity_units === 1 ? 'unit' : 'units'} ({req.required_blood_type})
                      </dd>
                    </div>

                    <div className={styles.factItem}>
                      <dt className={styles.factLabel}>
                        <MapPin size={14} /> Facility
                      </dt>
                      <dd className={styles.factValue} title={`${req.facility_name}, ${req.location?.municipality_name || 'Bataan'}`}>
                        {req.facility_name}
                      </dd>
                    </div>

                    <div className={styles.factItem}>
                      <dt className={styles.factLabel}>
                        <CalendarBlank size={14} /> Needed by
                      </dt>
                      <dd className={styles.factValue}>
                        {formatDate(req.needed_datetime)}
                      </dd>
                    </div>

                    <div className={styles.factItem}>
                      <dt className={styles.factLabel}>
                        <Clock size={14} /> Request status
                      </dt>
                      <dd className={styles.factValue}>
                        {req.status}
                      </dd>
                    </div>
                  </dl>
                </div>

                <div className={styles.cardActions}>
                  <div className={styles.primaryAction} style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap', alignItems: 'center' }}>
                    <Button to={`/profile/${req.requester_id}`} variant="secondary" size="sm">
                      View profile
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={() => openChatWithUser(req.requester_id, { name: req.requester_name || 'Requester' })}
                      style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                    >
                      <ChatCircleDots size={16} weight="regular" /> Message
                    </Button>
                  </div>

                  <div className={styles.secondaryActions}>
                    {isResponded && req.match_id && (
                      <Button size="sm" onClick={() => setReportingMatchId(req.match_id)}>
                        Report completed donation
                      </Button>
                    )}
                    {isResponded && req.match_id && (
                      <Button
                        variant="secondary"
                        size="sm"
                        className={styles.cancelOffer}
                        onClick={() => setConfirmWithdrawMatchId(req.match_id)}
                      >
                        <X size={14} style={{ marginRight: '4px' }} /> Cancel help
                      </Button>
                    )}
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      )}

      {/* Confirmation dialog for canceling an offer to help */}
      <ConfirmationDialog
        open={Boolean(confirmWithdrawMatchId)}
        title="Cancel your offer to help?"
        confirmLabel="Confirm cancellation"
        cancelLabel="Not now"
        destructive
        busy={actionLoading}
        onConfirm={handleWithdraw}
        onCancel={() => setConfirmWithdrawMatchId(null)}
      >
        The requester will no longer see you as a responding donor. You can offer again while this request remains open.
      </ConfirmationDialog>

      {/* Modal dialog for reporting a completed donation */}
      {reportingMatchId && (
        <div className={styles.modalBackdrop} role="presentation" onMouseDown={(e) => { if (e.target === e.currentTarget && !submittingReport) setReportingMatchId(null) }}>
          <form
            className={styles.modalContent}
            onSubmit={handleSubmitReport}
            role="dialog"
            aria-modal="true"
            aria-labelledby="report-modal-title"
          >
            <h2 id="report-modal-title">Report completed donation</h2>
            <p>Your report will be reviewed by a chapter officer before the donation is formally confirmed.</p>
            <label htmlFor="response-report-note">Optional note</label>
            <textarea
              id="response-report-note"
              value={reportNote}
              onChange={(e) => setReportNote(e.target.value)}
              placeholder="e.g. Donated at facility blood bank, 1 bag provided"
              maxLength={500}
              rows={4}
            />
            <div className={styles.modalActions}>
              <Button type="button" variant="secondary" disabled={submittingReport} onClick={() => setReportingMatchId(null)}>
                Cancel
              </Button>
              <Button type="submit" isLoading={submittingReport}>
                Send report
              </Button>
            </div>
          </form>
        </div>
      )}
    </div>
  )
}
