import { useEffect, useRef } from 'react'
import { ShieldCheck, X } from '@phosphor-icons/react'

export const REGISTRATION_PRIVACY_TITLE = 'Privacy Notice for Account Registration'
export const REGISTRATION_PRIVACY_CHECKBOX_LABEL =
  'I have read and understood the BloodMatch Privacy Notice and acknowledge the processing of my personal information for the purposes stated above.'

export const ID_PRIVACY_TITLE = 'Privacy Notice for Identification Document Submission'
export const ID_PRIVACY_CHECKBOX_LABEL =
  'I have read and understood this Identification Document Privacy Notice and acknowledge the processing of my submitted ID for identity and membership verification.'

export function RegistrationPrivacyBody() {
  return (
    <div className="privacy-body">
      <p>
        Before creating your BloodMatch account, please be informed that BloodMatch will collect and
        process the personal information you provide, such as your name, date of birth, email address,
        contact information, chapter affiliation, and other information required for account creation and
        system use.
      </p>
      <p>Your information will be processed for the following purposes:</p>
      <ul>
        <li>To create and maintain your BloodMatch account.</li>
        <li>To verify and manage your membership information.</li>
        <li>To determine access to features based on your account and verification status.</li>
        <li>To support blood donation coordination and related system functions.</li>
        <li>To maintain system security, records, and audit logs.</li>
      </ul>
      <p>
        Your personal information will only be accessed by authorized BloodMatch personnel when necessary
        for these purposes. Information will not be publicly displayed unless specifically intended by the
        system and permitted under applicable privacy requirements.
      </p>
      <p>
        BloodMatch will implement reasonable safeguards to protect your information against unauthorized
        access, disclosure, alteration, or loss. Your information will be retained only for as long as
        necessary to fulfill the purposes for which it was collected, or as otherwise required or permitted
        by applicable law.
      </p>
      <p>
        As a data subject, you have rights regarding your personal information, including the right to be
        informed, access and correct your information, object to certain processing, and request deletion or
        blocking when applicable under the Data Privacy Act of 2012.
      </p>
      <p>
        For privacy-related questions or requests, please contact the BloodMatch administrator or designated
        Data Protection Officer through the official contact details provided by the organization.
      </p>
    </div>
  )
}

export function IdPrivacyBody() {
  return (
    <div className="privacy-body">
      <p>
        BloodMatch collects your identification document for the purpose of verifying your identity and
        membership information before granting access to verification-dependent features.
      </p>
      <p>
        Your uploaded ID may contain sensitive personal information, including government-issued
        identification details. The document will be processed only for identity and membership verification
        and related account verification activities.
      </p>
      <p>Your uploaded document will:</p>
      <ul>
        <li>Be accessible only to authorized BloodMatch personnel responsible for verification.</li>
        <li>Not be publicly displayed to other BloodMatch users.</li>
        <li>Not be used for purposes unrelated to the stated verification process.</li>
        <li>
          Be protected using appropriate security measures against unauthorized access, disclosure,
          alteration, or loss.
        </li>
      </ul>
      <p>
        The information contained in your ID will be retained only for as long as necessary for the declared
        verification purpose and applicable organizational or legal requirements.
      </p>
      <p>
        You have the right to be informed about the processing of your personal information and may exercise
        applicable data subject rights under the Data Privacy Act of 2012.
      </p>
      <p>
        By submitting an identification document, you acknowledge that you have read and understood this
        notice and that your identification document will be processed for the stated verification purpose.
      </p>
    </div>
  )
}

export default function PrivacyNoticeModal({
  open,
  title,
  checkboxLabel,
  checkboxId,
  acknowledged,
  onAcknowledgeChange,
  onClose,
  onConfirm,
  confirmLabel = 'Continue',
  Body
}) {
  const closeRef = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    closeRef.current?.focus()
    const onKey = (e) => {
      if (e.key === 'Escape') onClose?.()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-labelledby="privacy-notice-title"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose?.()
      }}
    >
      <div className="modal-content privacy-modal" style={{ maxWidth: '640px' }}>
        <div className="privacy-modal-header">
          <div className="privacy-modal-icon" aria-hidden="true">
            <ShieldCheck size={20} weight="regular" />
          </div>
          <div style={{ flex: 1 }}>
            <div className="kicker" style={{ marginBottom: 'var(--space-1)' }}>Required privacy notice</div>
            <h2 id="privacy-notice-title" style={{ marginBottom: 0 }}>{title}</h2>
          </div>
          <button
            ref={closeRef}
            type="button"
            className="btn btn-secondary btn-sm privacy-modal-close"
            onClick={onClose}
            aria-label="Close privacy notice"
          >
            <X size={14} weight="regular" aria-hidden="true" />
          </button>
        </div>

        <div className="privacy-scroll" tabIndex={0} aria-label={`${title} full text`}>
          <Body />
        </div>

        <div className="check-row privacy-check">
          <input
            id={checkboxId}
            type="checkbox"
            checked={acknowledged}
            onChange={(e) => onAcknowledgeChange?.(e.target.checked)}
          />
          <label htmlFor={checkboxId}>{checkboxLabel}</label>
        </div>

        <div className="button-group" style={{ marginTop: 'var(--space-4)' }}>
          <button type="button" className="btn" disabled={!acknowledged} onClick={onConfirm}>
            {confirmLabel}
          </button>
          <button type="button" className="btn btn-secondary" onClick={onClose}>
            Close
          </button>
        </div>
        {!acknowledged && (
          <p className="field-hint" style={{ marginBottom: 0 }}>
            Please check the acknowledgment above to continue.
          </p>
        )}
      </div>
    </div>
  )
}
