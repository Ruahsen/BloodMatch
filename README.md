# BloodMatch

Peer-to-peer blood donor matching platform for DeMolay Bataan.

- Requirements source of truth: [`CONTEXT.md`](./CONTEXT.md)
- Agent rules: [`AGENTS.md`](./AGENTS.md)
- Implementation plan: [`ROADMAP.md`](./ROADMAP.md)

**Current status: Phases 1–12, 15–17 implemented and verified, plus post-Phase-17 additions (profile pictures, Bataan location reference system, donor-location refresh, privacy notices). Master regression: 12/12 suites green, 343 assertions (see `docs/test-log-location.md` for the current baseline; `docs/test-log-phase17.md` preserves the historical Phase-17 11/11, 316/316 baseline).**

## Stack

HTML/CSS/JavaScript · React (Vite) · PHP 8 · MySQL · XAMPP — MySQL on **port 3307**. No Laravel.

## Layout

```
backend/public/     document root (front controller index.php)
backend/src/        BloodMatch\ classes (Config, Controllers, Middleware, Repositories, Routing, Services, Utils, Http)
backend/routes/     API route table (backend/routes/api.php, 55 method+path registrations)
backend/storage/    uploaded files outside webroot (documents, profile_pictures)
database/           migrations/ (001–016) + seeds/ (001–004) + runners
frontend/           React app (Vite, pages, components, services, context, styles)
tests/              phase3–phase12, location, phase16_security, profile_picture + run_all.ps1
docs/               api.md, erd.md (+erd.svg), traceability.md, use-case-diagram.md (+.puml/.svg/.png), test logs
```

## Current capabilities

- Auth + registration with mandatory Privacy Notice acknowledgment (`privacy_acknowledged`), chapter binding, secure sessions, throttling, password reset (hashed single-use ~30-min tokens)
- 3-tier RBAC (member/officer/admin) with backend chapter scoping; admin user/role/chapter/deactivate endpoints; officer user listing
- Profile management via Bataan municipality/barangay selector (`location_id` → backend-resolved reference coordinates; raw `latitude`/`longitude` rejected), document upload with ID Privacy Notice acknowledgment, donor enrollment/availability
- Profile pictures (`users.profile_picture`, `POST/GET /api/profile/picture`): upload through Profile, JPG/PNG/WEBP ≤5 MB, navbar avatar with fallback icon, authenticated owner-only access, replacement deletes old file
- Blood requests with `location_id` facility selector (optional), OPEN/FULFILLED/CANCELLED/EXPIRED lifecycle, material-change re-matching, auto-expiry
- Centralized matching: `BloodCompatibilityService` (8-type red-cell matrix) + `MatchService` + `Geo::distanceKm` (Haversine); priority Compatibility → Availability → Verification → Proximity; proximity never overrides compatibility; privacy-safe results (`approximate_distance_km` only)
- Donor-location refresh: `MatchService::refreshMatchesForDonor()` on profile location change recalculates affected OPEN matches without generation bump, no duplicate notifications, COMPLETED/CLOSED history preserved
- Donation report → officer confirmation transaction, 42-hour standby + ~90-day cooldown, parallel donor engagement
- Notifications: in-app center + navbar flyout (current primary UI; dedicated `/notifications` page retained as View-all), `GET /api/notifications/unread-count`, dedup `(dedup_key, generation)`; email via PHPMailer (best-effort, rate-limited, critical bypass)
- Audit logging (append-only triggers), demand map (chapter centroids only), analytics, officer/admin dashboards
- Bataan location reference: `bataan_locations` (12 municipalities + 237 barangays, PSGC 030800000), `GET /api/locations/municipalities`, `GET /api/locations/barangays?municipality_code=`
- Compatibility matrix endpoint `GET /api/compatibility-matrix` (officer/admin), officer re-match `POST /api/officer/requests/{id}/re-match`, CSRF `GET /api/csrf`

## Testing

- Current baseline: `tests/run_all.ps1` — 12/12 suites green, 343 assertions (Phase 3: 26, Phase 4: 23, Phase 5: 44, Phase 6: 35, Phase 7: 28, Phase 8: 30, Phase 9: 16, Phase 10: 43, Phase 11: 31, Phase 12: 32, Location: 20/20, Phase 16 security: 15). Additional `tests/profile_picture.ps1` covers avatar upload/replacement/access/audit (13 checks, not in `run_all.ps1`).
- Historical baseline preserved in `docs/test-log-phase17.md` (11/11, 316/316 on 2026-08-27); current baseline in `docs/test-log-location.md` (2026-09-24).
- Requirements source of truth: [`CONTEXT.md`](./CONTEXT.md); full endpoint list: [`docs/api.md`](./docs/api.md); schema: [`docs/erd.md`](./docs/erd.md); traceability: [`docs/traceability.md`](./docs/traceability.md).

## Setup

1. Install XAMPP with PHP 8.x and MySQL. Start MySQL on port **3307** (`my.ini`: `port=3307`).
2. Copy `.env.example` to `.env` and fill in local database credentials (file is gitignored).
3. Create the empty database named by `DB_NAME`.

### Quick start (both servers)

Running `npm run dev` alone is **not sufficient**: the Vite frontend proxies `/api/*` to the PHP backend, so login fails with `Could not fetch CSRF token (500)` when the backend is down. Start both with the pre-flight-checked launcher (from the repository root):

```powershell
powershell -ExecutionPolicy Bypass -File .\start-dev.ps1
# backend only (leaves it running): add -BackendOnly
```

This uses `D:\xampp\php\php.exe` explicitly (override with `-PhpPath`), reuses an already-running backend on `127.0.0.1:8000` when present, waits for `GET /api/csrf` → HTTP 200 (no database required for this check), then runs Vite on `http://localhost:5173`. Backend: `127.0.0.1:8000` · Frontend: `localhost:5173`.

### Backend

```powershell
$php = "D:\xampp\php\php.exe"   # adjust to your XAMPP path

# dev server (or point Apache vhost docroot at backend/public)
& $php -S 127.0.0.1:8000 -t backend/public

# migrations / seeds (Phase 2+ adds SQL files)
& $php database/run_migrations.php
& $php database/run_seeds.php
```

Verify: `GET http://127.0.0.1:8000/api/health` → `{success:true,data:{status:"ok",db:{...port:3307}}}`

### Frontend

```powershell
cd frontend
npm.cmd install
npm.cmd run dev      # http://localhost:5173, proxies /api -> 127.0.0.1:8000
npm.cmd run build
```

### Smoke test

```powershell
.\tests\smoke.ps1    # optional -BaseUrl override
```

## Conventions

- JSON envelope: `{success, data}` / `{success:false, error:{message}}`
- Authorization enforced backend-side only; frontend hiding is cosmetic
- All SQL via prepared statements (PDO, emulated prepares off)
- Secrets live only in `.env`; never commit them
- Statuses follow AGENTS.md Implementation Truth Rule (✅ only with verified evidence)
