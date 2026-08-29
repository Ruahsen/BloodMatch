import { useState } from 'react'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { Check } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

export default function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [token, setToken] = useState(params.get('token') || '')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState(null)
  const [done, setDone] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (e) => {
    e.preventDefault()
    setError(null)
    if (password !== confirm) {
      setError('Passwords do not match.')
      return
    }
    setSubmitting(true)
    try {
      await api.post('/api/password-reset/confirm', { token, password })
      setDone(true)
      setTimeout(() => navigate('/login'), 2000)
    } catch (err) {
      setError(err.message || 'Failed to reset password.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-6)', textAlign: 'center' }}>
        <h1 style={{ marginBottom: 'var(--space-2)' }}>Reset Your Password</h1>
      </div>

      {done ? (
        <div className="card">
          <div className="alert alert-success" role="status" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <Check size={16} weight="regular" aria-hidden="true" /> Password reset successfully! Redirecting you to sign in…
          </div>
          <Link to="/login" className="btn" style={{ marginTop: 'var(--space-3)' }}>
            Sign In Now
          </Link>
        </div>
      ) : (
        <>
          {error && <div className="alert alert-error" role="alert">{error}</div>}
          <div className="card">
            <form className="form" onSubmit={onSubmit} noValidate>
              <div className="field">
                <label htmlFor="token">Reset Token *</label>
                <input
                  id="token"
                  placeholder="Paste 64-character token"
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                  required
                />
              </div>

              <div className="field">
                <label htmlFor="password">New Password *</label>
                <input
                  id="password"
                  type="password"
                  autoComplete="new-password"
                  placeholder="At least 8 chars with letters and numbers"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  minLength={8}
                />
                <small className="field-hint">Must contain at least 8 characters with at least one letter and one number.</small>
              </div>

              <div className="field">
                <label htmlFor="confirm">Confirm New Password *</label>
                <input
                  id="confirm"
                  type="password"
                  autoComplete="new-password"
                  placeholder="Re-enter password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  required
                />
              </div>

              <button type="submit" className="btn" disabled={submitting} style={{ marginTop: 'var(--space-2)' }}>
                {submitting ? 'Resetting Password…' : 'Set New Password'}
              </button>
            </form>
          </div>
        </>
      )}

      <p className="muted text-center" style={{ marginTop: 'var(--space-6)' }}>
        <Link to="/login" style={{ fontWeight: 600 }}>Back to sign in</Link>
      </p>
    </div>
  )
}
