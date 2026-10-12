import { useState, useEffect } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import {
  CaretLeft,
  CaretRight,
  ChartBar,
  ChartLineUp,
  CheckSquareOffset,
  House,
  List,
  MapPin,
  Scroll,
  ShieldCheck,
  User,
  X
} from '@phosphor-icons/react'
import { useAuth } from '../../context/AuthContext'
import styles from '../../components/PortalLayout.module.css'

export default function OfficerLayout() {
  const { user } = useAuth()
  const location = useLocation()

  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('bloodmatch_officer_sidebar_collapsed') === 'true'
    } catch {
      return false
    }
  })
  const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false)

  const toggleCollapse = () => {
    const next = !collapsed
    setCollapsed(next)
    try {
      localStorage.setItem('bloodmatch_officer_sidebar_collapsed', String(next))
    } catch {
      // ignore storage errors
    }
  }

  // Close mobile drawer and scroll to top on sub-route transition
  useEffect(() => {
    setMobileDrawerOpen(false)
    window.scrollTo({ top: 0, behavior: 'instant' })
  }, [location.pathname])

  const officerNavItems = [
    { label: 'Dashboard', path: '/officer/dashboard', icon: ChartBar },
    { label: 'Verifications', path: '/officer/verifications', icon: ShieldCheck },
    { label: 'Confirmations', path: '/officer/confirmations', icon: CheckSquareOffset },
    { label: 'Audit Logs', path: '/officer/audit-logs', icon: Scroll },
    { label: 'Regional Demand Map', path: '/officer/demand-map', icon: MapPin },
    { label: 'Analytics', path: '/officer/analytics', icon: ChartLineUp }
  ]

  const isActive = (path) => {
    if (path === '/officer/dashboard') {
      return location.pathname === '/officer' || location.pathname === '/officer/dashboard'
    }
    if (path === '/officer/verifications') {
      return location.pathname === '/officer/verifications' || location.pathname === '/officer/verification'
    }
    if (path === '/officer/demand-map') {
      return location.pathname === '/officer/demand-map' || location.pathname === '/demand-map'
    }
    if (path === '/officer/analytics') {
      return location.pathname === '/officer/analytics' || location.pathname === '/analytics'
    }
    return location.pathname.startsWith(path)
  }

  const currentNavItem = officerNavItems.find((item) => isActive(item.path)) || officerNavItems[0]

  return (
    <div className={`${styles.container} ${collapsed ? styles.collapsed : ''}`}>
      {/* Mobile Drawer Bar */}
      <div className={styles.mobileBar}>
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={() => setMobileDrawerOpen(!mobileDrawerOpen)}
          aria-label={mobileDrawerOpen ? 'Close officer menu' : 'Open officer menu'}
        >
          {mobileDrawerOpen ? <X size={18} /> : <List size={18} />}
          <span style={{ marginLeft: '6px' }}>Officer Portal Menu</span>
        </button>
      </div>

      {/* Persistent Sidebar Navigation */}
      <aside className={`${styles.sidebar} ${mobileDrawerOpen ? styles.sidebarMobileOpen : ''}`}>
        <div className={styles.sidebarHeader}>
          {!collapsed && (
            <div className={styles.titleBlock}>
              <span className={styles.badge}>Chapter Officer</span>
              <h3 className={styles.title}>Officer Portal</h3>
            </div>
          )}

          <button
            type="button"
            className={styles.collapseBtn}
            onClick={toggleCollapse}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 16 16"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
              className={styles.collapseIcon}
              aria-hidden="true"
            >
              <rect x="1.5" y="1.5" width="13" height="13" rx="2" stroke="currentColor" strokeWidth="1.25" />
              <line x1="5.5" y1="1.5" x2="5.5" y2="14.5" stroke="currentColor" strokeWidth="1.25" />
              {collapsed ? (
                <path d="M8.5 5.5L11 8L8.5 10.5" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" />
              ) : (
                <path d="M11 5.5L8.5 8L11 10.5" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" />
              )}
            </svg>
          </button>
        </div>

        <nav className={styles.nav} aria-label="Officer Navigation">
          <div className={styles.sectionLabel}>
            {!collapsed ? 'Officer Operations' : '•••'}
          </div>

          {officerNavItems.map((item) => {
            const Icon = item.icon
            const active = isActive(item.path)
            return (
              <Link
                key={item.path}
                to={item.path}
                className={`${styles.navLink} ${active ? styles.navLinkActive : ''}`}
                title={collapsed ? item.label : undefined}
                aria-current={active ? 'page' : undefined}
              >
                <Icon size={20} weight={active ? 'fill' : 'regular'} className={styles.navIcon} />
                {!collapsed && <span className={styles.navLabel}>{item.label}</span>}
              </Link>
            )
          })}

          <div className={styles.sectionLabel} style={{ marginTop: 'var(--space-4)' }}>
            {!collapsed ? 'Quick Shortcuts' : '•••'}
          </div>

          <Link
            to="/profile"
            className={`${styles.navLink} ${location.pathname === '/profile' ? styles.navLinkActive : ''}`}
            title={collapsed ? 'My Profile' : undefined}
          >
            <User size={20} weight="regular" className={styles.navIcon} />
            {!collapsed && <span className={styles.navLabel}>My Profile</span>}
          </Link>

          <Link
            to="/"
            className={styles.navLink}
            title={collapsed ? 'Public Home' : undefined}
          >
            <House size={20} weight="regular" className={styles.navIcon} />
            {!collapsed && <span className={styles.navLabel}>Public Home</span>}
          </Link>
        </nav>

        {!collapsed && (
          <div className={styles.sidebarFooter}>
            <div className={styles.statusDotContainer}>
              <span className={styles.statusDot} aria-hidden="true" />
              <span style={{ fontSize: 'var(--text-xs)', color: 'var(--color-text-muted)' }}>
                {user?.chapter_name ? `${user.chapter_name} Scope` : `Chapter #${user?.chapter_id || '–'} Scope`}
              </span>
            </div>
          </div>
        )}
      </aside>

      {/* Dynamic Main Viewport via React Router Outlet */}
      <section className={styles.mainContent}>
        {/* Contextual Header / Breadcrumbs */}
        <div style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginBottom: 'var(--space-4)',
          paddingBottom: 'var(--space-2)',
          borderBottom: '1px solid var(--color-border-subtle)',
          fontSize: 'var(--text-xs)',
          color: 'var(--color-text-muted)',
          fontFamily: 'var(--font-mono)'
        }}>
          <div>
            <Link to="/officer/dashboard" style={{ color: 'var(--color-text-muted)', textDecoration: 'none' }}>Officer Portal</Link>
            <span style={{ margin: '0 var(--space-2)' }}>/</span>
            <span style={{ color: 'var(--color-text)', fontWeight: 600 }}>{currentNavItem.label}</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <span className="badge badge-open" style={{ fontSize: '0.625rem' }}>
              {user?.chapter_name || `Chapter #${user?.chapter_id || '–'}`}
            </span>
          </div>
        </div>

        <Outlet />
      </section>
    </div>
  )
}
