# BloodMatch — Project Context

> **This file is the permanent source of truth for understanding the BloodMatch system before making implementation decisions.**
> Read this file before writing any code, changing architecture, or making claims about what is implemented.
> Where any earlier section conflicts with the ratified rules in §9 (Finalized BloodMatch Business Rules), **§9 prevails**.

---

## 1. Project Overview

| Field | Value |
|---|---|
| **System Name** | BloodMatch |
| **Full Title** | "Blood Match – Development of a Peer-to-Peer Blood Donor Matching Platform Utilizing Biological Substitution Hierarchy and Proximity Sorting for DeMolay Group" |
| **Type** | Web-based blood donor matching platform |
| **Audience** | Exclusively for members of the DeMolay organization (Bataan) |

BloodMatch makes it easier for a person who needs blood to find a potentially compatible donor within the organization. Instead of relying on scattered social media posts ("Need blood donor, Blood Type O+, urgently needed"), the system organizes donor information and uses **blood compatibility matching** plus **location/proximity filtering** to identify potential donors.

---

## 2. Core Problem

The current manual process of finding blood donors is:

- Slow
- Unorganized
- Dependent on social media posts
- Difficult to search
- Difficult to determine compatible blood types
- Difficult to identify nearby donors
- Difficult for officers to verify legitimate members
- Difficult to keep donor information updated

**Example:** A member needs A+ blood. Instead of posting on Facebook and waiting, BloodMatch searches its registered donor database and identifies potentially compatible donors based on:

1. Blood type compatibility
2. Donor availability/status
3. Location/proximity
4. Verification status

The system is a **structured matching platform**, not a social media site.

---

## 3. Target Users & Roles (3-Tier RBAC)

BloodMatch is intended exclusively for DeMolay members. There are three primary roles.

### 3.1 Member / Donor

A normal registered DeMolay member can:

- Create an account / log in
- Maintain their profile
- Provide blood type information
- Register as a donor
- Indicate donor availability
- View blood requests
- Search for compatible blood donors
- Receive notifications
- Respond to blood requests
- View their donor/request activity

A member can be **both** a blood donor and a blood requestor — no separate accounts are needed.

### 3.2 Officer / Admin

Designated DeMolay officers manage and verify users. They can:

- Review registered members
- Verify member identity / submitted documents
- Approve/reject accounts
- Manage users
- Monitor blood requests and donor activity
- View system activity, reports, and analytics
- Manage/monitor system records

Officers are trusted administrative users.

### 3.3 System Administrator

The highest-level administrative role. May have access to:

- User management
- Officer management
- System configuration
- Reports & analytics
- Audit logs
- Administrative controls

> Exact permissions must follow the project's **existing RBAC implementation** and must not be expanded without a requirement.

---

## 4. Main Purpose & Scope Boundaries

**Central objective:** Connect blood requestors with potentially compatible and available donors within the DeMolay organization.

**What BloodMatch is NOT:**

- ❌ It does **not replace hospitals, doctors, blood banks, or professional medical services**
- ❌ It is **NOT Facebook** — the Facebook-like aspect refers only to a familiar social-style interface where appropriate
- ✅ It **is** a matching and coordination platform; final medical suitability is determined by qualified professionals and the receiving facility

### 4.1 Out of Scope

Unless a future requirement explicitly adds them, BloodMatch does **NOT** require:

- Friends/followers
- Likes/reactions
- Generic social-media feed
- Stories
- Public donor directory
- Public blood-type directory
- General-purpose messaging platform
- Messenger clone
- Social-media sharing system

The system may use familiar social-platform interaction *patterns* in its UI where appropriate, but those are not core BloodMatch functional requirements and must not be built without an explicit new requirement.

---

## 5. Core Workflow

```
STEP 1: Member Registration → STEP 2: Account Verification → STEP 3: Blood Request
        → STEP 4: Matching Engine → STEP 5: Proximity Sorting
        → STEP 6: Donor Notification → STEP 7: Requestor–Donor Connection
```

### Step 1 — Member Registration

Member creates an account providing: name, contact info, location, blood type, DeMolay/member info, account credentials, and other required registration data.

### Step 2 — Account Verification

An authorized officer reviews submitted info/documents and can:

- Approve
- Reject
- Request correction/review

Only appropriately verified accounts participate in sensitive donor/request workflows, per the project's existing rules.

### Step 3 — Blood Request

A requestor creates a request containing: required blood type, amount/unit info where applicable, location/facility, urgency, needed date/time, additional info, and request status.

### Step 4 — Matching Engine

Analyzes registered donors using the predefined **biological substitution hierarchy** (see §6).

### Step 5 — Location / Proximity Sorting

Prioritizes compatible donors who are realistically closer to the request location.

### Step 6 — Donor Notification

Matching donors receive smart notifications (in-app + email, with duplicate/consecutive-notification prevention — see §9.7).

### Step 7 — Requestor–Donor Connection

Establishes a connection between requestor and donor.

> ⚠️ A "connection" does **NOT** necessarily mean live chat. The core functionality is matching/coordination itself. If live chat exists or is added, it is a communication feature **on top of** the matching system — never the core mechanism.

---

## 6. Key Feature: Smart Cascading Match Engine

Identifies compatible donors rather than only identical blood types. The engine uses **red-cell transfusion compatibility only** (ABO + Rh rules). Plasma, platelet, and whole-blood compatibility are out of scope. Results never replace clinical blood typing, crossmatching, or professional medical judgment.

**Example — recipient requires A+:**
Compatible donor types may include:

1. A+
2. A-
3. O+
4. O-

Matches should be ranked appropriately using this priority order:

```
Compatibility → Availability → Verification → Proximity
```

> ⚠️ The system must **never** prioritize a nearby incompatible donor over a compatible donor. Compatibility is the primary requirement; distance is only a ranking/filtering factor.

### Architectural Rule (critical)

- The compatibility matrix must be **stored centrally and consistently**.
- Do **not** scatter blood compatibility logic across React components, PHP pages, APIs, or SQL queries.
- Use a reusable backend service/function such as `BloodCompatibilityService`, `MatchService`, or a `CompatibilityMatrix`.
- **The backend is the authoritative source of matching logic.**

---

## 7. Location-Based Matching

Proximity sorting prioritizes donors closer to the requestor/request location/chapter. **Decided approach:** donors and requests carry latitude/longitude; approximate distance is computed (e.g., Haversine formula) and used purely as a ranking factor among eligible compatible donors (see §9.4). Chapter membership is **never** a hard filter (see §9.3).

**DeMolay chapters in Bataan:**

| Chapter | Location |
|---|---|
| Mt. Samat Chapter | Orani |
| Mt. Tarak Chapter | Mariveles |
| Meridian Heights Chapter | Balanga City |

---

## 8. Additional Modules

### 8.1 Regional Blood Demand Map

Visualizes where blood requests/demand occur. Helps officers understand:

- Which chapters have more requests
- Which blood types are frequently requested
- Demand patterns
- Regional shortages
- Activity trends

### 8.2 Officer Verification Module

Officers review submissions and verify users are legitimate members. Account statuses distinguish between:

- Registered
- Pending verification
- Verified
- Rejected
- Deactivated

> ⚠️ **Existing project decision:** Donor-card documents do **not** act as a hard gate in the same way as a national ID. Do not change this behavior unless the project specification explicitly changes.
>
> Ratified lifecycle, permission matrix, separation-of-duties, and age-eligibility rules: see §9.2.

### 8.3 Automated Soft-Deactivation

Instead of permanently deleting users/records, the system deactivates them — preserving historical records and database integrity.

- Soft-deactivated accounts are excluded from normal donor matching
- Implemented via e.g. `active = 1` or a more explicit status system
- Do **not** permanently delete records unless requirements explicitly call for it

### 8.4 Donor Availability

Being compatible does not mean currently available. Potential statuses:

- Available
- Unavailable
- Standby
- Deactivated

Only appropriate active/available donors normally appear as active matches.

> Standby/cooldown mechanics, donation confirmation, and reactivation rules: see §9.6.

### 8.5 Smart Email Notifications

Automated email notifications for:

- Account verification updates
- Password reset
- Blood request alerts
- Donor match notifications
- Important system notifications

> Anti-spam rule: if one event causes a notification, the same event must not repeatedly generate identical notifications.

### 8.6 Password Reset

Specified behavior (implementation status: see §10):

- Reset tokens are **hashed**
- Tokens are **single-use**
- Tokens expire after approximately **30 minutes**
- Passwords must be securely hashed
- Reset tokens must never be stored or exposed as plain reusable credentials

---

## 9. FINALIZED BLOODMATCH BUSINESS RULES

> Ratified through the requirements interview on **2026-08-26**. These rules are authoritative; earlier sections are background context.

### 9.1 Requirement Index

| FR ID | Requirement | Rules |
|---|---|---|
| FR-01 | User Registration | §9.2 |
| FR-02 | User Login and Logout | §9.2 |
| FR-03 | Role-Based Access Control | §9.2 |
| FR-04 | User Profile Management | §9.2 |
| FR-05 | Blood Request Creation | §9.5 |
| FR-06 | Cascading Match Engine | §9.3 |
| FR-07 | Match Prioritization | §9.4 |
| FR-08 | Chapter Filtering | §9.3, §9.4 |
| FR-09 | Donor Availability | §9.6 |
| FR-10 | Officer Verification | §9.2 |
| FR-11 | Verification Restrictions | §9.2 |
| FR-12 | 42-Hour Standby / Soft-Deactivation | §9.6 |
| FR-13 | Donor Reactivation | §9.6 |
| FR-14 | Request Status Management | §9.5 |
| FR-15 | Regional Demand Map | §9.9 |
| FR-16 | Notifications | §9.7 |
| FR-17 | Email Alerts | §9.7 |
| FR-18 | Activity Logging | §9.10 |
| FR-19 | Analytics | §9.11 |
| FR-20 | Officer Dashboard | §9.12 |

**Requirement texts supplied by the product owner on 2026-08-26 (verbatim):**

- **FR-01 — User Registration:** The system shall allow DeMolay Bataan members to create an account by providing the required personal, chapter, and blood-related information.
- **FR-02 — User Login and Logout:** The system shall allow registered users to securely log in and log out of their accounts.
- **FR-04 — User Profile Management:** The system shall allow users to view and update their personal information, blood type, chapter, contact information, and donor availability.

### 9.2 Accounts, Statuses & Permissions (FR-03, FR-10, FR-11)

**Account lifecycle:**

```
Registered → Pending Verification → Verified
                                     ↙      ↘
                                Rejected   Deactivated
                                   ↓ (resubmission allowed)
                             Pending Verification
```

**Capability matrix:**

| Status | Browse requests | Create blood request | Become donor | Appear in matches | Notifications |
|---|---|---|---|---|---|
| Registered | Limited | No | No | No | Account-related only |
| Pending | Yes | Yes — request marked *pending review* | No | No | Yes, relevant system notifications |
| Verified | Yes | Yes | Yes | Yes, if available | Yes |
| Rejected | No | No | No | No | Rejection/status notifications only |
| Deactivated | No | No | No | No | None |

**Rationale:** being unverified must not prevent someone from *asking for help*; it only prevents them from being *presented as a donor*.

**Verification evidence & blood-type data model:**

- Identity/membership verification does **not** require a donor card; the donor card is **supplementary** supporting evidence for blood-related information.
- A document never makes BloodMatch the final authority on medical compatibility.
- Blood type is modeled with three fields: `blood_type`, `blood_type_source` (e.g., `Donor Card` / `Self Reported`), `blood_type_verified` (boolean).
- Unverified (self-reported) blood type may participate in matching but must **never be represented to users as medically confirmed**.

**Separation of duties (mandatory):**

- A Chapter Officer verifies only members of their **assigned chapter**: review info/documents, approve, reject, request re-review/correction.
- No account may approve or verify **itself**. Officers cannot verify: themselves, another officer, or a System Administrator; modify their own verification status; or approve their own documents.
- Routine cross-chapter verification is prohibited; it requires explicit System Administrator authorization.
- Officers are created/verified/activated by the **System Administrator**, who also assigns officers to chapters, deactivates officers, reviews escalated cases, and overrides verification decisions where necessary.

**Age eligibility (Philippine guidance):**

| Age | Rule |
|---|---|
| Under 16 | Cannot participate as eligible donor |
| 16–17 | May register as potential donor **only with documented parental/guardian consent**; still subject to medical screening |
| 18+ | Normal registration as potential donor |

BloodMatch checks **administrative prerequisites only** (age rule + account verification + required consent + donor requirements). Actual donation always depends on medical screening by an authorized blood facility.

### 9.3 Cascading Match Engine (FR-06, FR-08)

- **Red-cell transfusion compatibility only** (ABO + Rh). No plasma/platelet/whole-blood calculations. Output is advisory, never a substitute for clinical typing/crossmatching/professional judgment.
- The engine runs **automatically when a request is created**, is **re-run when relevant donor/request eligibility information changes**, and may be **manually re-run by authorized users** for an active request.
- Re-running updates the existing match set — it never creates duplicate matches or duplicate notifications.
- A new notification occurs only on a **materially new match event** (see §9.7).
- **Chapter membership is NOT a hard eligibility filter.** All otherwise eligible compatible donors are considered regardless of chapter. Users may optionally filter *displayed* results by chapter, but display filtering can never override compatibility, verification, availability, or other mandatory eligibility requirements.

### 9.4 Match Prioritization & Proximity (FR-07, FR-08)

Priority order (unchanged): `Compatibility → Availability → Verification → Proximity`.

- Proximity = distance computed from donor/request **latitude/longitude** (e.g., Haversine), used as a ranking factor among eligible compatible donors.
- Same-chapter donors may receive a ranking preference where applicable.
- Exact coordinates are **never exposed to other users**.
- **Urgency** affects outreach breadth and notification priority only (critical → broader cross-chapter outreach); it never overrides compatibility, verification, or eligibility rules.

### 9.5 Requests & Matches (FR-05, FR-14)

**Request statuses:** `OPEN`, `FULFILLED`, `CANCELLED`, `EXPIRED`. Matching is an *operation* against an active request, not a permanent request status.

- Requestor may edit or cancel an OPEN request; authorized officers may intervene administratively.
- Auto-expiry: after the needed date/time passes, a request becomes EXPIRED and is removed from active matching, notifications, and the demand map.
- Changes to matching-affecting fields (blood type, location, urgency, needed date/time, quantity) trigger matching re-evaluation and reconciliation of existing matches without duplicates.

**Match statuses:** `POTENTIAL`, `NOTIFIED`, `RESPONDED`, `COMPLETED`.

- Donor response transitions `POTENTIAL`/`NOTIFIED` → `RESPONDED`.
- **Multiple donors may remain engaged in parallel** while a request is open; accepting one donor does not cancel others (backups until FULFILLED).
- When a request becomes FULFILLED/CANCELLED/EXPIRED, remaining unresolved matches close accordingly.
- `COMPLETED` requires the donation to pass the donation-confirmation workflow (§9.6).

### 9.6 Donor Availability, Standby & Cooldowns (FR-09, FR-12, FR-13)

Availability statuses: `Available`, `Unavailable`, `Standby`, `Deactivated`.

**Donation confirmation workflow (authoritative trigger):**

1. Donor submits a donation report (self-report alone is insufficient).
2. An authorized Chapter Officer confirms the donation.
3. Confirmation records `last_verified_donation_at` and triggers the post-donation states below.

**Two independent time-based rules:**

| Rule | Trigger | Duration | Effect |
|---|---|---|---|
| Post-donation standby | Officer-confirmed donation | ~**42 hours** (temporary) | Donor temporarily excluded from matching |
| Inter-donation cooldown | Officer-confirmed donation (`last_verified_donation_at`) | Configurable, ≈**3 months** per Philippine guidance | Donor ineligible for new matches until lapsed |

- **Non-response to a request does NOT trigger any standby.**
- Both rules are administrative platform rules; actual medical eligibility remains with the donating facility.
- Cooldowns **cannot be bypassed** by self-reactivation; they are enforced automatically by the backend.
- **Reactivation:** donors may toggle ordinary availability themselves, but only where no system-enforced state blocks them (post-donation standby, cooldown). Administrative deactivation requires an authorized Chapter Officer or System Administrator to lift.
- Verification status and donor availability are **independent states**; reactivation never resets verification status.
- > **Resolved conflict note:** early drafts described the 42-hour rule as a *non-response penalty*. Ruled out 2026-08-26: the 42-hour period is exclusively post-donation standby.

### 9.7 Notifications & Channels (FR-16, FR-17)

**Deduplication rule:** at most **one match notification per donor per request per matching generation**. A new generation is created only when a material change could alter the match set: required blood type, location, chapter/outreach scope, urgency, needed date/time, or other eligibility-affecting conditions. Re-runs without material change generate nothing; a donor becoming newly eligible for an existing request is a new match event and may be notified.

**Channels:**

- **In-app notification center:** dedicated notification records with read/unread status and timestamps.
- **Email:** separate channel for defined important events — critical matches, verification decisions, request/account-status changes.

**Critical-request outreach:** notify all currently eligible compatible donors within the defined outreach scope (cross-chapter included). No arbitrary small recipient cap; dedup + rate-limiting prevent duplicates from repeated events/runs. Critical notifications may send immediately, including off-hours, respecting user settings and platform delivery limits. Outreach stops when the request is FULFILLED, CANCELLED, or EXPIRED.

### 9.8 Privacy & Data Exposure (cross-cutting)

**Before a donor responds**, the requestor sees only: display name/first name, chapter, verification/availability status, approximate distance.

Hidden unless explicitly disclosed by the donor: exact address, coordinates, phone number, email address, identification documents, donor-card documents.

- After a response, communication stays in-system; direct contact details remain hidden unless the donor chooses to disclose them.
- Donor discovery is **request-scoped only** — there is no freely browsable member/donor directory, and members cannot obtain others' blood types, contacts, exact locations, or profiles.
- Officers/Administrators get broader access only to the extent necessary for authorized duties.

### 9.9 Regional Blood Demand Map (FR-15)

- Displays **aggregated counts of currently OPEN requests at chapter level, grouped by blood type**.
- Never displays individual request locations, requestor identities, exact coordinates, or pins.
- Access: authorized Chapter Officers and System Administrators only.
- Trend windows: default **30 days**; optional 7-day, 90-day, custom ranges.

### 9.10 Audit Logging (FR-18)

Append-only audit log. Mandatory events include: registration; login success/failure; logout; password-reset events; verification decisions; request creation/edit/cancellation/fulfillment/expiration; donation reports & confirmations; availability/standby/cooldown/reactivation changes; role or chapter changes; administrative overrides; cross-chapter authorizations; other significant security/system actions.

Record contents: timestamp, actor/account reference, action, target record (where applicable), relevant context — without unnecessarily storing sensitive document contents.

Viewership tiers: System Administrator = full log; Chapter Officer = chapter-relevant subset; members = none.

### 9.11 Analytics (FR-19)

Required metrics (from transactional data only, never manually entered values): request volume/trends; requests by blood type and chapter; fulfillment/cancellation/expiration rates; donor availability/status; verification activity; chapter engagement. Secondary (only if reliably derivable): time-to-first-response, donor response rate. CSV/export is **not** required unless separately approved.

### 9.12 Officer Dashboard (FR-20)

Primary work queues, in order:

1. Pending member/donor verifications within the officer's chapter
2. Donation reports awaiting confirmation
3. Active blood requests requiring monitoring/admin attention

Officers see full requestor identity within their own chapter when necessary for duties (data-minimization applies). Cross-chapter requestor information requires appropriate authorization/System Administrator access.

### 9.13 Chapters & Officer Assignment

- Three fixed chapters stored as **seeded reference data** in a dedicated chapters table: Mt. Samat (Orani), Mt. Tarak (Mariveles), Meridian Heights (Balanga City).
- Not createable/editable/deletable through normal administrative functions.
- Each Chapter Officer is assigned to **exactly one chapter**; ordinary authority is restricted to that chapter's members, requests, and verifications.
- System Administrators manage officer assignments and system-wide administration.

---

## 10. Verified Implementation Status

> **Specification ≠ implementation.** A feature described anywhere in this document is NOT implemented until its code has been inspected and verified in this repository. Statuses may only advance to ⚠️/✅ after inspecting committed application code.

### 10.0 Repository Audit (2026-08-26)

Full working-tree inspection found:

- Files present: `AGENTS.md`, `CONTEXT.md`, `skills-lock.json`, agent skills under `.agents/` — **nothing else**
- Branch `main` has **zero commits** (`git log`: "current branch 'main' does not have any commits yet")
- Glob for `*.php`, `*.js`, `*.jsx`, `*.sql`, `*.html`, `*.json` (excluding skills config): **no application source files**
- No database schema, migrations, dumps, endpoints, or React components exist in this repository

**Consequence:** every feature below is classified ❌ Not Implemented. Earlier drafts of this file carried "existing/implemented" claims inherited from the original project narrative; those claims are **withdrawn** until corresponding code exists and is verified here.

**Shared evidence for all rows:** no files/components/endpoints/database tables exist to cite — see Repository Audit above.

### 10.1 Requested Verification Results

| Feature | Status | Supporting files/components/endpoints/tables |
|---|---|---|
| FR-12 — 42-hour post-donation standby (§9.6) | ✅ IMPLEMENTED AND VERIFIED 2026-08-26 (`DonorEligibilityService` windows + `MatchService` pool guard; evidence `docs/test-log-phase9.md`) |
| Separate ~3-month inter-donation cooldown (§9.6) | ✅ IMPLEMENTED AND VERIFIED 2026-08-26 (same settings-driven mechanism; evidence `docs/test-log-phase9.md`) |
| FR-16 — In-app notifications (§9.7) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (migration 013 `notifications` table, `UNIQUE(dedup_key, generation)` deduplication, `NotificationService`, `NotificationRepository`, `NotificationsController`, mark-read, live unread count, type/read filters, React `NotificationsPage` & header bell badge; evidence `docs/test-log-phase10.md`) |
| FR-17 — Email notifications (§9.7) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (`Mailer.php` wrapping PHPMailer with env SMTP config, 5/user/hr rate limiting, critical urgency bypass, synchronous best-effort delivery; evidence `docs/test-log-phase10.md`) |
| FR-15 — Regional Blood Demand Map (§9.9) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (`DemandMapController`, `GET /api/demand-map`, chapter centroid aggregation of active OPEN requests, ABO/Rh and urgency breakdowns, zero individual pins/coordinates, officer scoping & admin global, React `DemandMapPage`; evidence `docs/test-log-phase12.md`) |
| FR-18 — Audit logging (§9.10) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (32/32 mandatory events audited across P3–P10; append-only triggers active; `AuditLogRepository` with resource-target chapter scoping & context sanitization; admin viewer `/admin/audit-logs` & scoped officer viewer `/officer/audit-logs`; evidence `docs/test-log-phase11.md`) |
| FR-19 — Analytics/reporting (§9.11) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (`AnalyticsRepository`, `GET /api/analytics/summary`, single-pass DB aggregation of request volume, resolution rates excluding open, blood type demand, urgency demand, daily trends, live donor eligibility breakdown, verification/donation activity, React `AnalyticsPage`; evidence `docs/test-log-phase12.md`) |
| FR-20 — Officer monitoring dashboard (§9.12) | ✅ IMPLEMENTED AND VERIFIED 2026-08-27 (`OfficerDashboardController`, `GET /api/officer/dashboard`, scoped to officer chapter, pending verifications queue, pending donation reports queue, active open requests, donor pool summary, blood type demand, recent chapter activity, `AdminDashboardController` system-wide overview, React `OfficerDashboardPage` & `AdminDashboardPage`; evidence `docs/test-log-phase12.md`) |

### 10.2 Full Feature Status

| Feature / Requirement | Status |
|---|---|
| Phase 2 foundational schema (chapters, users, password_resets, audit_log) — ✅ IMPLEMENTED AND VERIFIED 2026-08-26 (migrations 001–004 on blank DB; FK/CHECK/append-only triggers tested; see docs/erd.md). Table/column existence only — no behavior implemented | ✅ IMPLEMENTED AND VERIFIED (schema only) |
| FR-01 User Registration — verified 2026-08-26 (`AuthService::register`, `POST /api/register`; creates `verification_status='pending'`, `account_status='active'`; evidence `docs/test-log-phase3.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-02 Login/Logout — verified 2026-08-26 (`AuthService::login/logout`, session regeneration, `auth_throttle` lockout; evidence `docs/test-log-phase3.md`) | ✅ IMPLEMENTED AND VERIFIED |
| Password reset flow (§8.6) — verified 2026-08-26 / email wired 2026-08-27 (hashed single-use ~30-min tokens, reuse/expiry rejected, token delivered via best-effort email; evidence `docs/test-log-phase3.md`, `docs/test-log-phase10.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-04 Profile management — verified 2026-08-26 (`ProfileController` GET/PUT whitelist; forbidden-field rejection; coordinate validation; blood-provenance rules; evidence `docs/test-log-phase5.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-10 Officer verification — verified 2026-08-26 (own-chapter queue, pending→verified/rejected, donor-card provenance with non-medical disclaimer, audit trail; evidence `docs/test-log-phase5.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-11 Verification restrictions — verified 2026-08-26 (self/officer/admin-target and cross-chapter blocks all enforced+audited; capability-matrix helper live; request/donor gating cells deferred to P6/P7 endpoints; evidence `docs/test-log-phase5.md`) | ✅ IMPLEMENTED AND VERIFIED |
| Cascading match engine / centralized compatibility matrix (FR-06) — verified 2026-08-26 (`BloodCompatibilityService` sole authority, 8-type red-cell matrix table+seed, hard-filter pool, centralized `MatchService`, unique persistent (request,donor) rows, generation semantics, auto-generation on create/material-change, manual re-match authz, privacy-safe serializer, explicit donor-enrollment requirement; evidence `docs/test-log-phase7.md`). Notification/response/completion states deferred to P8/P10 | ✅ IMPLEMENTED AND VERIFIED |
| Proximity/location sorting (FR-07/FR-08) — verified 2026-08-26 (Haversine distances, chapter preference not filter, unlocated donors ranked last, proximity never overrides compatibility invariant-tested; evidence `docs/test-log-phase7.md`) | ✅ IMPLEMENTED AND VERIFIED |
| Blood request lifecycle (FR-05/FR-14) — verified 2026-08-26 (`RequestsController`, capability-gated creation incl. pending-user `pending_review` provenance, OPEN/FULFILLED/CANCELLED/EXPIRED lifecycle, material-change auditing, chapter-scoped access/cancel, idempotent expiry CLI; evidence `docs/test-log-phase6.md`) | ✅ IMPLEMENTED AND VERIFIED |
| Donor availability / reactivation (FR-09/FR-13) — verified 2026-08-26 (availability toggle through DonorEligibilityService extension point; respond flow w/ parallel engagement; donation_reports + transactional officer confirmation setting last_verified_donation_at + match COMPLETED; quantity-threshold FULFILLED rule; evidence `docs/test-log-phase8.md`) | ✅ IMPLEMENTED AND VERIFIED (P8 scope) |
| FR-12 42-hour standby + inter-donation cooldown — verified 2026-08-26 (system_settings-driven windows anchored to last_verified_donation_at; standby written only by confirmation tx; read-model matching enforcement; non-bypassable availability guards; clock-injectable tests incl. boundary + configurability; evidence `docs/test-log-phase9.md`) | ✅ IMPLEMENTED AND VERIFIED |
| RBAC, backend-enforced (FR-03) — verified 2026-08-26: role gates + chapter scoping + fresh account-status checks on privileged endpoints; admin user/role/chapter/deactivate endpoints; denial+action auditing (`authz.denied`, `admin.user.*`); evidence `docs/test-log-phase4.md`. Officer verification decisions remain P5 | ✅ IMPLEMENTED AND VERIFIED (Phase 4 scope) |
| Soft-deactivation — verified 2026-08-26 (P4 admin deactivate/reactivate endpoints w/ CHECK-paired `deactivated_at`, immediate live-session denial, verification_status untouched; audited) | ✅ IMPLEMENTED AND VERIFIED |
| FR-16 In-App Notifications — verified 2026-08-27 (migration 013 `notifications` table, `UNIQUE(dedup_key, generation)` deduplication, event wiring for matches/verification/account/request/donation/expiry, live unread counter, type/read filters, React NotificationsPage; evidence `docs/test-log-phase10.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-17 Email Alerts — verified 2026-08-27 (synchronous best-effort SMTP delivery via PHPMailer, 5/hr rate limiting with critical urgency bypass, graceful degradation when unconfigured; evidence `docs/test-log-phase10.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-18 Audit Logging — verified 2026-08-27 (32/32 mandatory events verified; append-only DB triggers active; `AuditLogRepository`, `AuditLogAdminController`, `AuditLogOfficerController`, scoped viewer pages; evidence `docs/test-log-phase11.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-15 Regional Blood Demand Map — verified 2026-08-27 (`DemandMapController`, `GET /api/demand-map`, chapter centroid aggregation, privacy-safe, officer-scoped & admin global; evidence `docs/test-log-phase12.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-19 Analytics & Reporting — verified 2026-08-27 (`AnalyticsRepository`, `GET /api/analytics/summary`, DB aggregations, resolution rates, donor pool availability, React AnalyticsPage; evidence `docs/test-log-phase12.md`) | ✅ IMPLEMENTED AND VERIFIED |
| FR-20 Officer & Admin Dashboards — verified 2026-08-27 (`OfficerDashboardController`, `AdminDashboardController`, operational queues, cross-chapter comparison, React dashboards; evidence `docs/test-log-phase12.md`) | ✅ IMPLEMENTED AND VERIFIED |

> When development begins, update these rows only after verifying actual files/endpoints/tables, and record the supporting evidence alongside each status.

---

## 11. Technical Constraints

| Constraint | Detail |
|---|---|
| **Stack (required)** | HTML, CSS, JavaScript, React, PHP, MySQL, XAMPP |
| **Laravel** | Do **not** introduce Laravel unless explicitly instructed |
| **MySQL port** | Runs through XAMPP on port **3307**, NOT 3306 |
| **Architecture** | Do not replace the existing architecture without a specific reason |

---

## 12. Development Guardrails

1. **Do not invent requirements.**
2. **Do not remove or reinterpret** functional and non-functional requirements.
3. **Distinguish implemented vs. planned features.** If existing code shows a feature isn't implemented, say so.
4. **Inspect the existing project** before making claims about what is already implemented.
5. **Enforce authorization on the backend.** Never rely solely on hiding buttons in React — a user must not reach an admin endpoint by manually entering its URL. Backend routes must verify: authentication, session, role, verification status (where required), and authorization.
6. **Keep compatibility/matching logic centralized on the backend** — never duplicated in frontend components, page scripts, or SQL queries.
7. **Preserve historical data** — prefer soft-deactivation over deletion.
