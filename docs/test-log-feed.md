# Feed Verification Log - Authenticated Home / Blood Request Feed

Executed against PHP 8.2.12 dev server (127.0.0.1:8000) → MariaDB 10.4 @ 127.0.0.1:**3307**, DB `bloodmatch_dev` (migration 017 applied).
Suite: `tests/feed.ps1` (50 assertions) · Full regression: `tests/run_all.ps1` 13/13 suites green · Vite production build clean.

## Schema (migration 017)

- `matches.status` ENUM += `ACCEPTED`, `WITHDRAWN` (verified via `SHOW COLUMNS`).
- `matches.donor_share_consent` / `matches.requester_share_consent` (`TINYINT(1) NOT NULL DEFAULT 0`).
- Index `matches(request_id, status)` (verified via `SHOW INDEX`).

## Results (50/50)

| Area | Assertions | Evidence |
|---|---|---|
| Feed access matrix | F1–F7 | anonymous 401; rejected/deactivated 403 server-side; unverified/pending/verified/officer/admin allowed |
| OPEN-only dataset | F8 | cancelled request excluded from every feed page; all rows `OPEN` |
| Compatibility tiers | F9–F11 | tier0 compatible+actionable ranks above tier3; unknown blood tier2; tier1 unenrolled/unavailable; incompatible visible |
| Ordering | F12–F13 | urgency → needed datetime → distance (normal); distance-first under `near_me`; deterministic id-DESC tiebreak; invalid params 400; unlocated requests excluded under `near_me`; viewer-without-location 400 |
| Pagination | F14 | disjoint pages, deterministic repeat, consistent total |
| Privacy | F15 | no `email`/`phone`/`latitude`/`longitude`/`password`/`document`/`donor_availability`/`verification_status` keys in any feed payload |
| Respond reconciliation | F16 | `match_id: null` + `respond` for newly eligible donor; request-scoped respond creates the row with consent; duplicate responds idempotent with exactly one `match.responded` notification |
| Accept/contact/unaccept | F17 | `RESPONDED→ACCEPTED` with both consents; contact returns both emails to principal; stranger 404; unaccept revives `RESPONDED` + resets requester consent; contact denied after; re-accept without consent 422 |
| Capacity | F18, F23 | second accept at quota 409; parallel accepts via background jobs serialize (one 200 + one 409) with `ACCEPTED+COMPLETED <= quantity` holding |
| Quantity guard | F19 | reduction below `ACCEPTED+COMPLETED` 409 with value unchanged; increase allowed |
| Cancel sweep | F20, F26 | `ACCEPTED→CLOSED`, contact revoked, accepted donor notified |
| IDOR/consent | F21 | cross-user withdraw/accept 403; respond without consent 422 |
| Withdrawal | F22 | `WITHDRAWN` terminal; capacity released for next donor; re-respond 409 |
| Consent lifecycle | F24 | revoke denies contact; re-grant restores while `ACCEPTED` |
| Deactivation | F25 | admin deactivate closes `ACCEPTED`, revokes contact, notifies counterpart (`match.closed`) |
| Top tabs (refinement) | F27–F32 | `feed_scope=match` returns only own qualifying OPEN requests with `match_count` matching DB truth; invalid scope 400; `critical` tab + composes with right filters; match+filter narrows to zero; `WITHDRAWN`/`CLOSED` excluded with own-only `match_id`; `match_count` drops after withdraw |
| Match semantics (narrowed) | F33–F34 | `POTENTIAL`/`NOTIFIED` engine candidacy excluded from Match tab + count; donor respond transitions the request in with count+1; `COMPLETED` on still-OPEN (multi-unit) request included + counted |

## Regressions fixed during verification

1. Single-hit `.Count` on `PSCustomObject` is empty in Windows PowerShell 5.1 - F11 used `@(...)` wrapping (test-only fix; API was already correct).
2. Per-user request-create throttle (~9/10 min) tripped at the 9th fixture create - suite splits creates across two requester fixtures (test-only; throttle behavior unchanged).
3. Existing `respond` calls in `tests/phase8.ps1`, `tests/phase9.ps1`, `tests/phase10.ps1` extended with `donor_share_consent` (behavior change: consent now required; historical logs preserved as records).

## UI verification

- `npm run build`: clean (vite 5.4.21, 4618 modules, ~54s).
- `/`, `/feed`, `/login` serve the SPA shell (HTTP 200) from the Vite dev server.
- No browser automation exists in this environment (no Python/Playwright); responsive layout, themes, focus order, and mobile navbar order were verified by code inspection against `tokens.css`/`global.css` conventions (same patterns as existing exercised pages). No screenshots captured.

## Known limitations

- Feed candidate filtering is SQL; ranking is PHP per page-load over the OPEN set (correctness-first; see implementation report for the evidence-gated SQL optimization trigger).
- `docs/erd.svg` was not regenerated (Mermaid source block updated; regeneration requires `npx @mermaid-js/mermaid-cli`).
