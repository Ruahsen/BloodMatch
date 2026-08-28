# BloodMatch

Peer-to-peer blood donor matching platform for DeMolay Bataan.

- Requirements source of truth: [`CONTEXT.md`](./CONTEXT.md)
- Agent rules: [`AGENTS.md`](./AGENTS.md)
- Implementation plan: [`ROADMAP.md`](./ROADMAP.md)

**Current status: Phase 1 (project foundation) scaffolded — no business features implemented yet.**

## Stack

HTML/CSS/JavaScript · React (Vite) · PHP 8 · MySQL · XAMPP — MySQL on **port 3307**. No Laravel.

## Layout

```
backend/public/     document root (front controller index.php)
backend/src/        BloodMatch\ classes (Config, Controllers, Middleware, Routing, Utils, Http)
backend/routes/     API route table
database/           migrations/ + seeds/ + runners
frontend/           React app (Vite)
tests/              smoke.ps1 + backend/phpunit.xml
```

## Setup

1. Install XAMPP with PHP 8.x and MySQL. Start MySQL on port **3307** (`my.ini`: `port=3307`).
2. Copy `.env.example` to `.env` and fill in local database credentials (file is gitignored).
3. Create the empty database named by `DB_NAME`.

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
