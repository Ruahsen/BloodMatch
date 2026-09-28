import { useEffect, useState } from 'react'
import { api } from '../services/apiClient'

/**
 * Cascading Bataan location selector (municipality/city -> optional barangay).
 * Calls onChange({ location_id, municipality_code, barangay_code }) whenever the
 * resolved selection changes. Backend remains the authority for coordinates.
 */
export default function LocationSelector({
  municipalityId,
  municipalityCode,
  barangayCode,
  onChange,
  errors = {},
  municipalityLabel = 'Municipality / City',
  required = true
}) {
  const [municipalities, setMunicipalities] = useState([])
  const [barangays, setBarangays] = useState([])
  const [loadError, setLoadError] = useState(null)

  useEffect(() => {
    api
      .get('/api/locations/municipalities')
      .then((d) => setMunicipalities(d.municipalities || []))
      .catch((err) => setLoadError(err.message))
  }, [])

  useEffect(() => {
    if (!municipalityCode) {
      setBarangays([])
      return undefined
    }
    let active = true
    api
      .get(`/api/locations/barangays?municipality_code=${encodeURIComponent(municipalityCode)}`)
      .then((d) => {
        if (active) setBarangays(d.barangays || [])
      })
      .catch((err) => {
        if (active) setLoadError(err.message)
      })
    return () => {
      active = false
    }
  }, [municipalityCode])

  const handleMunicipality = (e) => {
    const code = e.target.value
    const muni = municipalities.find((m) => m.psgc_code === code)
    // Reset barangay: the previous selection may not belong here.
    onChange({
      location_id: muni ? muni.location_id : null,
      municipality_code: code || null,
      barangay_code: null
    })
  }

  const handleBarangay = (e) => {
    const code = e.target.value
    const brgy = barangays.find((b) => b.psgc_code === code)
    onChange({
      location_id: brgy ? brgy.location_id : (municipalities.find((m) => m.psgc_code === municipalityCode)?.location_id ?? null),
      municipality_code: municipalityCode,
      barangay_code: code || null
    })
  }

  return (
    <>
      <div className="field">
        <label htmlFor={municipalityId}>{municipalityLabel}{required ? ' *' : ''}</label>
        <select
          id={municipalityId}
          value={municipalityCode || ''}
          onChange={handleMunicipality}
          required={required}
        >
          <option value="">Select Municipality / City…</option>
          {municipalities.map((m) => (
            <option key={m.psgc_code} value={m.psgc_code}>{m.name}</option>
          ))}
        </select>
        {loadError && <span className="field-error">{loadError}</span>}
        {errors.municipality_code && <span className="field-error">{errors.municipality_code.join(' ')}</span>}
        {errors.location_id && <span className="field-error">{errors.location_id.join(' ')}</span>}
      </div>

      <div className="field">
        <label htmlFor={`${municipalityId}-barangay`}>Barangay</label>
        <select
          id={`${municipalityId}-barangay`}
          value={barangayCode || ''}
          onChange={handleBarangay}
          disabled={!municipalityCode || barangays.length === 0}
        >
          <option value="">Select Barangay… (optional)</option>
          {barangays.map((b) => (
            <option key={b.psgc_code} value={b.psgc_code}>{b.name}</option>
          ))}
        </select>
        <small className="field-hint">Barangay is optional and helps identify your location within the selected municipality.</small>
        {errors.barangay_code && <span className="field-error">{errors.barangay_code.join(' ')}</span>}
      </div>
    </>
  )
}
