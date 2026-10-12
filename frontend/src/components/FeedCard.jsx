import React, { useId, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, CaretDown, CheckCircle, Drop, User } from '@phosphor-icons/react'
import { Card } from './ui/Card'
import { Button } from './ui/Button'
import { Badge } from './ui/Badge'
import { BloodTypeBlock } from './ui/BloodTypeBlock'
import ConfirmationDialog from './ConfirmationDialog'
import { useAuth } from '../context/AuthContext'
import { api } from '../services/apiClient'
import styles from './FeedCard.module.css'

function formatNeededDate(value, includeYear = false) {
  if (!value) return 'Date not provided'
  return new Date(value.replace(' ', 'T') + 'Z').toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', ...(includeYear ? { year: 'numeric' } : {})
  })
}

function formatPostedTime(value) {
  if (!value) return 'Posted time not provided'
  const timestamp = new Date(value.replace(' ', 'T') + 'Z').getTime()
  if (!Number.isFinite(timestamp)) return 'Posted time not provided'
  const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000))
  if (seconds < 60) return 'Posted just now'
  const [count, unit] = seconds < 3600 ? [Math.floor(seconds / 60), 'minute']
    : seconds < 86400 ? [Math.floor(seconds / 3600), 'hour'] : [Math.floor(seconds / 86400), 'day']
  return `Posted ${count} ${unit}${count === 1 ? '' : 's'} ago`
}

export function FeedCard({ request, hideActions = false, defaultExpanded = false, showNeededYear = false, children }) {
  const [expanded, setExpanded] = useState(defaultExpanded)
  const [confirmingResponse, setConfirmingResponse] = useState(false)
  const { user } = useAuth()
  const navigate = useNavigate()
  const responseBusyRef = useRef(false)
  const detailsId = useId()
  const reasonId = useId()
  const queryClient = useQueryClient()
  const response = useMutation({
    mutationFn: () => api.post(`/api/requests/${request.id}/respond`),
    onSuccess: () => {
      setConfirmingResponse(false)
      queryClient.invalidateQueries({ queryKey: ['home-feed'] })
      queryClient.invalidateQueries({ queryKey: ['my-responses'] })
      queryClient.invalidateQueries({ queryKey: ['request-matches', request.id] })
      navigate(`/requests/${request.id}/matches`)
    },
    onError: () => queryClient.invalidateQueries({ queryKey: ['home-feed'] }),
    onSettled: () => { responseBusyRef.current = false }
  })
  const responded = request.responded || response.isSuccess
  const isEnrolledNow = Boolean(user && user.donor_enrolled === true)
  const isNotEnrolled = !isEnrolledNow && (request.reason_code === 'not_enrolled' || Boolean(user && user.donor_enrolled === false && user.role === 'member'))
  const canRespond = (request.can_respond === true || (isEnrolledNow && request.reason_code === 'not_enrolled' && user?.verification_status === 'verified')) && !responded
  const confirmResponse = () => {
    if (!canRespond || response.isPending || responseBusyRef.current) return
    responseBusyRef.current = true
    response.mutate()
  }
  const responseReason = responded ? 'Your response has been sent to the requester.' : request.reason_code === 'staff_account' ? null : request.reason_text
  const urgencyVariant = ['emergency', 'critical'].includes(request.urgency)
    ? 'emergency'
    : request.urgency === 'urgent' ? 'urgent' : 'neutral'

  return (
    <Card padding="none" className={styles.feedCard}>
      <article className={styles.cardBody}>
        <button
          type="button"
          className={styles.summaryButton}
          aria-expanded={expanded}
          aria-controls={detailsId}
          onClick={() => setExpanded((current) => !current)}
        >
          <span className={styles.avatar}>
            {request.requester_profile_picture_url ? (
              <img src={request.requester_profile_picture_url} alt="" />
            ) : (
              <User size={22} aria-hidden="true" />
            )}
          </span>

          <span className={styles.identity}>
            <span className={styles.nameRow}>
              <span className={styles.requesterName}>{request.requester_name || 'Community member'}</span>
              {request.requester_verification_status === 'verified' && (
                <CheckCircle size={17} weight="fill" aria-label="Verified member" />
              )}
            </span>
            <span className={styles.chapter}>{request.requester_chapter_name ? `${request.requester_chapter_name} member` : 'Chapter not assigned'}</span>
            <span className={styles.postedTime}>{formatPostedTime(request.created_at)}</span>
          </span>

          <span className={styles.summaryMeta}>
            <Badge variant={urgencyVariant}>{request.urgency === 'critical' ? 'EMERGENCY' : request.urgency.toUpperCase()}</Badge>
            <BloodTypeBlock bloodType={request.required_blood_type} level="normal" />
            <CaretDown className={expanded ? styles.caretOpen : styles.caret} size={20} aria-hidden="true" />
          </span>
        </button>

        {expanded && (
          <div id={detailsId} className={styles.expandedContent}>
            <dl className={styles.metadata}>
              <div className={styles.hospital}>
                <dt>Hospital</dt>
                <dd>{request.facility_name || 'Not provided'}</dd>
              </div>
              <div>
                <dt>Municipality</dt>
                <dd>{request.location?.municipality_name || 'Not provided'}</dd>
              </div>
              <div>
                <dt>Blood needed</dt>
                <dd>{request.quantity_units} {request.quantity_units === 1 ? 'unit' : 'units'}</dd>
              </div>
              <div>
                <dt>Needed by</dt>
                <dd>{formatNeededDate(request.needed_datetime, showNeededYear)}</dd>
              </div>
            </dl>

            {!hideActions && (
              <div>
                <footer className={styles.actions}>
                  <Button type="button" variant="primary" disabled={(!canRespond && !isNotEnrolled) || response.isPending}
                    isLoading={response.isPending} aria-describedby={responseReason ? reasonId : undefined}
                    title={isNotEnrolled ? 'Enroll as donor to respond.' : undefined}
                    onClick={() => {
                      if (isNotEnrolled) {
                        navigate('/profile')
                        return
                      }
                      response.reset()
                      setConfirmingResponse(true)
                    }}>
                    {responded ? 'Responded' : 'Respond'}
                  </Button>
                  <Button className={styles.viewRequest} variant="secondary" to={`/requests/${request.id}/matches`}>View request</Button>
                </footer>
                {isNotEnrolled ? (
                  <div className={styles.enrollPrompt} role="region" aria-label="Donor enrollment required">
                    <div className={styles.enrollPromptContent}>
                      <span className={styles.enrollPromptIcon} aria-hidden="true">
                        <Drop size={18} weight="fill" />
                      </span>
                      <div className={styles.enrollPromptText}>
                        <strong className={styles.enrollPromptTitle}>Enroll as a donor to respond</strong>
                      </div>
                    </div>
                    <button
                      type="button"
                      className={styles.enrollPromptButton}
                      onClick={() => navigate('/profile')}
                      aria-label="Go to profile to enroll as a donor"
                    >
                      <span>Enroll as Donor</span>
                      <ArrowRight size={14} weight="bold" aria-hidden="true" />
                    </button>
                    <p
                      id={reasonId}
                      className={`${styles.responseReason} ${styles.clickableReason}`}
                      role="status"
                      title="Enroll as donor to respond."
                      onClick={() => navigate('/profile')}
                      style={{ display: 'none' }}
                    >
                      {responseReason || 'Enroll as donor to respond.'}
                    </p>
                  </div>
                ) : responseReason ? (
                  <p
                    id={reasonId}
                    className={styles.responseReason}
                    role="status"
                  >
                    {responseReason}
                  </p>
                ) : null}
                {response.isError && <p className={styles.responseReason} role="alert">{response.error.message}</p>}
              </div>
            )}
            {children}
          </div>
        )}
        <ConfirmationDialog open={confirmingResponse} title="Confirm that you can help"
          confirmLabel="Confirm I can help" cancelLabel="Not now" busy={response.isPending} confirmDisabled={!canRespond}
          error={response.isError ? response.error.message : undefined}
          onConfirm={confirmResponse} onCancel={() => setConfirmingResponse(false)}>
          <p>Your response will be shared with the requester for this {request.required_blood_type} request at {request.facility_name}. The receiving facility makes the final medical eligibility decision.</p>
          {!canRespond && <p role="status">{responseReason || 'This request is no longer available for a response.'}</p>}
        </ConfirmationDialog>
      </article>
    </Card>
  )
}
