param(
    [string]$PhpPath = "D:\xampp\php\php.exe",
    [string]$BackendHost = "127.0.0.1",
    [int]$BackendPort = 8000,
    [switch]$BackendOnly
)

# BloodMatch local development startup.
# Starts the PHP backend (XAMPP PHP) with a pre-flight CSRF readiness check,
# then runs the Vite frontend in the foreground.
# Running `npm run dev` alone is NOT sufficient: the frontend proxies /api/*
# to the PHP backend, and login (or any mutating request) fails with
# "Could not fetch CSRF token (500)" when the backend is down.
#
# Usage (from the repository root):
#   powershell -ExecutionPolicy Bypass -File .\start-dev.ps1
#   powershell -ExecutionPolicy Bypass -File .\start-dev.ps1 -BackendOnly
#
# The CSRF readiness check (GET /api/csrf -> HTTP 200) needs no database.

$ErrorActionPreference = "Stop"
$repoRoot = $PSScriptRoot
$backendUrl = "http://${BackendHost}:${BackendPort}/api/csrf"

function Test-BackendReady {
    try {
        $res = Invoke-WebRequest -Uri $backendUrl -TimeoutSec 3 -UseBasicParsing
        if ([int]$res.StatusCode -ne 200) { return $false }
        $body = $res.Content | ConvertFrom-Json
        return ($body.success -eq $true -and $body.data.csrf_token -ne $null -and $body.data.csrf_token -ne "")
    } catch {
        return $false
    }
}

if (-not (Test-Path -LiteralPath $PhpPath)) {
    Write-Output "ERROR: PHP executable not found at '$PhpPath'."
    Write-Output "Pass your XAMPP path explicitly, e.g.: .\start-dev.ps1 -PhpPath 'E:\xampp\php\php.exe'"
    exit 1
}

$startedByMe = $false
$backendProc = $null

if (Test-BackendReady) {
    Write-Output "Backend already serving BloodMatch at ${BackendHost}:${BackendPort} (reusing it)."
} else {
    Write-Output "Starting PHP backend ($PhpPath -S ${BackendHost}:${BackendPort} -t backend/public) ..."
    $backendProc = Start-Process -FilePath $PhpPath -ArgumentList "-S", "${BackendHost}:${BackendPort}", "-t", "backend/public" -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru
    $startedByMe = $true

    $ready = $false
    for ($i = 1; $i -le 30; $i++) {
        Start-Sleep -Seconds 1
        if (Test-BackendReady) { $ready = $true; break }
        if ($backendProc.HasExited) { break }
    }

    if (-not $ready) {
        if (($backendProc -ne $null) -and (-not $backendProc.HasExited)) {
            Stop-Process -Id $backendProc.Id -Force
        }
        Write-Output "ERROR: backend did not become ready (GET /api/csrf never returned HTTP 200)."
        Write-Output "Check that port ${BackendPort} is free and that $PhpPath runs (try it manually)."
        exit 1
    }
    Write-Output "Backend ready: GET /api/csrf -> HTTP 200 (PID $($backendProc.Id))."
}

if ($BackendOnly) {
    Write-Output "BackendOnly: leaving the backend running."
    Write-Output "Frontend:  cd frontend; npm.cmd run dev   # http://localhost:5173"
    if ($startedByMe) {
        Write-Output "Stop the backend started here with:  Stop-Process -Id $($backendProc.Id)"
    }
    exit 0
}

try {
    Write-Output "Starting Vite frontend (http://localhost:5173, proxies /api -> ${BackendHost}:${BackendPort}) ..."
    Push-Location (Join-Path $repoRoot "frontend")
    & cmd.exe /c "npm.cmd run dev"
} finally {
    Pop-Location
    if ($startedByMe -and ($backendProc -ne $null) -and (-not $backendProc.HasExited)) {
        Stop-Process -Id $backendProc.Id -Force
        Write-Output "Stopped the backend started by this script (PID $($backendProc.Id)). A pre-existing backend is always left running."
    }
}
