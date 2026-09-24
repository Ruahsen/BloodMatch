import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (e) => {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await api.post('/api/password-reset/request', { email })
      setSent(true)
    } catch (err) {
      setError(err.message || 'Request failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-6)', textAlign: 'center' }}>
        <h1 style={{ marginBottom: 'var(--space-2)' }}>Forgot Password</h1>
      </div>

      {sent ? (
        <div className="card">
          <div className="alert alert-success" role="status" style={{ marginBottom: 'var(--space-3)' }}>
            If that email exists in our records, a secure password reset link has been issued.
          </div>
          <p className="muted" style={{ fontSize: '0.875rem', margin: 0 }}>
            Please check your inbox. Password reset tokens expire in 30 minutes and can only be used once.
          </p>
          <div style={{ marginTop: 'var(--space-4)' }}>
            <Link to="/reset-password" className="btn" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
              Enter Reset Token <ArrowRight size={14} weight="regular" aria-hidden="true" />
            </Link>
          </div>
        </div>
      ) : (
        <>
          {error && <div className="alert alert-error" role="alert">{error}</div>}
          <div className="card">
            <form className="form" onSubmit={onSubmit} noValidate>
              <div className="field">
                <label htmlFor="email">Registered Email Address</label>
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  placeholder="name@example.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoFocus
                />
              </div>

              <button type="submit" className="btn" disabled={submitting} style={{ marginTop: 'var(--space-2)' }}>
                {submitting ? 'Sending Request…' : 'Send Reset Link'}
              </button>
            </form>
          </div>
        </>
      )}

      <p className="muted text-center" style={{ marginTop: 'var(--space-6)' }}>
        Remembered your password? <Link to="/login" style={{ fontWeight: 600 }}>Back to sign in</Link>
      </p>
    </div>
  )
}
