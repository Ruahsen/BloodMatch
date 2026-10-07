import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, Check, Info } from '@phosphor-icons/react'
import { api } from '../services/apiClient'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const URGENCIES = ['routine', 'urgent', 'critical']
const PAGE_SIZE = 15

const REASON_COPY = {
  ready: null,
  own_request: 'This is your request. Manage it from your matches.',
  already_responded: 'You already responded. Track it from the match view.',
  accepted_connected: 'You are connected. Coordinate from the match view.',
  donation_completed: 'Donation completed. Thank you.',
  withdrawn_terminal: 'You withdrew from this request.',
  incompatible: 'Your blood type is not compatible with this request.',
  no_blood_type: 'Set your blood type in your profile to respond.',
  unenrolled_donor: 'Enroll as a donor from your profile to respond.',
  donor_unavailable: 'Your donor availability is currently off.',
  standby_active: 'You are in post-donation standby.',
  cooldown_active: 'You are in the inter-donation cooldown.',
  unverified_account: 'Only verified members can respond as donors.',
  age_ineligible: 'Donor age requirements are not met.',
  not_actionable: 'You cannot currently respond to this request.'
}

function locationLabel(loc) {
  if (!loc) return 'Location pending'
  if (loc.barangay_name) return `${loc.barangay_name}, ${loc.municipality_name}`
  return loc.municipality_name ?? loc.name ?? 'Bataan'
}

function FeedCard({ item, onRespond, respondingId, consent, setConsent }) {
  const r = item
  const canRespond = r.primary_action === 'respond'
  const reasonCopy = REASON_COPY[r.action_reason] ?? null

  return (
    <article className="card" aria-label={`Blood request ${r.required_blood_type}, ${r.facility_name}`}>
      <div className="card-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
          <span style={{ fontSize: '1.25rem', fontWeight: 800 }}>{r.required_blood_type}</span>
          <span className="muted">·</span>
          <span style={{ fontWeight: 600 }}>{r.quantity_units} Unit(s)</span>
          <span className="muted">·</span>
          <span className={`badge ${r.urgency === 'critical' ? 'badge-critical' : (r.urgency === 'urgent' ? 'badge-urgent' : 'badge-routine')}`}>
            {r.urgency.toUpperCase()}
          </span>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
          <span className="badge badge-open">{r.status}</span>
          {r.is_own && <span className="badge badge-pill">Your request</span>}
        </div>
      </div>

      <div className="card-body">
        <p className="muted" style={{ fontSize: '0.8125rem', margin: '0 0 var(--space-3)' }}>
          Blood Request · {r.chapter_name ?? 'BloodMatch'}
        </p>

        <div className="grid-3">
          <div>
            <span className="metric-label">Hospital / Facility</span>
            <p style={{ margin: 0, fontWeight: 600 }}>{r.facility_name}</p>
          </div>
          <div>
            <span className="metric-label">Needed By</span>
            <p style={{ margin: 0, fontWeight: 600 }}>{new Date(r.needed_datetime.replace(' ', 'T') + 'Z').toLocaleString()}</p>
          </div>
          <div>
            <span className="metric-label">Approx. Distance</span>
            <p style={{ margin: 0, fontWeight: 600 }}>
              {r.approximate_distance_km !== null ? `~${r.approximate_distance_km} km` : 'Location pending'}
            </p>
          </div>
        </div>

        <div className="grid-3" style={{ marginTop: 'var(--space-3)' }}>
          <div>
            <span className="metric-label">Location</span>
            <p style={{ margin: 0 }}>{locationLabel(r.location)}</p>
          </div>
          <div>
            <span className="metric-label">Compatibility</span>
            <p style={{ margin: 0 }}>
              <span className={`badge ${r.compatibility_tier === 0 ? 'badge-verified' : ''}`}>{r.compatibility_label}</span>
            </p>
          </div>
          <div>
            <span className="metric-label">Request</span>
            <p style={{ margin: 0 }}><code>#{r.id}</code></p>
          </div>
        </div>

        {r.blood_type_notice && (
          <p className="muted" style={{ fontSize: '0.75rem', margin: 'var(--space-2) 0 0' }}>{r.blood_type_notice}</p>
        )}

        <div className="button-group" style={{ marginTop: 'var(--space-2)', borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-3)' }}>
          {r.primary_action === 'respond' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', width: '100%' }}>
              <label style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'flex-start', fontSize: '0.8125rem' }}>
                <input
                  type="checkbox"
                  checked={!!consent[r.id]}
                  onChange={(e) => setConsent((c) => ({ ...c, [r.id]: e.target.checked }))}
                  style={{ marginTop: '0.2rem' }}
                />
                <span>I agree to share my email address with this requester if my response is accepted.</span>
              </label>
              <div>
                <button
                  type="button"
                  className="btn btn-sm"
                  disabled={!consent[r.id] || respondingId === r.id}
                  onClick={() => onRespond(r.id)}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}
                >
                  <Check size={14} weight="regular" aria-hidden="true" />
                  {respondingId === r.id ? 'Responding…' : 'I Can Donate'}
                </button>
              </div>
            </div>
          )}

          {(r.primary_action === 'view_matches' || r.primary_action === 'view_match') && (
            <Link to={`/requests/${r.id}/matches`} className="btn btn-sm" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
              {r.is_own ? 'View Matches' : 'View Match'} <ArrowRight size={14} weight="regular" aria-hidden="true" />
            </Link>
          )}

          {r.is_own && r.status === 'OPEN' && (
            <Link to={`/requests/${r.id}/edit`} className="btn btn-secondary btn-sm">
              Edit Details
            </Link>
          )}

          {reasonCopy && (
            <span className="muted" style={{ fontSize: '0.8125rem', alignSelf: 'center' }}>{reasonCopy}</span>
          )}
        </div>
      </div>
    </article>
  )
}

export default function FeedPage() {
  const [data, setData] = useState(null)
  const [chapters, setChapters] = useState([])
  const [filters, setFilters] = useState({ blood_type: '', urgency: '', chapter_id: '', near_me: false })
  const [scope, setScope] = useState('all')
  const [page, setPage] = useState(1)
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [respondingId, setRespondingId] = useState(null)
  const [consent, setConsent] = useState({})
  // Monotonic request sequence: an older response must never overwrite
  // newer state when filters/pages change rapidly.
  const seqRef = useRef(0)

  const buildQuery = useCallback((f, p, s) => {
    const q = new URLSearchParams()
    if (s && s !== 'all') q.set('feed_scope', s)
    if (f.blood_type) q.set('blood_type', f.blood_type)
    if (f.urgency) q.set('urgency', f.urgency)
    if (f.chapter_id) q.set('chapter_id', f.chapter_id)
    if (f.near_me) q.set('near_me', '1')
    q.set('page', String(p))
    q.set('page_size', String(PAGE_SIZE))
    return `/api/requests/feed?${q.toString()}`
  }, [])

  const load = useCallback((f = filters, p = page, s = scope) => {
    const seq = seqRef.current + 1
    seqRef.current = seq
    setErrorAlert(null)
    return api
      .get(buildQuery(f, p, s))
      .then((d) => {
        if (seqRef.current !== seq) return
        // Success clears any prior error; viewer location state comes
        // exclusively from the server, never fabricated on failure.
        setErrorAlert(null)
        setData(d)
      })
      .catch((err) => {
        if (seqRef.current !== seq) return
        // Failure keeps the last good list (if any) and surfaces the real
        // error — including location-required errors — instead of hiding
        // them behind a fabricated has_location flag.
        setErrorAlert(err.message)
      })
  }, [buildQuery, filters, page, scope])

  useEffect(() => {
    api.get('/api/chapters').then((d) => setChapters(d.chapters || [])).catch(() => {})
  }, [])

  // Single driver for filter/scope/page changes: state updates below only
  // set state, and this effect issues exactly one request per change.
  useEffect(() => {
    load()
  }, [load])

  const applyFilters = (next) => {
    setFilters(next)
    setPage(1)
  }

  const applyScope = (next) => {
    setScope(next)
    setPage(1)
  }

  const gotoPage = (p) => {
    setPage(p)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const onRespond = async (requestId) => {
    setMessage(null)
    setErrorAlert(null)
    setRespondingId(requestId)
    try {
      await api.post(`/api/requests/${requestId}/respond`, { donor_share_consent: true })
      setMessage(`Your willingness to donate for request #${requestId} has been recorded.`)
      setConsent((c) => ({ ...c, [requestId]: false }))
      await load()
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setRespondingId(null)
    }
  }

  const hasLocation = data?.viewer?.has_location !== false
  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.page_size)) : 1
  const matchCount = data?.match_count ?? 0
  const emptyCopy = scope === 'match'
    ? { title: 'No matched requests yet.', body: 'Requests you are matched with will appear here. Respond to a compatible request to start a match.' }
    : scope === 'critical'
      ? { title: 'No critical requests match these filters.', body: 'Try clearing the filters, or check back soon.' }
      : { title: 'No open blood requests match these filters.', body: 'Try clearing the filters, or create a request if someone needs blood now.' }

  return (
    <div className="container wide">
      <header className="app-header">
        <div>
          <h1>Blood Request Feed</h1>
        </div>
        <Link to="/requests/new" className="btn">
          + Create New Request
        </Link>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && (
        <div className="alert alert-error" role="alert">
          {errorAlert}
          <div style={{ marginTop: 'var(--space-2)' }}>
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => load()}>Retry</button>
          </div>
        </div>
      )}

      <div className="feed-layout">
        <div className="feed-stream">
          <div className="feed-tabs" role="group" aria-label="Feed scope">
            <button
              type="button"
              className="feed-tab"
              aria-pressed={scope === 'all'}
              aria-label="Show all open requests"
              onClick={() => scope !== 'all' && applyScope('all')}
            >
              All
            </button>
            <button
              type="button"
              className="feed-tab"
              aria-pressed={scope === 'match'}
              aria-label={`Show your matched requests, ${matchCount} matched`}
              onClick={() => scope !== 'match' && applyScope('match')}
            >
              Match&nbsp;<span className="feed-tab-count" aria-hidden="true">{matchCount}</span>
            </button>
            <button
              type="button"
              className="feed-tab"
              aria-pressed={scope === 'critical'}
              aria-label="Show only critical requests"
              onClick={() => scope !== 'critical' && applyScope('critical')}
            >
              Critical
            </button>
          </div>

          {!data ? (
            <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
              <p className="muted">{errorAlert ? 'Could not load blood requests.' : 'Loading blood requests…'}</p>
              {errorAlert && (
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => load()}>Retry</button>
              )}
            </div>
          ) : data.requests.length === 0 ? (
            <div className="empty-state">
              <h3>{emptyCopy.title}</h3>
              <p>{emptyCopy.body}</p>
              <div className="button-group" style={{ justifyContent: 'center' }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => applyFilters({ blood_type: '', urgency: '', chapter_id: '', near_me: false })}
                >
                  Clear filters
                </button>
                <Link to="/requests/new" className="btn">Create Blood Request</Link>
              </div>
            </div>
          ) : (
            <>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                {data.requests.map((r) => (
                  <FeedCard
                    key={r.id}
                    item={r}
                    onRespond={onRespond}
                    respondingId={respondingId}
                    consent={consent}
                    setConsent={setConsent}
                  />
                ))}
              </div>

              {totalPages > 1 && (
                <nav aria-label="Feed pages" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', marginTop: 'var(--space-6)', flexWrap: 'wrap' }}>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    disabled={page <= 1}
                    onClick={() => gotoPage(page - 1)}
                  >
                    ← Newer
                  </button>
                  <span className="muted" style={{ fontSize: '0.8125rem' }} aria-live="polite">
                    Page {page} of {totalPages} · {data.total} open request{data.total === 1 ? '' : 's'}
                  </span>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    disabled={page >= totalPages}
                    onClick={() => gotoPage(page + 1)}
                  >
                    Older →
                  </button>
                </nav>
              )}
            </>
          )}
        </div>

        <aside className="feed-rail" aria-label="Feed filters and context">
          <details className="card" open>
            <summary style={{ fontWeight: 700, cursor: 'pointer' }}>Filters</summary>
            <div className="form" style={{ marginTop: 'var(--space-3)' }}>
              <div className="field">
                <label htmlFor="feed-blood">Blood type</label>
                <select
                  id="feed-blood"
                  value={filters.blood_type}
                  onChange={(e) => applyFilters({ ...filters, blood_type: e.target.value })}
                >
                  <option value="">All blood types</option>
                  {BLOOD_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>

              <div className="field">
                <label htmlFor="feed-urgency">Urgency</label>
                <select
                  id="feed-urgency"
                  value={filters.urgency}
                  onChange={(e) => applyFilters({ ...filters, urgency: e.target.value })}
                >
                  <option value="">All urgencies</option>
                  {URGENCIES.map((u) => <option key={u} value={u}>{u}</option>)}
                </select>
              </div>

              <div className="field">
                <label htmlFor="feed-chapter">Chapter</label>
                <select
                  id="feed-chapter"
                  value={filters.chapter_id}
                  onChange={(e) => applyFilters({ ...filters, chapter_id: e.target.value })}
                >
                  <option value="">All chapters</option>
                  {chapters.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </div>

              <div className="field">
                <label style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'flex-start' }}>
                  <input
                    type="checkbox"
                    checked={filters.near_me}
                    disabled={!hasLocation}
                    onChange={(e) => applyFilters({ ...filters, near_me: e.target.checked })}
                    style={{ marginTop: '0.2rem' }}
                  />
                  <span>Near you</span>
                </label>
                {!hasLocation && (
                  <small className="field-hint">
                    Set your location in <Link to="/profile">Profile</Link> to use Near You.
                  </small>
                )}
              </div>
            </div>
          </details>

          <div className="medical-disclaimer" role="note" aria-label="Medical Disclaimer">
            <div aria-hidden="true"><Info size={20} weight="regular" /></div>
            <div>
              <strong>Clinical confirmation required.</strong> Matches are advisory and do not replace crossmatching or physician review at the facility.
            </div>
          </div>
        </aside>
      </div>
    </div>
  )
}
