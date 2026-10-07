param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe',
    # Absolute directory the API server writes captured emails to
    # (server-side MAIL_CAPTURE_DIR). Must match the backend .env or the
    # send leg returns 503 and this suite cannot run end-to-end.
    [string]$MailCaptureDir = 'D:\James\SCHOOL 3RD YEAR\SE1 FINALS BLOODMATCH VERSIONS\BloodMatch\logs\mail-capture'
)

# Email OTP verification suite (migrations 019–021).
#
# O-series: states reachable WITH a session post-gate (auth negatives,
# verified-account behavior, privacy, audit, matrix).
# R-series: registration claim journey (logged-out) — the canonical OTP
#   mechanics: auto-send, wrong/expired/replay/superseded codes, cooldown,
#   hourly budget, race, cross-user isolation, deactivated, token lifecycle.
# G-series: the login gate — dedicated regression for the bypass where an
#   unverified registrant could authenticate (register -> login 200).
#
# Post-gate, an unverified SESSION is unreachable through public flows
# (login requires email_verified_at), so session-mode OTP mechanics are
# covered exclusively by the R-series; the old O03–O14/O17 cases for those
# states were removed (mechanics unchanged, coverage preserved 1:1).
#
# Prerequisite: backend MAIL_CAPTURE_DIR configured + server restarted.

$ErrorActionPreference = 'Stop'
$script:pass = 0
$script:fail = 0
$script:usedCodes = @()

function Ok($name) { $script:pass++; Write-Host "PASS  $name" }
function Bad($name, $why) { $script:fail++; Write-Host "FAIL  $name -> $why" }

function Get-Csrf($session) {
    $r = Invoke-RestMethod -Uri "$BaseUrl/api/csrf" -Method Get -WebSession $session -TimeoutSec 10 -UseBasicParsing
    return $r.data.csrf_token
}

function Invoke-Json($session, $method, $uri, $body, $csrf) {
    $headers = @{}
    if ($csrf) { $headers['X-CSRF-Token'] = $csrf }
    try {
        $args = @{ Uri = "$BaseUrl$uri"; Method = $method; WebSession = $session; TimeoutSec = 15; UseBasicParsing = $true }
        if ($null -ne $body) { $args['Body'] = ($body | ConvertTo-Json -Depth 5); $args['ContentType'] = 'application/json' }
        if ($csrf) { $args['Headers'] = $headers }
        $res = Invoke-WebRequest @args
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json); raw = $res.Content }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $status = [int]$resp.StatusCode
        try {
            $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
            $raw = $reader.ReadToEnd()
        } catch { $raw = '' }
        try { $parsed = $raw | ConvertFrom-Json } catch { $parsed = $null }
        return @{ status = $status; body = $parsed; raw = $raw }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

function New-User($email) {
    # Register only (stays logged out). Returns the claim token so callers
    # can complete the mandatory journey before signing in.
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/register' @{
        full_name = 'OTP Fixture'; email = $email; password = 'Str0ngPass1'
        chapter_id = 1; date_of_birth = '2000-05-10'; blood_type = 'O+'; privacy_acknowledged = $true
    } $csrf
    if ($r.status -ne 201) { throw "register failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $csrf; id = [int]$r.body.data.user.id; token = $r.body.data.email_otp.verification_token }
}

# Registration journey: register and KEEP the claim token, stay logged out.
function Register-Claim($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/register' @{
        full_name = 'OTP Claim Fixture'; email = $email; password = 'Str0ngPass1'
        chapter_id = 1; date_of_birth = '2000-05-10'; blood_type = 'O+'; privacy_acknowledged = $true
    } $csrf
    return @{ r = $r; s = $s; csrf = $csrf }
}

function Claim-Status($s, $csrf, $token) {
    return Invoke-Json $s 'Post' '/api/auth/email-otp/status' @{ verification_token = $token } $csrf
}

function Claim-Send($s, $csrf, $token) {
    return Invoke-Json $s 'Post' '/api/auth/email-otp/send' @{ verification_token = $token } $csrf
}

function Claim-Verify($s, $csrf, $token, $code) {
    return Invoke-Json $s 'Post' '/api/auth/email-otp/verify' @{ verification_token = $token; code = $code } $csrf
}

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    $csrf = $r.body.data.csrf_token
    if ([string]::IsNullOrEmpty($csrf)) { $csrf = Get-Csrf $s }
    return @{ s = $s; csrf = $csrf }
}

function Send-Otp($auth) {
    return Invoke-Json $auth.s 'Post' '/api/auth/email-otp/send' @{} $auth.csrf
}

function Verify-Otp($auth, $code) {
    return Invoke-Json $auth.s 'Post' '/api/auth/email-otp/verify' @{ code = $code } $auth.csrf
}

function Get-CapturedCode($email) {
    $esc = [regex]::Escape($email)
    $f = Get-ChildItem -Path $MailCaptureDir -Filter '*.eml' -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match $esc } |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($null -eq $f) { return @{ file = $null; code = $null; content = $null } }
    $content = Get-Content $f.FullName -Raw
    $m = [regex]::Match($content, '(?m)^(\d{6})$')
    $code = if ($m.Success) { $m.Groups[1].Value } else { $null }
    return @{ file = $f; code = $code; content = $content }
}

function Backdate-Otp($userId, $seconds) {
    DbQuery "UPDATE email_verification_otps SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL $seconds SECOND) WHERE user_id=$userId AND used_at IS NULL;"
}

function Sha256Hex($plain) {
    return [System.BitConverter]::ToString(
        [System.Security.Cryptography.SHA256]::Create().ComputeHash(
            [Text.Encoding]::UTF8.GetBytes($plain))).Replace('-', '').ToLower()
}

$suffix = "$(Get-Random)"
Write-Host "== Email OTP verification suite =="

# --- O01 CSRF protection on send ---
$s0 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-Json $s0 'Post' '/api/auth/email-otp/send' @{} $null
if ($r.status -eq 403) { Ok 'O01 send without CSRF rejected (403)' } else { Bad 'O01' "got $($r.status)" }

# --- O02 unauthenticated access denied ---
$r = Invoke-Json $s0 'Get' '/api/auth/email-otp/status' $null $null
if ($r.status -eq 401) { Ok 'O02a status without session 401' } else { Bad 'O02a' "got $($r.status)" }
$csrf0 = Get-Csrf $s0
$r = Invoke-Json $s0 'Post' '/api/auth/email-otp/verify' @{ code = '123456' } $csrf0
if ($r.status -eq 401) { Ok 'O02b verify without session 401' } else { Bad 'O02b' "got $($r.status)" }

Write-Host ''
Write-Host '== Login gate (unverified accounts cannot authenticate) =='

# --- G01 fresh registration is unverified with live OTP + claim ---
$emailG1 = "gateg1$suffix@test.local"
$regG1 = Register-Claim $emailG1
$g1 = $regG1.r
$tokG1 = $g1.body.data.email_otp.verification_token
$uG1 = [int]$g1.body.data.user.id
$vfyG1 = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uG1;"
$rowsG1 = DbQuery "SELECT (SELECT COUNT(*) FROM email_verification_otps WHERE user_id=$uG1 AND used_at IS NULL), (SELECT COUNT(*) FROM email_otp_claim_tokens WHERE user_id=$uG1 AND used_at IS NULL);"
$pG1 = ("$rowsG1" -split '\s+')
$capG1 = Get-CapturedCode $emailG1
$script:claimCodes += @($capG1.code)
if ($g1.status -eq 201 -and $vfyG1 -eq 'NULL' -and $pG1[0] -eq '1' -and $pG1[1] -eq '1' -and $null -ne $capG1.file) {
    Ok 'G01 new registration is unverified with auto-sent OTP + claim'
} else { Bad 'G01' "status=$($g1.status) vfy=$vfyG1 rows='$rowsG1'" }

# --- G02 correct-credential login blocked with guidance, no session ---
$lg = Invoke-Json $regG1.s 'Post' '/api/login' @{ email = $emailG1; password = 'Str0ngPass1' } $regG1.csrf
$det = $lg.body.error.details
$meG = Invoke-Json $regG1.s 'Get' '/api/auth/me' $null $null
if ($lg.status -eq 403 -and $lg.body.error.message -eq 'Please verify your email address before logging in.' -and $det.code -eq 'email_verification_required' -and "$($det.verification_token)" -match '^[0-9a-f]{64}$' -and $meG.status -eq 401) {
    Ok 'G02 unverified login 403 + recovery token; no session created'
} else { Bad 'G02' "status=$($lg.status) me=$($meG.status)" }

# --- G03 fresh session (left page / new tab): still blocked ---
$sG3 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$cG3 = Get-Csrf $sG3
$lg3 = Invoke-Json $sG3 'Post' '/api/login' @{ email = $emailG1; password = 'Str0ngPass1' } $cG3
if ($lg3.status -eq 403 -and $lg3.body.error.details.code -eq 'email_verification_required') {
    Ok 'G03 bypass via new session fails: backend still rejects'
} else { Bad 'G03' "got $($lg3.status)" }

# --- G04/G05 no password oracle: wrong-pw identical to unknown email ---
$w = Invoke-Json $sG3 'Post' '/api/login' @{ email = $emailG1; password = 'WrongPass9' } $cG3
$u = Invoke-Json $sG3 'Post' '/api/login' @{ email = "ghost$suffix@test.local"; password = 'WrongPass9' } $cG3
if ($w.status -eq 401 -and $u.status -eq 401 -and $w.body.error.message -eq $u.body.error.message -and $null -eq $w.body.error.details.code) {
    Ok 'G04/G05 wrong password indistinguishable from unknown email (401 uniform)'
} else { Bad 'G04/G05' "w=$($w.status) u=$($u.status)" }

# --- G06/G07 verify via the freshest 403-issued token, then login succeeds ---
# Each blocked login mints exactly one live token (superseding the last):
# G02's token died at G03, so G06 uses G03's token — the stale ones must be
# dead, and only the latest 403-issued token verifies.
$codeG1 = $capG1.code
$stale = Claim-Verify $regG1.s $regG1.csrf $tokG1 $codeG1
$tokG1b = $lg3.body.error.details.verification_token
$v = Claim-Verify $regG1.s $regG1.csrf $tokG1b $codeG1
$authG1 = Login $emailG1
$meG1 = Invoke-Json $authG1.s 'Get' '/api/auth/me' $null $null
if ($stale.status -eq 401 -and $v.status -eq 200 -and $authG1 -ne $null -and $meG1.status -eq 200 -and $meG1.body.data.user.email -eq $emailG1 -and $null -ne $meG1.body.data.user.email_verified_at) {
    Ok 'G06/G07 stale token dead; fresh token verifies; login + session work'
} else { Bad 'G06/G07' "stale=$($stale.status) verify=$($v.status) me=$($meG1.status)" }

# --- G08 deactivated precedence preserved ---
$emailG8 = "gateg8$suffix@test.local"
$regG8 = Register-Claim $emailG8
$uG8 = [int]$regG8.r.body.data.user.id
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=UTC_TIMESTAMP() WHERE id=$uG8;"
$lg8 = Invoke-Json $regG8.s 'Post' '/api/login' @{ email = $emailG8; password = 'Str0ngPass1' } $regG8.csrf
DbQuery "UPDATE users SET account_status='active', deactivated_at=NULL WHERE id=$uG8;"
if ($lg8.status -eq 403 -and $lg8.body.error.message -eq 'This account has been deactivated.') {
    Ok 'G08 deactivated account still reports deactivation (not verification)'
} else { Bad 'G08' "got $($lg8.status): $($lg8.body.error.message)" }

# --- G09 lockout throttle still precedes the gate ---
# (Same 4-then-lock shape as phase3 T19: the shared throttle locks during
# the 4th recorded failure, so only the first wrong attempts are 401s.)
$emailG9 = "gateg9$suffix@test.local"
$regG9 = Register-Claim $emailG9
$sG9 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$cG9 = Get-Csrf $sG9
for ($i = 0; $i -lt 5; $i++) {
    Invoke-Json $sG9 'Post' '/api/login' @{ email = $emailG9; password = 'WrongPass9' } $cG9 | Out-Null
}
$r = Invoke-Json $sG9 'Post' '/api/login' @{ email = $emailG9; password = 'Str0ngPass1' } $cG9
if ($r.status -eq 429) { Ok 'G09 brute-force lockout intact (429 before gate)' } else { Bad 'G09' "correct-while-locked got $($r.status)" }

# --- G10 standard fixtures log in (grandfathered, identity model intact) ---
$g10ok = $true
foreach ($stdMail in @('admin@test.local', 'samatofficer@test.local', 'tarakofficer@test.local', 'verified@test.local', 'pending@test.local', 'rejected@test.local')) {
    $ss = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $cc = Get-Csrf $ss
    $lr = Invoke-Json $ss 'Post' '/api/login' @{ email = $stdMail; password = 'Str0ngPass1' } $cc
    if ($lr.status -ne 200) { $g10ok = $false; Bad 'G10' "$stdMail login got $($lr.status)"; break }
}
if ($g10ok) { Ok 'G10 admin/officers/verified/pending/rejected fixtures log in' }

# --- G11 blocked attempts audited ---
$blockedAud = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='auth.login.blocked_unverified';"
if ([int]$blockedAud -ge 2) { Ok "G11 blocked logins audited ($blockedAud rows)" } else { Bad 'G11' "only $blockedAud rows" }

# --- G12 the blocked-login token itself recovers the journey ---
$emailG12 = "gateg12$suffix@test.local"
$regG12 = Register-Claim $emailG12
$lg12 = Invoke-Json $regG12.s 'Post' '/api/login' @{ email = $emailG12; password = 'Str0ngPass1' } $regG12.csrf
$tokG12 = $lg12.body.error.details.verification_token
$codeG12 = (Get-CapturedCode $emailG12).code
$script:claimCodes += @($codeG12)
$v12 = Claim-Verify $regG12.s $regG12.csrf $tokG12 $codeG12
$authG12 = Login $emailG12
if ($lg12.status -eq 403 -and $v12.status -eq 200 -and $authG12 -ne $null) {
    Ok 'G12 403-issued token verifies; login then succeeds'
} else { Bad 'G12' "block=$($lg12.status) verify=$($v12.status)" }

# (O03–O14 removed: unverified sessions are unreachable now that login
# requires email_verified_at. Their mechanics live on as R04–R13.)

# (O09–O11 removed: same reason; see R07–R09.)

# (O12–O14 removed: same reason; see R05-last-part/R11/R12.)

# --- O15 verified user: send is a no-op (via the mandatory journey) ---
$emailV = "otpv$suffix@test.local"
$uV = New-User $emailV
$codeV = (Get-CapturedCode $emailV).code
$vv = Claim-Verify $uV.s $uV.csrf $uV.token $codeV
$authV = Login $emailV
$rowsBefore = DbQuery "SELECT COUNT(*) FROM email_verification_otps WHERE user_id=$($uV.id);"
$filesBefore = (Get-ChildItem -Path $MailCaptureDir -Filter '*.eml' | Where-Object { $_.Name -match [regex]::Escape($emailV) }).Count
$r = Send-Otp $authV
$rowsAfter = DbQuery "SELECT COUNT(*) FROM email_verification_otps WHERE user_id=$($uV.id);"
$filesAfter = (Get-ChildItem -Path $MailCaptureDir -Filter '*.eml' | Where-Object { $_.Name -match [regex]::Escape($emailV) }).Count
if ($vv.status -eq 200 -and $r.status -eq 200 -and $r.body.data.already_verified -eq $true -and "$rowsBefore" -eq "$rowsAfter" -and $filesBefore -eq $filesAfter) {
    Ok 'O15 verified user send returns already_verified; no row, no email'
} else { Bad 'O15' "verify=$($vv.status) status=$($r.status) rows=$rowsBefore/$rowsAfter files=$filesBefore/$filesAfter" }

# --- O16 password-reset separation ---
$loginAgain = Invoke-Json $authV.s 'Post' '/api/login' @{ email = $emailV; password = 'Str0ngPass1' } $authV.csrf
$resetCount = DbQuery "SELECT COUNT(*) FROM password_resets WHERE user_id=$($uV.id);"
if ($loginAgain.status -eq 200 -and [int]$resetCount -eq 0) {
    Ok 'O16 OTP verification changes no password and mints no reset rows'
} else { Bad 'O16' "login=$($loginAgain.status) resets=$resetCount" }

# (O17 removed: session-mode race needs an unverified session, unreachable
# post-gate; atomic single-winner consumption is covered by R10.)

# --- O18a status responses carry no OTP/hash material ---
$probe = Invoke-Json $authV.s 'Get' '/api/auth/email-otp/status' $null $null
$probeRaw = "$($probe.raw)"
$respLeak = ($probeRaw -match '(?m)^\d{6}$') -or ($probeRaw -match '[0-9a-f]{64}') -or ($probeRaw -match 'otp_hash')
if ($probe.status -eq 200 -and -not $respLeak) { Ok 'O18a status response carries no OTP/hash material' } else { Bad 'O18a' 'OTP material found in status response' }

Write-Host ''
Write-Host '== Registration journey (claim-token mode, never logged in) =='
$script:claimCodes = @()

# --- R01 Create Account automatically sends exactly one OTP + claim token ---
$emailR1 = "regr1$suffix@test.local"
$reg1 = Register-Claim $emailR1
$r1 = $reg1.r
$otp1 = $r1.body.data.email_otp
$tok1 = $otp1.verification_token
$uR1 = [int]$r1.body.data.user.id
$maskOk = "$($otp1.masked_email)" -match '^.\*\*\*@test\.local$'
$tokOk = "$tok1" -match '^[0-9a-f]{64}$'
$vfyR1 = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uR1;"
$vsR1 = DbQuery "SELECT verification_status FROM users WHERE id=$uR1;"
$rowsR1 = DbQuery "SELECT (SELECT COUNT(*) FROM email_verification_otps WHERE user_id=$uR1 AND used_at IS NULL), (SELECT COUNT(*) FROM email_otp_claim_tokens WHERE user_id=$uR1 AND used_at IS NULL);"
$pR1 = ("$rowsR1" -split '\s+')
if ($r1.status -eq 201 -and $otp1.required -eq $true -and $otp1.delivered -eq $true -and $maskOk -and $tokOk -and $vfyR1 -eq 'NULL' -and $vsR1 -eq 'pending' -and $pR1[0] -eq '1' -and $pR1[1] -eq '1') {
    Ok 'R01 register 201 auto-sends one OTP + claim token; unverified pending account'
} else { Bad 'R01' "status=$($r1.status) rows='$rowsR1' mask=$($otp1.masked_email)" }
if ($r1.status -eq 201 -and $otp1.delivered -ne $true) {
    Write-Host 'FATAL: server mail transport unconfigured (delivered=false). Set MAIL_CAPTURE_DIR in backend .env and restart the PHP server, then re-run.'
    exit 1
}

# --- R02 no OTP on failed registration ---
# A throwaway valid registration provides an anonymous session; its own
# legitimate auto-send happened BEFORE the snapshot below.
$badReg = Register-Claim "regbad$suffix@test.local"
$otpCountBefore = DbQuery "SELECT COUNT(*) FROM email_verification_otps;"
$claimCountBefore = DbQuery "SELECT COUNT(*) FROM email_otp_claim_tokens;"
$inv = Invoke-Json $badReg.s 'Post' '/api/register' @{
    full_name = 'X'; email = "reginv$suffix@test.local"; password = 'short'
    chapter_id = 1; date_of_birth = '2000-05-10'; privacy_acknowledged = $true
} $badReg.csrf
$dup = Invoke-Json $badReg.s 'Post' '/api/register' @{
    full_name = 'Dup'; email = $emailR1; password = 'Str0ngPass1'
    chapter_id = 1; date_of_birth = '2000-05-10'; privacy_acknowledged = $true
} $badReg.csrf
$otpCountAfter = DbQuery "SELECT COUNT(*) FROM email_verification_otps;"
$claimCountAfter = DbQuery "SELECT COUNT(*) FROM email_otp_claim_tokens;"
$ghostUser = DbQuery "SELECT COUNT(*) FROM users WHERE email='reginv$suffix@test.local';"
$ghostMail = Get-CapturedCode "reginv$suffix@test.local"
if ($inv.status -eq 400 -and $dup.status -eq 409 -and "$ghostUser" -eq '0' -and "$otpCountBefore" -eq "$otpCountAfter" -and "$claimCountBefore" -eq "$claimCountAfter" -and $null -eq $ghostMail.file) {
    Ok 'R02 invalid (400) and duplicate (409) registration create no account, no OTP, no email'
} else { Bad 'R02' "inv=$($inv.status) dup=$($dup.status) ghost=$ghostUser otp=$otpCountBefore/$otpCountAfter" }

# --- R03 registration email goes to the right recipient, response stays clean ---
$capR1 = Get-CapturedCode $emailR1
$codeR1 = $capR1.code
$script:usedCodes += @($codeR1)
$script:claimCodes += @($codeR1)
$toR1 = ($capR1.content -split "`n" | Where-Object { $_ -match '^To:' } | Select-Object -First 1)
$subjR1 = $capR1.content -match 'Subject: BloodMatch Email Verification'
$bodyR1 = ($capR1.content -match 'Your BloodMatch verification code is:') -and ($capR1.content -match 'expires in 10 minutes') -and ($capR1.content -match 'If you did not request this code, you can ignore this email.')
$leakR1 = ($capR1.content -match 'password_hash') -or ($capR1.content -match 'token_hash') -or ($capR1.content -match 'csrf' -and $capR1.content -match '[a-f0-9]{32}') -or ($capR1.content -match '-?\d{2,3}\.\d{4,}')
# The claim token is SUPPOSED to be in the 201 response (it authorizes the
# logged-out journey), and data.user.email is pre-existing behavior. What
# must stay out of the email_otp block: the full address, the code, hashes.
$otpBlock = ($r1.body.data.email_otp | ConvertTo-Json -Compress)
$respClean = ($otpBlock -notmatch [regex]::Escape($emailR1)) -and ($otpBlock -notmatch [regex]::Escape("$codeR1")) -and ($otpBlock -notmatch 'otp_hash') -and ($otpBlock -match 'masked_email')
if ($null -ne $capR1.file -and $codeR1 -match '^\d{6}$' -and ($toR1 -match [regex]::Escape($emailR1)) -and $subjR1 -and $bodyR1 -and -not $leakR1 -and $respClean) {
    Ok 'R03 OTP email to registrant with correct content; 201 response leaks nothing'
} else { Bad 'R03' "to=$toR1 subj=$subjR1 body=$bodyR1 leak=$leakR1 clean=$respClean" }

# --- R04 claim-mode verify without ever logging in ---
$v = Claim-Verify $reg1.s $reg1.csrf $tok1 $codeR1
$vfyAfter = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uR1;"
$meAnon = Invoke-Json $reg1.s 'Get' '/api/auth/me' $null $null
$tokUsed = DbQuery "SELECT IFNULL(used_at,'NULL') FROM email_otp_claim_tokens WHERE user_id=$uR1 ORDER BY id DESC LIMIT 1;"
if ($v.status -eq 200 -and $v.body.data.verified -eq $true -and $vfyAfter -ne 'NULL' -and $meAnon.status -eq 401 -and $tokUsed -ne 'NULL') {
    Ok 'R04 claim verify succeeds with no session; token consumed; still anonymous'
} else { Bad 'R04' "status=$($v.status) verified=$vfyAfter me=$($meAnon.status) tokUsed=$tokUsed" }

# --- R05 claim-mode wrong/expired codes rejected ---
$emailR5 = "regr5$suffix@test.local"
$reg5 = Register-Claim $emailR5
$tok5 = $reg5.r.body.data.email_otp.verification_token
$uR5 = [int]$reg5.r.body.data.user.id
$capR5 = Get-CapturedCode $emailR5
$codeR5 = $capR5.code
$wrongR5 = if ($codeR5.Substring(0,1) -ne '0') { '0' + $codeR5.Substring(1) } else { '1' + $codeR5.Substring(1) }
$script:usedCodes += @($codeR5, $wrongR5)
$script:claimCodes += @($codeR5, $wrongR5)
$r = Claim-Verify $reg5.s $reg5.csrf $tok5 $wrongR5
$attR5 = DbQuery "SELECT attempt_count FROM email_verification_otps WHERE user_id=$uR5 AND used_at IS NULL ORDER BY id DESC LIMIT 1;"
$rMal = Claim-Verify $reg5.s $reg5.csrf $tok5 'abc'
$attR5b = DbQuery "SELECT attempt_count FROM email_verification_otps WHERE user_id=$uR5 AND used_at IS NULL ORDER BY id DESC LIMIT 1;"
# Expire every live row, then plant a known-expired one: nothing usable left.
DbQuery "UPDATE email_verification_otps SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE user_id=$uR5 AND used_at IS NULL;"
$plainX = '739201'
$script:claimCodes += @($plainX)
DbQuery "INSERT INTO email_verification_otps (user_id, otp_hash, expires_at) VALUES ($uR5, '$(Sha256Hex $plainX)', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE));"
$rExp = Claim-Verify $reg5.s $reg5.csrf $tok5 $plainX
$vfyR5 = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uR5;"
if ($r.status -eq 400 -and [int]$attR5 -eq 1 -and $rMal.status -eq 400 -and "$attR5" -eq "$attR5b" -and $rExp.status -eq 400 -and $vfyR5 -eq 'NULL') {
    Ok 'R05 claim wrong/expired codes rejected; attempts counted; malformed ignored'
} else { Bad 'R05' "wrong=$($r.status)/$attR5 mal=$($rMal.status)/$attR5b exp=$($rExp.status) vfy=$vfyR5" }

# --- R06 replay + token single-use ---
$r = Claim-Verify $reg1.s $reg1.csrf $tok1 $codeR1
$authR1 = Login $emailR1
$rSess = Verify-Otp $authR1 $codeR1
if ($r.status -eq 401 -and $rSess.status -eq 400) {
    Ok 'R06 consumed token (401) and consumed code (400) both rejected'
} else { Bad 'R06' "claim=$($r.status) session=$($rSess.status)" }

# --- R07 claim resend supersedes ---
$emailR7 = "regr7$suffix@test.local"
$reg7 = Register-Claim $emailR7
$tok7 = $reg7.r.body.data.email_otp.verification_token
$uR7 = [int]$reg7.r.body.data.user.id
$codeA7 = (Get-CapturedCode $emailR7).code
Backdate-Otp $uR7 61
$rs = Claim-Send $reg7.s $reg7.csrf $tok7
$codeB7 = (Get-CapturedCode $emailR7).code
$script:claimCodes += @($codeA7, $codeB7)
$rOld = Claim-Verify $reg7.s $reg7.csrf $tok7 $codeA7
$rNew = Claim-Verify $reg7.s $reg7.csrf $tok7 $codeB7
if ($rs.status -eq 200 -and $codeA7 -ne $codeB7 -and $rOld.status -eq 400 -and $rNew.status -eq 200) {
    Ok 'R07 claim resend supersedes: old rejected, replacement accepted'
} else { Bad 'R07' "send=$($rs.status) old=$($rOld.status) new=$($rNew.status)" }

# --- R08 claim resend cooldown ---
$emailR8 = "regr8$suffix@test.local"
$reg8 = Register-Claim $emailR8
$r = Claim-Send $reg8.s $reg8.csrf $reg8.r.body.data.email_otp.verification_token
if ($r.status -eq 429) { Ok 'R08 immediate claim resend rejected (429 cooldown)' } else { Bad 'R08' "got $($r.status)" }

# --- R09 claim hourly budget (register consumed send #1) ---
$emailR9 = "regr9$suffix@test.local"
$reg9 = Register-Claim $emailR9
$tok9 = $reg9.r.body.data.email_otp.verification_token
$uR9 = [int]$reg9.r.body.data.user.id
$ok = $true
for ($i = 0; $i -lt 4; $i++) {
    Backdate-Otp $uR9 61
    $r = Claim-Send $reg9.s $reg9.csrf $tok9
    if ($r.status -ne 200) { $ok = $false; Bad 'R09' "resend $($i+1) got $($r.status)"; break }
}
if ($ok) {
    Backdate-Otp $uR9 61
    $r = Claim-Send $reg9.s $reg9.csrf $tok9
    if ($r.status -eq 429) { Ok 'R09 6th overall send rejected (429 shared budget)' } else { Bad 'R09' "6th got $($r.status)" }
}

# --- R10 concurrent claim verifies: exactly one winner ---
$emailR10 = "regr10$suffix@test.local"
$reg10 = Register-Claim $emailR10
$tok10 = $reg10.r.body.data.email_otp.verification_token
$codeR10 = (Get-CapturedCode $emailR10).code
$script:claimCodes += @($codeR10)
$jobClaim = {
    param($base, $token, $code)
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    try {
        $c = (Invoke-RestMethod -Uri "$base/api/csrf" -WebSession $s -TimeoutSec 10 -UseBasicParsing).data.csrf_token
        $res = Invoke-WebRequest -Uri "$base/api/auth/email-otp/verify" -Method Post `
            -Body (@{ verification_token = $token; code = $code } | ConvertTo-Json) `
            -ContentType 'application/json' -Headers @{ 'X-CSRF-Token' = $c } `
            -WebSession $s -TimeoutSec 15 -UseBasicParsing
        return [int]$res.StatusCode
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { return -1 }
        return [int]$resp.StatusCode
    }
}
$j1 = Start-Job -ScriptBlock $jobClaim -ArgumentList $BaseUrl, $tok10, $codeR10
$j2 = Start-Job -ScriptBlock $jobClaim -ArgumentList $BaseUrl, $tok10, $codeR10
Wait-Job $j1, $j2 | Out-Null
$w1 = Receive-Job $j1
$w2 = Receive-Job $j2
Remove-Job $j1, $j2
$ws = @($w1, $w2) | Sort-Object
if ($ws[0] -eq 200 -and ($ws[1] -eq 400 -or $ws[1] -eq 401)) {
    Ok 'R10 concurrent claim verifies: exactly one succeeds'
} else { Bad 'R10' "statuses=$w1/$w2" }

# --- R11 claim tokens are account-bound ---
$emailRA = "regra$suffix@test.local"
$emailRB = "regrb$suffix@test.local"
$regA = Register-Claim $emailRA
$regB = Register-Claim $emailRB
$tokA = $regA.r.body.data.email_otp.verification_token
$tokB = $regB.r.body.data.email_otp.verification_token
$uRA = [int]$regA.r.body.data.user.id
$uRB = [int]$regB.r.body.data.user.id
$codeA = (Get-CapturedCode $emailRA).code
$script:claimCodes += @($codeA)
$attABefore = DbQuery "SELECT attempt_count FROM email_verification_otps WHERE user_id=$uRA AND used_at IS NULL ORDER BY id DESC LIMIT 1;"
$r = Claim-Verify $regB.s $regB.csrf $tokB $codeA
$attAAfter = DbQuery "SELECT attempt_count FROM email_verification_otps WHERE user_id=$uRA AND used_at IS NULL ORDER BY id DESC LIMIT 1;"
$vfyRB = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uRB;"
if ($r.status -eq 400 -and "$attABefore" -eq "$attAAfter" -and $vfyRB -eq 'NULL') {
    Ok "R11 A's code cannot verify B; A's challenge untouched"
} else { Bad 'R11' "status=$($r.status) att=$attABefore/$attAAfter b=$vfyRB" }

# --- R12 deactivated account blocked in claim mode ---
$emailR12 = "regr12$suffix@test.local"
$reg12 = Register-Claim $emailR12
$tok12 = $reg12.r.body.data.email_otp.verification_token
$uR12 = [int]$reg12.r.body.data.user.id
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=UTC_TIMESTAMP() WHERE id=$uR12;"
$c1 = Claim-Status $reg12.s $reg12.csrf $tok12
$c2 = Claim-Send $reg12.s $reg12.csrf $tok12
$c3 = Claim-Verify $reg12.s $reg12.csrf $tok12 '123456'
DbQuery "UPDATE users SET account_status='active', deactivated_at=NULL WHERE id=$uR12;"
if ($c1.status -eq 403 -and $c2.status -eq 403 -and $c3.status -eq 403) {
    Ok 'R12 deactivated account: claim status/send/verify all 403'
} else { Bad 'R12' "$($c1.status)/$($c2.status)/$($c3.status)" }

# --- R13 expired/consumed/malformed tokens ---
$badTok = Claim-Status $reg5.s $reg5.csrf 'not-a-token'
# Fresh random unknown token per run: the per-token brute-force throttle
# keys on the token hash, so a constant probe would self-lock across runs.
$randHex = -join ((1..64) | ForEach-Object { '0123456789abcdef'[(Get-Random -Maximum 16)] })
$hexTok = Claim-Status $reg5.s $reg5.csrf $randHex
DbQuery "UPDATE email_otp_claim_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE user_id=$uR5 AND used_at IS NULL;"
$expTok = Claim-Status $reg5.s $reg5.csrf $tok5
if ($badTok.status -eq 401 -and $hexTok.status -eq 401) {
    Ok 'R13 malformed/unknown claim tokens rejected (401 generic)'
} else { Bad 'R13' "$($badTok.status)/$($hexTok.status)" }
if ($expTok.status -eq 401) { Ok 'R13b expired claim token rejected' } else { Bad 'R13b' "got $($expTok.status)" }

# --- R14 login still works after claim verification (password untouched) ---
$loginR1 = Invoke-Json $reg1.s 'Post' '/api/login' @{ email = $emailR1; password = 'Str0ngPass1' } $reg1.csrf
if ($loginR1.status -eq 200 -and $loginR1.body.data.user.email_verified_at -ne $null) {
    Ok 'R14 login works after claim verification; identity carries verified state'
} else { Bad 'R14' "got $($loginR1.status)" }

# --- R17 session send converges after claim verification ---
# (runs before R15 changes the password below)
$authR1b = Login $emailR1
$r = Send-Otp $authR1b
if ($r.status -eq 200 -and $r.body.data.already_verified -eq $true) {
    Ok 'R17 verified-via-claim account reports already_verified in session mode'
} else { Bad 'R17' "got $($r.status)" }

# --- R15 password reset stays independent ---
$pr = Invoke-Json $reg1.s 'Post' '/api/password-reset/request' @{ email = $emailR1 } $loginR1.body.data.csrf_token
$plainP = ('b3f19c' + ([guid]::NewGuid().ToString('N')))
DbQuery "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($uR1, '$(Sha256Hex $plainP)', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE));"
$pc = Invoke-Json $reg1.s 'Post' '/api/password-reset/confirm' @{ token = $plainP; password = 'NewPass123' } $loginR1.body.data.csrf_token
$loginNew = Invoke-Json $reg1.s 'Post' '/api/login' @{ email = $emailR1; password = 'NewPass123' } $loginR1.body.data.csrf_token
if ($pr.status -eq 200 -and $pc.status -eq 200 -and $loginNew.status -eq 200) {
    Ok 'R15 password reset works independently after claim verification'
} else { Bad 'R15' "req=$($pr.status) confirm=$($pc.status) login=$($loginNew.status)" }

# --- R16 explicit token wins over another session (no wrong-account verify) ---
$emailRX = "regrx$suffix@test.local"
$uX = New-User $emailRX
$codeX = (Get-CapturedCode $emailRX).code
$script:claimCodes += @($codeX)
$vrx = Claim-Verify $uX.s $uX.csrf $uX.token $codeX
if ($vrx.status -ne 200) { Bad 'R16 setup' "X claim-verify got $($vrx.status)" }
$authX = Login $emailRX
$emailRY = "regry$suffix@test.local"
$regY = Register-Claim $emailRY
$tokY = $regY.r.body.data.email_otp.verification_token
$uRY = [int]$regY.r.body.data.user.id
$codeY = (Get-CapturedCode $emailRY).code
$script:claimCodes += @($codeY)
$r = Invoke-Json $authX.s 'Post' '/api/auth/email-otp/verify' @{ verification_token = $tokY; code = $codeY } $authX.csrf
$meX = Invoke-Json $authX.s 'Get' '/api/auth/me' $null $null
$vfyY = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$uRY;"
$vfyX = DbQuery "SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id=$($uX.id);"
# X verified itself earlier (required to hold its session); Y is verified
# by its own token presented under X's session. Session stays X throughout.
if ($r.status -eq 200 -and $meX.body.data.user.email -eq $emailRX -and $vfyY -ne 'NULL' -and $vfyX -ne 'NULL') {
    Ok 'R16 token verifies its own account even under a different session'
} else { Bad 'R16' "status=$($r.status) me=$($meX.body.data.user.email) y=$vfyY x=$vfyX" }

# --- R18 registration works while another session is active ---
$regZ = Invoke-Json $authX.s 'Post' '/api/register' @{
    full_name = 'Reg While In'; email = "regrz$suffix@test.local"; password = 'Str0ngPass1'
    chapter_id = 1; date_of_birth = '2000-05-10'; privacy_acknowledged = $true
} $authX.csrf
$meX2 = Invoke-Json $authX.s 'Get' '/api/auth/me' $null $null
if ($regZ.status -eq 201 -and "$($regZ.body.data.email_otp.verification_token)" -match '^[0-9a-f]{64}$' -and $meX2.body.data.user.email -eq $emailRX) {
    Ok 'R18 registration issues token without disturbing the active session'
} else { Bad 'R18' "status=$($regZ.status) me=$($meX2.body.data.user.email)" }

# --- R19 claim issuance audited ---
$claimAud = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='auth.email_otp.claim_issued';"
$regCtx = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='user.registered' AND context LIKE '%email_otp_delivered%';"
if ([int]$claimAud -ge 1 -and [int]$regCtx -ge 1) {
    Ok 'R19 claim issuance + register delivery flag audited'
} else { Bad 'R19' "claim=$claimAud regctx=$regCtx" }

# --- R20 no claim/OTP material in logs for the registration journey ---
# Boundary-anchored: bare alternation would false-positive on substrings
# of capture timestamps in mailer lines (e.g. code 175346 inside
# ...06175346_...); only standalone 6-digit tokens count as leaks.
$codePatternR = '(?<!\d)(' + ($script:claimCodes -join '|') + ')(?!\d)'
$logHitsR = Select-String -Path 'logs/app.log' -Pattern $codePatternR -ErrorAction SilentlyContinue
if ($null -eq $logHitsR -or @($logHitsR).Count -eq 0) { Ok 'R20 application log contains no registration OTP codes' } else { Bad 'R20' 'OTP code found in logs/app.log' }

# --- O18b application log contains no OTP codes (all journeys) ---
$allCodes = @($script:usedCodes + $script:claimCodes | Where-Object { $_ -ne $null -and $_ -ne '' } | Select-Object -Unique)
$codePattern = '(?<!\d)(' + ($allCodes -join '|') + ')(?!\d)'
$logHits = Select-String -Path 'logs/app.log' -Pattern $codePattern -ErrorAction SilentlyContinue
if ($null -eq $logHits -or @($logHits).Count -eq 0) { Ok 'O18b application log contains no OTP codes' } else { Bad 'O18b' 'OTP code found in logs/app.log' }

# --- O19 audit trail ---
$actions = DbQuery "SELECT DISTINCT action FROM audit_log WHERE action LIKE 'auth.email_otp.%';"
$need = @('auth.email_otp.requested', 'auth.email_otp.failed', 'auth.email_otp.verified', 'auth.email_otp.exhausted', 'auth.email_otp.claim_issued')
$missing = @($need | Where-Object { $actions -notcontains $_ })
if ($missing.Count -eq 0) { Ok 'O19 OTP lifecycle audit events recorded' } else { Bad 'O19' "missing: $($missing -join ',')" }
$ctxLeak = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'auth.email_otp.%' AND (context LIKE '%otp_hash%' OR context LIKE '%code%');"
if ([int]$ctxLeak -eq 0) { Ok 'O19b audit contexts carry no OTP material' } else { Bad 'O19b' "$ctxLeak audit rows leak OTP context" }
$blockedAudited = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='auth.login.blocked_unverified';"
if ([int]$blockedAudited -ge 1) { Ok "O19c blocked logins audited ($blockedAudited rows)" } else { Bad 'O19c' 'no blocked_unverified audit rows' }

# --- O20 fresh registration keeps member/pending/active, email unverified ---
$emailN = "otpn$suffix@test.local"
$uN = New-User $emailN
$capRow = DbQuery "SELECT CONCAT(role, '|', verification_status, '|', account_status, '|', IF(donor_enrolled_at IS NULL, 'no-donor', 'donor'), '|', IF(email_verified_at IS NULL, 'unverified-email', 'verified-email')) FROM users WHERE id=$($uN.id);"
if ("$capRow" -eq 'member|pending|active|no-donor|unverified-email') { Ok 'O20 new account gated: member/pending/active, email unverified' } else { Bad 'O20' "row='$capRow'" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
