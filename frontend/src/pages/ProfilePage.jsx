import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/apiClient'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const DOC_TYPES = [
  { id: 'national_id', label: 'Government-Issued ID (National ID, Passport, Driver\'s License)' },
  { id: 'donor_card', label: 'Official Blood Donor Card (Philippine Red Cross / DOH)' },
  { id: 'parental_consent', label: 'Parental / Guardian Consent Form (Ages 16–17)' }
]

export default function ProfilePage() {
  const [profile, setProfile] = useState(null)
  const [form, setForm] = useState(null)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [docType, setDocType] = useState('national_id')
  const [file, setFile] = useState(null)
  const [reports, setReports] = useState([])
  const [submitting, setSubmitting] = useState(false)
  const [enrolling, setEnrolling] = useState(false)

  const load = () =>
    api
      .get('/api/profile')
      .then((data) => {
        setProfile(data.profile)
        setForm({
          full_name: data.profile.full_name,
          phone: data.profile.phone || '',
          date_of_birth: data.profile.date_of_birth || '',
          blood_type: data.profile.blood_type || '',
          latitude: data.profile.latitude ?? '',
          longitude: data.profile.longitude ?? ''
        })
        if (data.profile.role === 'member') {
          return api.get('/api/my/donation-reports').then((d) => setReports(d.reports || []))
        }
        return null
      })
      .catch((err) => setErrorAlert(err.message))

  const setAvailability = async (value) => {
    setMessage(null)
    setErrorAlert(null)
    try {
      await api.post('/api/profile/donor-availability', { availability: value })
      await load()
      setMessage(`Donor availability updated to ${value.toUpperCase()}.`)
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  const enrollAsDonor = async () => {
    setEnrolling(true)
    setMessage(null)
    setErrorAlert(null)
    try {
      await api.post('/api/profile/enroll-donor')
      await load()
      setMessage('Successfully enrolled as a volunteer blood donor! You will now be matched when compatible requests arise in your area.')
    } catch (err) {
      setErrorAlert(err.message)
    } finally {
      setEnrolling(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  const setField = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }))

  const onSave = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    setErrorAlert(null)
    setSubmitting(true)
    try {
      const data = await api.put('/api/profile', {
        full_name: form.full_name,
        phone: form.phone || null,
        date_of_birth: form.date_of_birth || null,
        blood_type: form.blood_type || null,
        latitude: form.latitude === '' ? null : Number(form.latitude),
        longitude: form.longitude === '' ? null : Number(form.longitude)
      })
      setProfile(data.profile)
      setMessage('Profile details saved successfully.')
    } catch (err) {
      if (err.details && Object.keys(err.details).length > 0) {
        setErrors(err.details)
      } else {
        setErrorAlert(err.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  const onUpload = async (e) => {
    e.preventDefault()
    setMessage(null)
    setErrorAlert(null)
    if (!file) {
      setErrorAlert('Please select a file to upload.')
      return
    }
    try {
      await api.upload('/api/profile/documents', file, { doc_type: docType })
      setFile(null)
      await load()
      setMessage('Document uploaded successfully.')
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  const onResubmit = async () => {
    setMessage(null)
    setErrorAlert(null)
    try {
      await api.post('/api/profile/resubmit')
      await load()
      setMessage('Verification resubmitted. An officer will review your updated documents.')
    } catch (err) {
      setErrorAlert(err.message)
    }
  }

  if (!profile || !form) {
    return (
      <div className="container narrow">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading profile…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="container" style={{ maxWidth: '800px' }}>
      <header className="app-header">
        <div>
          <h1>Member Profile & Status</h1>
        </div>
      </header>

      {message && <div className="alert alert-success" role="status">{message}</div>}
      {errorAlert && <div className="alert alert-error" role="alert">{errorAlert}</div>}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-6)' }}>
        {/* Section 1: Account Status & Capabilities */}
        <section className="card">
          <div className="card-header">
            <h3>Verification & Account Status</h3>
            <span className={`badge ${profile.verification_status === 'verified' ? 'badge-verified' : ''}`}>
              {profile.verification_status.toUpperCase()}
            </span>
          </div>

          <div className="grid-3" style={{ marginBottom: 'var(--space-4)' }}>
            <div>
              <span className="metric-label">Chapter</span>
              <p style={{ margin: 0, fontWeight: 600 }}>{profile.chapter_name || `Chapter ID ${profile.chapter_id}`}</p>
            </div>
            <div>
              <span className="metric-label">Role</span>
              <p style={{ margin: 0, fontWeight: 600, textTransform: 'capitalize' }}>{profile.role}</p>
            </div>
            <div>
              <span className="metric-label">Account Status</span>
              <p style={{ margin: 0, fontWeight: 600, textTransform: 'capitalize' }}>{profile.account_status}</p>
            </div>
          </div>

          {profile.capabilities && (
            <div style={{ padding: 'var(--space-3)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)', fontSize: '0.8125rem' }}>
              <strong>Active Capabilities:</strong> Browse Requests: {profile.capabilities.browse_requests ? 'Yes' : 'No'} · Create Blood Requests: {profile.capabilities.create_request ? 'Yes' : 'No'} · Matched as Donor: {profile.capabilities.appear_as_donor ? 'Yes' : 'No'}
            </div>
          )}

          {profile.verification_status === 'rejected' && (
            <div style={{ marginTop: 'var(--space-4)', padding: 'var(--space-3)', border: '1px solid var(--color-danger-border)', borderRadius: 'var(--radius-md)', background: 'var(--color-danger-subtle)' }}>
              <p style={{ color: 'var(--color-danger)', fontWeight: 600, margin: '0 0 var(--space-2)' }}>Verification Needs Attention</p>
              <p className="muted" style={{ fontSize: '0.875rem', marginBottom: 'var(--space-3)' }}>
                Your verification was rejected by a chapter officer. Please review and upload clear identification or donor documents below, then resubmit for review.
              </p>
              <button type="button" className="btn" onClick={onResubmit}>
                Resubmit for Verification
              </button>
            </div>
          )}
        </section>

        {/* Section 2: Donor Enrollment & Availability */}
        {profile.role === 'member' && (
          <section className="card">
            <div className="card-header">
              <h3>Volunteer Donor Participation</h3>
              {profile.donor_enrolled ? (
                <span className={`badge ${profile.availability_window?.blocked ? 'badge-standby' : (profile.availability === 'available' ? 'badge-available' : 'badge-cooldown')}`}>
                  {profile.availability_window?.blocked
                    ? profile.availability_window.which.toUpperCase()
                    : (profile.availability ? profile.availability.toUpperCase() : 'ENROLLED')}
                </span>
              ) : (
                <span className="badge">NOT ENROLLED</span>
              )}
            </div>

            {profile.verification_status !== 'verified' ? (
              <p className="muted" style={{ margin: 0, fontSize: '0.875rem' }}>
                Donor enrollment requires verified member status. Once your submitted documents are verified by an officer, you can opt in to receive compatibility match notifications.
              </p>
            ) : !profile.donor_enrolled ? (
              <div>
                <p className="muted" style={{ fontSize: '0.875rem', marginBottom: 'var(--space-4)' }}>
                  You are eligible to enroll as a volunteer blood donor. When local patients create blood requests matching your blood type, you will receive automated match notifications.
                </p>
                <button type="button" className="btn" onClick={enrollAsDonor} disabled={enrolling}>
                  {enrolling ? 'Enrolling…' : 'Enroll as Volunteer Donor'}
                </button>
              </div>
            ) : (
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 'var(--space-3)', marginBottom: 'var(--space-4)' }}>
                  <div>
                    <span className="metric-label">Current Availability Status</span>
                    <p style={{ margin: 0, fontWeight: 600 }}>
                      {profile.availability_window?.blocked
                        ? `Locked (${profile.availability_window.which.toUpperCase()} window until ${profile.availability_window.ends_at_utc} UTC)`
                        : (profile.availability === 'available' ? 'Available to receive match alerts' : 'Unavailable (Temporarily paused)')}
                    </p>
                  </div>

                  {!profile.availability_window?.blocked && (
                    <div className="button-group">
                      <button
                        type="button"
                        className={profile.availability === 'available' ? 'btn btn-sm' : 'btn btn-secondary btn-sm'}
                        disabled={profile.availability === 'available'}
                        onClick={() => setAvailability('available')}
                      >
                        Set Available
                      </button>
                      <button
                        type="button"
                        className={profile.availability === 'unavailable' ? 'btn btn-sm' : 'btn btn-secondary btn-sm'}
                        disabled={profile.availability === 'unavailable'}
                        onClick={() => setAvailability('unavailable')}
                      >
                        Set Unavailable
                      </button>
                    </div>
                  )}
                </div>

                {profile.availability_window?.blocked && (
                  <div className="alert" style={{ margin: 0, fontSize: '0.8125rem' }}>
                    <strong>Post-Donation {profile.availability_window.which === 'standby' ? 'Standby' : 'Cooldown'} Active:</strong> Changes to availability are locked for {Math.ceil(profile.availability_window.remaining_seconds / 3600)} hour(s). This standard interval protects donor health and ensures adequate recovery before subsequent donations.
                  </div>
                )}
              </div>
            )}
          </section>
        )}

        {/* Section 3: Edit Personal & Medical Details */}
        <section className="card">
          <div className="card-header">
            <h3>Personal & Location Details</h3>
          </div>

          <form className="form" onSubmit={onSave} noValidate>
            <div className="field">
              <label htmlFor="full_name">Full Name</label>
              <input id="full_name" value={form.full_name} onChange={setField('full_name')} required maxLength={150} />
              {errors.full_name && <span className="field-error">{errors.full_name.join(' ')}</span>}
            </div>

            <div className="grid-2">
              <div className="field">
                <label htmlFor="phone">Phone Number</label>
                <input id="phone" type="tel" value={form.phone} onChange={setField('phone')} placeholder="0917-123-4567" />
                {errors.phone && <span className="field-error">{errors.phone.join(' ')}</span>}
              </div>

              <div className="field">
                <label htmlFor="dob">Date of Birth</label>
                <input id="dob" type="date" value={form.date_of_birth} onChange={setField('date_of_birth')} />
                {errors.date_of_birth && <span className="field-error">{errors.date_of_birth.join(' ')}</span>}
              </div>
            </div>

            <div className="field">
              <label htmlFor="blood_type">Blood Type</label>
              <select id="blood_type" value={form.blood_type} onChange={setField('blood_type')}>
                <option value="">Unknown / Not sure</option>
                {BLOOD_TYPES.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </select>
              <small className="field-hint">
                Provenance: {profile.blood_type_source || 'Unspecified'} · {profile.blood_type_verified ? 'Verified by Officer against physical donor card' : 'Self-Reported'}
              </small>
              {errors.blood_type && <span className="field-error">{errors.blood_type.join(' ')}</span>}
            </div>

            <div className="field">
              <label htmlFor="lat">Location Coordinates (Optional, used for proximity ranking)</label>
              <div className="grid-2">
                <input id="lat" value={form.latitude} onChange={setField('latitude')} placeholder="Latitude (-90 to 90)" />
                <input id="lng" value={form.longitude} onChange={setField('longitude')} placeholder="Longitude (-180 to 180)" />
              </div>
              <small className="field-hint">Your exact coordinates are never exposed to other members; only anonymized distances are displayed.</small>
              {(errors.latitude || errors.longitude || errors.location) && (
                <span className="field-error">
                  {(errors.latitude || errors.longitude || errors.location || []).join(' ')}
                </span>
              )}
            </div>

            <button type="submit" className="btn" disabled={submitting} style={{ alignSelf: 'flex-start' }}>
              {submitting ? 'Saving…' : 'Save Changes'}
            </button>
          </form>
        </section>

        {/* Section 4: Document Verification */}
        <section className="card">
          <div className="card-header">
            <h3>Verification Documents</h3>
          </div>

          <div style={{ marginBottom: 'var(--space-4)' }}>
            {profile.documents.length === 0 ? (
              <p className="muted" style={{ fontSize: '0.875rem' }}>No verification documents uploaded yet. Upload a government ID or official donor card below to expedite your verification.</p>
            ) : (
              <div className="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>Document Type</th>
                      <th>Format</th>
                      <th>Size</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.documents.map((d) => (
                      <tr key={d.id}>
                        <td><strong>{d.doc_type.replace('_', ' ').toUpperCase()}</strong></td>
                        <td><code>{d.mime_type}</code></td>
                        <td>{Math.ceil(d.size_bytes / 1024)} KB</td>
                        <td>
                          <a href={`/api/profile/documents/${d.id}/file`} target="_blank" rel="noreferrer" className="btn btn-secondary btn-sm">
                            View File ↗
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <form onSubmit={onUpload} className="form" style={{ padding: 'var(--space-4)', background: 'var(--color-surface-sunken)', borderRadius: 'var(--radius-md)' }}>
            <h4 style={{ margin: 0 }}>Upload Supporting Document</h4>
            <div className="field">
              <label htmlFor="doc_type">Document Category</label>
              <select id="doc_type" value={docType} onChange={(e) => setDocType(e.target.value)}>
                {DOC_TYPES.map((t) => (
                  <option key={t.id} value={t.id}>{t.label}</option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor="doc_file">File (JPG, PNG, WEBP, or PDF, Max 5 MB)</label>
              <input
                id="doc_file"
                type="file"
                accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                onChange={(e) => setFile(e.target.files[0] || null)}
              />
            </div>

            <button type="submit" className="btn btn-secondary" style={{ alignSelf: 'flex-start' }}>
              Upload Document
            </button>
          </form>
        </section>

        {/* Section 5: Donation History */}
        {profile.role === 'member' && (
          <section className="card">
            <div className="card-header">
              <h3>My Donation History</h3>
            </div>

            {reports.length === 0 ? (
              <p className="muted" style={{ fontSize: '0.875rem', margin: 0 }}>
                No completed donation reports recorded yet.
              </p>
            ) : (
              <div className="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>Report ID</th>
                      <th>Blood Type</th>
                      <th>Facility</th>
                      <th>Status</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    {reports.map((rep) => (
                      <tr key={rep.id}>
                        <td><code>#{rep.id}</code></td>
                        <td><strong>{rep.required_blood_type}</strong></td>
                        <td>{rep.facility_name}</td>
                        <td>
                          <span className={`badge ${rep.status === 'CONFIRMED' ? 'badge-verified' : (rep.status === 'REJECTED' ? 'badge-critical' : 'badge-standby')}`}>
                            {rep.status}
                          </span>
                        </td>
                        <td>{new Date(rep.reported_at.replace(' ', 'T') + 'Z').toLocaleDateString()}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}
      </div>
    </div>
  )
}
