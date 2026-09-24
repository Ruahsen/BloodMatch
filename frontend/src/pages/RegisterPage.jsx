import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ShieldCheck } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import PrivacyNoticeModal, {
  REGISTRATION_PRIVACY_CHECKBOX_LABEL,
  REGISTRATION_PRIVACY_TITLE,
  RegistrationPrivacyBody
} from '../components/PrivacyNoticeModal'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']

export default function RegisterPage() {
  const navigate = useNavigate()
  const [form, setForm] = useState({
    full_name: '',
    email: '',
    password: '',
    chapter_id: '',
    date_of_birth: '',
    phone: '',
    blood_type: ''
  })
  const [chapters, setChapters] = useState([])
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [privacyAck, setPrivacyAck] = useState(false)
  const [privacyModalOpen, setPrivacyModalOpen] = useState(false)
  const [privacyError, setPrivacyError] = useState(null)

  useEffect(() => {
    api
      .get('/api/chapters')
      .then((data) => setChapters(data.chapters || []))
      .catch(() => {})
  }, [])

  const setField = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }))

  const onSubmit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    if (!privacyAck) {
      setPrivacyError('Please read the Privacy Notice and check the acknowledgment to create your account.')
      return
    }
    setPrivacyError(null)
    setSubmitting(true)
    try {
      await api.post('/api/register', {
        ...form,
        chapter_id: Number(form.chapter_id),
        blood_type: form.blood_type || null,
        phone: form.phone || null,
        privacy_acknowledged: true
      })
      navigate('/login', { state: { registered: true } })
    } catch (err) {
      if (err.details && Object.keys(err.details).length > 0) {
        setErrors(err.details)
      } else {
        setMessage(err.message || 'Registration failed.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-6)', textAlign: 'center' }}>
        <h1 style={{ marginBottom: 'var(--space-2)' }}>Create your account</h1>
      </div>

      {message && <div className="alert alert-error" role="alert">{message}</div>}

      <div className="card">
        <form className="form" onSubmit={onSubmit} noValidate>
          <div className="field">
            <label htmlFor="full_name">Full legal name *</label>
            <input
              id="full_name"
              autoComplete="name"
              placeholder="e.g. Maria Santos"
              value={form.full_name}
              onChange={setField('full_name')}
              required
              maxLength={150}
              aria-describedby={errors.full_name ? 'full_name_err' : undefined}
            />
            {errors.full_name && <span id="full_name_err" className="field-error">{errors.full_name.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="email">Email address *</label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="name@example.com"
              value={form.email}
              onChange={setField('email')}
              required
              aria-describedby={errors.email ? 'email_err' : undefined}
            />
            {errors.email && <span id="email_err" className="field-error">{errors.email.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="password">Password *</label>
            <input
              id="password"
              type="password"
              autoComplete="new-password"
              placeholder="At least 8 chars with letters and numbers"
              value={form.password}
              onChange={setField('password')}
              required
              minLength={8}
              aria-describedby="pwd-hint"
            />
            <small id="pwd-hint" className="field-hint">Must contain at least 8 characters, with at least one letter and one number.</small>
            {errors.password && <span className="field-error">{errors.password.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="chapter_id">Assigned Chapter *</label>
            <select
              id="chapter_id"
              value={form.chapter_id}
              onChange={setField('chapter_id')}
              required
              aria-describedby={errors.chapter_id ? 'chapter_err' : undefined}
            >
              <option value="">Select your local chapter…</option>
              {chapters.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.municipality})
                </option>
              ))}
            </select>
            {errors.chapter_id && <span id="chapter_err" className="field-error">{errors.chapter_id.join(' ')}</span>}
          </div>

          <div className="grid-2">
            <div className="field">
              <label htmlFor="date_of_birth">Date of birth *</label>
              <input
                id="date_of_birth"
                type="date"
                autoComplete="bday"
                value={form.date_of_birth}
                onChange={setField('date_of_birth')}
                required
                aria-describedby="dob-hint"
              />
              <small id="dob-hint" className="field-hint">Ages 16–17 require parental consent doc.</small>
              {errors.date_of_birth && <span className="field-error">{errors.date_of_birth.join(' ')}</span>}
            </div>

            <div className="field">
              <label htmlFor="blood_type">Blood type (self-reported)</label>
              <select
                id="blood_type"
                value={form.blood_type}
                onChange={setField('blood_type')}
              >
                <option value="">Unknown / not sure</option>
                {BLOOD_TYPES.map((bt) => (
                  <option key={bt} value={bt}>{bt}</option>
                ))}
              </select>
              <small className="field-hint">Will be marked self-reported until officer verified.</small>
              {errors.blood_type && <span className="field-error">{errors.blood_type.join(' ')}</span>}
            </div>
          </div>

          <div className="field">
            <label htmlFor="phone">Phone number</label>
            <input
              id="phone"
              type="tel"
              autoComplete="tel"
              placeholder="e.g. 0917-123-4567"
              value={form.phone}
              onChange={setField('phone')}
            />
            {errors.phone && <span className="field-error">{errors.phone.join(' ')}</span>}
          </div>

          <div className="privacy-box" aria-labelledby="register-privacy-heading">
            <h3 id="register-privacy-heading" style={{ fontSize: '0.9375rem' }}>
              <ShieldCheck size={16} weight="regular" aria-hidden="true" /> Privacy Notice for Account Registration
            </h3>
            <p className="privacy-summary">
              BloodMatch collects your name, date of birth, email, contact information, chapter affiliation,
              and related details to create and maintain your account, verify membership, manage feature
              access, support donation coordination, and maintain security and audit records under the Data
              Privacy Act of 2012.
            </p>
            <div className="check-row" style={{ background: 'var(--color-surface)' }}>
              <input
                id="register-privacy-ack"
                type="checkbox"
                checked={privacyAck}
                onChange={(e) => {
                  setPrivacyAck(e.target.checked)
                  if (e.target.checked) setPrivacyError(null)
                }}
                aria-describedby="register-privacy-hint"
              />
              <label htmlFor="register-privacy-ack">{REGISTRATION_PRIVACY_CHECKBOX_LABEL}</label>
            </div>
            <p id="register-privacy-hint" className="field-hint" style={{ marginBottom: 0, marginTop: 'var(--space-2)' }}>
              Required to create an account.{' '}
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setPrivacyModalOpen(true)}>
                Read full Privacy Notice
              </button>
            </p>
            {errors.privacy_acknowledged && (
              <span className="field-error">{errors.privacy_acknowledged.join(' ')}</span>
            )}
          </div>

          <button type="submit" className="btn" disabled={submitting || !privacyAck} style={{ marginTop: 'var(--space-2)' }}>
            {submitting ? 'Creating account…' : 'Create account'}
          </button>
          {!privacyAck && (
            <p className="field-hint" role="note" style={{ marginBottom: 0 }}>
              Account creation is enabled after you acknowledge the Privacy Notice.{' '}
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={() => setPrivacyModalOpen(true)}
                style={{ marginLeft: 'var(--space-2)', verticalAlign: 'middle' }}
              >
                Read Privacy Notice
              </button>
            </p>
          )}
          {privacyError && (
            <span className="field-error" role="alert">
              {privacyError}{' '}
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={() => setPrivacyModalOpen(true)}
                style={{ marginLeft: 'var(--space-2)' }}
              >
                Read Privacy Notice
              </button>
            </span>
          )}
        </form>
      </div>

      <PrivacyNoticeModal
        open={privacyModalOpen}
        title={REGISTRATION_PRIVACY_TITLE}
        checkboxLabel={REGISTRATION_PRIVACY_CHECKBOX_LABEL}
        checkboxId="register-privacy-ack-modal"
        acknowledged={privacyAck}
        onAcknowledgeChange={(v) => {
          setPrivacyAck(v)
          if (v) setPrivacyError(null)
        }}
        onClose={() => setPrivacyModalOpen(false)}
        onConfirm={() => setPrivacyModalOpen(false)}
        confirmLabel="Continue"
        Body={RegistrationPrivacyBody}
      />

      <p className="muted text-center" style={{ marginTop: 'var(--space-6)' }}>
        Already have an account? <Link to="/login" style={{ fontWeight: 600 }}>Sign in</Link>
      </p>
    </div>
  )
}
