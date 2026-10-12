import { useState, useEffect } from 'react'
import { Link, useLocation } from 'react-router-dom'
import {
  ChartBar,
  ChartLineUp,
  CheckSquareOffset,
  CaretLeft,
  CaretRight,
  House,
  List,
  MapPin,
  Scroll,
  ShieldCheck,
  User,
  X
} from '@phosphor-icons/react'
import { useAuth } from '../context/AuthContext'
import styles from './PortalLayout.module.css'

export default function PortalLayout({ children }) {
  const { user } = useAuth()
  const location = useLocation()

  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('bloodmatch_portal_sidebar_collapsed') === 'true'
    } catch {
      return false
    }
  })
  const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false)

  const toggleCollapse = () => {
    const next = !collapsed
    setCollapsed(next)
    try {
      localStorage.setItem('bloodmatch_portal_sidebar_collapsed', String(next))
    } catch {
      // ignore storage errors
    }
  }

  // Close mobile drawer on route change
  useEffect(() => {
    setMobileDrawerOpen(false)
  }, [location.pathname])

  const isActive = (path) => location.pathname === path

  const officerNavItems = [
    { label: 'Dashboard', path: '/officer/dashboard', icon: ChartBar },
    { label: 'Verifications', path: '/officer/verifications', icon: ShieldCheck },
    { label: 'Confirmations', path: '/officer/confirmations', icon: CheckSquareOffset },
    { label: 'Audit Logs', path: '/officer/audit-logs', icon: Scroll },
    { label: 'Regional Demand Map', path: '/officer/demand-map', icon: MapPin },
    { label: 'Analytics', path: '/officer/analytics', icon: ChartLineUp }
  ]

  const adminNavItems = [
    { label: 'Dashboard', path: '/admin/dashboard', icon: ChartBar },
    { label: 'Demand Map', path: '/admin/demand-map', icon: MapPin },
    { label: 'Analytics', path: '/admin/analytics', icon: ChartLineUp },
    { label: 'Audit Logs', path: '/admin/audit-logs', icon: Scroll }
  ]

  const navItems = user?.role === 'admin' ? adminNavItems : officerNavItems
  const portalTitle = user?.role === 'admin' ? 'Admin Portal' : 'Officer Portal'
  const sectionTitle = user?.role === 'admin' ? 'Administrative Tools' : 'Officer Operations'

  return (
    <div className={`${styles.container} ${collapsed ? styles.collapsed : ''}`}>
      {/* Mobile Drawer Toggle */}
      <div className={styles.mobileBar}>
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={() => setMobileDrawerOpen(!mobileDrawerOpen)}
          aria-label={mobileDrawerOpen ? 'Close sidebar menu' : 'Open sidebar menu'}
        >
          {mobileDrawerOpen ? <X size={18} /> : <List size={18} />}
          <span style={{ marginLeft: '6px' }}>{portalTitle} Menu</span>
        </button>
      </div>

      {/* Sidebar Navigation */}
      <aside className={`${styles.sidebar} ${mobileDrawerOpen ? styles.sidebarMobileOpen : ''}`}>
        <div className={styles.sidebarHeader}>
          {!collapsed && (
            <div className={styles.titleBlock}>
              <span className={styles.badge}>
                {user?.role === 'admin' ? 'System Administrator' : 'Chapter Officer'}
              </span>
              <h3 className={styles.title}>{portalTitle}</h3>
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

        <nav className={styles.nav} aria-label="Portal Secondary Navigation">
          <div className={styles.sectionLabel}>
            {!collapsed ? sectionTitle : '•••'}
          </div>

          {navItems.map((item) => {
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
            className={`${styles.navLink} ${isActive('/profile') ? styles.navLinkActive : ''}`}
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
                System Secure · UTC Active
              </span>
            </div>
          </div>
        )}
      </aside>

      {/* Main Content Viewport */}
      <section className={styles.mainContent}>
        {children}
      </section>
    </div>
  )
}
