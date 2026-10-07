import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'

const CLAIM_KEY = 'bloodmatch_email_otp_claim'

function readClaimToken() {
  try {
    return sessionStorage.getItem(CLAIM_KEY)
  } catch {
    return null
  }
}

function clearClaimToken() {
  try {
    sessionStorage.removeItem(CLAIM_KEY)
  } catch {
    // Storage unavailable: nothing to clean up.
  }
}

function formatCountdown(totalSeconds) {
  const s = Math.max(0, Math.floor(totalSeconds))
  const m = Math.floor(s / 60)
  const rest = s % 60
  return `${m}:${String(rest).padStart(2, '0')}`
}

/**
 * Email verification completing the registration journey.
 *
 * Two modes, one UI:
 * - claim mode: the user just registered (no session yet) and authorizes
 *   with the single-purpose claim token issued by POST /api/register,
 *   kept in sessionStorage (tab-scoped, never in a URL).
 * - session mode: a signed-in, still-unverified user (e.g. returning via
 *   the profile banner) uses the normal authenticated endpoints.
 */
export default function EmailVerificationPage() {
  const { refresh } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [mode, setMode] = useState(null) // 'claim' | 'session' | 'none'
  const [claimToken, setClaimToken] = useState(null)
  const [status, setStatus] = useState(null)
  const [maskedEmail, setMaskedEmail] = useState(location.state?.maskedEmail || null)
  const [deliveryFailed, setDeliveryFailed] = useState(location.state?.registered === true && location.state?.delivered === false)
  const [loading, setLoading] = useState(true)
  const [code, setCode] = useState('')
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(
    location.state?.registered && location.state?.delivered !== false
      ? 'Account created. Check your email for your verification code.'
      : location.state?.fromBlockedLogin
        ? 'Sign-in needs a verified email first. Enter the code below — request a new one if yours expired.'
        : null
  )
  const [verifying, setVerifying] = useState(false)
  const [sending, setSending] = useState(false)
  const [cooldown, setCooldown] = useState(0)
  const [expiresIn, setExpiresIn] = useState(0)
  const [autoSent, setAutoSent] = useState(false)

  const applyStatus = useCallback((data) => {
    setStatus(data)
    if (data.masked_email) setMaskedEmail(data.masked_email)
    setCooldown(data.resend_available_in_seconds || 0)
    setExpiresIn(data.expires_in_seconds || 0)
  }, [])

  const sendCode = useCallback(async (activeMode, token) => {
    setSending(true)
    setError(null)
    setNotice(null)
    try {
      const data = await api.post(
        '/api/auth/email-otp/send',
        activeMode === 'claim' ? { verification_token: token } : {}
      )
      if (data.already_verified) {
        if (activeMode === 'session') {
          await refresh()
          applyStatus(await api.get('/api/auth/email-otp/status'))
        }
        setNotice('Your email is already verified.')
      } else {
        setCooldown(data.resend_available_in_seconds || 60)
        setExpiresIn(data.expires_in_seconds || 600)
        setDeliveryFailed(false)
        setNotice('A new verification code was sent to your registered email.')
      }
    } catch (err) {
      if (err.status === 503) {
        setDeliveryFailed(true)
        setError('We could not deliver the email right now. Wait a moment, then request a new code below.')
      } else {
        setError(err.message || 'Could not send the verification code.')
      }
    } finally {
      setSending(false)
    }
  }, [applyStatus, refresh])

  useEffect(() => {
    let active = true
    const init = async () => {
      // Prefer the signed-in journey when a session exists.
      try {
        const data = await api.get('/api/auth/email-otp/status')
        if (!active) return
        setMode('session')
        applyStatus(data)
        setLoading(false)
        // Returning banner visitor with no live code: issue one for them.
        if (!data.verified && !data.has_active_code && !autoSent) {
          setAutoSent(true)
          await sendCode('session', null)
        }
        return
      } catch (err) {
        if (!active) return
        if (err.status !== 401) {
          setError(err.message || 'Could not load verification status.')
          setLoading(false)
          return
        }
        // No session: fall through to the registration claim token.
      }
      const token = readClaimToken()
      if (!token) {
        if (active) {
          setMode('none')
          setLoading(false)
        }
        return
      }
      try {
        const data = await api.post('/api/auth/email-otp/status', { verification_token: token })
        if (!active) return
        setMode('claim')
        setClaimToken(token)
        applyStatus(data)
        // The code was already sent by registration: never auto-resend
        // here (it would immediately hit the resend cooldown).
        if (typeof location.state?.expiresIn === 'number' && !data.has_active_code) {
          setExpiresIn(location.state.expiresIn)
        }
      } catch (err) {
        if (!active) return
        clearClaimToken()
        setMode('none')
        if (err.status !== 401) setError(err.message || 'Could not load verification status.')
      } finally {
        if (active) setLoading(false)
      }
      setLoading(false)
    }
    init()
    return () => {
      active = false
    }
  }, [applyStatus, autoSent, location.state, sendCode])

  useEffect(() => {
    const t = setInterval(() => {
      setCooldown((c) => Math.max(0, c - 1))
      setExpiresIn((e) => Math.max(0, e - 1))
    }, 1000)
    return () => clearInterval(t)
  }, [])

  const onVerify = async (e) => {
    e.preventDefault()
    setError(null)
    setNotice(null)
    const digits = code.replace(/\D/g, '').slice(0, 6)
    if (digits.length !== 6) {
      setError('Enter the 6-digit code from your email.')
      return
    }
    setVerifying(true)
    try {
      const payload = mode === 'claim' ? { verification_token: claimToken, code: digits } : { code: digits }
      await api.post('/api/auth/email-otp/verify', payload)
      setCode('')
      if (mode === 'claim') {
        clearClaimToken()
        navigate('/login', { state: { emailVerified: true } })
        return
      }
      await refresh()
      applyStatus(await api.get('/api/auth/email-otp/status'))
      setNotice('Email verified. Thank you for confirming your address.')
    } catch (err) {
      setError(err.message || 'Verification failed.')
      // Refresh attempts/cooldown from the server (attempt counting,
      // expiry, and exhaustion all live server-side).
      try {
        if (mode === 'claim' && claimToken) {
          applyStatus(await api.post('/api/auth/email-otp/status', { verification_token: claimToken }))
        } else if (mode === 'session') {
          applyStatus(await api.get('/api/auth/email-otp/status'))
        }
      } catch {
        // Keep the verification error visible when the refresh fails.
      }
    } finally {
      setVerifying(false)
    }
  }

  const subtitle = maskedEmail
    ? `We sent a 6-digit code to ${maskedEmail} to confirm you can access it.`
    : 'We sent a 6-digit code to your registered email to confirm you can access it.'

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-6)', textAlign: 'center' }}>
        <h1 style={{ marginBottom: 'var(--space-2)' }}>Verify your email address</h1>
        <p className="muted" style={{ margin: 0 }}>{subtitle}</p>
      </div>

      {loading ? (
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading verification status…</p>
        </div>
      ) : mode === 'none' ? (
        <div className="card">
          <div className="alert alert-error" role="alert" style={{ marginBottom: 'var(--space-3)' }}>
            This verification link is missing or has expired.
          </div>
          <p className="muted" style={{ fontSize: '0.875rem', marginTop: 0 }}>
            Verification codes are tied to your registration session. Create a new account,
            or sign in and finish verification from your profile.
          </p>
          <div style={{ display: 'flex', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
            <Link to="/register" className="btn">Create an account</Link>
            <Link to="/login" className="btn btn-secondary">Sign in</Link>
          </div>
        </div>
      ) : status?.verified ? (
        <div className="card">
          <div className="alert alert-success" role="status" style={{ marginBottom: 'var(--space-3)' }}>
            Your email address is verified.
          </div>
          <p className="muted" style={{ fontSize: '0.875rem', margin: 0 }}>
            Registration verification is complete. Email verification confirms you can access this address.
            It does not verify you as a donor — donor verification is a separate officer review in your profile.
          </p>
          <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
            <Link to="/profile" className="btn">Back to profile</Link>
            <Link to="/feed" className="btn btn-secondary">Go to Blood Request Feed</Link>
          </div>
        </div>
      ) : (
        <>
          {error && <div className="alert alert-error" role="alert">{error}</div>}
          {notice && <div className="alert alert-success" role="status">{notice}</div>}
          {deliveryFailed && (
            <div className="alert alert-error" role="alert" style={{ marginBottom: 'var(--space-3)' }}>
              Your account was created, but the verification email could not be delivered.
              Request a new code below — your email stays unverified until a code arrives and is accepted.
            </div>
          )}
          <div className="card">
            <form className="form" onSubmit={onVerify} noValidate>
              <div className="field">
                <label htmlFor="otp-code">6-digit verification code</label>
                <input
                  id="otp-code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  required
                  minLength={6}
                  maxLength={6}
                  pattern="\d{6}"
                  autoFocus
                  aria-describedby="otp-hint"
                  style={{ letterSpacing: '0.35em', fontSize: '1.25rem', textAlign: 'center' }}
                />
                <small id="otp-hint" className="field-hint">
                  {expiresIn > 0
                    ? `Current code expires in ${formatCountdown(expiresIn)}. Each code is single-use.`
                    : 'Codes expire 10 minutes after they are sent and each code is single-use.'}
                </small>
              </div>

              <button type="submit" className="btn" disabled={verifying} style={{ marginTop: 'var(--space-2)' }}>
                {verifying ? 'Verifying…' : 'Verify email'}
              </button>
            </form>

            <div style={{ marginTop: 'var(--space-4)', borderTop: '1px solid var(--color-border)', paddingTop: 'var(--space-4)' }}>
              <p className="muted" style={{ fontSize: '0.875rem', marginTop: 0 }}>
                Didn&apos;t get the code? Check your spam folder, then request a new one.
              </p>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => sendCode(mode, claimToken)}
                disabled={sending || cooldown > 0}
              >
                {sending
                  ? 'Sending…'
                  : cooldown > 0
                    ? `Resend code (${formatCountdown(cooldown)})`
                    : 'Resend code'}
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
