import React, { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Camera, CheckCircle, Drop, FileText, UploadSimple, User, ChatCircleDots } from '@phosphor-icons/react'
import { api } from '../services/apiClient'
import { useAuth } from '../context/AuthContext'
import { useChat } from '../context/ChatContext'
import PrivacyConsentModal from '../components/PrivacyConsentModal'
import LocationSelector from '../components/LocationSelector'
import RequestFormModal from '../components/RequestFormModal'
import ConfirmationDialog from '../components/ConfirmationDialog'
import { useRequestCreation } from '../context/RequestCreationContext'
import { FeedCard } from '../components/FeedCard'
import { Card } from '../components/ui/Card'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { Badge, StatusPill } from '../components/ui/Badge'
import { LoadingSpinner } from '../components/ui/LoadingSpinner'
import styles from './ProfilePage.module.css'

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']

function formatDate(value, fallback = 'Not provided') {
  if (!value) return fallback
  return new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`).toLocaleDateString()
}

function titleCase(value) {
  if (!value) return 'Not provided'
  return value.charAt(0).toUpperCase() + value.slice(1).toLowerCase()
}

function formatMemberSince(value) {
  if (!value) return 'Not provided'
  return new Date(`${value.slice(0, 10)}T00:00:00Z`).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
}

function formatPostedTime(value) {
  if (!value) return 'Posted time not provided'
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(value.replace(' ', 'T') + 'Z').getTime()) / 1000))
  if (!Number.isFinite(seconds)) return 'Posted time not provided'
  if (seconds < 60) return 'Posted just now'
  const [count, unit] = seconds < 3600 ? [Math.floor(seconds / 60), 'minute'] : seconds < 86400 ? [Math.floor(seconds / 3600), 'hour'] : [Math.floor(seconds / 86400), 'day']
  return `Posted ${count} ${unit}${count === 1 ? '' : 's'} ago`
}

export default function ProfilePage() {
  const { id: profileId } = useParams()
  const isOtherProfile = Boolean(profileId)
  const { user, refresh } = useAuth()
  const { openChatWithUser } = useChat()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(null)
  const [location, setLocation] = useState({ location_id: null, municipality_code: null, barangay_code: null })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [errorAlert, setErrorAlert] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [enrolling, setEnrolling] = useState(false)
  const [file, setFile] = useState(null)
  const [documentInputKey, setDocumentInputKey] = useState(0)
  const [documentUploading, setDocumentUploading] = useState(false)
  const [failedDocumentPreviewId, setFailedDocumentPreviewId] = useState(null)
  const [idPrivacyModalOpen, setIdPrivacyModalOpen] = useState(false)
  const [pictureInputKey, setPictureInputKey] = useState(0)
  const [pictureUploading, setPictureUploading] = useState(false)
  const [pictureFailed, setPictureFailed] = useState(false)
  const pictureInputRef = useRef(null)
  const [activeSection, setActiveSection] = useState('overview')
  const [requestFilter, setRequestFilter] = useState('ALL')
  const [editingRequestId, setEditingRequestId] = useState(null)
  const openCreateRequest = useRequestCreation()
  const [cancelTarget, setCancelTarget] = useState(null)
  const [cancelling, setCancelling] = useState(false)
  const [cancelError, setCancelError] = useState(null)
  const cancelBusyRef = useRef(false)

  const profileQueryKey = isOtherProfile
    ? (user?.id ? ['member-profile', profileId, user?.id] : ['member-profile', profileId])
    : (user?.id ? ['profile', user.id] : ['profile'])

  const isProfileEnabled = isOtherProfile
    ? true
    : (Boolean(user?.id) || Boolean(queryClient.getQueryData(['profile'])))

  const { data, isLoading, error: profileError, refetch } = useQuery({
    queryKey: profileQueryKey,
    enabled: isProfileEnabled,
    queryFn: async () => {
      const response = await api.get(isOtherProfile ? `/api/profile/${profileId}` : '/api/profile')
      if (isOtherProfile) {
        return { profile: response.profile, reports: [], requests: [] }
      }
      const [donations, requests] = await Promise.all([
        api.get('/api/my/donation-reports'),
        api.get('/api/my/requests')
      ])
      return {
        profile: response.profile,
        reports: donations.reports || [],
        requests: requests.requests || []
      }
    },
    retry: false
  })

  const profile = data?.profile
  const reports = data?.reports || []
  const requestHistory = data?.requests || []
  const filteredRequests = requestFilter === 'ALL' ? requestHistory : requestHistory.filter((request) => request.status === requestFilter)

  useEffect(() => setPictureFailed(false), [profile?.profile_picture_url])

  const showError = (error) => setErrorAlert(error?.message || 'The change could not be saved.')
  const clearNotices = () => { setMessage(null); setErrorAlert(null) }
  const closeRequestForm = useCallback(() => { setEditingRequestId(null) }, [])
  const handleRequestSaved = async () => {
    const wasEditing = Boolean(editingRequestId)
    closeRequestForm()
    queryClient.invalidateQueries({ queryKey: ['my-requests'] })
    queryClient.invalidateQueries({ queryKey: ['request-matches', String(editingRequestId)] })
    queryClient.invalidateQueries({ queryKey: ['home-feed'] })
    await refetch()
    setMessage(wasEditing ? 'Request details updated.' : 'Blood request created and matching started.')
  }

  const enrollAsDonor = async () => {
    clearNotices()
    setEnrolling(true)
    try {
      await api.post('/api/profile/enroll-donor')
      queryClient.invalidateQueries({ queryKey: ['home-feed'] })
      queryClient.invalidateQueries({ queryKey: ['request-matches'] })
      await Promise.all([refetch(), refresh()])
      setMessage('You are now enrolled and available for compatible donor matches.')
    } catch (error) {
      showError(error)
    } finally {
      setEnrolling(false)
    }
  }

  const onPictureUpload = async (pictureFile) => {
    if (!pictureFile) return
    clearNotices()
    setPictureUploading(true)
    try {
      await api.upload('/api/profile/picture', pictureFile)
      setPictureInputKey((key) => key + 1)
      await Promise.all([refetch(), refresh()])
      setMessage('Profile picture updated.')
    } catch (error) {
      showError(error)
    } finally {
      setPictureUploading(false)
    }
  }

  const setField = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))

  const onSave = async (event) => {
    event.preventDefault()
    clearNotices()
    setErrors({})
    if (!location.location_id) {
      setErrors({ location_id: ['Please select your municipality or city.'] })
      return
    }
    setSubmitting(true)
    try {
      await api.put('/api/profile', {
        first_name: form.first_name,
        middle_name: form.middle_name || null,
        last_name: form.last_name,
        phone: form.phone || null,
        date_of_birth: form.date_of_birth || null,
        blood_type: form.blood_type || null,
        location_id: location.location_id
      })
      await Promise.all([refetch(), refresh()])
      setMessage('Profile details saved.')
      setActiveSection('overview')
    } catch (error) {
      if (error.details && Object.keys(error.details).length) setErrors(error.details)
      else showError(error)
    } finally {
      setSubmitting(false)
    }
  }

  const openProfileEditor = () => {
    setForm({ first_name: profile.first_name || '', middle_name: profile.middle_name || '', last_name: profile.last_name || '', phone: profile.phone || '', date_of_birth: profile.date_of_birth || '', blood_type: profile.blood_type || '' })
    setLocation({ location_id: profile.location?.location_id ?? null, municipality_code: profile.location?.municipality_code ?? null, barangay_code: profile.location?.barangay_code ?? null })
    setErrors({})
    setActiveSection('edit')
  }

  const cancelRequest = async () => {
    if (!cancelTarget || cancelBusyRef.current) return
    cancelBusyRef.current = true
    clearNotices()
    setCancelError(null)
    setCancelling(true)
    try {
      await api.post(`/api/requests/${cancelTarget.id}/cancel`)
      queryClient.invalidateQueries({ queryKey: ['my-requests'] })
      queryClient.invalidateQueries({ queryKey: ['request-matches', String(cancelTarget.id)] })
      queryClient.invalidateQueries({ queryKey: ['home-feed'] })
      setCancelTarget(null)
      await refetch()
      setMessage('Blood request cancelled.')
    } catch (error) { setCancelError(error.message || 'The request could not be cancelled. Try again.') }
    finally { cancelBusyRef.current = false; setCancelling(false) }
  }

  const onUpload = async (event) => {
    event.preventDefault()
    clearNotices()
    if (profile.verification_status === 'verified') {
      setErrorAlert('National ID uploads are locked after verification.')
      return
    }
    if (!file) {
      setErrorAlert('Select a National ID file to upload.')
      return
    }
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
    if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
      setErrorAlert('Choose a JPG, PNG, WEBP, or PDF file no larger than 5 MB.')
      return
    }
    setDocumentUploading(true)
    try {
      await api.upload('/api/profile/documents', file, { doc_type: 'national_id', privacy_acknowledged: '1' })
      setFile(null)
      setDocumentInputKey((key) => key + 1)
      await refetch()
      setMessage('National ID submitted for review.')
    } catch (error) {
      showError(error)
    } finally {
      setDocumentUploading(false)
    }
  }

  if (isLoading && !profile) return <Card padding="lg" className={styles.loadingCard}><LoadingSpinner text="Loading profile…" /></Card>

  if (profileError && !profile) {
    return (
      <div className={styles.container}>
        <Card padding="lg" className={styles.errorCard}>
          <h1>{profileError.status === 404 ? 'This profile isn’t available' : 'Profile unavailable'}</h1>
          <p>{profileError.message}</p>
          <Button variant="secondary" onClick={() => navigate(-1)}><ArrowLeft size={16} /> Go back</Button>
        </Card>
      </div>
    )
  }

  if (isOtherProfile) {
    return (
      <div className={styles.container}>
        <div className={styles.backRow}><Button variant="secondary" size="sm" onClick={() => navigate(-1)}><ArrowLeft size={16} /> Back</Button></div>
        <header className={styles.profileHeader}>
          <div className={styles.headerInner}>
            <div className={styles.avatarWrapper}>
              {profile.profile_picture_url ? <img className={styles.avatarLarge} src={profile.profile_picture_url} alt={`${profile.full_name}'s profile picture`} /> : <span className={styles.avatarLarge} role="img" aria-label="No profile picture"><User size={64} aria-hidden="true" /></span>}
            </div>
            <div className={styles.headerIdentity}>
              <div className={styles.nameLine}><h1>{profile.full_name}</h1>{profile.verification_status === 'verified' && <CheckCircle size={23} weight="fill" aria-label="Verified account" />}</div>
              <p>{profile.chapter_name ? `${profile.chapter_name} member` : profile.role_label}</p>
            </div>
            {user && user.id !== Number(profileId) && (
              <div className={styles.headerActions}>
                <Button
                  variant="secondary"
                  onClick={() => openChatWithUser(Number(profileId), { name: profile.full_name, avatar: profile.profile_picture_url })}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
                >
                  <ChatCircleDots size={18} weight="regular" /> Message
                </Button>
              </div>
            )}
          </div>
        </header>
        <main className={styles.profileLayout}>
          <Card className={styles.aboutCard}>
            <div className={styles.cardHeader}><h2>About</h2></div>
            <dl className={styles.aboutDetails}>
              <div><dt>Blood type</dt><dd>{profile.blood_type || 'Not provided'}</dd></div>
              <div><dt>Chapter</dt><dd>{profile.chapter_name || 'Not assigned'}</dd></div>
              <div><dt>Member since</dt><dd>{formatMemberSince(profile.member_since)}</dd></div>
              <div><dt>Verification</dt><dd>{titleCase(profile.verification_status)}</dd></div>
              {profile.email && <div><dt>Email</dt><dd><a href={`mailto:${profile.email}`}>{profile.email}</a></dd></div>}
              {profile.role_label && <div><dt>Role</dt><dd>{profile.role_label}</dd></div>}
            </dl>
          </Card>
        </main>
      </div>
    )
  }

  if (!profile) return <Card padding="lg" className={styles.loadingCard}><LoadingSpinner text="Loading profile…" /></Card>

  const canCreateRequest = profile.capabilities?.create_request === true
  const nationalIdDocuments = (profile.documents || [])
    .filter((document) => document.doc_type === 'national_id')
    .sort((a, b) => b.id - a.id)
  const availabilityBlocked = Boolean(profile.availability_window?.blocked)
  const availabilityStatus = availabilityBlocked
    ? `${titleCase(profile.availability_window.which)} until ${formatDate(profile.availability_window.ends_at_utc)}`
    : profile.donor_enrolled
      ? (profile.availability === 'available' ? 'Available to donate' : 'Unavailable for donor matches')
      : 'Not enrolled as a donor'

  return (
    <div className={styles.container}>
      <div className={styles.notices}>
        {message && <StatusPill variant="active">{message}</StatusPill>}
        {errorAlert && <StatusPill variant="error">{errorAlert}</StatusPill>}
      </div>

      {profile.verification_status === 'rejected' && (
        <div className={`${styles.alertBox} ${styles.alertDanger}`}>
          <div><strong>Verification needs attention</strong><p>Upload a clearer or valid National ID to request another review.</p></div>
          <Button variant="primary" onClick={() => setActiveSection('verification')}>Upload another ID</Button>
        </div>
      )}

      <header className={styles.profileHeader}>
        <div className={styles.headerInner}>
          <div className={styles.avatarWrapper}>
            {profile.profile_picture_url && !pictureFailed ? (
              <img className={styles.avatarLarge} src={profile.profile_picture_url} alt={`${profile.full_name}'s profile picture`} onError={() => setPictureFailed(true)} />
            ) : (
              <span className={styles.avatarLarge} role="img" aria-label="No profile picture"><User size={64} aria-hidden="true" /></span>
            )}
            <button type="button" className={styles.avatarCameraOverlay} aria-label="Update profile picture" title="Update profile picture" disabled={pictureUploading} onClick={() => pictureInputRef.current?.click()}>
              {pictureUploading ? <span className="spinner" aria-hidden="true" /> : <Camera size={19} weight="fill" aria-hidden="true" />}
            </button>
            {profile.role === 'member' && profile.donor_enrolled && profile.availability === 'available' && !availabilityBlocked && (
              <span className={styles.availabilityIndicator} title="Available to donate">
                <span className={styles.visuallyHidden}>Available to donate</span>
              </span>
            )}
            <input ref={pictureInputRef} key={pictureInputKey} id="profile-picture" className={styles.visuallyHidden} type="file" disabled={pictureUploading} accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onChange={(event) => onPictureUpload(event.target.files?.[0])} />
          </div>

          <div className={styles.headerIdentity}>
            <div className={styles.nameLine}>
              <h1>{profile.full_name}</h1>
              {profile.verification_status === 'verified' && <CheckCircle size={23} weight="fill" aria-label="Verified account" />}
            </div>
            <p>{profile.role === 'admin' ? 'Admin' : profile.chapter_name ? `${profile.chapter_name} member` : titleCase(profile.role)}</p>
            {profile.role === 'member' && <span className={styles.availabilityText}><Drop size={16} weight="fill" aria-hidden="true" /> {availabilityStatus}</span>}
          </div>

          <div className={styles.headerActions}>
            {profile.role === 'member' && profile.verification_status !== 'verified' && <Button variant="secondary" disabled>Verification required</Button>}
            {profile.role === 'member' && profile.verification_status === 'verified' && !profile.donor_enrolled && <Button variant="secondary" onClick={enrollAsDonor} isLoading={enrolling}>Enroll as donor</Button>}
            <Button to="/matches" variant="secondary">Manage requests</Button>
          </div>
        </div>
        <nav className={styles.headerNav} aria-label="Profile sections">
          {[['overview', 'Overview'], ['verification', 'Verification'], ['history', 'History']].map(([section, label]) => (
            <button key={section} type="button" title={section === 'history' ? 'Donation History' : undefined} aria-current={activeSection === section || (section === 'overview' && activeSection === 'edit') ? 'page' : undefined}
              onClick={() => { setActiveSection(section); setEditingRequestId(null); setCancelTarget(null) }}>{label}</button>
          ))}
        </nav>
      </header>

      <main className={styles.profileLayout}>
        <aside className={styles.aboutColumn} aria-label="Member information">
          <Card className={styles.aboutCard}>
            <div className={styles.cardHeader}><h2>About</h2></div>
            <dl className={styles.aboutDetails}>
              <div><dt>Blood type</dt><dd>{profile.blood_type || 'Not provided'}</dd></div>
              <div><dt>Chapter</dt><dd>{profile.chapter_name || 'Not assigned'}</dd></div>
              <div><dt>Municipality</dt><dd>{profile.location?.municipality_name || 'Not provided'}</dd></div>
              <div><dt>Member since</dt><dd>{formatMemberSince(profile.member_since)}</dd></div>
              <div><dt>Verification</dt><dd>{titleCase(profile.verification_status)}</dd></div>
              {profile.role === 'member' && <div><dt>Donor status</dt><dd>{availabilityStatus}</dd></div>}
            </dl>
            <Button variant="secondary" fullWidth onClick={openProfileEditor}>Edit profile</Button>
          </Card>
        </aside>

        <div className={styles.profileContent}>
          {activeSection === 'edit' && (
            <div>
              <Card className={styles.personalCard}>
                <div className={styles.cardHeader}><div><h2>Edit profile</h2><p>Keep your matching details accurate.</p></div></div>
                <form onSubmit={onSave} noValidate className={styles.formStack}>
                  <Input label="First name" aria-label="First name" id="first_name" value={form.first_name} onChange={setField('first_name')} required maxLength={50} error={errors.first_name?.join(' ')} autoFocus />
                  <Input label="Middle name" aria-label="Middle name" id="middle_name" value={form.middle_name} onChange={setField('middle_name')} maxLength={50} error={errors.middle_name?.join(' ')} />
                  <Input label="Last name" aria-label="Last name" id="last_name" value={form.last_name} onChange={setField('last_name')} required maxLength={50} error={errors.last_name?.join(' ')} />
                  <div className={styles.twoColumns}>
                    <Input label="Phone number" aria-label="Phone number" id="phone" type="tel" value={form.phone} onChange={setField('phone')} placeholder="0917-123-4567" error={errors.phone?.join(' ')} />
                    <Input label="Date of birth" aria-label="Date of birth" id="date_of_birth" type="date" value={form.date_of_birth} onChange={setField('date_of_birth')} error={errors.date_of_birth?.join(' ')} />
                  </div>
                  <div className={styles.fieldGroup}>
                    <label htmlFor="blood_type">Blood type</label>
                    <select id="blood_type" value={form.blood_type} onChange={setField('blood_type')}>
                      <option value="">Unknown / Not sure</option>
                      {BLOOD_TYPES.map((type) => <option key={type} value={type}>{type}</option>)}
                    </select>
                    {errors.blood_type && <span className={styles.fieldError}>{errors.blood_type.join(' ')}</span>}
                  </div>
                  <div className={styles.fieldGroup}>
                    <span>Location in Bataan</span>
                    <LocationSelector municipalityId="profile-municipality" municipalityCode={location.municipality_code} barangayCode={location.barangay_code} onChange={setLocation} errors={errors} />
                  </div>
                  <div className={styles.postActions}>
                    <Button type="submit" isLoading={submitting}>Save changes</Button>
                    <Button type="button" variant="secondary" disabled={submitting} onClick={() => setActiveSection('overview')}>Cancel</Button>
                  </div>
                </form>
              </Card>
            </div>
          )}

          {activeSection === 'verification' && (
          <Card id="verification-section" className={styles.verificationCard}>
            {profile.verification_status !== 'verified' && <div className={styles.cardHeader}>
              <Badge variant={profile.verification_status === 'verified' ? 'success' : profile.verification_status === 'rejected' ? 'emergency' : 'neutral'}>{titleCase(profile.verification_status)}</Badge>
            </div>}
            {profile.verification_status !== 'verified' && (nationalIdDocuments.length === 0 || profile.verification_status === 'rejected') ? (
              <form onSubmit={onUpload} className={styles.verificationUpload}>
                <FileText size={30} weight="duotone" aria-hidden="true" />
                <div><h3>{profile.verification_status === 'rejected' ? 'Choose a replacement ID' : 'Upload your National ID'}</h3><p>Use a clear JPG, PNG, WEBP, or PDF up to 5 MB.</p></div>
                <input key={documentInputKey} id="profile-national-id" className={styles.visuallyHidden} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setFile(event.target.files?.[0] || null)} />
                <div className={styles.verificationActions}>
                  <label htmlFor="profile-national-id" className={styles.filePicker}><UploadSimple size={18} /> {file ? 'Choose another file' : 'Choose a file'}</label>
                  {file && <span className={styles.fileName}>{file.name}</span>}
                  {file && <Button type="submit" isLoading={documentUploading}>{profile.verification_status === 'rejected' ? 'Request another review' : 'Submit for review'}</Button>}
                  <button type="button" className={styles.privacyLink} onClick={() => setIdPrivacyModalOpen(true)}>Review the privacy notice</button>
                </div>
              </form>
            ) : (
              <div className={styles.verificationComplete}>
                <CheckCircle size={30} weight="duotone" aria-hidden="true" />
                <div><h3>{profile.verification_status === 'verified' ? (nationalIdDocuments.length ? 'National ID verified' : 'Account verified') : 'Review in progress'}</h3><p>{profile.verification_status === 'verified' ? 'National ID uploads are locked after verification.' : 'You will receive a notification when the review is complete.'}</p></div>
                <div className={styles.verificationActions}>
                  {nationalIdDocuments[0] && <Button to={`/api/profile/documents/${nationalIdDocuments[0].id}/file`} target="_blank" rel="noreferrer" variant="secondary" size="sm">View uploaded ID</Button>}
                  <button type="button" className={styles.privacyLink} onClick={() => setIdPrivacyModalOpen(true)}>Review the privacy notice</button>
                </div>
              </div>
            )}
            {nationalIdDocuments[0] && ['image/jpeg', 'image/png', 'image/webp'].includes(nationalIdDocuments[0].mime_type) && (
              failedDocumentPreviewId === nationalIdDocuments[0].id
                ? <p className={styles.documentPreviewNote} role="status">The image preview could not be loaded. Use View uploaded ID to open the document.</p>
                : <img className={styles.documentPreview} src={`/api/profile/documents/${nationalIdDocuments[0].id}/file`}
                    alt="Your uploaded National ID" loading="lazy" onError={() => setFailedDocumentPreviewId(nationalIdDocuments[0].id)} />
            )}
            {profile.verification_status === 'verified' && nationalIdDocuments.length === 0 && <p className={styles.documentPreviewNote}>No uploaded National ID is available for this account.</p>}
          </Card>
          )}

          {activeSection === 'history' && (
          <div className={styles.historySection}>
            <section aria-labelledby={reports.length ? 'donation-history-heading' : undefined} aria-label={reports.length ? undefined : 'Donation history'}>
              {reports.length === 0 ? <p className={styles.emptyText}>No donation history yet.</p> : <>
              <h3 id="donation-history-heading" className={styles.historySubheading}>Donation history</h3>
              <Card className={styles.historyCard}>
                <div className={styles.historyList}>
                  {reports.map((report) => (
                    <article key={report.id} className={styles.historyRow}>
                      <div className={styles.historyBlood}>{report.required_blood_type}</div>
                      <div><h4>{report.facility_name}</h4><p>{formatDate(report.reported_at)}</p>
                        {report.status === 'REJECTED' && <p className={styles.rejectionReason}>Rejection reason: {report.rejection_reason || 'No reason recorded'}</p>}
                      </div>
                      <Badge variant={report.status === 'CONFIRMED' ? 'success' : report.status === 'REJECTED' ? 'emergency' : 'urgent'}>{titleCase(report.status)}</Badge>
                    </article>
                  ))}
                </div>
              </Card>
              </>}
            </section>

          </div>
          )}

          {activeSection === 'overview' && (
            <>
              {profile.role === 'member' && profile.verification_status === 'verified' && !profile.donor_enrolled && (
                <Card className={styles.enrollCard} role="region" aria-label="Donor enrollment prompt">
                  <div className={styles.enrollCardContent}>
                    <div className={styles.enrollCardIcon} aria-hidden="true">
                      <Drop size={28} weight="fill" />
                    </div>
                    <div className={styles.enrollCardDetails}>
                      <h3>Complete Your Donor Enrollment</h3>
                    </div>
                    <Button variant="primary" onClick={enrollAsDonor} isLoading={enrolling}>
                      Enroll as Donor
                    </Button>
                  </div>
                </Card>
              )}
              <section className={styles.activitySection} aria-labelledby="profile-requests-heading">
              <div className={styles.cardHeader}>
                <h2 id="profile-requests-heading">Blood requests</h2>
                <Button variant="secondary" disabled={!canCreateRequest} onClick={openCreateRequest}>+ Create request</Button>
              </div>
              <nav className={styles.requestFilters} aria-label="Filter blood requests by status">
                {['ALL', 'OPEN', 'FULFILLED', 'CANCELLED', 'EXPIRED'].map((status) => (
                  <button key={status} type="button" className={requestFilter === status ? styles.requestFilterActive : styles.requestFilter}
                    aria-pressed={requestFilter === status} onClick={() => { setRequestFilter(status); setCancelTarget(null) }}>
                    {status === 'ALL' ? 'All' : titleCase(status)} <span>{status === 'ALL' ? requestHistory.length : requestHistory.filter((request) => request.status === status).length}</span>
                  </button>
                ))}
              </nav>
              {filteredRequests.length === 0 ? (
                <Card className={styles.compactCard}><p className={styles.emptyText}>{requestFilter === 'ALL' ? 'No blood requests yet. Create a request to begin matching.' : 'No ' + requestFilter.toLowerCase() + ' blood requests. Choose another status to review your activity.'}</p></Card>
              ) : filteredRequests.map((request) => (
                <FeedCard key={request.id} hideActions defaultExpanded showNeededYear request={{ ...request,
                  requester_name: profile.full_name,
                  requester_chapter_name: profile.chapter_name,
                  requester_profile_picture_url: profile.profile_picture_url,
                  requester_verification_status: profile.verification_status
                }}>
                  <div className={styles.postCounts}>
                    <Badge variant={request.status === 'FULFILLED' ? 'success' : request.status === 'OPEN' ? 'brand' : 'neutral'}>{titleCase(request.status)}</Badge>
                    {request.match_count != null && <span>{request.match_count} potential {request.match_count === 1 ? 'donor' : 'donors'}</span>}
                    {request.response_count != null && <span>{request.response_count} {request.response_count === 1 ? 'response' : 'responses'}</span>}
                  </div>
                  <div className={styles.requestCardActions}>
                    {request.status === 'OPEN' && <Button className={styles.editRequest} variant="secondary" onClick={() => { clearNotices(); setEditingRequestId(request.id) }}>Edit</Button>}
                    <Button className={styles.viewMatches} to={`/requests/${request.id}/matches`}>View matches</Button>
                    {request.status === 'OPEN' && <Button className={styles.cancelRequest} variant="dangerOutline" onClick={() => { clearNotices(); setCancelError(null); setCancelTarget(request) }}>Cancel request</Button>}
                  </div>
                </FeedCard>
              ))}
            </section>
            </>
          )}
        </div>
      </main>

      <RequestFormModal open={Boolean(editingRequestId)} id={editingRequestId} onSuccess={handleRequestSaved} onClose={closeRequestForm} />
      <ConfirmationDialog open={Boolean(cancelTarget)} title="Cancel this blood request?" confirmLabel="Cancel request" cancelLabel="Keep request"
        destructive busy={cancelling} error={cancelError} onConfirm={cancelRequest} onCancel={() => { if (!cancelBusyRef.current) setCancelTarget(null) }}>
        <p>The request for {cancelTarget?.required_blood_type} blood at {cancelTarget?.facility_name} will stop accepting responses.</p>
      </ConfirmationDialog>
      <PrivacyConsentModal isOpen={idPrivacyModalOpen} onClose={() => setIdPrivacyModalOpen(false)} readonly />
    </div>
  )
}
