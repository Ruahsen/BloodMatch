import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../services/apiClient'
import LocationSelector from '../components/LocationSelector'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const URGENCIES = [
  { id: 'routine', label: 'Routine (Scheduled surgeries / standard transfusions)' },
  { id: 'urgent', label: 'Urgent (Required within 24 hours)' },
  { id: 'critical', label: 'Critical (Immediate emergency / trauma)' }
]

export default function RequestFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()

  const [form, setForm] = useState({
    required_blood_type: '',
    quantity_units: 1,
    facility_name: '',
    urgency: 'routine',
    needed_datetime: ''
  })
  const [location, setLocation] = useState({ location_id: null, municipality_code: null, barangay_code: null })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [loaded, setLoaded] = useState(!editing)

  useEffect(() => {
    if (!editing) return
    api
      .get(`/api/requests/${id}`)
      .then((data) => {
        const r = data.request
        setForm({
          required_blood_type: r.required_blood_type,
          quantity_units: r.quantity_units,
          facility_name: r.facility_name,
          urgency: r.urgency,
          needed_datetime: (r.needed_datetime || '').slice(0, 16).replace(' ', 'T')
        })
        const loc = r.location
        setLocation({
          location_id: loc?.location_id ?? null,
          municipality_code: loc?.municipality_code ?? null,
          barangay_code: loc?.barangay_code ?? null
        })
        setLoaded(true)
      })
      .catch((err) => setMessage(err.message))
  }, [editing, id])

  const setField = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }))

  const onSubmit = async (e) => {
    e.preventDefault()
    setErrors({})
    setMessage(null)
    setSubmitting(true)
    try {
      const payload = { ...form, location_id: location.location_id }
      if (editing) {
        await api.put(`/api/requests/${id}`, payload)
      } else {
        await api.post('/api/requests', payload)
      }
      navigate('/requests/mine')
    } catch (err) {
      if (err.details && Object.keys(err.details).length > 0) {
        setErrors(err.details)
      } else {
        setMessage(err.message || 'Failed to save request.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (!loaded) {
    return (
      <div className="container narrow">
        <div className="card text-center" style={{ padding: 'var(--space-8)' }}>
          <p className="muted">Loading request details…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="container narrow">
      <div style={{ marginBottom: 'var(--space-6)' }}>
        <h1 style={{ marginBottom: 'var(--space-2)' }}>
          {editing ? `Edit Blood Request #${id}` : 'Create Blood Request'}
        </h1>
      </div>

      {message && <div className="alert alert-error" role="alert">{message}</div>}

      <div className="card">
        <form className="form" onSubmit={onSubmit} noValidate>
          <div className="field">
            <label htmlFor="required_blood_type">Required Blood Type *</label>
            <select
              id="required_blood_type"
              value={form.required_blood_type}
              onChange={setField('required_blood_type')}
              required
            >
              <option value="">Select blood type…</option>
              {BLOOD_TYPES.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
            {errors.required_blood_type && <span className="field-error">{errors.required_blood_type.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="quantity_units">Quantity Needed (Units) *</label>
            <input
              id="quantity_units"
              type="number"
              min={1}
              max={10}
              value={form.quantity_units}
              onChange={setField('quantity_units')}
              required
            />
            <small className="field-hint">Standard whole blood units (1–10 units per request).</small>
            {errors.quantity_units && <span className="field-error">{errors.quantity_units.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="facility_name">Healthcare Facility / Hospital Name *</label>
            <input
              id="facility_name"
              placeholder="e.g. Bataan General Hospital and Medical Center"
              value={form.facility_name}
              onChange={setField('facility_name')}
              maxLength={150}
              required
            />
            {errors.facility_name && <span className="field-error">{errors.facility_name.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="needed_datetime">Date & Time Needed *</label>
            <input
              id="needed_datetime"
              type="datetime-local"
              value={form.needed_datetime}
              onChange={setField('needed_datetime')}
              required
            />
            <small className="field-hint">Requests automatically expire once the specified deadline has passed.</small>
            {errors.needed_datetime && <span className="field-error">{errors.needed_datetime.join(' ')}</span>}
          </div>

          <div className="field">
            <label htmlFor="urgency">Urgency Level *</label>
            <select id="urgency" value={form.urgency} onChange={setField('urgency')} required>
              {URGENCIES.map((u) => (
                <option key={u.id} value={u.id}>{u.label}</option>
              ))}
            </select>
            {errors.urgency && <span className="field-error">{errors.urgency.join(' ')}</span>}
          </div>

          <div className="field" role="group" aria-labelledby="request-location-heading">
            <span id="request-location-heading" className="metric-label" style={{ display: 'block', marginBottom: 'var(--space-2)' }}>Facility Location</span>
            <small className="field-hint" style={{ display: 'block', marginBottom: 'var(--space-3)' }}>
              Select the municipality or city of the facility and optionally its barangay. This helps
              BloodMatch prioritize compatible donors who are closer to where the blood is needed.
              BloodMatch uses an approximate geographic reference for proximity ranking.
            </small>
            <LocationSelector
              municipalityId="request-municipality"
              municipalityCode={location.municipality_code}
              barangayCode={location.barangay_code}
              onChange={setLocation}
              errors={errors}
              required={false}
            />
            <small className="field-hint">Exact coordinates are never shown to members; only approximate distances are displayed.</small>
          </div>

          <div className="button-group" style={{ marginTop: 'var(--space-3)' }}>
            <button type="submit" className="btn" disabled={submitting}>
              {submitting ? 'Saving…' : (editing ? 'Update Request' : 'Publish Request')}
            </button>
            <Link to="/requests/mine" className="btn btn-secondary">
              Cancel
            </Link>
          </div>
        </form>
      </div>
    </div>
  )
}
