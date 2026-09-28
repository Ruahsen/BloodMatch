import { useEffect, useState } from 'react'
import { Link, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { Moon, Sun, User } from '@phosphor-icons/react'
import { Divide as Hamburger } from 'hamburger-react'
import { useAuth } from './context/AuthContext'
import { useTheme } from './context/ThemeContext'
import { clearCsrf } from './services/apiClient'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import ResetPasswordPage from './pages/ResetPasswordPage'
import ProfilePage from './pages/ProfilePage'
import OfficerVerificationPage from './pages/OfficerVerificationPage'
import OfficerConfirmationsPage from './pages/OfficerConfirmationsPage'
import RequestsPage from './pages/RequestsPage'
import RequestFormPage from './pages/RequestFormPage'
import MatchesPage from './pages/MatchesPage'
import NotificationFlyout from './components/NotificationFlyout'
import Footer from './components/Footer'
import NotificationsPage from './pages/NotificationsPage'
import AdminAuditLogsPage from './pages/AdminAuditLogsPage'
import OfficerAuditLogsPage from './pages/OfficerAuditLogsPage'
import DemandMapPage from './pages/DemandMapPage'
import OfficerDashboardPage from './pages/OfficerDashboardPage'
import AdminDashboardPage from './pages/AdminDashboardPage'
import AnalyticsPage from './pages/AnalyticsPage'
import { api } from './services/apiClient'

function HomePage() {
  const { user } = useAuth()
  const { theme } = useTheme()
  const heroSrc = theme === 'dark' ? '/Home-Page-Picture-Dark.png' : '/Home-Page-Picture-White.png'
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-8)' }}>
      {/* Hero: editorial, halftone, reduced density */}
      <section className="hero" aria-labelledby="hero-title">
        <img
          className="hero-bg"
          src={heroSrc}
          alt=""
          aria-hidden="true"
          loading="eager"
          decoding="async"
        />
        <div className="hero-kicker">Bataan · DeMolay Community Network</div>
        <h1 id="hero-title" className="hero-title">
          Verified blood <span className="accent">matching</span>,<br />without the noise.
        </h1>
        <p className="hero-lede">
          BloodMatch links patients who need blood with verified volunteer donors from
          Mt. Samat, Mt. Tarak and Meridian Heights, ranked by compatibility first, proximity second.
        </p>
        <div className="hero-actions">
          {user ? (
            <>
              <Link to="/requests/new" className="btn btn-lg">Create Blood Request</Link>
              <Link to="/profile" className="btn btn-secondary">Manage Profile</Link>
              {user.role === 'officer' && <Link to="/officer/dashboard" className="btn btn-secondary">Officer Dashboard</Link>}
              {user.role === 'admin' && <Link to="/admin/dashboard" className="btn btn-secondary">Admin Dashboard</Link>}
            </>
          ) : (
            <>
              <Link to="/register" className="btn btn-lg">Register as Donor</Link>
              <Link to="/login" className="btn btn-secondary">Sign In</Link>
            </>
          )}
        </div>
        <div style={{ marginTop: 'var(--space-6)', display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', fontSize: 'var(--text-xs)', color: 'var(--color-text-subtle)', fontFamily: 'var(--font-mono)', letterSpacing: '0.04em' }}>
          <span>3 chapters</span><span>·</span><span>8 blood types</span><span>·</span><span>Officer-verified</span>
        </div>
      </section>

      {/* Pillars: tighter, typographic */}
      <div>
        <div className="section-label">How matching works</div>
        <section className="grid-3">
          <div className="card card--halftone">
            <div className="kicker" style={{ marginBottom: 'var(--space-2)' }}><span className="font-pixel">01</span>: Compatibility</div>
            <h3 style={{ marginBottom: 'var(--space-2)' }}>Red-cell first</h3>
            <p className="muted" style={{ fontSize: 'var(--text-sm)', lineHeight: 1.6, margin: 0 }}>
              Only ABO + Rh compatible donors are considered. Proximity refines the eligible pool, it never overrides compatibility.
            </p>
          </div>
          <div className="card card--halftone">
            <div className="kicker" style={{ marginBottom: 'var(--space-2)' }}><span className="font-pixel">02</span>: Verification</div>
            <h3 style={{ marginBottom: 'var(--space-2)' }}>Officer review</h3>
            <p className="muted" style={{ fontSize: 'var(--text-sm)', lineHeight: 1.6, margin: 0 }}>
              Donor cards and IDs are checked by chapter officers. Every confirmed donation is logged in a tamper-evident trail.
            </p>
          </div>
          <div className="card card--halftone">
            <div className="kicker" style={{ marginBottom: 'var(--space-2)' }}><span className="font-pixel">03</span>: Safety</div>
            <h3 style={{ marginBottom: 'var(--space-2)' }}>Protected intervals</h3>
            <p className="muted" style={{ fontSize: 'var(--text-sm)', lineHeight: 1.6, margin: 0 }}>
              Post-donation 42-hour standby and 90-day cooldown are enforced automatically; availability reflects them.
            </p>
          </div>
        </section>
      </div>

      {/* Medical disclaimer: quieter */}
      <section className="medical-disclaimer" role="note" aria-label="Medical Disclaimer">
        <div aria-hidden="true" style={{ fontFamily: 'var(--font-mono)', fontSize: '0.7rem', letterSpacing: '0.08em', textTransform: 'uppercase', color: 'var(--color-text-subtle)', paddingTop: '2px' }}>Note</div>
        <div>
          <strong>Clinical confirmation required.</strong> Suggestions are advisory and do not replace crossmatching, infectious screening or physician review at the facility.
        </div>
      </section>
    </div>
  )
}

function RequireAuth({ children, roles }) {
  const { user, loading } = useAuth()
  if (loading) {
    return (
      <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
        <p className="muted">Loading session…</p>
      </div>
    )
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (roles && !roles.includes(user.role)) {
    return <Navigate to="/" replace />
  }
  return children
}

function NavbarAvatar({ src, alt }) {
  const [failed, setFailed] = useState(false)

  useEffect(() => { setFailed(false) }, [src])

  if (!src || failed) {
    return (
      <span className="avatar avatar-fallback" role="img" aria-label="No profile picture">
        <User size={16} weight="regular" aria-hidden="true" />
      </span>
    )
  }
  return <img className="avatar" src={src} alt={alt} onError={() => setFailed(true)} />
}

export default function App() {
  const { theme, toggleTheme } = useTheme()
  const { user, loading, logout } = useAuth()
  const [unread, setUnread] = useState(0)
  const location = useLocation()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  useEffect(() => {
    if (!user) {
      setUnread(0)
      return undefined
    }
    let active = true
    const tick = () =>
      api
        .get('/api/notifications/unread-count')
        .then((d) => {
          if (active) setUnread(d.unread_count)
        })
        .catch(() => {})
    tick()
    const t = setInterval(tick, 30000)
    return () => {
      active = false
      clearInterval(t)
    }
  }, [user])

  const isActive = (path) => location.pathname === path

  useEffect(() => { setMobileOpen(false) }, [location.pathname])
  useEffect(() => {
    if (!mobileOpen) return undefined
    const onKey = (e) => { if (e.key === 'Escape') setMobileOpen(false) }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [mobileOpen])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      clearCsrf()
      setMobileOpen(false)
      navigate('/', { replace: true })
    }
  }

  return (
    <>
      <a href="#main-content" className="skip-link">Skip to main content</a>
      <header className="topbar">
        <div className="topbar-inner">
          <Link to="/" className="brand" aria-label="BloodMatch Home">
            <svg className="brand-mark" width="18" height="18" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true" focusable="false">
              <path d="M6 6 H13 V12 L9 16 L13 20 V26 H6 Z" stroke="currentColor" strokeWidth="1" strokeLinejoin="round" />
              <path d="M26 6 H19 V13.5 L16.5 16 L19 18.5 V26 H26 Z" stroke="currentColor" strokeWidth="1" strokeLinejoin="round" />
            </svg>
            <span>BloodMatch</span>
          </Link>

          <nav className="nav nav--desktop" aria-label="Main Navigation">
            <Link to="/" className={`nav-link ${isActive('/') ? 'active' : ''}`} aria-current={isActive('/') ? 'page' : undefined}>Home</Link>
            {user ? (
              <>
                <Link to="/profile" className={`nav-link ${isActive('/profile') ? 'active' : ''}`} aria-current={isActive('/profile') ? 'page' : undefined}>Profile</Link>
                <Link to="/requests/mine" className={`nav-link ${isActive('/requests/mine') ? 'active' : ''}`} aria-current={isActive('/requests/mine') ? 'page' : undefined}>My Requests</Link>
                {user.role === 'officer' && (
                  <>
                    <Link to="/officer/dashboard" className={`nav-link ${isActive('/officer/dashboard') ? 'active' : ''}`} aria-current={isActive('/officer/dashboard') ? 'page' : undefined}>Dashboard</Link>
                    <Link to="/demand-map" className={`nav-link ${isActive('/demand-map') ? 'active' : ''}`} aria-current={isActive('/demand-map') ? 'page' : undefined}>Demand Map</Link>
                    <Link to="/analytics" className={`nav-link ${isActive('/analytics') ? 'active' : ''}`} aria-current={isActive('/analytics') ? 'page' : undefined}>Analytics</Link>
                    <Link to="/officer/verifications" className={`nav-link ${isActive('/officer/verifications') ? 'active' : ''}`} aria-current={isActive('/officer/verifications') ? 'page' : undefined}>Verifications</Link>
                    <Link to="/officer/confirmations" className={`nav-link ${isActive('/officer/confirmations') ? 'active' : ''}`} aria-current={isActive('/officer/confirmations') ? 'page' : undefined}>Confirmations</Link>
                    <Link to="/officer/audit-logs" className={`nav-link ${isActive('/officer/audit-logs') ? 'active' : ''}`} aria-current={isActive('/officer/audit-logs') ? 'page' : undefined}>Audit Logs</Link>
                  </>
                )}
                {user.role === 'admin' && (
                  <>
                    <Link to="/admin/dashboard" className={`nav-link ${isActive('/admin/dashboard') ? 'active' : ''}`} aria-current={isActive('/admin/dashboard') ? 'page' : undefined}>Dashboard</Link>
                    <Link to="/demand-map" className={`nav-link ${isActive('/demand-map') ? 'active' : ''}`} aria-current={isActive('/demand-map') ? 'page' : undefined}>Demand Map</Link>
                    <Link to="/analytics" className={`nav-link ${isActive('/analytics') ? 'active' : ''}`} aria-current={isActive('/analytics') ? 'page' : undefined}>Analytics</Link>
                    <Link to="/admin/audit-logs" className={`nav-link ${isActive('/admin/audit-logs') ? 'active' : ''}`} aria-current={isActive('/admin/audit-logs') ? 'page' : undefined}>Audit Logs</Link>
                  </>
                )}
                </>
              ) : null}
          </nav>

          <div className="topbar-right">
            <div className="nav-actions nav-actions--desktop">
              {user ? (
                <>
                  <NotificationFlyout unread={unread} setUnread={setUnread} variant="desktop" />
                  <NavbarAvatar src={user.profile_picture_url} alt={`${user.full_name}'s profile picture`} />
                  <span className="user-identity" title={user.email}>
                    <span className="user-name"><strong>{user.full_name}</strong></span>
                    <span className="user-status" aria-label={`Role: ${user.role}`}>({user.role})</span>
                  </span>
                  <button type="button" className="btn btn-secondary btn-sm" onClick={handleLogout}>Log out</button>
                </>
              ) : (
                <>
                  <Link to="/login" className={`nav-link ${isActive('/login') ? 'active' : ''}`} aria-current={isActive('/login') ? 'page' : undefined}>Log in</Link>
                  <Link to="/register" className={`btn btn-sm ${isActive('/register') ? 'active' : ''}`}>Register</Link>
                </>
              )}
            </div>

            <div className="topbar-utilities">
              {user && (
                <span className="notif-mobile">
                  <NotificationFlyout unread={unread} setUnread={setUnread} variant="desktop" />
                </span>
              )}
              <button type="button" className="theme-toggle" onClick={toggleTheme} aria-label={`Switch to ${theme === 'light' ? 'dark' : 'light'} mode`} title={`Switch to ${theme === 'light' ? 'dark' : 'light'} mode`}>
                {theme === 'light' ? <Moon size={16} weight="regular" aria-hidden="true" /> : <Sun size={16} weight="regular" aria-hidden="true" />}
              </button>
              <span
                className="hamburger"
                onKeyDown={(e) => {
                  if (e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault()
                    setMobileOpen((v) => !v)
                  }
                }}
              >
                <Hamburger
                  toggled={mobileOpen}
                  toggle={setMobileOpen}
                  size={20}
                  rounded
                  duration={0.22}
                  color="currentColor"
                  label={mobileOpen ? 'Close navigation menu' : 'Open navigation menu'}
                />
              </span>
            </div>
          </div>
        </div>

        {mobileOpen && (
          <div id="mobile-panel" className="mobile-panel" role="region" aria-label="Mobile navigation">
            <nav className="mobile-nav" aria-label="Mobile navigation">
              <Link to="/" className={`mobile-link ${isActive('/') ? 'active' : ''}`} aria-current={isActive('/') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Home</Link>
              {user ? (
                <>
                  <div className="mobile-section-label">Account</div>
                  <Link to="/profile" className={`mobile-link ${isActive('/profile') ? 'active' : ''}`} aria-current={isActive('/profile') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Profile</Link>
                  <Link to="/requests/mine" className={`mobile-link ${isActive('/requests/mine') ? 'active' : ''}`} aria-current={isActive('/requests/mine') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>My Requests</Link>
                  {user.role === 'officer' && (
                    <>
                      <div className="mobile-section-label">Officer</div>
                      <Link to="/officer/dashboard" className={`mobile-link ${isActive('/officer/dashboard') ? 'active' : ''}`} aria-current={isActive('/officer/dashboard') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Dashboard</Link>
                      <Link to="/demand-map" className={`mobile-link ${isActive('/demand-map') ? 'active' : ''}`} aria-current={isActive('/demand-map') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Demand Map</Link>
                      <Link to="/analytics" className={`mobile-link ${isActive('/analytics') ? 'active' : ''}`} aria-current={isActive('/analytics') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Analytics</Link>
                      <Link to="/officer/verifications" className={`mobile-link ${isActive('/officer/verifications') ? 'active' : ''}`} aria-current={isActive('/officer/verifications') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Verifications</Link>
                      <Link to="/officer/confirmations" className={`mobile-link ${isActive('/officer/confirmations') ? 'active' : ''}`} aria-current={isActive('/officer/confirmations') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Confirmations</Link>
                      <Link to="/officer/audit-logs" className={`mobile-link ${isActive('/officer/audit-logs') ? 'active' : ''}`} aria-current={isActive('/officer/audit-logs') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Audit Logs</Link>
                    </>
                  )}
                  {user.role === 'admin' && (
                    <>
                      <div className="mobile-section-label">Administration</div>
                      <Link to="/admin/dashboard" className={`mobile-link ${isActive('/admin/dashboard') ? 'active' : ''}`} aria-current={isActive('/admin/dashboard') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Dashboard</Link>
                      <Link to="/demand-map" className={`mobile-link ${isActive('/demand-map') ? 'active' : ''}`} aria-current={isActive('/demand-map') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Demand Map</Link>
                      <Link to="/analytics" className={`mobile-link ${isActive('/analytics') ? 'active' : ''}`} aria-current={isActive('/analytics') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Analytics</Link>
                      <Link to="/admin/audit-logs" className={`mobile-link ${isActive('/admin/audit-logs') ? 'active' : ''}`} aria-current={isActive('/admin/audit-logs') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Audit Logs</Link>
                    </>
                  )}
                  <div className="mobile-section-label">Session</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
                    <NavbarAvatar src={user.profile_picture_url} alt={`${user.full_name}'s profile picture`} />
                    <div className="user-identity user-identity--mobile" title={user.email} style={{ alignSelf: 'flex-start' }}>
                      <span className="user-name"><strong>{user.full_name}</strong></span>
                      <span className="user-status" aria-label={`Role: ${user.role}`}>({user.role})</span>
                    </div>
                  </div>
                  <button type="button" className="btn btn-secondary" style={{ width: '100%' }} onClick={handleLogout}>Log out</button>
                </>
              ) : (
                <>
                  <div className="mobile-section-label">Access</div>
                  <Link to="/login" className={`mobile-link ${isActive('/login') ? 'active' : ''}`} aria-current={isActive('/login') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Log in</Link>
                  <Link to="/register" className={`mobile-link ${isActive('/register') ? 'active' : ''}`} aria-current={isActive('/register') ? 'page' : undefined} onClick={() => setMobileOpen(false)}>Register</Link>
                </>
              )}
            </nav>
          </div>
        )}
      </header>

      <main id="main-content" className="container">
        {loading ? (
          <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
            <p className="muted">Loading session…</p>
          </div>
        ) : (
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/profile" element={<RequireAuth><ProfilePage /></RequireAuth>} />
            <Route path="/officer/dashboard" element={<RequireAuth roles={['officer']}><OfficerDashboardPage /></RequireAuth>} />
            <Route path="/admin/dashboard" element={<RequireAuth roles={['admin']}><AdminDashboardPage /></RequireAuth>} />
            <Route path="/demand-map" element={<RequireAuth roles={['officer', 'admin']}><DemandMapPage /></RequireAuth>} />
            <Route path="/analytics" element={<RequireAuth roles={['officer', 'admin']}><AnalyticsPage /></RequireAuth>} />
            <Route path="/officer/verifications" element={<RequireAuth roles={['officer']}><OfficerVerificationPage /></RequireAuth>} />
            <Route path="/requests/mine" element={<RequireAuth><RequestsPage /></RequireAuth>} />
            <Route path="/requests/new" element={<RequireAuth><RequestFormPage /></RequireAuth>} />
            <Route path="/requests/:id/edit" element={<RequireAuth><RequestFormPage /></RequireAuth>} />
            <Route path="/requests/:id/matches" element={<RequireAuth><MatchesPage /></RequireAuth>} />
            <Route path="/officer/confirmations" element={<RequireAuth roles={['officer']}><OfficerConfirmationsPage /></RequireAuth>} />
            <Route path="/notifications" element={<RequireAuth><NotificationsPage /></RequireAuth>} />
            <Route path="/admin/audit-logs" element={<RequireAuth roles={['admin']}><AdminAuditLogsPage /></RequireAuth>} />
            <Route path="/officer/audit-logs" element={<RequireAuth roles={['officer']}><OfficerAuditLogsPage /></RequireAuth>} />
            <Route path="*" element={
              <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
                <h2>Page Not Found</h2>
                <p className="muted">The requested page does not exist or has been moved.</p>
                <Link to="/" className="btn">Return Home</Link>
              </div>
            } />
          </Routes>
        )}
      </main>

      <Footer />
    </>
  )
}
