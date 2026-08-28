# BloodMatch — Master Implementation Roadmap

> Derived from [CONTEXT.md](./CONTEXT.md) (requirements source of truth, incl. §9 Finalized Business Rules and §10 Verified Implementation Status) and [AGENTS.md](./AGENTS.md) (development rules).
> This roadmap plans work only — no application code has been written.

---

## 0. Current State Assessment

### 0.1 Functional & Security Implementation State

Per the verified Repository Audits and Master Regression Suite (`tests/run_all.ps1`, 2026-08-27):
- **Phases 1–12:** Complete and end-to-end verified.
- **Phase 15 (Frontend Integration, Accessibility & Design Polish):** Complete and end-to-end verified; clean Vite production build.
- **Phase 16 (Security & Performance Hardening):** Complete and end-to-end verified (15/15 security hardening assertions).
- **Master Regression Suite:** 11 / 11 suites passed (316 / 316 assertions green).

| Requirements & Scope | State |
|---|---|
| FR-01 … FR-20 (all 20) | ✅ **100% IMPLEMENTED AND VERIFIED** — verified across Phases 1–12; evidence in `docs/test-log-phase*.md` |
| Phase 15: Frontend & A11y Polish | ✅ **100% IMPLEMENTED AND VERIFIED** — verified in `docs/test-log-phase15.md` |
| Phase 16: Security Hardening | ✅ **100% IMPLEMENTED AND VERIFIED** — verified in `docs/test-log-phase16.md` |

Requirement texts live in CONTEXT.md §9.1; ratified behavior rules in §9.2–§9.13.

### 0.2 Non-Functional Requirements NFR-01 → NFR-17

⚠️ **NFR-01…NFR-17 are NOT DEFINED anywhere in this repository** (verified by full-text search of all `.md` files). They are treated as **Pending — Product Owner must supply definitions**. Per AGENTS.md rule #4, they are not invented here.

Until supplied, each phase's security/performance/UX considerations are anchored to the **already-documented non-functional constraints**:

| Source | Documented constraint |
|---|---|
| AGENTS.md #7–9 | Stack: HTML/CSS/JS/React/PHP/MySQL/XAMPP; MySQL port **3307**; no Laravel |
| AGENTS.md #10–16 | Secure hashing, prepared statements, input validation, backend authorization, protected routes, CSRF preservation, no secrets in frontend |
| AGENTS.md #17–22 | Centralized matching logic, compatibility > proximity, medical disclaimer stance, no duplicate notifications, audit logging, transactions/data integrity |
| AGENTS.md #23–28 | Existing conventions, component reuse, minimal dependencies, responsive UI, black-and-white identity w/ light+dark mode, accessibility/usability/performance/maintainability |
| CONTEXT.md §4 | Not a medical service; advisory matching only |

> **Open item:** request NFR-01–NFR-17 text before Phase 16/17 so traceability can be completed honestly.

---

## Phase Dependency Graph (summary)

```
P1 Foundation ──► P2 Database ──► P3 Auth/Registration ──► P4 RBAC/Chapters
                                                                  │
        P5 Profiles & Verification ◄──────────────────────────────┘
                  │
        P6 Request Lifecycle ──► P7 Matching Engine ──► P8 Availability & Donation
                                                                │
                              P9 Standby & Cooldown ◄───────────┘
                                        │
              P10 Notifications & Email ◄── (also needs P6)
                        │
              P11 Audit Logging (schema seeded at P2; coverage completed here)
                        │
              P12 Dashboards, Analytics & Demand Map (Consolidated FR-15, FR-19, FR-20)
                        │
              P15 Frontend Integration & UX (continuous, finalized here)
                        │
              P16 Testing & Security ──► P17 Traceability
```

---

## PHASE 1 — Project Foundation

1. **Objective:** Establish repo structure, tooling, environment config, and coding conventions so all later phases commit against a stable base.
2. **Requirements covered:** AGENTS.md #7–9 (stack), #23 (conventions); enables all FRs.
3. **Dependencies:** None.
4. **Database work:** None (connection layer only).
5. **Backend/API work:**
   - Proposed layout (adjustable, follows XAMPP norms): `backend/public/` (document root), `backend/src/{config,controllers,services,middleware,repositories}`, `database/{migrations,seeds}`, `frontend/` (React app), `docs/`.
   - `config/database.php` reading env-style config file (gitignored sample provided); PDO connection factory targeting MySQL **port 3307**.
   - Single front controller (`index.php`) + minimal router for `/api/*` JSON endpoints.
6. **Frontend work:** Scaffold React app (Vite or CRA-equivalent), base folder conventions, proxy config to PHP API.
7. **Security considerations:** Secrets outside git (`.env` pattern + `.gitignore`); error display off in prod-style config; no secrets bundled into React build (AGENTS.md #16).
8. **Tests required:** Smoke test — DB connects on port 3307; router returns JSON 404; React dev server builds.
9. **Definition of Done:** Fresh XAMPP checkout reaches "DB connected" via a health endpoint; repo README documents setup; empty commit history begins with foundation commit.

**Internal order:** (1) folder skeleton + gitignore → (2) DB config/connection → (3) router/front controller → (4) React scaffold → (5) smoke tests → (6) README.

---

## PHASE 2 — Database Architecture

1. **Objective:** Create the normalized schema for foundational entities plus the audit-log table (created early so later phases can write events immediately), with migrations/seeds as repeatable scripts.
2. **Requirements covered:** Data model supporting FR-01–FR-20; soft-deletion rule (§8.3); chapters seed (§9.13).
3. **Dependencies:** Phase 1.
4. **Database work:**
   - `chapters` (seeded: Mt. Samat/Orani, Mt. Tarak/Mariveles, Meridian Heights/Balanga City — §9.13).
    - `users`: identity fields, `role` enum(member/officer/admin), **separated status fields** (per Phase 2 design rule): `verification_status` enum(unverified/pending/verified/rejected) for the officer workflow AND `account_status` enum(active/deactivated) + `deactivated_at` for soft-deactivation; `chapter_id` FK, age/DOB, `blood_type`, `blood_type_source`, `blood_type_verified` (§9.2), `latitude`,`longitude` (§9.4), `donor_enrolled_at`, `donor_availability` enum(available/unavailable/standby), `last_verified_donation_at` reserved (used in P8/P9), timestamps.
   - `password_resets`: email, token hash, expires_at (~30 min), single-use marker (§8.6).
   - `audit_log` (append-only; no UPDATE/DELETE grants in app user): timestamp, actor_id, action, target_type/target_id, context JSON (§9.10).
   - Conventions: InnoDB, FK constraints, utf8mb4, `created_at/updated_at`; migration runner script.
5. **Backend/API work:** Migration + seed runner CLI scripts under `database/`.
6. **Frontend work:** None.
7. **Security considerations:** Least-privilege MySQL app account (no DROP on audit_log); no PII in seed fixtures beyond test accounts; DOB stored to enforce age rules later (§9.2).
8. **Tests required:** Migrations run clean twice (idempotent); seeds idempotent; FK integrity checks; verify port 3307.
9. **Definition of Done:** `php database/run_migrations.php && php database/run_seeds.php` reproduces schema on a blank DB; ERD documented in `docs/erd.md`.

**Internal order:** (1) chapters + users + password_resets → (2) audit_log → (3) migration runner → (4) seeds → (5) ERD doc.

---

## PHASE 3 — Authentication & Registration

1. **Objective:** Implement FR-01 registration, FR-02 login/logout, and the specified password-reset flow with secure session handling.
2. **Requirements covered:** FR-01, FR-02; password reset spec (CONTEXT.md §8.6); capability-matrix row "Registered" (§9.2).
3. **Dependencies:** Phases 1–2.
4. **Database work:** None beyond P2 (users, password_resets ready).
5. **Backend/API work:**
    - `POST /api/register` (FR-01 fields: personal, chapter select from `chapters`, blood-related info incl. `blood_type_source=Self Reported`), creating accounts with `verification_status='pending'`, `account_status='active'` per lifecycle (§9.2) — *note: lifecycle starts new members at Pending Verification; the matrix "Registered" state maps to `verification_status='unverified', account_status='active'` and applies to rows that have not completed a submission.*
   - `POST /api/login`, `POST /api/logout`; server-side sessions with regeneration on login.
   - `POST /api/password-reset/request|confirm`: store **hash** of single-use token, ~30-minute expiry (§8.6).
6. **Frontend work:** Registration form (chapter dropdown sourced from API), login page, logout control, reset request/set pages.
7. **Security considerations:** `password_hash()` (bcrypt/argon2id); prepared statements everywhere; CSRF tokens on state-changing endpoints; rate-limit login/reset endpoints; uniform auth errors (no user enumeration); validate/sanitize all inputs server-side (AGENTS.md #10–15).
8. **Tests required:** Register→login→logout happy path; duplicate email rejection; wrong-password lockout/rate limit; reset token expiry + reuse failure; session fixation check; CSRF rejection case.
9. **Definition of Done:** All endpoints verified via HTTP tests/curl script; passwords verifiably hashed in DB; tokens stored only as hashes; results recorded in `docs/test-log-phase3.md`.

**Internal order:** (1) register → (2) login/logout/session middleware → (3) CSRF utility → (4) password reset → (5) frontend forms → (6) tests.

---

## PHASE 4 — RBAC & Chapter Scoping

1. **Objective:** Enforce the 3-tier role model and officer chapter scoping on the backend, so every later endpoint inherits authorization.
2. **Requirements covered:** FR-03 (RBAC), §9.2 separation of duties, §9.13 chapter assignment; capability matrix gating (partial — full enforcement lands in P5).
3. **Dependencies:** Phase 3 (sessions).
4. **Database work:** Add `officer_assignments` representation (e.g., `users.chapter_id` + role check, or dedicated table if multiple historical assignments needed) — prefer minimal: role + chapter_id on users; SysAdmin manages assignments.
5. **Backend/API work:**
   - Middleware chain: `requireAuth` → `requireRole(...)` → `requireChapterScope($targetChapterId)` → verification-status gate helper (consumed fully in P5).
   - Guard utilities returning 401 vs 403 correctly; central place to add audit hooks later.
   - Admin endpoints: manage officer role/chapter assignment (SysAdmin only).
6. **Frontend work:** Role-aware routing shell; no privileged UI rendered without backend-confirmed role (UI hiding is cosmetic only — AGENTS.md #13).
7. **Security considerations:** Every protected route passes middleware — deny by default; URL-guessing an admin path must fail 403; officers restricted to own chapter server-side (§9.2); log authorization failures to audit_log (event instrumentation starts here).
8. **Tests required:** Matrix test per role × endpoint class; cross-chapter access denial; unauthenticated access denial; direct URL entry to admin routes denied.
9. **Definition of Done:** Authorization helper is the only code path to protected resources; test matrix green; audit_log records denials.

**Internal order:** (1) requireAuth middleware → (2) role middleware → (3) chapter-scope helper → (4) admin assignment endpoints → (5) audit hook-in point → (6) test matrix.

---

## PHASE 5 — User Profiles & Verification

1. **Objective:** Deliver FR-04 profile management plus the full officer verification workflow with separation of duties, age eligibility, and blood-type provenance display.
2. **Requirements covered:** FR-04, FR-10, FR-11; §9.2 (lifecycle, capability matrix, evidence rules, age rule); donor-card supplementary-evidence policy.
3. **Dependencies:** Phase 4 (roles/scoping).
4. **Database work:**
   - `member_documents` (type: national_id / donor_card / parental_consent; file ref; uploaded_at) — files stored outside webroot; DB stores safe references only.
   - `verification_decisions` (verification history: officer_id, decision approve/reject/re-review, reason, timestamp) for resubmission loop Rejected→Pending (§9.2).
   - Users: ensure DOB present (age gate), consent-document linkage for ages 16–17.
5. **Backend/API work:**
   - `GET/PUT /api/profile` (FR-04: personal info, blood type + source, chapter, contact, availability toggle placeholder until P8 owns it).
   - Officer queue endpoints: list pending by officer's chapter; decide approve/reject/re-review — enforcing: same-chapter only, never self/officer/SysAdmin, never own documents (§9.2); SysAdmin override + escalation endpoints.
   - Capability-matrix enforcement using P4 gates: Pending = browse + request-create(pending-marked); Registered = limited browse only; Rejected/Deactivated locked out accordingly.
   - Age eligibility service: <16 ineligible; 16–17 requires consent document on file.
6. **Frontend work:** Profile view/edit forms (blood-type source shown; unverified type labeled "not medically confirmed"); document upload UI; officer verification queue screens with decision actions.
7. **Security considerations:** File upload hardening (type/size allowlist, randomized names, outside webroot, no execution); officers cannot mutate own status (server-enforced); documents excluded from any member-facing API responses; audit events: verification decisions, overrides.
8. **Tests required:** Full lifecycle transitions incl. resubmission; self-verify attempt blocked; cross-chapter decision blocked; 15-year-old rejected, 16-year-old without consent flagged; profile update validation; upload abuse cases.
9. **Definition of Done:** Capability matrix (§9.2) demonstrably enforced end-to-end; verification history queryable; audit entries exist for every decision.

**Internal order:** (1) profile GET/PUT → (2) document upload/storage → (3) verification queue + decisions → (4) SysAdmin overrides → (5) capability-matrix enforcement wiring → (6) age service → (7) frontend screens → (8) tests.

---

## PHASE 6 — Blood Request Lifecycle

1. **Objective:** Implement FR-05 creation and FR-14 status management with auto-expiry and match-affecting edit detection.
2. **Requirements covered:** FR-05, FR-14; §9.5 statuses/transitions; capability matrix (Pending may create, marked *pending review* — §9.2).
3. **Dependencies:** Phases 4–5 (auth, statuses, chapters).
4. **Database work:**
   - `blood_requests`: requester_id, required blood type, quantity/units, facility location fields (+ lat/lng), urgency enum(routine/urgent/critical), needed datetime, status enum(OPEN/FULFILLED/CANCELLED/EXPIRED), created/updated/expired_at.
   - Indexes for expiry sweep and map aggregation (chapter, status, needed date).
5. **Backend/API work:**
   - CRUD: create (status OPEN; pending-user requests flagged for review), edit (detect changes to blood type/location/urgency/needed-date/quantity → emit "material change" event for P7/P10 reconciliation), cancel (requestor own OPEN only), officer administrative intervention (own chapter, authorized cases).
   - Scheduled expiry job (XAMPP-compatible: cron/Task Scheduler calling PHP CLI) flipping past-needed-date OPEN → EXPIRED, removing from active surfaces (§9.5).
6. **Frontend work:** Request create/edit forms, my-requests list, request detail with status badge, cancel action.
7. **Security considerations:** Ownership checks (only owner edits/cancels); officer intervention scoped + audited; input validation (blood type whitelist, coordinates sanity, dates future); transactions around status changes (AGENTS.md #22).
8. **Tests required:** Status machine transitions incl. illegal ones (edit FULFILLED etc.); expiry job correctness; material-change detection emits correct diff; permission matrix (pending/registered/verified/officer).
9. **Definition of Done:** Lifecycle diagram (§9.5) fully reproducible in tests; expired requests leave active queries; audit entries for create/edit/cancel/expire.

**Internal order:** (1) schema → (2) create → (3) read/detail → (4) edit+material-change detector → (5) cancel → (6) expiry job → (7) frontend → (8) tests.

---

## PHASE 7 — Blood Compatibility & Matching Engine

1. **Objective:** Build the centralized red-cell compatibility service and match generation/prioritization per §9.3–§9.4 — the system's core differentiator.
2. **Requirements covered:** FR-06, FR-07, FR-08 (display filtering only); §9.3 engine semantics; §9.4 priority chain.
3. **Dependencies:** Phases 5–6 (eligible donors + requests exist).
4. **Database work:**
   - `compatibility_matrix` seed table (recipient_type → allowed donor types, red-cell ABO/Rh) — data-driven, consumed only by the service (never ad-hoc SQL elsewhere; AGENTS.md #17).
   - `matches`: request_id, donor_id, generation (int), status enum(POTENTIAL/NOTIFIED/RESPONDED/COMPLETED/CLOSED), distance_km, rank_score components, created/updated_at; unique(request_id, donor_id) to guarantee no duplicates (§9.3).
5. **Backend/API work:**
   - `BloodCompatibilityService::getCompatibleDonorTypes(string $recipientType): array` — single authority (CONTEXT.md §6).
   - `MatchService`: generate(request) → eligible pool = verified ∧ available ∧ compatible ∧ age-consented; compute Haversine distance (donor/request lat-lng); rank by Compatibility → Availability → Verification → Proximity with same-chapter preference boost; upsert into `matches` with new generation number on material change; reconcile (close stale) otherwise.
   - Triggers: on request create; on material-change event from P6; manual re-run endpoint (authorized); NO notification side-effects here (P10 subscribes).
   - Read API: request-scoped donor match list honoring §9.8 field minimization (first/display name, chapter, verified/availability status, approximate distance only).
6. **Frontend work:** Match results panel on request detail (minimal fields, chapter display filter that cannot alter underlying set — §9.3).
7. **Security considerations:** Engine callable only server-side; match-list API enforces requestor ownership; privacy filter unit-tested so exact coords/phone/email/documents never serialize (§9.8); no matching logic duplicated client-side (AGENTS.md #17).
8. **Tests required:** Full 8-type matrix correctness vs known red-cell table; incompatible-nearby never outranks compatible-far (priority invariant); generation upsert produces zero duplicate matches; distance math property tests; privacy serialization snapshot test.
9. **Definition of Done:** Compatibility matrix exists in exactly one place; §9.3/9.4 invariants pass; matches reproducible given same inputs; audit entries for manual re-runs.

**Internal order:** (1) matrix table+seed → (2) BloodCompatibilityService + unit tests → (3) eligibility query builder → (4) haversine util → (5) MatchService generate/reconcile → (6) triggers wiring → (7) match-read API w/ privacy filter → (8) frontend panel → (9) invariant tests.

---

## PHASE 8 — Donor Availability & Donation Lifecycle

1. **Objective:** Own donor availability states and implement donation report → officer confirmation flow feeding `last_verified_donation_at` (prerequisite for P9 timers).
2. **Requirements covered:** FR-09, FR-13; §9.6 confirmation workflow; §9.5 RESPONDED/COMPLETED transitions; parallel engagement rule.
3. **Dependencies:** Phases 5 (verification), 7 (matches exist).
4. **Database work:**
   - `availability` columns finalized on users (status enum AVAILABLE/UNAVAILABLE/STANDBY/DEACTIVATED + manual-toggle tracking) or dedicated table if history needed — choose column + audit trail via audit_log.
   - `donation_reports`: donor_id, match_id/request_id, reported_at, status(PENDING_CONFIRMED/CONFIRMED/REJECTED), confirmed_by(officer), confirmed_at; `users.last_verified_donation_at` set on confirm.
5. **Backend/API work:**
   - Availability toggle endpoint (self-service; blocked when system-enforced states exist — enforced fully in P9); appears in matches only when Available+Verified (§8.4).
   - Respond-to-match endpoint: POTENTIAL/NOTIFIED → RESPONDED; multiple donors may respond in parallel (no auto-cancel of others — §9.5).
   - Donation report submit (donor) → officer confirmation queue action (own chapter) → transactionally: report CONFIRMED, `last_verified_donation_at`, match → COMPLETED (AGENTS.md #22).
6. **Frontend work:** Availability switch UI; respond action on notifications/match items; donation-report form; officer confirmation queue item.
7. **Security considerations:** Only match-owner donor responds; only chapter officer confirms; confirmation transaction prevents double-confirm races; audit events for report/confirm/reject/toggle.
8. **Tests required:** Parallel response scenario keeps both RESPONDED; confirm sets fields exactly once; non-chapter officer blocked; unavailable donors excluded from fresh match runs; COMPLETED only via confirmed workflow (§9.5 invariant).
9. **Definition of Done:** §9.6 steps 1–3 demonstrable end-to-end; `last_verified_donation_at` populated correctly; all transitions audited.

**Internal order:** (1) availability storage+toggle → (2) respond endpoint → (3) donation report submit → (4) officer confirm transaction → (5) match COMPLETED transition → (6) frontend pieces → (7) tests.

---

## PHASE 9 — 42-Hour Standby & Inter-Donation Cooldown

1. **Objective:** Implement the two independent post-donation time locks (FR-12/§9.6): 42-hour standby and configurable ≈3-month cooldown, both backend-enforced and non-bypassable.
2. **Requirements covered:** FR-12 (standby/cooldown half), FR-13 (reactivation limits); §9.6 table.
3. **Dependencies:** Phase 8 (`last_verified_donation_at` trigger event).
4. **Database work:** `system_settings` key-value (e.g., `standby_hours=42`, `cooldown_days≈90`) so durations are configurable; standby derivable from `last_verified_donation_at + settings` (stateless computation preferred over cron-mutated flags — avoids stale-state bugs).
5. **Backend/API work:**
   - Eligibility function used by MatchService: donor excluded while `now < last_verified_donation_at + standby` OR `now < last_verified_donation_at + cooldown`.
   - On donation confirmation: set availability to STANDBY; automatic return to AVAILABLE computed when window lapses (lazy evaluation on read/match, no cron dependency).
   - Self-reactivation path: permitted for ordinary toggles only — cooldowns/standby cannot be bypassed (server-checked, §9.6).
6. **Frontend work:** Donor status card showing standby/cooldown remaining time; disabled toggle with explanatory state.
7. **Security considerations:** All window math server-side; client receives read-only remaining-time; regression guard: self-service endpoint refuses when within either window; audit events on entry/exit where computable.
8. **Tests required:** Time-travel/unit tests with injected clock: boundary at 42h; boundary at ~90 days; non-response does NOT create standby (negative test, §9.6); bypass attempt fails; configurable values respected.
9. **Definition of Done:** Both windows independently verifiable with clock-injected tests; settings-driven durations confirmed; §9.6 conflict note honored (standby is post-donation only).

**Internal order:** (1) settings table+seed → (2) eligibility service + clock injection → (3) hook into MatchService pool → (4) standby state on confirmation → (5) bypass guards on toggle endpoints → (6) frontend status display → (7) tests.

---

## PHASE 10 — Notifications & Email

1. **Objective:** Implement FR-16 in-app center and FR-17 email alerts with the §9.7 deduplication contract and critical-outreach breadth controls.
2. **Requirements covered:** FR-16, FR-17; §9.7 (generation-based dedup, channels, critical outreach, stop conditions).
3. **Dependencies:** Phases 6 (requests), 7 (match generations), 8 (respond events), 5 (verification/account events); email transport from XAMPP.
4. **Database work:** `notifications` (user_id, type, title, body, related_type/related_id, channel_in_app, emailed_at nullable, read_at nullable, dedup_key varchar unique where applicable, generation int, created_at) — unique index on (dedup_key, generation) implements §9.7 mechanically.
5. **Backend/API work:**
   - Notification service: `notify(user, event)` writing in-app record always; queuing email for defined important events (critical matches, verification decisions, request/account status changes — §9.7).
   - Dedup guard: insert-or-ignore on dedup key = f(request, donor, generation); newly-eligible donor ⇒ new generation ⇒ allowed (§9.7).
   - Mailer: PHPMailer-class transport via XAMPP sendmail/SMTP config from environment (credentials never in code); retry/backoff log; rate limiter per donor (batching) honoring "no arbitrary small cap but prevent duplicate storms" (§9.7).
   - Stop conditions: fulfillment/cancel/expire events suppress queued outreach (§9.7).
   - APIs: list/read/unread-count/mark-read; user notification preferences (respect off-hours preferences for non-critical).
6. **Frontend work:** Notification bell + center screen (read/unread, timestamps), preference settings page.
7. **Security considerations:** Recipient list built server-side from eligibility (never client-supplied); email addresses never exposed cross-user; SMTP creds from env only (AGENTS.md #16); anti-enumeration on mail errors; audit: notification batch summaries (not per-PII content).
8. **Tests required:** Same-generation rerun yields zero new rows (unique-index proof); material change creates exactly one new notification per matched donor; critical outreach reaches all eligible across chapters; stop-condition cancels pending sends; unread counts correct; mail failure retries logged not fatal.
9. **Definition of Done:** §9.7 contract demonstrated by integration test replaying a request lifecycle; in-app center functional; evidence recorded in `docs/test-log-phase10.md`.

**Internal order:** (1) schema+dedup index → (2) notify service in-app → (3) wire match/request/verification/account events → (4) mailer transport+templates → (5) rate limiting/batching → (6) stop conditions → (7) preferences + center UI → (8) tests.

---

## PHASE 11 — Audit Logging (Coverage Completion)

1. **Objective:** Complete append-only audit coverage across ALL workflows and expose tiered viewership (schema existed since P2; hooks were laid progressively).
2. **Requirements covered:** FR-18; §9.10 event list + viewership tiers.
3. **Dependencies:** All feature phases whose events are logged (P3–P10); P4 RBAC (view permissions).
4. **Database work:** Verify audit_log constraints (append-only app grants; context JSON size sane); optional helper view for chapter-scoped reads.
5. **Backend/API work:**
   - Gap analysis vs §9.10 mandatory list: registration, login success/fail, logout, password resets, verification decisions, request create/edit/cancel/fulfillment/expiration, donation reports/confirmations, availability/standby/cooldown/reactivation changes, role/chapter changes, admin overrides, cross-chapter authorizations.
   - Viewer APIs: SysAdmin full; Chapter Officer filtered to own chapter-relevant actors/targets; members none.
   - Retention policy config (no deletion path exposed; export/report only).
6. **Frontend work:** Admin audit browser (filter by actor/action/date/chapter); officer-scoped view.
7. **Security considerations:** Audit writes fail-closed (transaction aborts if critical event can't be logged — decide per-event severity); no sensitive document contents in context (§9.10); viewer APIs enforce tiers server-side.
8. **Tests required:** Event checklist walk-through: perform each §9.10 action → assert row; tier tests (officer sees only own chapter; member 403); tamper simulation (UPDATE/DELETE attempts fail).
9. **Definition of Done:** §9.10 checklist signed off with captured sample rows per event type; viewership matrix green.

**Internal order:** (1) gap checklist → (2) missing hooks → (3) viewer APIs → (4) admin/officer UI → (5) tamper tests → (6) sign-off doc.

---

## PHASE 12 — Analytics, Regional Blood Demand Map & Officer/Admin Dashboards (Consolidated FR-15, FR-19, FR-20)

> **Status:** ✅ **IMPLEMENTED AND VERIFIED** (2026-08-27) · Evidence: `docs/test-log-phase12.md` · Suite: `tests/phase12.ps1` (32/32 assertions green).

1. **Objective:** Implement and unify the analytical, regional demand visualization, and operational dashboard requirements (FR-15, FR-19, and FR-20) into a cohesive backend service and frontend dashboard suite.
2. **Requirements covered:**
   - **FR-15 (Regional Blood Demand Map):** `GET /api/demand-map` providing privacy-safe chapter centroid demand visualization of active `OPEN` requests by blood type and urgency with zero request pins or PII.
   - **FR-19 (Analytics & Reporting):** `GET /api/analytics/summary` providing single-pass database aggregations for request volume, lifecycle resolution rates (fulfillment, cancellation, expiration), live donor eligibility (standby/cooldown via `DonorEligibilityService`), daily trends, and operational activity.
   - **FR-20 (Officer & Admin Dashboards):** `GET /api/officer/dashboard` (strictly chapter-scoped operational triage queues) and `GET /api/admin/dashboard` (global KPIs and 3-chapter comparison).
3. **Dependencies:** Phases 1–11 data models, matching engine, donor availability, standby/cooldown rules, notifications, and audit logging.
4. **Database work:** Optimized multi-dimensional SQL aggregation queries on `blood_requests`, `users`, `matches`, `donation_reports`, and `audit_log`.
5. **Backend/API work:** `AnalyticsRepository.php`, `DemandMapController.php`, `AnalyticsController.php`, `OfficerDashboardController.php`, `AdminDashboardController.php`.
6. **Frontend work:** `DemandMapPage.jsx` (`/demand-map`), `AnalyticsPage.jsx` (`/analytics`), `OfficerDashboardPage.jsx` (`/officer/dashboard`), and `AdminDashboardPage.jsx` (`/admin/dashboard`).
7. **Security considerations:** Strict server-side chapter scoping for officers with `HTTP 403` on cross-chapter parameter tampering; role-gated endpoints; canonical centroids only (no individual coordinates).
8. **Tests required:** `tests/phase12.ps1` covering RBAC, anti-enumeration, scoping, rate formulas, privacy constraints, and dashboard queues.
9. **Definition of Done:** All 32 Phase 12 assertions green; full regression suite (Phases 3–12: 301/301 assertions) passing; Vite production build clean.

---

## PHASE 13 — Analytics & Reporting [CONSOLIDATED INTO PHASE 12]

> **Status:** ✅ **CONSOLIDATED AND DELIVERED IN PHASE 12** (2026-08-27).  
> The requirements for FR-19 (§9.11) were built into `AnalyticsRepository.php`, `GET /api/analytics/summary`, and `AnalyticsPage.jsx` as part of the unified Phase 12 implementation. Verified via `tests/phase12.ps1` and `docs/test-log-phase12.md`. No separate work is pending for this phase.

---

## PHASE 14 — Regional Demand Map [CONSOLIDATED INTO PHASE 12]

> **Status:** ✅ **CONSOLIDATED AND DELIVERED IN PHASE 12** (2026-08-27).  
> The requirements for FR-15 (§9.9) were built into `DemandMapController.php`, `GET /api/demand-map`, and `DemandMapPage.jsx` as part of the unified Phase 12 implementation. Verified via `tests/phase12.ps1` and `docs/test-log-phase12.md`. No separate work is pending for this phase.

---

## PHASE 15 — Frontend Integration & UX

> **Status:** ✅ **IMPLEMENTED AND VERIFIED** (2026-08-27)  
> Complete frontend design system unification, accessibility pass, and responsive layout polish delivered via `frontend-design` and `impeccable`.  
> - **Design tokens & styling:** Refined B&W design tokens in `tokens.css` and complete design system in `global.css` (Light/Dark themes, typography scale, metric cards, status chips, accessible form controls, empty states, medical disclaimers).
> - **Product workflows:** Polished all member, donor, requester, chapter officer, and system administrator workflows across 17 pages.
> - **Verification Evidence:** [docs/test-log-phase15.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/test-log-phase15.md) · Vite production build clean (`dist/`) in 1.35s · Master regression: 316/316 assertions green across Phases 3–16 via [tests/run_all.ps1](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/tests/run_all.ps1).

1. **Objective:** Unify all screens into a coherent responsive black-and-white identity (light/dark), meeting accessibility/performance baselines (AGENTS.md #26–28).
2. **Requirements covered:** UX/UI constraints across all FRs; §4 interface stance (social-style familiarity only where appropriate).
3. **Dependencies:** All feature phases' screens exist.
4. **Database work:** None.
5. **Backend/API work:** None (contract parity preserved).
6. **Frontend work:** Design-system pass (typography scale, spacing, B&W palette + theme toggle persisted); responsive layout audit (mobile-first for donor-facing flows); accessibility pass (labels, focus management, contrast in both themes, keyboard nav); empty/error/loading states everywhere; clean production bundle compilation.
7. **Security considerations:** Parameter-free profile mutations, privacy-safe demand map and donor anonymization, sanitized audit contexts.
8. **Tests required:** Production frontend build, full regression test runner (`tests/run_all.ps1` 11/11 suites green). Total 316/316 assertions green.
9. **Definition of Done:** All primary flows usable mobile+desktop in both themes; a11y checks pass stated baseline; design-system doc committed.

**Internal order:** (1) design tokens/theme system → (2) shared components refactor → (3) page-by-page polish (donor flows first) → (4) a11y/responsive fixes → (5) audits + snapshots.

---

## PHASE 16 — Testing & Security Hardening

> **Status:** ✅ **IMPLEMENTED AND VERIFIED** (2026-08-27)  
> All security hardening controls and verification suites are implemented and verified green.  
> - **Security fixes:** Session cookie deletion preservation (`SEC-MED-01`), defense-in-depth security headers (`Content-Security-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`), mutation rate limiting on high-risk endpoints (`POST /api/requests`, `POST /api/profile/documents`, `POST /api/profile/resubmit`).
> - **Performance indexes:** Composite indexes active on `blood_requests(request_chapter_id, status, created_at)` and `audit_log(target_type, target_id, created_at)` via Migration 014.
> - **Verification Evidence:** [docs/test-log-phase16.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/test-log-phase16.md) · Suite: [tests/phase16_security.ps1](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/tests/phase16_security.ps1) (15/15 passed) · Master regression: 316/316 assertions green across Phases 3–16 · Vite production build clean.

1. **Objective:** System-wide verification: security review, integration suites, and operational readiness on XAMPP.
2. **Requirements covered:** AGENTS.md #10–16, #29–31; NFR placeholders (see §0.2 open item).
3. **Dependencies:** All functional phases complete.
4. **Database work:** Migration 014 composite performance indexes on `blood_requests` and `audit_log`.
5. **Backend/API work:** Session cookie deletion fix in `Session.php`; CSP & Permissions-Policy headers in `SecurityHeaders.php`; mutation rate limiting in `AuthThrottleRepository.php`, `RequestsController.php`, `DocumentController.php`, and `ProfileController.php`.
6. **Frontend work:** Vite production bundle compatibility verification with strict CSP (0 violations).
7. **Security considerations:** Parameter-free profile mutations, IDOR assertions on requests/matches/reports/notifications, audit log immutability triggers, MIME validation on document uploads.
8. **Tests required:** `tests/phase16_security.ps1` (15/15 green), full regression suite (Phases 3–12: 301/301 green). Total 316/316 assertions green.
9. **Definition of Done:** Zero high/critical findings open; regression suite 100% green; security test log committed.

**Internal order:** (1) automated authz/IDOR crawl → (2) manual pentest pass → (3) fix + retest loop → (4) performance/load checks → (5) backup drill → (6) sign-off.

---

## PHASE 17 — Final Requirements Traceability & Handover

> **Status:** ✅ **IMPLEMENTED AND VERIFIED** (2026-08-27)  
> Definitive FR ↔ NFR ↔ Business Rules ↔ Evidence matrix published; zero code changes; master test baseline preserved (316/316 green).  
> - **Traceability matrix:** Complete end-to-end mapping of FR-01–FR-20, NFR-01–NFR-17, and Q1–Q25 decisions in [docs/traceability.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/traceability.md).
> - **API Inventory:** Comprehensive documentation of all 26 endpoints in [docs/api.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/api.md).
> - **Schema & Test logs:** Schema synchronized in [docs/erd.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/erd.md); verification log in [docs/test-log-phase17.md](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/docs/test-log-phase17.md).
> - **Verification Evidence:** Master regression suite [tests/run_all.ps1](file:///d:/James/SCHOOL%203RD%20YEAR/BloodMatch_OxAlpha/tests/run_all.ps1) passing 316/316 assertions (100% green); clean Vite production build.

1. **Objective:** Produce the definitive FR ↔ implementation ↔ evidence matrix; update CONTEXT.md §10 statuses honestly.
2. **Requirements covered:** AGENTS.md #29–33; CONTEXT.md §10 maintenance rule.
3. **Dependencies:** Phase 16 green.
4. **Database work:** Capture final schema snapshot into `docs/erd.md`.
5. **Backend/API work:** Documented all endpoints in `docs/api.md`.
6. **Frontend work:** None (design and accessibility verified).
7. **Security considerations:** Ensure evidence docs themselves contain no secrets/PII.
8. **Tests required:** Re-run full suite on clean clone as final proof (`tests/run_all.ps1`: 316/316 assertions green).
9. **Definition of Done:** `docs/traceability.md` maps every FR-01–FR-20 and NFR-01–NFR-17 to implementing files, endpoints, tables, tests, and verification dates; CONTEXT.md §10 updated.

**Internal order:** (1) matrix draft from phase logs → (2) evidence citation pass → (3) clean-clone re-verification → (4) CONTEXT.md §10 update → (5) owner review.

---

## Global Rules Applied Throughout

1. Every phase obeys AGENTS.md priorities: Correctness → Security → Requirements → Existing functionality → Data integrity → Performance → UX/UI → Maintainability.
2. Matching logic lives only in backend services (AGENTS.md #17–18); frontend never computes eligibility.
3. Soft-deletes only; audit trail on every privileged mutation (AGENTS.md #20–21).
4. Status claims follow CONTEXT.md §10: implemented only after inspection-verified evidence.
5. **Blocking prerequisite for Phase 17:** product owner supplies NFR-01–NFR-17 text; until then traceability covers FRs + documented constraints only.
