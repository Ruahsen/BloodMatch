# BloodMatch - API Inventory & Contract Reference

> Current as of 2026-10-07 (migrations 001–020, registration-time email OTP). Source: `backend/routes/api.php` (66 method+path registrations) + controllers/services. Historical Phase-17 revision documented 26 endpoints / 001–014 schema (see `docs/test-log-phase17.md`); this file supersedes it. Envelope: `Response::success` → `{success:true,data:{...}}`; `Response::error` → `{success:false,error:{message,details?}}`.

This document provides a comprehensive inventory of all API routes implemented in BloodMatch (`backend/routes/api.php` and `backend/src/Controllers/`).

All responses adhere to the standard JSON envelopes:
- Success: `{ "success": true, "data": { ... } }` with HTTP 200/201.
- Error: `{ "success": false, "error": { "message": "Human readable message", "details": { ...fieldErrors } } }` with HTTP 4xx/5xx.

---

## 1. System & Health Endpoints

### `GET /api/health`
- **Purpose:** Health check reporting server timestamp and MySQL connectivity.
- **Auth:** Public / Anonymous.
- **Response (enveloped):** `{ "success": true, "data": { "status": "ok", "time": "<utc>", "db": { "connected": true, "port": 3307 } } }`

### `GET /api/csrf`
- **Purpose:** Issue per-session CSRF token required on state-changing endpoints (`X-CSRF-Token` header).
- **Auth:** Public / Anonymous (session-bound).
- **Response (200):** `{ "success": true, "data": { "csrf_token": "..." } }`

### `GET /api/chapters`
- **Purpose:** Retrieve the list of fixed Bataan chapters for registration and filtering.
- **Auth:** Public / Anonymous.
- **Response:** `{ "chapters": [{ "id": 1, "code": "mt_samat", "name": "Mt. Samat Chapter", "municipality": "Orani" }, ...] }` (3 rows; no coordinates on this endpoint - chapter canonical centroids live in `chapters.latitude/longitude` and surface only via `GET /api/demand-map`).

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
- **Purpose:** Create a new user account (defaults to `member` role, `pending` verification, `active` account). Location is not collected here - raw `latitude`/`longitude` are rejected with `location_id` guidance; set location after registration via `PUT /api/profile`.
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
  (`privacy_acknowledged` mandatory - `true`/`1`/`"1"`/`"true"`/`"on"`/`"yes"` accepted; missing/false/invalid → 400 + `error.details.privacy_acknowledged`. Verified in `tests/phase3.ps1` T05b–T05d; frontend `RegisterPage.jsx` + `PrivacyNoticeModal.jsx` enforce checkbox + modal.)
- **Response (201):** `{ "success": true, "data": { "user": { "id": 42, ... }, "email_otp": { "required": true, "delivered": true, "masked_email": "m***@example.com", "expires_in_seconds": 600, "resend_available_in_seconds": 60, "verification_token": "<64_hex>" } } }` - account creation automatically issues the first 6-digit OTP to the registered address and mints a single-purpose claim token (migration 020) so the still-logged-out registrant can verify. No session is created. If delivery fails, the account still stands with `"delivered": false`, nothing is marked verified, and the token authorizes a resend.
- **Errors:** 400 Validation Error (missing fields, weak password, invalid email, missing/invalid `privacy_acknowledged`, raw coordinates), 409 Email already registered. Failed registrations create no account, no OTP, and no claim token.

### `POST /api/login`
- **Purpose:** Authenticate user and establish secure, HttpOnly session cookie (`bloodmatch_session`).
- **Auth:** Public / Anonymous.
- **Rate Limit:** 5 failed attempts per email throttle (locks for 15 minutes).
- **Request Body:** `{ "email": "maria@example.com", "password": "Password123" }`
- **Response (200):** `{ "user": { "id": 42, "email": "...", "role": "member", "chapter_id": 1, ... }, "csrf_token": "..." }` - the server rotates CSRF at login; clients must adopt `csrf_token` (or re-fetch `GET /api/csrf`) before the next mutation.
- **Errors:** 401 Invalid credentials (uniform for unknown email and wrong password - no enumeration oracle), 403 Deactivated account, 403 Email verification required (`error.details`: `{ "code": "email_verification_required", "masked_email": "m***@example.com", "verification_token": "<64_hex>" }` - correct password but `email_verified_at IS NULL`; no session is created; the claim token gives an immediate path back to `/verify-email`), 429 Too many failed login attempts.

### `POST /api/logout`
- **Purpose:** Destroy server session, delete active session record, and issue cleared session cookie with identical security flags.
- **Auth:** Authenticated.
- **Response (200):** `{ "message": "Logged out successfully" }`

### `GET /api/auth/me`
- **Purpose:** Return current authenticated user identity, role, chapter binding, and verification state.
- **Auth:** Authenticated.
- **Response (200):** `{ "authenticated": true, "user": { "id": 42, "email": "...", "role": "member", "chapter_id": 1, "verification_status": "verified" } }`

### `POST /api/password-reset/request`
- **Purpose:** Request single-use password reset token (valid for 30 minutes). Body-only (`email` in JSON; query-string values are not accepted).
- **Auth:** Public / Anonymous.
- **Rate Limit:** identical throttle for known and unknown emails (5/15 min; no lockout oracle). At most 3 concurrently valid tokens per account; further requests reuse the window.
- **Request Body:** `{ "email": "maria@example.com" }`
- **Response (200):** `{ "message": "If that email exists in our records, a reset link has been issued." }` (generic in all cases)

### `POST /api/password-reset/confirm`
- **Purpose:** Set new password using valid single-use reset token. Body-only (`token` must be in JSON, never in the query string).
- **Auth:** Public / Anonymous.
- **Request Body:** `{ "token": "64_hex_token", "password": "NewSecurePassword123" }`
- **Response (200):** `{ "message": "Password has been reset. You can now log in." }`
- **Errors:** 400 Invalid, expired, or already-used token; 403 account deactivated; 429 too many failed confirmations. Consumption is atomic: of concurrent confirms, exactly one succeeds. A successful reset bumps `users.session_version`, revoking previously issued sessions.

### `POST /api/auth/email-otp/send`
- **Purpose:** Issue a single-use 6-digit email-ownership code (migration 019). The newest code supersedes prior unused codes; delivery goes through the shared `Mailer` transport (SMTP or `MAIL_CAPTURE_DIR` test capture). Two identity modes: signed-in session (existing), or logged-out registration journey via `verification_token` in the JSON body (explicit token wins and must validate - fail closed). Body empty or `{ "verification_token": "<64_hex>" }`; CSRF required (global middleware, anonymous sessions included).
- **Auth:** Session user, or valid claim token (migration 020: single-purpose, ~30-min TTL, consumed on verify; authorizes send/verify/status only - a successful claim-mode verify signs the user in).
- **Rate Limits:** 60s resend cooldown per user (429 while cooling down); max 5 sends per user per hour (429, shared across both modes).
- **Response (200):** `{ "already_verified": false, "message": "Verification code sent.", "expires_in_seconds": 600, "resend_available_in_seconds": 60 }`, or `{ "already_verified": true, "message": "Email is already verified." }` (no row, no email when already verified).
- **Errors:** 401 unauthenticated/invalid token, 403 deactivated/CSRF, 422 no deliverable address, 429 cooldown/budget, 503 email delivery failed (the unused OTP row is deleted; nothing is marked verified).

### `POST /api/auth/email-otp/verify`
- **Purpose:** Prove ownership of the registered email with the 6-digit code. Body-only (`code`, plus optional `verification_token`, in JSON - never in query string/URL). Consumes the code atomically (exactly one winner under concurrent submits); sets `users.email_verified_at` (first verification wins). Claim-mode success also consumes the claim token and signs the user straight in (response carries `user` + `csrf_token`; no separate sign-in step). Orthogonal to `verification_status`/`account_status`: verifying an email never verifies a donor, officer, or medical eligibility, and gates no capabilities.
- **Auth:** Session user, or valid claim token.
- **Request Body:** `{ "code": "482916" }` or `{ "verification_token": "<64_hex>", "code": "482916" }`
- **Response (200):** `{ "verified": true, "email_verified_at": "<utc>" }`, plus `user` + `csrf_token` in claim mode (auto sign-in).
- **Errors:** 400 generic `Invalid or expired code.` (wrong/malformed/expired/used-up/exhausted/superseded - no oracle), 401 invalid token, 403 deactivated, otherwise as above. Max 5 failed attempts per code, then the code is exhausted and a resend is required. Password-reset tokens (64-hex) are format-rejected and can never verify; OTP codes can never reset a password.

### `GET /api/auth/email-otp/status`
- **Purpose:** Verification state for the signed-in owner (drives the `/verify-email` UI cooldown/expiry display). Never exposes code/hash material.
- **Auth:** Authenticated.
- **Response (200):** `{ "verified": false, "email_verified_at": null, "has_active_code": true, "expires_in_seconds": 512, "resend_available_in_seconds": 12, "attempts_remaining": 4, "masked_email": "m***@example.com" }`

### `POST /api/auth/email-otp/status`
- **Purpose:** Claim-mode status for the logged-out registration journey (POST so the token stays in the JSON body, never in a URL). With a session and no token, behaves like the GET status.
- **Auth:** Valid claim token, or session user.
- **Request Body:** `{ "verification_token": "<64_hex>" }`
- **Errors:** 401 invalid/expired token (page shows re-register/sign-in guidance).

---

## 3. Profile & Member Document Management

### `GET /api/profile`
- **Purpose:** Retrieve member profile, blood type provenance, active capabilities, availability status, and uploaded documents.
- **Auth:** Authenticated.
- **Response (200):** Contains user details, `capabilities` matrix, `availability_window` status, and list of `documents`.

### `PUT /api/profile`
- **Purpose:** Update personal profile details (name, phone, birthdate, self-reported blood type, Bataan location).
- **Auth:** Authenticated.
- **Request Body:** `{ "full_name": "...", "phone": "...", "date_of_birth": "YYYY-MM-DD", "blood_type": "O+", "location_id": 161 }` - `location_id` references `bataan_locations`; coordinates resolve server-side. Raw `latitude`/`longitude` keys are rejected (400).
- **Response (200):** `{ "profile": { ...updatedUser, "location": { "municipality_name": "Orani", "barangay_name": null, ... } } }`

### `POST /api/profile/documents`
- **Purpose:** Upload identity or donor verification document (JPG, PNG, WEBP, PDF up to 5 MB).
- **Auth:** Authenticated. Requires CSRF.
- **Rate Limit:** 10 uploads / 5 minutes (`SEC-LOW-02`).
- **Multipart Form:** `file` (binary), `doc_type` (`national_id` | `donor_card` | `parental_consent`), `privacy_acknowledged` (`1`/`true` mandatory ID Privacy Notice acknowledgment - missing/false → 400 + `error.details.privacy_acknowledged`; verified `tests/phase5.ps1` B1b–B1c; frontend `ProfilePage.jsx` + `PrivacyNoticeModal.jsx`).
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
- **Purpose:** Stream own profile picture bytes (owner-only; no cross-user access - other user without picture gets 404).
- **Auth:** Authenticated (Owner only).
- **Response (200):** image bytes (`Content-Type: image/*`, `Content-Disposition: inline`); 404 when none/invalid/missing.
- **Frontend:** `ProfilePage.jsx` upload section + `NavbarAvatar` in `App.jsx` (fallback `User` icon when null/fails).

---

## 4. Blood Requests & Matching Engine

### `GET /api/my/requests`
- **Purpose:** List blood requests created by the authenticated user (server-side pagination).
- **Auth:** Authenticated.
- **Query Params:** `page` (≥1, default 1), `page_size` (1–100, default 20).
- **Response (200):** `{ "requests": [ ...bloodRequests ], "total": 42, "page": 1, "page_size": 20 }`

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
  (`location_id` references `bataan_locations`; facility coordinates resolve server-side. Raw `latitude`/`longitude` keys are rejected. Omitting `location_id` keeps the request location empty - proximity ranking is then skipped for it.)
- **Response (201):** `{ "message": "Blood request created", "request_id": 101, "matches_count": 4 }`

### `GET /api/requests/{id}`
- **Purpose:** Get full details of a specific blood request.
- **Auth:** Authenticated (Request owner, same-chapter Chapter Officer, or Admin).
- **Privacy:** exact `latitude`/`longitude` are included only for the owning requester; officers/admins receive location labels (`location`) without coordinates.
- **Response (200):** `{ "request": { ...requestDetails } }`

### `PUT /api/requests/{id}`
- **Purpose:** Update facility, needed date, units, urgency, or Bataan location of an active OPEN request. Location edit is material (coords re-resolved, `request.material_change` audited, regeneration).
- **Auth:** Authenticated (Request owner only).
- **Request Body (partial):** `{ "facility_name": "...", "needed_datetime": "...", "quantity_units": 2, "urgency": "urgent", "location_id": 26 }` (raw `latitude`/`longitude` rejected 400).
- **Response (200):** `{ "message": "Request updated", "request": { ... } }`
- **Errors:** 403 Forbidden if not owner, 400 Bad Request if request is not in OPEN status.
- **Quantity guard:** `quantity_units` reductions are rejected (409) under the request row lock when `new_quantity < COUNT(ACCEPTED) + COUNT(COMPLETED)`; increases and other edits keep material-change regeneration.

### `POST /api/requests/{id}/cancel`
- **Purpose:** Cancel an active OPEN blood request. Atomically (request row lock): claims the request, re-checks OPEN, snapshots ACCEPTED donors, sets `CANCELLED`, and closes unresolved matches (`POTENTIAL`/`NOTIFIED`/`RESPONDED`/`ACCEPTED`; `COMPLETED`/`WITHDRAWN` preserved). Accepted donors are notified directly.
- **Auth:** Authenticated (Request owner, same-chapter officer, or admin).
- **Response (200):** `{ "message": "Request cancelled successfully" }`

### `GET /api/requests/{id}/matches`
- **Purpose:** View candidate donor matches ranked by red-cell compatibility and geographic distance (server-side pagination over the ranked set). Privacy-safe: `approximate_distance_km` only; no `latitude`/`longitude`/`phone`/`email`/`password`/`document` fields (verified L13).
- **Auth:** Authenticated (Request owner, matched donor own-entry only, Chapter Officer same-chapter, or Admin).
- **Query Params:** `page` (≥1, default 1), `page_size` (1–200, default 50).
- **Response (200):** `{ "matches": [...], "history": [...], "total": 12, "page": 1, "page_size": 50 }` plus:
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
  (Actual `MatchService::privacySafeMatches`: `match_id`, `donor_reference`, `display_name` (full name), `chapter_id`/`chapter_name`, `verification_status`, `availability`, `approximate_distance_km`, `generation`, `status`. Active lists exclude `CLOSED`/`WITHDRAWN`; responses add a sanitized `history` array for withdrawn terminal records visible to involved principals.)

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
- **Purpose:** Donor signals willingness to donate for a notified match. Transitions match status to `RESPONDED`. Requires explicit `donor_share_consent` (422 `consent_required` without it); eligibility is re-checked from live donor state.
- **Auth:** Authenticated (The matched donor only).
- **Request Body:** `{ "donor_share_consent": true }`
- **Response (200):** `{ "message": "Willingness to donate recorded", "match_status": "RESPONDED" }`

### `POST /api/requests/{id}/respond`
- **Purpose:** Request-scoped Respond - primary action for the Home feed. Reconciles a possibly missing/stale persisted relationship from live donor state (newly eligible donors have no match row yet), then transitions to `RESPONDED`. Shares one implementation with match-scoped respond (`MatchDecisionService::respondDonor`); never invokes candidate generation and never emits `match.new`. WITHDRAWN rows are terminal (409).
- **Auth:** Authenticated (Any eligible donor; request must be OPEN).
- **Request Body:** `{ "donor_share_consent": true }` (required, 422 otherwise).
- **Response (200):** `{ "message": "Response recorded.", "status": "RESPONDED", "match_id": 501 }`
- **Errors:** 404 unknown request, 409 non-OPEN / ineligible / withdrawn-terminal, 422 consent missing.

### `POST /api/matches/{id}/accept`
- **Purpose:** Requester selects a responded donor as an actual donor relationship (`RESPONDED` → `ACCEPTED`). Re-validates full live donor eligibility (pool + age; stale `RESPONDED` rows cannot be accepted after ineligibility) and enforces `COUNT(ACCEPTED) + COUNT(COMPLETED) <= quantity_units` under request-row + match-row locks (409 `capacity_full`); requires both contact consents (422 `consent_required`). Already-`ACCEPTED` replays return 200 idempotently without new audit/notification rows.
- **Auth:** Authenticated (Request owner only; request must be OPEN).
- **Request Body:** `{ "requester_share_consent": true }` (required, 422 otherwise).
- **Response (200):** `{ "message": "Donor accepted.", "status": "ACCEPTED", "match_id": 501 }`

### `POST /api/matches/{id}/unaccept`
- **Purpose:** Requester reverses an acceptance (`ACCEPTED` → `RESPONDED`). Willingness survives; requester contact consent resets (fresh consent needed to re-accept); contact revoked; capacity released.
- **Auth:** Authenticated (Request owner only; request must be OPEN).
- **Response (200):** `{ "message": "Acceptance withdrawn. ...", "status": "RESPONDED", "match_id": 501 }`

### `POST /api/matches/{id}/withdraw`
- **Purpose:** Donor withdraws a response (`RESPONDED`/`ACCEPTED` → `WITHDRAWN`, terminal for that pair; never resurrected; capacity released if accepted; contact revoked; requester notified).
- **Auth:** Authenticated (The matched donor only; request must be OPEN).
- **Response (200):** `{ "message": "Response withdrawn.", "status": "WITHDRAWN", "match_id": 501 }`

### `POST /api/matches/{id}/consent`
- **Purpose:** Either principal sets only their own email-sharing flag. Grants allowed only while `ACCEPTED` + OPEN + both active; revokes allowed in `RESPONDED`/`ACCEPTED`/`COMPLETED`+OPEN. Revocation notifies the other principal; state unchanged.
- **Auth:** Authenticated (Match donor or requester only).
- **Request Body:** `{ "share": true }` / `{ "share": false }`.
- **Response (200):** `{ "message": "Email sharing enabled.", "match_id": 501, "side": "donor", "share": true }`

### `GET /api/matches/{id}/contact`
- **Purpose:** Protected bilateral email exchange. Returns both addresses only when actor is a principal AND match is `ACCEPTED`/`COMPLETED` AND request is OPEN AND both accounts active AND both consents true. Reads live from `users`; views audited without addresses.
- **Auth:** Authenticated (Match donor or requester only).
- **Response (200):** `{ "contact": { "donor_email": "...", "requester_email": "..." } }`
- **Errors:** 404 non-principal (existence not leaked), 403 principal in invalid state (incl. after unaccept/withdraw/cancel/expire/fulfill/deactivation/consent revoke).

### `GET /api/requests/feed`
- **Purpose:** Authenticated Home feed: OPEN requests whose requester is active, ranked for the viewer. Member-safe serializer: location labels + `approximate_distance_km` only - no `latitude`/`longitude`/`email`/`phone`/documents. Server-derived `primary_action` + `action_reason` per item (`match_id` is the viewer's own match only).
- **Ranking (finalized contract):** normal mode `compatibility/actionability tier → urgency → needed datetime → distance → created DESC → id DESC`; **Near You intentionally promotes distance to the first secondary key** (`tier → distance → urgency → needed → created DESC → id DESC`) so nearby actionable requests surface first when the viewer opts into proximity.
- **Auth:** Authenticated with `CapabilityMatrix.browse_requests !== false` (rejected/deactivated → 403).
- **Query Params:** `feed_scope` (`all` default | `match` | `critical`; anything else 400), `blood_type` (8-enum), `urgency` (3-enum), `chapter_id` (existing chapter), `near_me=1` (requires viewer location, else 400; excludes unlocated requests), `page` (≥1), `page_size` (1–50, default 15). Raw `latitude`/`longitude` params rejected 400.
- **Scopes:** `match` restricts candidates to distinct OPEN requests carrying one of the viewer's own action-backed match relationships (`RESPONDED`/`ACCEPTED`/`COMPLETED`; `CLOSED`/`WITHDRAWN` excluded, as are `POTENTIAL`/`NOTIFIED` engine candidacy rows the donor never acted on) via a single subquery - ranking within the narrowed set is unchanged. `critical` forces `urgency=critical`, ANDed with the right-side filters.
- **Response (200):** `{ "requests": [ { "id": 9, "required_blood_type": "A+", ..., "compatibility_tier": 0, "is_own": false, "my_match_status": null, "match_id": null, "primary_action": "respond", "action_reason": "ready" } ], "total": 285, "page": 1, "page_size": 15, "match_count": 3, "viewer": { "has_location": true } }` (`match_count` = distinct OPEN requests with the viewer's own qualifying matches, independent of right-side filters.)

### `POST /api/donation-reports`
- **Purpose:** Donor submits report of completed donation at facility for officer confirmation. Accepted matches are the preferred path; responded matches remain supported as the operational path (confirmation never unlocks contact without `ACCEPTED` + both consents). At most one active `PENDING` report per match (atomic insert-if-no-pending; duplicates 409).
- **Auth:** Authenticated (The matched donor only; match must be `RESPONDED` or `ACCEPTED`, request OPEN, donor account active).
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
- **Purpose:** Confirm donation. Executes atomic transaction (lock order request → match → report): re-checks request OPEN + match `RESPONDED`/`ACCEPTED` (any other state - `POTENTIAL`/`NOTIFIED`/`CLOSED`/`WITHDRAWN` - rejects with 409 and no side effects), re-validates donor safety prerequisites (active, verified, enrolled, compatible, age-eligible; scheduling rules excluded), enforces capacity for `RESPONDED`→`COMPLETED`, marks report CONFIRMED, marks match COMPLETED, updates donor's `last_verified_donation_at`, activates Standby (42h) / Cooldown (90d), evaluates fulfillment (requester notified `request.fulfilled`; displaced ACCEPTED donors notified `match.closed`).
- **Auth:** Authenticated (`officer` or `admin` role; officers same-chapter, no self-confirmation).
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
- **Purpose:** Soft-deactivate account (`account_status='deactivated'`, `deactivated_at` set; verification untouched; live sessions denied). Immediately closes the user's OPEN `ACCEPTED` relationships (either side) with contact revoked, counterpart notified (`match.closed`), and `COMPLETED`/`WITHDRAWN` history preserved.
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
- **Query Params:** `blood_type`, `urgency`, `days` (created within N days; default 30-day trend window in the UI, `days` omitted = all-time OPEN).
- **Response (200):** `{ "chapters": [{ "chapter_id": 1, "chapter_code": "mt_samat", "chapter_name": "Mt. Samat Chapter", "municipality": "Orani", "latitude": 14.8003, "longitude": 120.5336, "open_requests_count": 5, "total_units_needed": 8, "blood_type_counts": { "A+": 3, ... }, "urgency_counts": { "routine": 2, "urgent": 2, "critical": 1 } }] }` (`blood_type_counts[bt]` = units needed, not request counts.)

### `GET /api/analytics/summary`
- **Purpose:** Comprehensive operational reporting: request volume, fulfillment rate, cancellation rate, expiration rate (computed against resolved denominator `FULFILLED + CANCELLED + EXPIRED`), ABO distribution, urgency breakdown, and daily trends.
- **Auth:** Authenticated (`officer` or `admin` role). Officers scoped to chapter; Admins support `?chapter_id=N`.
- **Query Params:** `date_from`, `date_to` (default window: last 30 days), `chapter_id`.
- **Response (200):** `{ "request_volume": { "total_requests", "open_requests", "fulfilled_requests", "cancelled_requests", "expired_requests", "resolved_requests", "total_units_requested", "fulfillment_rate_percent", "cancellation_rate_percent", "expiration_rate_percent" }, "demand_by_blood_type": { "A+": { "requests", "units" }, ... }, "demand_by_urgency": { "critical": { "requests", "units" }, ... }, "daily_request_trend": [{ "req_date", "req_count" }], "donor_pool": {...}, "verification_activity": {...}, "donation_engagement": {...} }`

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
- **Response (200):** `{ "marked_read": 5 }`

### Email channel (FR-17, no new endpoints)
- **Behavior:** important notification types additionally send email via `NotificationService` -> `NotificationEmailTemplate` -> `Mailer` (SMTP or `MAIL_CAPTURE_DIR` file capture). The list payload exposes `emailed_at` per notification. Email failures never break the API response. See `docs/email-notifications.md`.

---

## 9. Cross-cutting contracts

### Notifications & dedup
- `notifications.dedup_key` / `generation` are `NOT NULL` with `UNIQUE(dedup_key, generation)` (migration 018): dedup holds by schema, not caller discipline.
- Match outreach sends at most 500 notifications per generation (intentional anti-flood bound); remaining eligible donors persist as `POTENTIAL` candidates visible to the requester and become notifiable on later generations.
- Critical match emails skip the normal 5/hour budget but are throttled per request (max 3 critical emails per donor per request per hour); in-app notifications are always created.
- Fulfillment emits `request.fulfilled` (requester) and `match.closed` (displaced ACCEPTED donors); cancellation/expiry notify the requester plus each ACCEPTED donor.

### Sessions, CSRF & revocation (migration 018)
- `users.session_version` revokes previously issued sessions on password reset, deactivation/reactivation, and role/chapter changes; `requireActiveUser` additionally enforces a 12-hour idle timeout (`AuthMiddleware::IDLE_TIMEOUT_SECONDS`).
- Production must serve HTTPS with `APP_SECURE_COOKIES=true` and HSTS at the proxy layer (see `.env.example`).

### Audit logging
- Append-only (`audit_log` triggers reject UPDATE/DELETE). Security-critical mutations (accept/unaccept/withdraw/respond/confirm, verification decisions, admin role/chapter/status changes) are fail-closed: a failed audit write aborts the mutation instead of proceeding silently. Actor FK races retry with a null actor + `_actor_unresolved` context rather than dropping the event.

### Officer/admin verification asymmetry (intentional)
- The verification **queue** (`GET /api/officer/verifications`) is officer-only (chapter work queue); **detail/decide** additionally allow `admin` as the System Administrator override path. Admins use the API directly for cross-chapter cases; this asymmetry is by design, not a gap.
- **Response (200):** `{ "success": true, "data": { "marked_read": 5 } }`
