# BloodMatch — API Inventory & Contract Reference

> Current as of 2026-09-24 (migrations 001–016). Source: `backend/routes/api.php` (55 method+path registrations) + controllers/services. Historical Phase-17 revision documented 26 endpoints / 001–014 schema (see `docs/test-log-phase17.md`); this file supersedes it. Envelope: `Response::success` → `{success:true,data:{...}}`; `Response::error` → `{success:false,error:{message,details?}}`.

This document provides a comprehensive inventory of all API routes implemented in BloodMatch (`backend/routes/api.php` and `backend/src/Controllers/`).

All responses adhere to the standard JSON envelopes:
- Success: `{ "success": true, "data": { ... } }` with HTTP 200/201.
- Error: `{ "success": false, "error": { "message": "Human readable message", "details": { ...fieldErrors } } }` with HTTP 4xx/5xx.

---

## 1. System & Health Endpoints

### `GET /api/health`
- **Purpose:** Health check reporting server timestamp, PHP version, MySQL connectivity, and migration status.
- **Auth:** Public / Anonymous.
- **Response:**
  ```json
  {
    "status": "healthy",
    "timestamp": "2026-08-27T02:45:00Z",
    "php_version": "8.2.12",
    "database": { "connected": true, "name": "bloodmatch_dev" },
    "schema": { "migrations_applied": 16 }
  }
  ```
  (`migrations_applied` is 16 on the current 001–016 schema; historical Phase-17 docs show 14.)

### `GET /api/csrf`
- **Purpose:** Issue per-session CSRF token required on state-changing endpoints (`X-CSRF-Token` header).
- **Auth:** Public / Anonymous (session-bound).
- **Response (200):** `{ "success": true, "data": { "csrf_token": "..." } }`

### `GET /api/chapters`
- **Purpose:** Retrieve the list of fixed Bataan chapters for registration and filtering.
- **Auth:** Public / Anonymous.
- **Response:**
  ```json
  {
    "chapters": [
      { "id": 1, "code": "mt_samat", "name": "Mt. Samat Chapter", "municipality": "Orani", "latitude": 14.7997, "longitude": 120.5361 },
      { "id": 2, "code": "mt_tarak", "name": "Mt. Tarak Chapter", "municipality": "Mariveles", "latitude": 14.4337, "longitude": 120.4853 },
      { "id": 3, "code": "meridian_heights", "name": "Meridian Heights Chapter", "municipality": "Balanga City", "latitude": 14.6765, "longitude": 120.5361 }
    ]
  }
  ```

### `GET /api/locations/municipalities`
- **Purpose:** Retrieve the 12 Bataan cities/municipalities (PSGC province 030800000) for the cascading location selector.
- **Auth:** Public / Anonymous.
- **Response:** `{ "municipalities": [{ "location_id": 161, "psgc_code": "030809000", "name": "Orani" }] }`

### `GET /api/locations/barangays?municipality_code=030809000`
- **Purpose:** Retrieve barangays of one municipality for the cascading selector (29 for Orani).
- **Auth:** Public / Anonymous.
- **Validation:** Missing/unknown `municipality_code` → 400.
- **Response:** `{ "municipality": { "location_id": 161, ... }, "barangays": [{ "location_id": 183, "psgc_code": "030809023", "name": "Tugatog" }] }`

> Location selections resolve server-side to canonical reference coordinates (`users`/`blood_requests` `location_id` + derived `latitude`/`longitude`). Raw `latitude`/`longitude` keys are rejected on profile/request/register writes (400, `location_id` guidance).

---

## 2. Authentication & Password Management

### `POST /api/register`
- **Purpose:** Create a new user account (defaults to `member` role, `pending` verification, `active` account). Location is not collected here — raw `latitude`/`longitude` are rejected with `location_id` guidance; set location after registration via `PUT /api/profile`.
- **Auth:** Public / Anonymous. Requires CSRF token.
- **Rate Limit:** 5 requests / IP / minute.
- **Request Body:**
  ```json
  {
    "full_name": "Maria Santos",
    "email": "maria@example.com",
    "password": "Password123",
    "chapter_id": 1,
    "date_of_birth": "1998-05-15",
    "phone": "0917-123-4567",
    "blood_type": "O+",
    "privacy_acknowledged": true
  }
  ```
  (`privacy_acknowledged` mandatory — `true`/`1`/`"1"`/`"true"`/`"on"`/`"yes"` accepted; missing/false/invalid → 400 + `error.details.privacy_acknowledged`. Verified in `tests/phase3.ps1` T05b–T05d; frontend `RegisterPage.jsx` + `PrivacyNoticeModal.jsx` enforce checkbox + modal.)
- **Response (201):** `{ "success": true, "data": { "user": { "id": 42, ... } } }`
- **Errors:** 400 Validation Error (missing fields, weak password, invalid email, missing/invalid `privacy_acknowledged`, raw coordinates), 409 Email already registered.

### `POST /api/login`
- **Purpose:** Authenticate user and establish secure, HttpOnly session cookie (`bloodmatch_session`).
- **Auth:** Public / Anonymous.
- **Rate Limit:** 5 failed attempts per IP+Email throttle (locks for 15 minutes).
- **Request Body:** `{ "email": "maria@example.com", "password": "Password123" }`
- **Response (200):** `{ "message": "Authenticated", "user": { "id": 42, "email": "...", "role": "member", "chapter_id": 1 } }`
- **Errors:** 401 Invalid credentials / Deactivated account, 429 Too many failed login attempts.

### `POST /api/logout`
- **Purpose:** Destroy server session, delete active session record, and issue cleared session cookie with identical security flags.
- **Auth:** Authenticated.
- **Response (200):** `{ "message": "Logged out successfully" }`

### `GET /api/auth/me`
- **Purpose:** Return current authenticated user identity, role, chapter binding, and verification state.
- **Auth:** Authenticated.
- **Response (200):** `{ "authenticated": true, "user": { "id": 42, "email": "...", "role": "member", "chapter_id": 1, "verification_status": "verified" } }`

### `POST /api/password-reset/request`
- **Purpose:** Request single-use password reset token (valid for 30 minutes).
- **Auth:** Public / Anonymous.
- **Request Body:** `{ "email": "maria@example.com" }`
- **Response (200):** `{ "message": "If that email exists, a password reset token has been issued." }`

### `POST /api/password-reset/confirm`
- **Purpose:** Set new password using valid single-use reset token.
- **Auth:** Public / Anonymous.
- **Request Body:** `{ "token": "64_hex_token", "password": "NewSecurePassword123" }`
- **Response (200):** `{ "message": "Password updated successfully." }`
- **Errors:** 400 Invalid, expired, or already-used token.

---

## 3. Profile & Member Document Management

### `GET /api/profile`
- **Purpose:** Retrieve member profile, blood type provenance, active capabilities, availability status, and uploaded documents.
- **Auth:** Authenticated.
- **Response (200):** Contains user details, `capabilities` matrix, `availability_window` status, and list of `documents`.

### `PUT /api/profile`
- **Purpose:** Update personal profile details (name, phone, birthdate, self-reported blood type, Bataan location).
- **Auth:** Authenticated.
- **Request Body:** `{ "full_name": "...", "phone": "...", "date_of_birth": "YYYY-MM-DD", "blood_type": "O+", "location_id": 161 }` — `location_id` references `bataan_locations`; coordinates resolve server-side. Raw `latitude`/`longitude` keys are rejected (400).
- **Response (200):** `{ "profile": { ...updatedUser, "location": { "municipality_name": "Orani", "barangay_name": null, ... } } }`

### `POST /api/profile/documents`
- **Purpose:** Upload identity or donor verification document (JPG, PNG, WEBP, PDF up to 5 MB).
- **Auth:** Authenticated. Requires CSRF.
- **Rate Limit:** 10 uploads / 5 minutes (`SEC-LOW-02`).
- **Multipart Form:** `file` (binary), `doc_type` (`national_id` | `donor_card` | `parental_consent`), `privacy_acknowledged` (`1`/`true` mandatory ID Privacy Notice acknowledgment — missing/false → 400 + `error.details.privacy_acknowledged`; verified `tests/phase5.ps1` B1b–B1c; frontend `ProfilePage.jsx` + `PrivacyNoticeModal.jsx`).
- **Response (201):** `{ "document": { "id": 10, "doc_type": "donor_card", "size_bytes": 104857 } }`

### `GET /api/profile/documents`
- **Purpose:** List own uploaded documents (metadata only; no file paths).
- **Auth:** Authenticated (Owner only).
- **Response (200):** `{ "success": true, "data": { "documents": [ { "id": 10, "doc_type": "national_id", "mime_type": "...", "size_bytes": 104857, "uploaded_at": "..." } ] } }`

### `GET /api/profile/documents/{id}/file`
- **Purpose:** Securely stream uploaded document for the document owner.
- **Auth:** Authenticated (Owner only).
- **Errors:** 403 Forbidden, 404 Not Found.

### `POST /api/profile/resubmit`
- **Purpose:** Resubmit member verification for officer review following a previous rejection.
- **Auth:** Authenticated (Rejected members only).
- **Rate Limit:** 5 resubmissions / 15 minutes (`SEC-LOW-02`).
- **Response (200):** `{ "message": "Verification resubmitted successfully" }`

### `POST /api/profile/enroll-donor`
- **Purpose:** Explicitly opt in as a volunteer blood donor. Requires verified member status and age eligibility.
- **Auth:** Authenticated (Verified members only).
- **Response (200):** `{ "message": "Enrolled as volunteer donor", "availability": "available" }`

### `POST /api/profile/donor-availability`
- **Purpose:** Toggle voluntary availability (`available` vs `unavailable`). Blocked during active Standby or Cooldown windows.
- **Auth:** Authenticated (Enrolled donors only).
- **Request Body:** `{ "availability": "available" | "unavailable" }`
- **Response (200):** `{ "message": "Availability updated", "availability": "available" }`
- **Errors:** 409 Conflict if Standby (42h) or Cooldown (90d) window is active.

### `GET /api/my/donation-reports`
- **Purpose:** Retrieve list of donation reports submitted by the logged-in member.
- **Auth:** Authenticated (Members only).
- **Response (200):** `{ "reports": [ ...reports ] }`

### `POST /api/profile/picture`
- **Purpose:** Upload/replace profile picture for navbar avatar (migration 015). JPG/PNG/WEBP ≤5 MB, MIME + `getimagesize` validated, 64-hex server-generated filename in `backend/storage/profile_pictures`; replacement deletes previous file.
- **Auth:** Authenticated (Owner only). Requires CSRF.
- **Rate Limit:** 10 uploads / 5 minutes.
- **Multipart Form:** `file` (binary image).
- **Response (200):** `{ "success": true, "data": { "user": { "id": 42, "profile_picture_url": "/api/profile/picture?v=..." } } }` (null when none).
- **Errors:** 400 invalid type/empty, 413 over 5 MB, 429 rate-limited. Audited as `profile.picture_updated`. Verified `tests/profile_picture.ps1` P01–P13.
- **Notes:** Does not affect verification, matching, or eligibility.

### `GET /api/profile/picture`
- **Purpose:** Stream own profile picture bytes (owner-only; no cross-user access — other user without picture gets 404).
- **Auth:** Authenticated (Owner only).
- **Response (200):** image bytes (`Content-Type: image/*`, `Content-Disposition: inline`); 404 when none/invalid/missing.
- **Frontend:** `ProfilePage.jsx` upload section + `NavbarAvatar` in `App.jsx` (fallback `User` icon when null/fails).

---

## 4. Blood Requests & Matching Engine

### `GET /api/my/requests`
- **Purpose:** List all blood requests created by the authenticated user.
- **Auth:** Authenticated.
- **Response (200):** `{ "requests": [ ...bloodRequests ] }`

### `POST /api/requests`
- **Purpose:** Create a new blood request. Automatically triggers matching engine and notifies compatible available donors.
- **Auth:** Authenticated.
- **Rate Limit:** 10 requests / 10 minutes (`SEC-LOW-02`).
- **Request Body:**
  ```json
  {
    "required_blood_type": "A+",
    "quantity_units": 2,
    "facility_name": "Bataan General Hospital",
    "needed_datetime": "2026-08-30T12:00:00Z",
    "urgency": "urgent",
    "location_id": 26
  }
  ```
  (`location_id` references `bataan_locations`; facility coordinates resolve server-side. Raw `latitude`/`longitude` keys are rejected. Omitting `location_id` keeps the request location empty — proximity ranking is then skipped for it.)
- **Response (201):** `{ "message": "Blood request created", "request_id": 101, "matches_count": 4 }`

### `GET /api/requests/{id}`
- **Purpose:** Get full details of a specific blood request.
- **Auth:** Authenticated (Request owner, Chapter Officer, or Admin).
- **Response (200):** `{ "request": { ...requestDetails } }`

### `PUT /api/requests/{id}`
- **Purpose:** Update facility, needed date, units, urgency, or Bataan location of an active OPEN request. Location edit is material (coords re-resolved, `request.material_change` audited, regeneration).
- **Auth:** Authenticated (Request owner only).
- **Request Body (partial):** `{ "facility_name": "...", "needed_datetime": "...", "quantity_units": 2, "urgency": "urgent", "location_id": 26 }` (raw `latitude`/`longitude` rejected 400).
- **Response (200):** `{ "message": "Request updated", "request": { ... } }`
- **Errors:** 403 Forbidden if not owner, 400 Bad Request if request is not in OPEN status.

### `POST /api/requests/{id}/cancel`
- **Purpose:** Cancel an active OPEN blood request. Updates status to `CANCELLED` and closes open matches.
- **Auth:** Authenticated (Request owner only).
- **Response (200):** `{ "message": "Request cancelled successfully" }`

### `GET /api/requests/{id}/matches`
- **Purpose:** View candidate donor matches ranked by red-cell compatibility and geographic distance. Privacy-safe: `approximate_distance_km` only; no `latitude`/`longitude`/`phone`/`email`/`password`/`document` fields (verified L13).
- **Auth:** Authenticated (Request owner, matched donor own-entry only, Chapter Officer same-chapter, or Admin).
- **Response (200):**
  ```json
  {
    "request_id": 101,
    "matches": [
      {
        "match_id": 501,
        "donor_reference": "donor-42",
        "display_name": "Maria Santos",
        "chapter_id": 1,
        "chapter_name": "Mt. Samat Chapter",
        "verification_status": "verified",
        "availability": "available",
        "approximate_distance_km": 4.2,
        "status": "NOTIFIED"
      }
    ]
  }
  ```
  (Actual `MatchService::privacySafeMatches`: `match_id`, `donor_reference`, `display_name` (full name), `chapter_id`/`chapter_name`, `verification_status`, `availability`, `approximate_distance_km`, `generation`, `status`.)

### `POST /api/officer/requests/{id}/re-match`
- **Purpose:** Manually re-run matching for an active OPEN request (officer/admin). Uses `MatchService::generateForRequest(..., bump=false, trigger='manual_rematch')`; reconciles without duplicates; audited as `match.manual_rematch`.
- **Auth:** Authenticated (`officer` or `admin`; chapter-scoped to `request_chapter_id`).
- **Response (200):** `{ "success": true, "data": { "matching": { "generation": 2, "pool_size": 4, "inserted": 0, "updated": 4, "closed": 0, "notified": 0 } } }`
- **Errors:** 404 not found, 409 non-OPEN, 403 cross-chapter.

### `GET /api/compatibility-matrix`
- **Purpose:** Return full 8-type red-cell compatibility matrix (sole consumer otherwise `BloodCompatibilityService`).
- **Auth:** Authenticated (`officer` or `admin` only).
- **Response (200):** `{ "success": true, "data": { "matrix": { "A+": ["A+","A-","O+","O-"], ... } } }`

### `POST /api/matches/{id}/respond`
- **Purpose:** Donor signals willingness to donate for a notified match. Transitions match status to `RESPONDED`.
- **Auth:** Authenticated (The matched donor only).
- **Response (200):** `{ "message": "Willingness to donate recorded", "match_status": "RESPONDED" }`

### `POST /api/donation-reports`
- **Purpose:** Donor submits report of completed donation at facility for officer confirmation.
- **Auth:** Authenticated (The matched donor only).
- **Request Body:** `{ "match_id": 501, "note": "Donated 1 unit at Blood Bank station 2." }`
- **Response (201):** `{ "message": "Donation report submitted", "report_id": 88 }`

---

## 5. Chapter Officer Operations (Scoped to Officer's Chapter)

### `GET /api/officer/users`
- **Purpose:** List member users in the officer's own chapter (chapter isolation enforced; `?chapter_id=` tampering → 403).
- **Auth:** Authenticated (`officer` role).
- **Response (200):** `{ "success": true, "data": { "chapter_id": 1, "users": [ ... ] } }`

### `GET /api/officer/dashboard`
- **Purpose:** Chapter officer triage metrics, pending verifications, confirmation queues, and donor readiness.
- **Auth:** Authenticated (`officer` role).
- **Chapter Scope:** Scoped to officer's assigned `chapter_id`.

### `GET /api/officer/verifications`
- **Purpose:** Retrieve pending member verifications awaiting identity review in the officer's chapter.
- **Auth:** Authenticated (`officer` role).
- **Response (200):** `{ "queue": [ ...pendingUsers ] }`

### `GET /api/officer/verifications/{id}`
- **Purpose:** Inspect specific member verification submission, age calculation, and documents.
- **Auth:** Authenticated (`officer` role, same chapter).

### `POST /api/officer/verifications/{id}/decision`
- **Purpose:** Approve or reject member verification submission. Optional `accept_donor_card` upgrades blood type provenance to officer-verified.
- **Auth:** Authenticated (`officer` role, same chapter).
- **Request Body:** `{ "decision": "verified" | "rejected", "reason": "...", "accept_donor_card": true }`
- **Response (200):** `{ "message": "Verification decision recorded" }`

### `GET /api/officer/documents/{id}/file`
- **Purpose:** Inspect member verification document within officer's chapter.
- **Auth:** Authenticated (`officer` role, same chapter).

### `GET /api/officer/donation-reports`
- **Purpose:** Retrieve pending donation reports submitted for requests in officer's chapter.
- **Auth:** Authenticated (`officer` role).
- **Response (200):** `{ "pending_reports": [ ...reports ] }`

### `POST /api/officer/donation-reports/{id}/confirm`
- **Purpose:** Confirm donation. Executes atomic transaction: marks report CONFIRMED, marks match COMPLETED, updates donor's `last_verified_donation_at`, activates Standby (42h) / Cooldown (90d), checks request fulfillment quota.
- **Auth:** Authenticated (`officer` role, same chapter).
- **Response (200):** `{ "message": "Donation confirmed", "request_fulfilled": false }`

### `POST /api/officer/donation-reports/{id}/reject`
- **Purpose:** Reject unverified or fraudulent donation report.
- **Auth:** Authenticated (`officer` role, same chapter).
- **Response (200):** `{ "message": "Donation report rejected" }`

### `GET /api/officer/audit-logs`
- **Purpose:** Chapter-scoped query of immutable audit log events.
- **Auth:** Authenticated (`officer` role).
- **Query Params:** `action`, `actor_id`, `target_type`, `target_id`, `date_from`, `date_to`, `page`, `page_size`.

---

## 6. System Administration & Global Operations

### `GET /api/admin/users`
- **Purpose:** List all users system-wide with filters and pagination.
- **Auth:** Authenticated (`admin` role).
- **Query Params:** `role`, `verification_status`, `account_status`, `chapter_id`, `q`, `page`, `page_size`.
- **Response (200):** paginated user list.

### `POST /api/admin/users/{id}/role`
- **Purpose:** Assign `member`/`officer`/`admin` role (self-change forbidden 403).
- **Auth:** Authenticated (`admin` role).

### `POST /api/admin/users/{id}/chapter`
- **Purpose:** Assign/clear user chapter binding (clearing an officer's chapter forbidden 422).
- **Auth:** Authenticated (`admin` role).

### `POST /api/admin/users/{id}/deactivate`
- **Purpose:** Soft-deactivate account (`account_status='deactivated'`, `deactivated_at` set; verification untouched; live sessions denied).
- **Auth:** Authenticated (`admin` role).

### `POST /api/admin/users/{id}/reactivate`
- **Purpose:** Reactivate soft-deactivated account.
- **Auth:** Authenticated (`admin` role).

### `GET /api/admin/dashboard`
- **Purpose:** Global platform KPIs, total users, 3-chapter comparative matrix, and lifecycle resolution rates.
- **Auth:** Authenticated (`admin` role).

### `GET /api/admin/audit-logs`
- **Purpose:** Global, unfiltered access to immutable system audit logs with multi-parameter search.
- **Auth:** Authenticated (`admin` role).
- **Query Params:** `chapter_id`, `action`, `actor_id`, `target_type`, `target_id`, `date_from`, `date_to`, `page`, `page_size`.

---

## 7. Demand Map & Analytics

### `GET /api/demand-map`
- **Purpose:** Chapter centroid aggregated demand for active OPEN blood requests. Privacy-safe: returns canonical chapter coordinates only, never exposes individual requester identity or exact GPS pins.
- **Auth:** Authenticated (`officer` or `admin` role). Officers scoped to chapter; Admins see all chapters.
- **Query Params:** `blood_type`, `urgency`, `days`.
- **Response (200):**
  ```json
  {
    "chapters": [
      {
        "chapter_id": 1,
        "chapter_name": "Mt. Samat Chapter",
        "municipality": "Orani",
        "latitude": 14.7997,
        "longitude": 120.5361,
        "open_requests_count": 5,
        "total_units_needed": 8,
        "urgency_counts": { "routine": 2, "urgent": 2, "critical": 1 },
        "blood_type_demand": { "A+": { "requests_count": 2, "units_needed": 3 } }
      }
    ]
  }
  ```

### `GET /api/analytics/summary`
- **Purpose:** Comprehensive operational reporting: request volume, fulfillment rate, cancellation rate, expiration rate (computed against resolved denominator `FULFILLED + CANCELLED + EXPIRED`), ABO distribution, urgency breakdown, and daily trends.
- **Auth:** Authenticated (`officer` or `admin` role). Officers scoped to chapter; Admins support `?chapter_id=N`.
- **Query Params:** `date_from`, `date_to`, `chapter_id`.

---

## 8. In-App Notifications

### `GET /api/notifications`
- **Purpose:** Retrieve paginated notifications for the authenticated user.
- **Auth:** Authenticated.
- **Query Params:** `type`, `read` (`unread` | `read`), `page`, `page_size`.
- **Response (200):** `{ "total": 12, "page": 1, "page_size": 15, "notifications": [ ... ] }`

### `GET /api/notifications/unread-count`
- **Purpose:** Live unread counter polled by navbar `NotificationFlyout` (30s) and badge.
- **Auth:** Authenticated.
- **Response (200):** `{ "success": true, "data": { "unread_count": 3 } }`

### `POST /api/notifications/{id}/read`
- **Purpose:** Mark single notification as read (idempotent; recipient-only, 404 otherwise).
- **Auth:** Authenticated (Recipient only).
- **Response (200):** `{ "success": true, "data": { "unread_count": 2 } }`

### `POST /api/notifications/read-all`
- **Purpose:** Mark all unread notifications as read for current user.
- **Auth:** Authenticated.
- **Response (200):** `{ "success": true, "data": { "marked_read": 5 } }`
