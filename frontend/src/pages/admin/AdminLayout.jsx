import { useState, useEffect } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import {
  CaretLeft,
  CaretRight,
  ChartBar,
  ChartLineUp,
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

export default function AdminLayout() {
  const { user } = useAuth()
  const location = useLocation()

  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('bloodmatch_admin_sidebar_collapsed') === 'true'
    } catch {
      return false
    }
  })
  const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false)

  const toggleCollapse = () => {
    const next = !collapsed
    setCollapsed(next)
    try {
      localStorage.setItem('bloodmatch_admin_sidebar_collapsed', String(next))
    } catch {
      // ignore storage errors
    }
  }

  // Close mobile drawer and scroll to top on sub-route transition
  useEffect(() => {
    setMobileDrawerOpen(false)
    window.scrollTo({ top: 0, behavior: 'instant' })
  }, [location.pathname])

  const adminNavItems = [
    { label: 'Dashboard', path: '/admin/dashboard', icon: ChartBar },
    { label: 'Account Verifications', path: '/admin/verifications', icon: ShieldCheck },
    { label: 'Demand Map', path: '/admin/demand-map', icon: MapPin },
    { label: 'Analytics', path: '/admin/analytics', icon: ChartLineUp },
    { label: 'Audit Logs', path: '/admin/audit-logs', icon: Scroll }
  ]

  const isActive = (path) => {
    if (path === '/admin/dashboard') {
      return location.pathname === '/admin' || location.pathname === '/admin/dashboard'
    }
    return location.pathname.startsWith(path)
  }

  return (
    <div className={`${styles.container} ${collapsed ? styles.collapsed : ''}`}>
      {/* Mobile Drawer Bar */}
      <div className={styles.mobileBar}>
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={() => setMobileDrawerOpen(!mobileDrawerOpen)}
          aria-label={mobileDrawerOpen ? 'Close admin menu' : 'Open admin menu'}
        >
          {mobileDrawerOpen ? <X size={18} /> : <List size={18} />}
          <span style={{ marginLeft: '6px' }}>Admin Portal Menu</span>
        </button>
      </div>

      {/* Persistent Sidebar Navigation */}
      <aside className={`${styles.sidebar} ${mobileDrawerOpen ? styles.sidebarMobileOpen : ''}`}>
        <div className={styles.sidebarHeader}>
          {!collapsed && (
            <div className={styles.titleBlock}>
              <span className={styles.badge}>System Administration</span>
              <h3 className={styles.title}>Admin Portal</h3>
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

        <nav className={styles.nav} aria-label="Admin Navigation">
          <div className={styles.sectionLabel}>
            {!collapsed ? 'Administrative Tools' : '•••'}
          </div>

          {adminNavItems.map((item) => {
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
            to="/"
            className={styles.navLink}
            title={collapsed ? 'Public Home' : undefined}
          >
            <House size={20} weight="regular" className={styles.navIcon} />
            {!collapsed && <span className={styles.navLabel}>Public Home</span>}
          </Link>
        </nav>

        <div className={styles.sidebarFooter}>
          {!collapsed ? (
            <Link to="/profile" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', textDecoration: 'none', color: 'inherit' }}>
              <div style={{ width: '32px', height: '32px', borderRadius: '50%', background: 'var(--color-brand-navy)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '0.8125rem' }}>
                {user?.full_name?.charAt(0) || 'A'}
              </div>
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: 'var(--text-xs)', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {user?.full_name}
                </div>
                <div className="muted" style={{ fontSize: '0.6875rem' }}>Administrator</div>
              </div>
            </Link>
          ) : (
            <div style={{ display: 'flex', justifyContent: 'center' }}>
              <div style={{ width: '28px', height: '28px', borderRadius: '50%', background: 'var(--color-brand-navy)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '0.75rem' }} title={user?.full_name}>
                {user?.full_name?.charAt(0) || 'A'}
              </div>
            </div>
          )}
        </div>
      </aside>

      {/* Dynamic Main Viewport via React Router Outlet */}
      <section className={styles.mainContent}>
        <Outlet />
      </section>
    </div>
  )
}
