import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, Circle, PencilSimple, ShieldCheck } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { setPendingDocument } from '../services/pendingDocument'
import PrivacyNoticeModal, {
  ID_PRIVACY_CHECKBOX_LABEL,
  ID_PRIVACY_TITLE,
  IdPrivacyBody,
  REGISTRATION_PRIVACY_CHECKBOX_LABEL,
  REGISTRATION_PRIVACY_TITLE,
  RegistrationPrivacyBody
} from '../components/PrivacyNoticeModal'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const DOC_TYPES = [
  { id: 'national_id', label: 'Government-Issued ID (National ID, Passport, Driver\'s License)' },
  { id: 'donor_card', label: 'Official Blood Donor Card (Philippine Red Cross / DOH)' },
  { id: 'parental_consent', label: 'Parental / Guardian Consent Form (Ages 16 to 17)' }
]
const ID_ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf'
const ID_MAX_BYTES = 5242880

const STEPS = [
  { n: 1, label: 'Account' },
  { n: 2, label: 'Details' },
  { n: 3, label: 'Review' }
]

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_RE = /^[0-9+\-\s()]{5,30}$/

function isPasswordStrong(pw) {
  return pw.length >= 8 && pw.length <= 72 && /[A-Za-z]/.test(pw) && /\d/.test(pw)
}

function isDobPlausible(dob) {
  if (!dob) return false
  const dt = new Date(`${dob}T00:00:00Z`)
  if (Number.isNaN(dt.getTime())) return false
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  if (dt > today) return false
  return Number(dob.slice(0, 4)) >= 1900
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  return `${Math.ceil(bytes / 1024)} KB`
}

function composeFullName(first, middle, last) {
  return [first.trim(), middle.trim(), last.trim()].filter(Boolean).join(' ').replace(/\s+/g, ' ')
}

function StepIndicator({ step }) {
  return (
    <ol className="reg-steps" aria-label="Registration progress">
      {STEPS.map((s, i) => {
        const done = s.n < step
        const current = s.n === step
        return (
          <li
            key={s.n}
            className={`reg-step${current ? ' is-current' : ''}${done ? ' is-done' : ''}`}
            aria-current={current ? 'step' : undefined}
          >
            <span className="reg-dot" aria-hidden="true">
              {done ? <Check size={13} weight="bold" /> : <span className="reg-num">{s.n}</span>}
            </span>
            <span className="reg-step-text">
              <span className="reg-step-label">{s.label}</span>
            </span>
            {i < STEPS.length - 1 && <span className="reg-link" aria-hidden="true" />}
          </li>
        )
      })}
    </ol>
  )
}

function ReqList({ items }) {
  return (
    <ul className="req-list" aria-live="polite" aria-label="What is still needed to continue">
      {items.map((r) => (
        <li key={r.label} className={r.met ? 'is-met' : 'is-unmet'}>
          <span className="req-dot" aria-hidden="true">
            {r.met ? <Check size={11} weight="bold" /> : <Circle size={11} weight="regular" />}
          </span>
          <span>{r.label}</span>
          <span className="sr-only">{r.met ? 'done' : 'still needed'}</span>
        </li>
      ))}
    </ul>
  )
}

export default function RegisterPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState(1)
  const stepHeadingRef = useRef(null)

  const [s1, setS1] = useState({
    firstName: '',
    lastName: '',
    middleName: '',
    email: '',
    password: '',
    confirmPassword: '',
    chapterId: ''
  })
  const [privacyAck, setPrivacyAck] = useState(false)
  const [privacyModalOpen, setPrivacyModalOpen] = useState(false)
  const [privacyError, setPrivacyError] = useState(null)

  const [s2, setS2] = useState({ dob: '', bloodType: '', phone: '', docType: 'national_id' })
  const [idFile, setIdFile] = useState(null)
  const [idFileError, setIdFileError] = useState(null)
  const [idPrivacyAck, setIdPrivacyAck] = useState(false)
  const [idPrivacyModalOpen, setIdPrivacyModalOpen] = useState(false)
  const [idPrivacyError, setIdPrivacyError] = useState(null)
  const [fileInputKey, setFileInputKey] = useState(0)

  const [chapters, setChapters] = useState([])
  const [chaptersError, setChaptersError] = useState(false)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    api
      .get('/api/chapters')
      .then((data) => {
        setChapters(data.chapters || [])
        setChaptersError(false)
      })
      .catch(() => setChaptersError(true))
  }, [])

  useEffect(() => {
    stepHeadingRef.current?.focus()
  }, [step])

  const setS1Field = (name) => (e) => setS1((f) => ({ ...f, [name]: e.target.value }))
  const setS2Field = (name) => (e) => setS2((f) => ({ ...f, [name]: e.target.value }))

  const fullName = useMemo(
    () => composeFullName(s1.firstName, s1.middleName, s1.lastName),
    [s1.firstName, s1.middleName, s1.lastName]
  )

  const step1Reqs = useMemo(() => [
    { label: 'First and last name entered', met: !!s1.firstName.trim() && !!s1.lastName.trim() && fullName.length >= 2 && fullName.length <= 150 },
    { label: 'Valid email address', met: EMAIL_RE.test(s1.email.trim()) },
    { label: 'Password with 8 or more characters, a letter and a number', met: isPasswordStrong(s1.password) },
    { label: 'Passwords match', met: !!s1.confirmPassword && s1.confirmPassword === s1.password },
    { label: 'Chapter selected', met: !!s1.chapterId },
    { label: 'Privacy Notice acknowledged', met: privacyAck }
  ], [s1, fullName, privacyAck])

  const step1Valid = step1Reqs.every((r) => r.met)

  const step2Reqs = useMemo(() => [
    { label: 'Valid date of birth', met: isDobPlausible(s2.dob) },
    { label: 'Blood type selected', met: !!s2.bloodType },
    { label: 'Valid phone number', met: PHONE_RE.test(s2.phone.trim()) },
    { label: 'ID privacy acknowledged', met: idPrivacyAck },
    { label: 'Valid ID attached', met: !!idFile && !idFileError }
  ], [s2, idPrivacyAck, idFile, idFileError])

  const step2Valid = step2Reqs.every((r) => r.met)

  const chapterName = useMemo(() => {
    const c = chapters.find((x) => String(x.id) === String(s1.chapterId))
    return c ? `${c.name} (${c.municipality})` : 'Not selected'
  }, [chapters, s1.chapterId])

  const docTypeLabel = useMemo(() => {
    const t = DOC_TYPES.find((x) => x.id === s2.docType)
    return t ? t.label : 'Not selected'
  }, [s2.docType])

  const onPickFile = (e) => {
    const f = e.target.files?.[0] || null
    setIdFileError(null)
    if (!f) {
      setIdFile(null)
      return
    }
    const ext = f.name.split('.').pop()?.toLowerCase()
    const okType = ['jpg', 'jpeg', 'png', 'webp', 'pdf'].includes(ext || '')
    if (!okType) {
      setIdFile(null)
      setIdFileError('File type not accepted. Use JPG, PNG, WEBP, or PDF.')
      return
    }
    if (f.size > ID_MAX_BYTES) {
      setIdFile(null)
      setIdFileError('File exceeds the maximum size of 5 MB.')
      return
    }
    setIdFile(f)
  }

  const clearFile = () => {
    setIdFile(null)
    setIdFileError(null)
    setFileInputKey((k) => k + 1)
  }

  const goNextFrom1 = () => {
    setPrivacyError(null)
    if (!step1Valid) return
    setErrors({})
    setMessage(null)
    setStep(2)
  }

  const goNextFrom2 = () => {
    setIdPrivacyError(null)
    if (!idPrivacyAck) {
      setIdPrivacyError('Please read the Identification Document Privacy Notice and check the acknowledgment before continuing.')
      return
    }
    if (!step2Valid) return
    setErrors({})
    setMessage(null)
    setStep(3)
  }

  const onSubmit = async () => {
    setErrors({})
    setMessage(null)
    if (!privacyAck) {
      setPrivacyError('Please read the Privacy Notice and check the acknowledgment inside it to create your account.')
      setStep(1)
      return
    }
    if (!idPrivacyAck) {
      setIdPrivacyError('Please read the Identification Document Privacy Notice and check the acknowledgment before continuing.')
      setStep(2)
      return
    }
    setSubmitting(true)
    try {
      const data = await api.post('/api/register', {
        full_name: fullName,
        email: s1.email.trim(),
        password: s1.password,
        chapter_id: Number(s1.chapterId),
        date_of_birth: s2.dob,
        phone: s2.phone.trim(),
        blood_type: s2.bloodType,
        privacy_acknowledged: true
      })
      if (idFile) {
        setPendingDocument(idFile, s2.docType)
      }
      const otp = data?.email_otp
      if (otp?.verification_token) {
        try {
          sessionStorage.setItem('bloodmatch_email_otp_claim', otp.verification_token)
        } catch {
          // Storage unavailable (private mode): the page still works when
          // reached directly after this navigation via location state.
        }
        navigate('/verify-email', {
          state: {
            registered: true,
            maskedEmail: otp.masked_email || null,
            delivered: otp.delivered !== false,
            expiresIn: otp.expires_in_seconds || 600,
            pendingIdDoc: !!idFile,
            pendingIdName: idFile?.name || null
          }
        })
      } else {
        navigate('/login', { state: { registered: true } })
      }
    } catch (err) {
      const details = err.details && Object.keys(err.details).length > 0 ? err.details : null
      if (details) {
        setErrors(details)
        if (details.full_name || details.email || details.password || details.chapter_id || details.privacy_acknowledged) {
          setStep(1)
        } else if (details.date_of_birth || details.blood_type || details.phone) {
          setStep(2)
        }
        if (details.privacy_acknowledged) {
          setPrivacyError(details.privacy_acknowledged.join(' '))
        }
      } else {
        setMessage(err.message || 'Registration failed.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-5)', textAlign: 'center' }}>
        <div className="reg-kicker" style={{ marginBottom: 'var(--space-2)' }}>Step {step} of 3</div>
        <h1 ref={stepHeadingRef} tabIndex={-1} style={{ marginBottom: 0, outline: 'none' }}>
          {step === 1 ? 'Create your account' : step === 2 ? 'Personal details and ID' : 'Review your details'}
        </h1>
      </div>

      <StepIndicator step={step} />

      {message && <div className="alert alert-error" role="alert">{message}</div>}

      <div className="card">
        {step === 1 && (
          <div className="form">
            <div className="grid-2">
              <div className="field">
                <label htmlFor="first_name">First name</label>
                <input
                  id="first_name"
                  autoComplete="given-name"
                  placeholder="Maria"
                  value={s1.firstName}
                  onChange={setS1Field('firstName')}
                  maxLength={80}
                />
              </div>
              <div className="field">
                <label htmlFor="last_name">Last name</label>
                <input
                  id="last_name"
                  autoComplete="family-name"
                  placeholder="Santos"
                  value={s1.lastName}
                  onChange={setS1Field('lastName')}
                  maxLength={80}
                />
              </div>
            </div>
            {(errors.full_name) && <span className="field-error">{errors.full_name.join(' ')}</span>}

            <div className="field">
              <label htmlFor="middle_name">Middle name <span className="optional">Optional</span></label>
              <input
                id="middle_name"
                autoComplete="additional-name"
                placeholder="Dela Cruz"
                value={s1.middleName}
                onChange={setS1Field('middleName')}
                maxLength={80}
              />
            </div>

            <div className="field">
              <label htmlFor="email">Email address</label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="name@example.com"
                value={s1.email}
                onChange={setS1Field('email')}
                aria-describedby={errors.email ? 'email_err' : undefined}
              />
              {errors.email && <span id="email_err" className="field-error">{errors.email.join(' ')}</span>}
            </div>

            <div className="grid-2">
              <div className="field">
                <label htmlFor="password">Password</label>
                <input
                  id="password"
                  type="password"
                  autoComplete="new-password"
                  placeholder="Letters and numbers"
                  value={s1.password}
                  onChange={setS1Field('password')}
                  aria-describedby="pwd-hint"
                />
                {errors.password && <span className="field-error">{errors.password.join(' ')}</span>}
              </div>
              <div className="field">
                <label htmlFor="confirm_password">Confirm password</label>
                <input
                  id="confirm_password"
                  type="password"
                  autoComplete="new-password"
                  placeholder="Repeat your password"
                  value={s1.confirmPassword}
                  onChange={setS1Field('confirmPassword')}
                />
              </div>
            </div>
            <small id="pwd-hint" className="field-hint" style={{ marginTop: 'calc(0px - var(--space-4))' }}>
              Use at least 8 characters, with at least one letter and one number.
              {s1.password && s1.confirmPassword && s1.confirmPassword !== s1.password
                ? ' Passwords do not match yet.'
                : ''}
            </small>

            <div className="field">
              <label htmlFor="chapter_id">Assigned chapter</label>
              <select
                id="chapter_id"
                value={s1.chapterId}
                onChange={setS1Field('chapterId')}
                aria-describedby={errors.chapter_id ? 'chapter_err' : undefined}
              >
                <option value="">Select your local chapter</option>
                {chapters.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} ({c.municipality})
                  </option>
                ))}
              </select>
              {errors.chapter_id && <span id="chapter_err" className="field-error">{errors.chapter_id.join(' ')}</span>}
              {chaptersError && (
                <span className="field-error" role="alert">
                  Could not load the chapter list. Make sure the backend is running, then reload this page.
                </span>
              )}
            </div>

            <div className="privacy-box" aria-labelledby="reg-privacy-heading">
              <h4 id="reg-privacy-heading" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)', fontSize: '0.9375rem' }}>
                <ShieldCheck size={16} weight="regular" aria-hidden="true" /> Account registration privacy
              </h4>
              <p className="privacy-summary" style={{ marginBottom: 'var(--space-3)' }}>
                {privacyAck
                  ? 'Privacy Notice acknowledged. You can re-read the full notice any time.'
                  : 'We collect your name, email, and chapter to create and maintain your account. Please read the notice, then acknowledge it inside to continue.'}
              </p>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setPrivacyModalOpen(true)}>
                Read Privacy Notice
              </button>
              {errors.privacy_acknowledged && (
                <span className="field-error" style={{ marginTop: 'var(--space-2)' }}>{errors.privacy_acknowledged.join(' ')}</span>
              )}
              {privacyError && (
                <span className="field-error" role="alert" style={{ marginTop: 'var(--space-2)' }}>
                  {privacyError}
                </span>
              )}
            </div>

            <div className="button-group" style={{ marginTop: 'var(--space-2)' }}>
              <button type="button" className="btn" disabled={!step1Valid} onClick={goNextFrom1} title={step1Valid ? 'Continue to personal details' : 'Fill in all account information and acknowledge the Privacy Notice to continue'}>
                Next <ArrowRight size={14} weight="regular" aria-hidden="true" />
              </button>
            </div>
            {!step1Valid && (
              <ReqList items={step1Reqs} />
            )}
          </div>
        )}

        {step === 2 && (
          <div className="form">
            <div className="grid-2">
              <div className="field">
                <label htmlFor="date_of_birth">Date of birth</label>
                <input
                  id="date_of_birth"
                  type="date"
                  autoComplete="bday"
                  value={s2.dob}
                  onChange={setS2Field('dob')}
                  aria-describedby="dob-hint"
                />
                {errors.date_of_birth && <span className="field-error">{errors.date_of_birth.join(' ')}</span>}
              </div>
              <div className="field">
                <label htmlFor="blood_type">Blood type</label>
                <select id="blood_type" value={s2.bloodType} onChange={setS2Field('bloodType')}>
                  <option value="">Select blood type</option>
                  {BLOOD_TYPES.map((bt) => (
                    <option key={bt} value={bt}>{bt}</option>
                  ))}
                </select>
                {errors.blood_type && <span className="field-error">{errors.blood_type.join(' ')}</span>}
              </div>
            </div>
            <small id="dob-hint" className="field-hint" style={{ marginTop: 'calc(0px - var(--space-4))' }}>
              Ages 16 to 17 require a parental consent document, submitted after registration in your profile.
            </small>

            <div className="field">
              <label htmlFor="phone">Phone number</label>
              <input
                id="phone"
                type="tel"
                autoComplete="tel"
                placeholder="0917-123-4567"
                value={s2.phone}
                onChange={setS2Field('phone')}
              />
              {errors.phone && <span className="field-error">{errors.phone.join(' ')}</span>}
            </div>

            <div className="privacy-box" style={{ background: 'var(--color-surface)' }} aria-labelledby="id-privacy-heading">
              <h4 id="id-privacy-heading" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)', fontSize: '0.9375rem' }}>
                <ShieldCheck size={16} weight="regular" aria-hidden="true" /> Identification document privacy
              </h4>
              <p className="privacy-summary" style={{ marginBottom: 'var(--space-3)' }}>
                {idPrivacyAck
                  ? 'ID Privacy Notice acknowledged. You can re-read the full notice any time.'
                  : 'Your ID is processed only for identity and membership verification. Please read the notice, then acknowledge it inside to continue.'}
              </p>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setIdPrivacyModalOpen(true)}>
                Read Privacy Notice
              </button>
              {idPrivacyError && (
                <span className="field-error" role="alert" style={{ marginTop: 'var(--space-2)' }}>
                  {idPrivacyError}
                </span>
              )}
            </div>

            <div className="field">
              <label htmlFor="doc_type">Document category</label>
              <select id="doc_type" value={s2.docType} onChange={setS2Field('docType')} disabled={!idPrivacyAck}>
                {DOC_TYPES.map((t) => (
                  <option key={t.id} value={t.id}>{t.label}</option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor="id_file">Upload supporting document</label>
              <input
                key={fileInputKey}
                id="id_file"
                type="file"
                accept={ID_ACCEPT}
                onChange={onPickFile}
                disabled={!idPrivacyAck}
                aria-describedby="id-file-hint"
              />
              <small id="id-file-hint" className="field-hint">
                Attach a valid ID as JPG, PNG, WEBP, or PDF, max 5 MB.
                {!idPrivacyAck ? ' Check the privacy acknowledgment above to enable this.' : ''}
              </small>
              {idFileError && <span className="field-error" role="alert">{idFileError}</span>}
              {idFile && (
                <div className="file-chip" role="status">
                  <span className="file-chip-name">{idFile.name}</span>
                  <span className="muted">{formatBytes(idFile.size)}</span>
                  <button type="button" className="btn btn-secondary btn-sm" onClick={clearFile}>
                    Remove
                  </button>
                </div>
              )}
            </div>

            <div className="button-group" style={{ marginTop: 'var(--space-2)' }}>
              <button type="button" className="btn btn-secondary" onClick={() => setStep(1)}>
                <ArrowLeft size={14} weight="regular" aria-hidden="true" /> Back
              </button>
              <button type="button" className="btn" disabled={!step2Valid} onClick={goNextFrom2} title={step2Valid ? 'Continue to review' : 'Complete date of birth, blood type, phone, privacy acknowledgment, and ID file to continue'}>
                Next <ArrowRight size={14} weight="regular" aria-hidden="true" />
              </button>
            </div>
            {!step2Valid && (
              <ReqList items={step2Reqs} />
            )}
          </div>
        )}

        {step === 3 && (
          <div className="form">
            <section className="review-card" aria-labelledby="review-account-heading">
              <div className="review-card-header">
                <div>
                  <div className="reg-kicker">Step 1</div>
                  <h3 id="review-account-heading" style={{ margin: 0 }}>Account</h3>
                </div>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setStep(1)}>
                  <PencilSimple size={14} weight="regular" aria-hidden="true" /> Edit
                </button>
              </div>
              <dl className="review-list">
                <div><dt>First name</dt><dd>{s1.firstName.trim() || 'Not provided'}</dd></div>
                <div><dt>Last name</dt><dd>{s1.lastName.trim() || 'Not provided'}</dd></div>
                <div><dt>Middle name</dt><dd>{s1.middleName.trim() || 'None'}</dd></div>
                <div><dt>Email address</dt><dd>{s1.email.trim() || 'Not provided'}</dd></div>
                <div><dt>Password</dt><dd aria-label="Password hidden">Completed</dd></div>
                <div><dt>Assigned chapter</dt><dd>{chapterName}</dd></div>
                <div><dt>Registration privacy</dt><dd>{privacyAck ? 'Acknowledged' : 'Not acknowledged'}</dd></div>
              </dl>
            </section>

            <section className="review-card" aria-labelledby="review-details-heading">
              <div className="review-card-header">
                <div>
                  <div className="reg-kicker">Step 2</div>
                  <h3 id="review-details-heading" style={{ margin: 0 }}>Personal details and ID</h3>
                </div>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setStep(2)}>
                  <PencilSimple size={14} weight="regular" aria-hidden="true" /> Edit
                </button>
              </div>
              <dl className="review-list">
                <div><dt>Date of birth</dt><dd>{s2.dob || 'Not provided'}</dd></div>
                <div><dt>Blood type</dt><dd>{s2.bloodType || 'Not provided'}</dd></div>
                <div><dt>Phone number</dt><dd>{s2.phone.trim() || 'Not provided'}</dd></div>
                <div><dt>Supporting document</dt><dd>{idFile ? `${idFile.name} (${formatBytes(idFile.size)})` : 'No file attached'}</dd></div>
                <div><dt>Document category</dt><dd>{docTypeLabel}</dd></div>
                <div><dt>ID privacy</dt><dd>{idPrivacyAck ? 'Acknowledged' : 'Not acknowledged'}</dd></div>
              </dl>
            </section>

            <div className="button-group" style={{ marginTop: 'var(--space-2)' }}>
              <button type="button" className="btn btn-secondary" disabled={submitting} onClick={() => setStep(2)}>
                <ArrowLeft size={14} weight="regular" aria-hidden="true" /> Back
              </button>
              <button type="button" className="btn" disabled={submitting || !step1Valid || !step2Valid} onClick={onSubmit}>
                {submitting ? 'Creating account' : 'Create account'}
              </button>
            </div>
          </div>
        )}
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

      <PrivacyNoticeModal
        open={idPrivacyModalOpen}
        title={ID_PRIVACY_TITLE}
        checkboxLabel={ID_PRIVACY_CHECKBOX_LABEL}
        checkboxId="register-id-privacy-ack-modal"
        acknowledged={idPrivacyAck}
        onAcknowledgeChange={(v) => {
          setIdPrivacyAck(v)
          if (v) setIdPrivacyError(null)
        }}
        onClose={() => setIdPrivacyModalOpen(false)}
        onConfirm={() => setIdPrivacyModalOpen(false)}
        confirmLabel="Continue to upload"
        Body={IdPrivacyBody}
      />

      <p className="muted text-center" style={{ marginTop: 'var(--space-6)' }}>
        Already have an account? <Link to="/login" style={{ fontWeight: 600 }}>Sign in</Link>
      </p>
    </div>
  )
}
