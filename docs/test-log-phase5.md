# Phase 5 Verification Log — Profiles, Documents & Officer Verification

Executed: 2026-08-26 · Suite: `tests/phase5.ps1` (40 assertions) · **Result: 40 passed, 0 failed**
Regressions: `tests/phase3.ps1` → **23/23**, `tests/phase4.ps1` → **23/23** (both unchanged)
Runtime: PHP 8.2.12 dev server (`upload_max_filesize=10M` override) → MariaDB @ 3307, DB `bloodmatch_dev`
Final clean-log run: zero PHP warnings/errors; React production build clean

## Endpoints introduced

| Endpoint | Access | Notes |
|---|---|---|
| `GET/PUT /api/profile` | any active user | whitelist fields only; role/status/email/password/blood_type_verified explicitly rejected |
| `POST /api/profile/resubmit` | member w/ rejected status | requires national_id document; rejected→pending |
| `POST /api/profile/documents` | active user | multipart; server-side finfo MIME allowlist (JPEG/PNG/WEBP/PDF); 5 MB cap |
| `GET /api/profile/documents[/{id}/file]` | owner only | streams file; metadata-only JSON never exposes paths |
| `GET /api/officer/verifications` | officer | pending members of own chapter |
| `GET /api/officer/verifications/{userId}` | officer(own ch)/admin | member summary + docs meta + age eligibility + decision history |
| `POST /api/officer/verifications/{userId}/decision` | officer(own ch)/admin(scope-exempt) | verified/rejected from pending only; optional donor-card provenance |
| `GET /api/officer/documents/{documentId}/file` | officer(own ch, member-owner)/admin | protected review download |

## Schema additions (migrations 006–007)

- `member_documents`: random 64-hex `stored_name` UNIQUE; doc_type enum(national_id/donor_card/parental_consent); mime/ext/size metadata; FK cascade
- `verification_decisions`: append-style history (target/officer/decision enum incl. resubmitted/re_review_requested/reason/timestamps)

## Verified behaviors

**Profile (A):** safe composition (no password_hash/security fields in JSON); permitted-field updates persisted; attempts to set `role`/`verification_status` rejected 400 with field errors; single coordinate and out-of-range latitude rejected; pair saved. Blood-type provenance: profile edits force `self_reported`; same-value re-save preserves existing provenance; value change resets to self-reported/unverified.

**Documents (B):** valid PDF accepted (201) stored under `backend/storage/documents/<64-hex>` — outside webroot, random name, original filename discarded; executable (MZ), text-masquerading-as-png rejected via server-side finfo; >5 MB → 413; `doc_type='../evil'` rejected; anonymous fetch 401; foreign-user fetch 404 (no existence leak); owner stream OK with no path leakage; other-chapter officer denied 403 (+audit); same-chapter officer allowed.

**Verification (C):** own-chapter queue correct; self-verification, officer-target, admin-target, cross-chapter decisions all 403 (+audit); donor-card provenance accepted only on approval with uploaded card (`donor_card|1` in DB) with mandatory non-medical disclaimer surfaced; pending→rejected with reason; resubmission blocked without documents then rejected→pending; double-decision → 409; admin scope-exempt override works.

**Age eligibility (D):** computed server-side from DOB: 15→denied; 16/17 without parental_consent→denied; with consent→allowed; 18+→allowed. Boundary dates handled via DateTimeImmutable.

**Capability matrix (E):** exposed through `/api/profile.capabilities`; verified member = appear_as_donor ✓ + create_request ✓; rejected = create_request ✗ + resubmit ✓.
*Non-exercisable cells:* request browsing/creation and donor-match appearance have no live endpoints yet (P6/P7+); the helper is applied where relevant today and gates will be wired when those surfaces exist.

**Audit (F):** 8 distinct new event types observed (`profile.updated`, `document.uploaded`, `document.accessed`, `verification.approved`, `verification.rejected`, `verification.resubmitted`, `verification.provenance_accepted`, plus `authz.denied`) — no document contents stored in log context.

## Bugs found & fixed during verification

1. `findById()` missing latitude/longitude columns → compose() fatal
2. Profile update threw uncaught ValidationException (500 instead of 400)
3. Route param mismatch `{documentId}` vs `$params['id']`
4. Test harness: PS5.1 multipart writer emitted zero-length segments; age fixtures off-by-one DOB

## Known gaps / deferred

- Reset-token email delivery (carried from Phase 3) still unwired
- Email address changes not supported in profile (identity-change flow undefined in spec)
- Capability cells for request/donor surfaces activate with Phases 6–7 endpoints
- No admin-specific document browse UI (§9.8 "as necessary" — none needed yet)
