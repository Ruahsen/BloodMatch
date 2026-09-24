# BloodMatch — API Inventory & Contract Reference

This document provides a comprehensive inventory of all API routes implemented in BloodMatch (`backend/public/index.php` and `backend/src/Controllers/`).

All responses adhere to the standard JSON envelopes:
- Success: `{ "status": "success", ...data }` or direct payload with appropriate HTTP 200/201 status code.
- Error: `{ "error": "Human readable message", "code": "ERROR_CODE", "details": { ...fieldErrors } }` with HTTP 4xx/5xx status code.

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
    "schema": { "migrations_applied": 14 }
  }
  ```

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

---

## 2. Authentication & Password Management

### `POST /api/register`
- **Purpose:** Create a new user account (defaults to `member` role, `pending` verification).
- **Auth:** Public / Anonymous.
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
    "blood_type": "O+"
  }
  ```
- **Response (201):** `{ "message": "User registered successfully", "user_id": 42 }`
- **Errors:** 400 Validation Error (missing fields, weak password, invalid email), 409 Email already registered.

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
- **Purpose:** Update personal profile details (name, phone, birthdate, self-reported blood type, coordinates).
- **Auth:** Authenticated.
- **Request Body:** `{ "full_name": "...", "phone": "...", "date_of_birth": "YYYY-MM-DD", "blood_type": "O+", "latitude": 14.79, "longitude": 120.53 }`
- **Response (200):** `{ "profile": { ...updatedUser } }`

### `POST /api/profile/documents`
- **Purpose:** Upload identity or donor verification document (JPG, PNG, WEBP, PDF up to 5 MB).
- **Auth:** Authenticated.
- **Rate Limit:** 10 uploads / 5 minutes (`SEC-LOW-02`).
- **Multipart Form:** `file` (binary), `doc_type` (`national_id` | `donor_card` | `parental_consent`).
- **Response (201):** `{ "document": { "id": 10, "doc_type": "donor_card", "size_bytes": 104857 } }`

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
    "latitude": 14.6765,
    "longitude": 120.5361
  }
  ```
- **Response (201):** `{ "message": "Blood request created", "request_id": 101, "matches_count": 4 }`

### `GET /api/requests/{id}`
- **Purpose:** Get full details of a specific blood request.
- **Auth:** Authenticated (Request owner, Chapter Officer, or Admin).
- **Response (200):** `{ "request": { ...requestDetails } }`

### `PUT /api/requests/{id}`
- **Purpose:** Update facility, needed date, units, or urgency of an active OPEN request.
- **Auth:** Authenticated (Request owner only).
- **Response (200):** `{ "message": "Request updated", "request": { ... } }`
- **Errors:** 403 Forbidden if not owner, 400 Bad Request if request is not in OPEN status.

### `POST /api/requests/{id}/cancel`
- **Purpose:** Cancel an active OPEN blood request. Updates status to `CANCELLED` and closes open matches.
- **Auth:** Authenticated (Request owner only).
- **Response (200):** `{ "message": "Request cancelled successfully" }`

### `GET /api/requests/{id}/matches`
- **Purpose:** View candidate donor matches ranked by red-cell compatibility and geographic distance. Donors are anonymized (`Donor #123`).
- **Auth:** Authenticated (Request owner, matched donor, Chapter Officer, or Admin).
- **Response (200):**
  ```json
  {
    "request_id": 101,
    "matches": [
      {
        "match_id": 501,
        "donor_reference": "donor-42",
        "display_name": "Donor #42",
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

### `POST /api/notifications/{id}/read`
- **Purpose:** Mark single notification as read (idempotent).
- **Auth:** Authenticated (Recipient only).

### `POST /api/notifications/read-all`
- **Purpose:** Mark all unread notifications as read for current user.
- **Auth:** Authenticated.
- **Response (200):** `{ "message": "All notifications marked as read" }`
