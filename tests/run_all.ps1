param()

$suites = @(
    'phase3.ps1',
    'phase4.ps1',
    'phase5.ps1',
    'phase6.ps1',
    'phase7.ps1',
    'phase8.ps1',
    'phase9.ps1',
    'phase10.ps1',
    'phase11.ps1',
    'phase12.ps1',
    'phase16_security.ps1'
)

$passed = 0
$failed = 0

foreach ($s in $suites) {
    Write-Host "`n======================================================="
    Write-Host " RUNNING SUITE: $s"
    Write-Host "======================================================="
    $path = Join-Path $PSScriptRoot $s
    & powershell -ExecutionPolicy Bypass -File $path
    if ($LASTEXITCODE -eq 0) {
        $passed++
    } else {
        $failed++
        Write-Host "ERROR: $s failed with exit code $LASTEXITCODE"
    }
}

Write-Host "`n======================================================="
Write-Host " ALL TEST SUITES FINISHED: $passed passed, $failed failed"
Write-Host "======================================================="

if ($failed -gt 0) { exit 1 } else { exit 0 }
