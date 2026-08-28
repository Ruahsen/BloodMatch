param(
    [string]$BaseUrl = 'http://127.0.0.1:8000'
)

$ErrorActionPreference = 'Stop'

Write-Host "== BloodMatch smoke test against $BaseUrl =="

$health = Invoke-RestMethod -Uri "$BaseUrl/api/health" -Method Get -TimeoutSec 10

if ($health.success -ne $true) {
    throw "Health endpoint returned success=$($health.success)"
}
if ($health.data.status -ne 'ok') {
    throw "Unexpected status: $($health.data.status)"
}
if ($health.data.db.port -ne 3307) {
    throw "Expected DB port 3307, got $($health.data.db.port)"
}

Write-Host "PASS: health ok; DB port = $($health.data.db.port); connected = $($health.data.db.connected)"
