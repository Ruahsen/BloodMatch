import { useEffect, useRef, useState } from 'react'
import { CheckCircle, EnvelopeSimple, WarningCircle, X } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'
import styles from './EmailVerificationDialog.module.css'

const emptyCode = () => Array(6).fill('')

export default function EmailVerificationDialog({ isOpen, onClose, onSuccess, initialEmail = '' }) {
  const { refresh } = useAuth()
  const [code, setCode] = useState(emptyCode)
  const [status, setStatus] = useState('idle') // idle, sending, error, success, rate_limited
  const [errorMsg, setErrorMsg] = useState('')
  const [countdown, setCountdown] = useState(0)
  const digitRefs = useRef([])

  useEffect(() => {
    if (!isOpen) {
      setCode(emptyCode())
      setStatus('idle')
      setErrorMsg('')
    } else {
      window.requestAnimationFrame(() => digitRefs.current[0]?.focus())
    }
  }, [isOpen])

  useEffect(() => {
    let timer
    if (countdown > 0) {
      timer = setInterval(() => setCountdown(c => c - 1), 1000)
    }
    return () => clearInterval(timer)
  }, [countdown])

  const handleVerify = async (e) => {
    e.preventDefault()
    if (code.some((digit) => !digit)) return
    setStatus('sending')
    try {
      const res = await api.post('/api/auth/verify', { email: initialEmail, code: code.join('') })
      if (refresh) {
        await refresh()
      }
      setStatus('success')
      if (onSuccess) {
        setTimeout(() => onSuccess(res?.user), 1500)
      }
    } catch (err) {
      setStatus('error')
      setErrorMsg(err.message || 'Verification failed. Please check the code and try again.')
    }
  }

  const updateDigit = (index, value) => {
    const digits = value.replace(/\D/g, '')
    if (digits.length > 1) {
      const autofilledCode = digits.slice(0, 6)
      setCode(Array.from({ length: 6 }, (_, digitIndex) => autofilledCode[digitIndex] || ''))
      setErrorMsg('')
      digitRefs.current[Math.min(autofilledCode.length, 6) - 1]?.focus()
      return
    }
    const digit = digits.slice(-1)
    const next = [...code]
    next[index] = digit
    setCode(next)
    setErrorMsg('')
    if (digit && index < 5) digitRefs.current[index + 1]?.focus()
  }

  const handleDigitKeyDown = (index, event) => {
    if (event.key === 'Backspace' && !code[index] && index > 0) {
      digitRefs.current[index - 1]?.focus()
    }
    if (event.key === 'ArrowLeft' && index > 0) digitRefs.current[index - 1]?.focus()
    if (event.key === 'ArrowRight' && index < 5) digitRefs.current[index + 1]?.focus()
  }

  const handlePaste = (event) => {
    const pastedCode = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6)
    if (!pastedCode) return
    event.preventDefault()
    setCode(Array.from({ length: 6 }, (_, index) => pastedCode[index] || ''))
    setErrorMsg('')
    digitRefs.current[Math.min(pastedCode.length, 6) - 1]?.focus()
  }

  const handleResend = async () => {
    if (countdown > 0 || status === 'sending') return
    setStatus('sending')
    try {
      await api.post('/api/auth/verify/resend', { email: initialEmail })
      setStatus('idle')
      setCountdown(60)
      setCode(emptyCode())
      setErrorMsg('')
    } catch (err) {
      if (err.status === 429) {
        setStatus('rate_limited')
        setErrorMsg('Too many resend attempts. Please wait and try again later.')
      } else {
        setStatus('error')
        setErrorMsg(err.message || 'Failed to resend code.')
      }
    }
  }

  if (!isOpen) return null

  return (
    <div className={styles.overlay} onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="dialog-title">
      <div className={styles.dialog} onClick={e => e.stopPropagation()}>
        <button className={styles.closeButton} onClick={onClose} aria-label="Close dialog">
          <X size={24} />
        </button>

        {status === 'success' ? (
          <div className={styles.successState}>
            <CheckCircle size={48} weight="fill" className={styles.successIcon} />
            <h2 id="dialog-title">Email Verified!</h2>
            <p>Your email has been successfully verified.</p>
          </div>
        ) : (
          <div className={styles.verifyState}>
            <div className={styles.iconMark} aria-hidden="true">
              <EnvelopeSimple size={24} weight="duotone" />
            </div>
            <h2 id="dialog-title">Check your email</h2>
            <p className={styles.intro}>
              Enter the six-digit code sent to
              {initialEmail && <strong className={styles.email}>{initialEmail}</strong>}.
            </p>
            
            <form onSubmit={handleVerify} className={styles.form}>
              <div className={styles.inputGroup}>
                <span className={styles.inputLabel}>Verification code</span>
                <div className={styles.codeInputs} onPaste={handlePaste}>
                  {Array.from({ length: 6 }, (_, index) => (
                    <input
                      key={index}
                      ref={(element) => { digitRefs.current[index] = element }}
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={1}
                      value={code[index] || ''}
                      onChange={(event) => updateDigit(index, event.target.value)}
                      onKeyDown={(event) => handleDigitKeyDown(index, event)}
                      className={styles.codeInput}
                      autoComplete={index === 0 ? 'one-time-code' : 'off'}
                      aria-label={`Verification code digit ${index + 1}`}
                      aria-invalid={!!errorMsg}
                    />
                  ))}
                </div>
              </div>

              {errorMsg && (
                <div className={styles.errorMessage} role="alert">
                  <WarningCircle size={20} />
                  <span>{errorMsg}</span>
                </div>
              )}

              <button 
                type="submit" 
                className={styles.submitButton}
                disabled={code.some((digit) => !digit) || status === 'sending'}
              >
                {status === 'sending' ? 'Verifying…' : 'Verify email'}
              </button>
            </form>

            <div className={styles.resendSection}>
              <span>Didn't receive the code?</span>
              <button 
                onClick={handleResend} 
                disabled={countdown > 0 || status === 'sending' || status === 'rate_limited'}
                className={styles.resendButton}
              >
                {countdown > 0 ? `Resend in ${countdown}s` : 'Resend code'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
