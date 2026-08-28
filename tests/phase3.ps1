param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$suffix = "$(Get-Random)"
$script:pass = 0
$script:fail = 0

function Ok($name) { $script:pass++; Write-Host "PASS  $name" }
function Bad($name, $why) { $script:fail++; Write-Host "FAIL  $name -> $why" }

function Get-Csrf($session) {
    $r = Invoke-RestMethod -Uri "$BaseUrl/api/csrf" -Method Get -WebSession $session -TimeoutSec 10 -UseBasicParsing
    return $r.data.csrf_token
}

function Post($session, $uri, $body, $csrf) {
    $headers = @{}
    if ($csrf) { $headers['X-CSRF-Token'] = $csrf }
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Post -Body ($body | ConvertTo-Json) `
            -ContentType 'application/json' -Headers $headers -WebSession $session -TimeoutSec 10 -UseBasicParsing
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $status = [int]$resp.StatusCode
        try {
            $stream = $resp.GetResponseStream()
            $reader = New-Object System.IO.StreamReader($stream)
            $raw = $reader.ReadToEnd()
        } catch { $raw = '' }
        try { $parsed = $raw | ConvertFrom-Json } catch { $parsed = $null }
        return @{ status = $status; body = $parsed }
    }
}

function GetReq($session, $uri) {
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Get -WebSession $session -TimeoutSec 10 -UseBasicParsing
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $status = [int]$resp.StatusCode
        try {
            $stream = $resp.GetResponseStream()
            $reader = New-Object System.IO.StreamReader($stream)
            $raw = $reader.ReadToEnd()
        } catch { $raw = '' }
        try { $parsed = $raw | ConvertFrom-Json } catch { $parsed = $null }
        return @{ status = $status; body = $parsed }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

Write-Host "== Phase 3 verification audit =="

# --- T01 CSRF missing on POST ---
$s1 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Post $s1 '/api/register' @{ full_name = 'X'; email = 'x@t.local'; password = 'abc12345' } $null
if ($r.status -eq 403) { Ok 'T01 register without CSRF rejected (403)' } else { Bad 'T01' "got $($r.status)" }

# --- T02 registration happy path ---
$csrf1 = Get-Csrf $s1
$email1 = "p3user$(Get-Random)@test.local"
$r = Post $s1 '/api/register' @{
    full_name = 'Phase Three Tester'; email = $email1; password = 'Str0ngPass1';
    chapter_id = 1; date_of_birth = '2000-05-10'; blood_type = 'O+'
} $csrf1
if ($r.status -eq 201 -and $r.body.data.user.verification_status -eq 'pending' -and $r.body.data.user.account_status -eq 'active') {
    Ok 'T02 register 201 + pending/active'
} else { Bad 'T02' "got $($r.status): $($r.body.error.message)" }
$uid1 = $r.body.data.user.id

# --- T03 duplicate email ---
$r = Post $s1 '/api/register' @{
    full_name = 'Dup'; email = $email1; password = 'Str0ngPass1'; chapter_id = 1; date_of_birth = '2000-05-10'
} $csrf1
if ($r.status -eq 409) { Ok 'T03 duplicate email 409' } else { Bad 'T03' "got $($r.status)" }

# --- T04 weak password ---
$r = Post $s1 '/api/register' @{
    full_name = 'Weak'; email = "weak$(Get-Random)@test.local"; password = 'short'; chapter_id = 1; date_of_birth = '2000-05-10'
} $csrf1
if ($r.status -eq 400 -and $r.body.error.details.password) { Ok 'T04 weak password 400 + field error' } else { Bad 'T04' "got $($r.status)" }

# --- T05 invalid chapter ---
$r = Post $s1 '/api/register' @{
    full_name = 'BadChap'; email = "bc$(Get-Random)@test.local"; password = 'Str0ngPass1'; chapter_id = 999; date_of_birth = '2000-05-10'
} $csrf1
if ($r.status -eq 400 -and $r.body.error.details.chapter_id) { Ok 'T05 invalid chapter 400' } else { Bad 'T05' "got $($r.status)" }

# --- T06 chapters list ---
$r = GetReq $s1 '/api/chapters'
if ($r.status -eq 200 -and $r.body.data.chapters.Count -eq 3) { Ok 'T06 chapters list = 3' } else { Bad 'T06' "got $($r.status)" }

# --- T07/T08 login failures ---
$s2 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrf2 = Get-Csrf $s2
$r = Post $s2 '/api/login' @{ email = "ghost$(Get-Random)@test.local"; password = 'Whatever1' } $csrf2
if ($r.status -eq 401) { Ok 'T07 unknown email 401 uniform' } else { Bad 'T07' "got $($r.status)" }
$r = Post $s2 '/api/login' @{ email = $email1; password = 'WrongPass9' } $csrf2
if ($r.status -eq 401) { Ok 'T08 wrong password 401' } else { Bad 'T08' "got $($r.status)" }

# --- T09 successful login + session ---
$r = Post $s2 '/api/login' @{ email = $email1; password = 'Str0ngPass1' } $csrf2
if ($r.status -eq 200 -and $r.body.data.user.email -eq $email1) { Ok 'T09 login success' } else { Bad 'T09' "got $($r.status)" }

$r = GetReq $s2 '/api/auth/me'
if ($r.status -eq 200 -and $r.body.data.user.id -eq $uid1 -and $r.body.data.user.verification_status -eq 'pending') {
    Ok 'T10 /auth/me returns pending user (matrix sanity, no donor endpoints yet)'
} else { Bad 'T10' "got $($r.status)" }

# --- T11 logout kills session ---
$r = Post $s2 '/api/logout' @{} $csrf2
$r = GetReq $s2 '/api/auth/me'
if ($r.status -eq 401) { Ok 'T11 logout invalidates session (401)' } else { Bad 'T11' "got $($r.status)" }

# --- T12 reset request unknown email: generic 200 ---
$sAnon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrfAnon = Get-Csrf $sAnon
$r = Post $sAnon '/api/password-reset/request' @{ email = "nobody$suffix@test.local" } $csrfAnon
if ($r.status -eq 200) { Ok 'T12 reset request unknown email generic 200' } else { Bad 'T12' "got $($r.status)" }

# --- T13 reset request known email; token hashed in DB ---
$s3 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrf3 = Get-Csrf $s3
$r = Post $s3 '/api/password-reset/request' @{ email = $email1 } $csrf3
$row = DbQuery "SELECT LENGTH(token_hash), used_at, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires_at) FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE u.email = '$email1' ORDER BY pr.id DESC LIMIT 1;"
$parts = ($row -split "`t")
if ($r.status -eq 200 -and $parts[0] -eq '64' -and $parts[1] -eq 'NULL' -and [int]$parts[2] -ge 28 -and [int]$parts[2] -le 31) {
    Ok 'T13 reset row: sha256 hex(64), unused, ~30min expiry'
} else { Bad 'T13' "row='$row'" }

# --- T14 confirm with garbage token ---
$r = Post $s3 '/api/password-reset/confirm' @{ token = ('f' * 64); password = 'NewPass123' } $csrf3
if ($r.status -eq 400) { Ok 'T14 garbage token 400' } else { Bad 'T14' "got $($r.status)" }

# --- T15 real reset: craft token server-side, confirm, login new password ---
$plainToken = ('a7c93b41e2d8f605' + ([guid]::NewGuid().ToString('N')))
$hash = [System.BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($plainToken))).Replace('-', '').ToLower()
DbQuery "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($uid1, '$hash', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE));"
$r = Post $s3 '/api/password-reset/confirm' @{ token = $plainToken; password = 'Br4ndNew9' } $csrf3
if ($r.status -ne 200) { Bad 'T15a confirm' "got $($r.status)" } 
$r = Post $s3 '/api/login' @{ email = $email1; password = 'Br4ndNew9' } $csrf3
if ($r.status -eq 200) { Ok 'T15 reset completes; new password logs in' } else { Bad 'T15' "new-password login got $($r.status)" }
$r = Post $s3 '/api/password-reset/confirm' @{ token = $plainToken; password = 'Again678x' } $csrf3
if ($r.status -eq 400) { Ok 'T16 token single-use (reuse 400)' } else { Bad 'T16' "got $($r.status)" }
$sOld = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrfOld = Get-Csrf $sOld
$oldLogin = Post $sOld '/api/login' @{ email = $email1; password = 'Str0ngPass1' } $csrfOld
if ($oldLogin.status -eq 401) { Ok 'T17 old password rejected after reset' } else { Bad 'T17' "got $($oldLogin.status)" }

# --- T18 expired token ---
$expiredPlain = 'deadbeef' + ([guid]::NewGuid().ToString('N'))
$expiredHash = [System.BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($expiredPlain))).Replace('-', '').ToLower()
DbQuery "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($uid1, '$expiredHash', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE));"
$r = Post $s3 '/api/password-reset/confirm' @{ token = $expiredPlain; password = 'Exp1red9z' } $csrf3
if ($r.status -eq 400) { Ok 'T18 expired token 400' } else { Bad 'T18' "got $($r.status)" }

# --- T19 lockout after 5 failures ---
$lockEmail = "lock$(Get-Random)@test.local"
Post $s1 '/api/register' @{ full_name = 'Lock Me'; email = $lockEmail; password = 'Str0ngPass1'; chapter_id = 1; date_of_birth = '1999-01-01' } $csrf1 | Out-Null
$s4 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrf4 = Get-Csrf $s4
for ($i = 0; $i -lt 5; $i++) { Post $s4 '/api/login' @{ email = $lockEmail; password = 'Nope1234' } $csrf4 | Out-Null }
$r = Post $s4 '/api/login' @{ email = $lockEmail; password = 'Nope1234' } $csrf4
if ($r.status -eq 429) { Ok 'T19 lockout 429 after 5 failures' } else { Bad 'T19' "got $($r.status)" }
$r = Post $s4 '/api/login' @{ email = $lockEmail; password = 'Str0ngPass1' } $csrf4
if ($r.status -eq 429) { Ok 'T20 even correct password blocked while locked' } else { Bad 'T20' "got $($r.status)" }

# --- T21 deactivated account cannot log in ---
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=NOW() WHERE id=$uid1;"
$s5 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrf5 = Get-Csrf $s5
$r = Post $s5 '/api/login' @{ email = $email1; password = 'Br4ndNew9' } $csrf5
if ($r.status -eq 403) { Ok 'T21 deactivated login 403' } else { Bad 'T21' "got $($r.status)" }
DbQuery "UPDATE users SET account_status='active', deactivated_at=NULL WHERE id=$uid1;"

# --- T22 audit trail exists ---
$rows = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('user.registered','auth.login.success','auth.login.failed','auth.logout','auth.password_reset.requested','auth.password_reset.completed');"
if ([int]$rows -ge 5) { Ok "T22 audit events recorded ($rows distinct auth actions)" } else { Bad 'T22' "only $rows distinct actions" }

# --- T23 no plaintext token anywhere ---
$plainLeak = DbQuery "SELECT COUNT(*) FROM password_resets WHERE token_hash NOT REGEXP '^[0-9a-f]{64}$';"
if ([int]$plainLeak -eq 0) { Ok 'T23 all stored tokens are 64-hex hashes only' } else { Bad 'T23' "$plainLeak bad rows" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }


