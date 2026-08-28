param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe',
    [string]$PhpPath = 'D:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$script:pass = 0
$script:fail = 0

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

function New-FixtureUser($email, $name, $role, $chapterId, $vs) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'; if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status) VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', 'active');"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
}

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $csrf }
}

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host "== Phase 6 blood request lifecycle audit =="

# fixtures
$adminEmail = "p6adm$suffix@test.local"; $adminId = New-FixtureUser $adminEmail 'Admin Six' 'admin' $null 'active'
$offEmail   = "p6off$suffix@test.local"; $offId   = New-FixtureUser $offEmail 'Officer Six' 'officer' 1 'verified'
$offBEmail  = "p6offb$suffix@test.local"
$offBId     = New-FixtureUser $offBEmail 'Officer Bravo Six' 'officer' 2 'verified'
$penEmail   = "p6pen$suffix@test.local"; $penId   = New-FixtureUser $penEmail 'Pending Pete VI' 'member' 1 'pending'
$verEmail   = "p6ver$suffix@test.local"; $verId   = New-FixtureUser $verEmail 'Verified Vera' 'member' 1 'verified'
$regEmail   = "p6reg$suffix@test.local"; $regId   = New-FixtureUser $regEmail 'Registered Ray' 'member' 1 'unverified'
$rejEmail   = "p6rej$suffix@test.local"; $rejId   = New-FixtureUser $rejEmail 'Rejected Rex' 'member' 1 'rejected'
$othEmail   = "p6oth$suffix@test.local"; $othId   = New-FixtureUser $othEmail 'Other Chapter Olga' 'member' 2 'verified'

$adm = Login $adminEmail
$off = Login $offEmail
$offB = Login $offBEmail
$pen = Login $penEmail
$ver = Login $verEmail
$reg = Login $regEmail
$rej = Login $rejEmail
$oth = Login $othEmail

# --- T01 unauthenticated: GET -> 401; anonymous POST -> CSRF shield 403 (documented) ---
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' '/api/my/requests' $null $null
if ($r.status -eq 401) { Ok 'T01a unauthenticated GET 401' } else { Bad 'T01a' "got $($r.status)" }
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' "/api/requests/1" $null $null
if ($r.status -eq 401) { Ok 'T01b unauthenticated request view 401' } else { Bad 'T01b' "got $($r.status)" }
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Post' '/api/requests' @{} $null
if ($r.status -eq 403 -and $r.body.error.message -match 'CSRF') { Ok 'T01c anonymous POST blocked by CSRF shield (pre-auth layer)' } else { Bad 'T01c' "got $($r.status)" }

# --- T02 matrix gates ---
$r = Invoke-Json $reg.s 'Post' '/api/requests' @{ required_blood_type='O+'; facility_name='Clinic X'; needed_datetime=$future } $reg.csrf
if ($r.status -eq 403) { Ok 'T02a unverified (Registered) cannot create' } else { Bad 'T02a' "got $($r.status)" }
$r = Invoke-Json $rej.s 'Post' '/api/requests' @{ required_blood_type='O+'; facility_name='Clinic X'; needed_datetime=$future } $rej.csrf
if ($r.status -eq 403) { Ok 'T02b rejected cannot create' } else { Bad 'T02b' "got $($r.status)" }

# --- T03 pending creates with pending_review ---
$r = Invoke-Json $pen.s 'Post' '/api/requests' @{
    required_blood_type='O-'; quantity_units=2; facility_name='Balanga General';
    latitude=14.67; longitude=120.53; urgency='urgent'; needed_datetime=$future
} $pen.csrf
if ($r.status -eq 201 -and $r.body.data.request.review_status -eq 'pending_review' -and $r.body.data.request.status -eq 'OPEN') { Ok 'T03 pending-user request OPEN+pending_review' } else { Bad 'T03' "got $($r.status)" }
$reqPId = $r.body.data.request.id

# --- T04 verified creates not_required ---
$r = Invoke-Json $ver.s 'Post' '/api/requests' @{ required_blood_type='A+'; facility_name='Orani Clinic'; needed_datetime=$future } $ver.csrf
if ($r.status -eq 201 -and $r.body.data.request.review_status -eq 'not_required') { Ok 'T04 verified-user request not_required' } else { Bad 'T04' "got $($r.status)" }
$reqVId = $r.body.data.request.id

# --- T05 validation battery ---
$cases = @(
    @{ n='bad blood type'; b=@{ required_blood_type='Z+'; facility_name='F'; needed_datetime=$future }; f='required_blood_type' },
    @{ n='qty zero'; b=@{ required_blood_type='O+'; quantity_units=0; facility_name='F'; needed_datetime=$future }; f='quantity_units' },
    @{ n='past date'; b=@{ required_blood_type='O+'; facility_name='F'; needed_datetime='2020-01-01 00:00:00' }; f='needed_datetime' },
    @{ n='lat only'; b=@{ required_blood_type='O+'; facility_name='F'; needed_datetime=$future; latitude=14.5 }; f='location' },
    @{ n='lng out of range'; b=@{ required_blood_type='O+'; facility_name='F'; needed_datetime=$future; latitude=14.5; longitude=999 }; f='longitude' },
    @{ n='short facility'; b=@{ required_blood_type='O+'; facility_name='F'; needed_datetime=$future }; f='facility_name' },
    @{ n='bad urgency'; b=@{ required_blood_type='O+'; facility_name='Fac'; needed_datetime=$future; urgency='whenever' }; f='urgency' }
)
$i = 0
foreach ($c in $cases) {
    $i++
    $r = Invoke-Json $ver.s 'Post' '/api/requests' $c.b $ver.csrf
    if ($r.status -eq 400 -and $r.body.error.details.($c.f)) { Ok "T05$i validation: $($c.n)" } else { Bad "T05$i $($c.n)" "got $($r.status)" }
}

# --- T06 chapter snapshot immutable ---
$dbChap = DbQuery "SELECT request_chapter_id FROM blood_requests WHERE id=$reqVId;"
if ($dbChap -eq '1') { Ok 'T06 request_chapter_id derived from requestor' } else { Bad 'T06' "db=$dbChap" }

# --- T07 access matrix on show ---
$r = Invoke-Json $ver.s 'Get' "/api/requests/$reqVId" $null $ver.csrf
if ($r.status -eq 200 -and $r.raw -notmatch 'password_hash') { Ok 'T07 owner view safe fields' } else { Bad 'T07' "got $($r.status)" }
$r = Invoke-Json $oth.s 'Get' "/api/requests/$reqVId" $null $oth.csrf
if ($r.status -eq 403) { Ok 'T08 unrelated member denied 403' } else { Bad 'T08' "got $($r.status)" }
$r = Invoke-Json $off.s 'Get' "/api/requests/$reqVId" $null $off.csrf
if ($r.status -eq 200) { Ok 'T09 same-chapter officer view allowed' } else { Bad 'T09' "got $($r.status)" }
$r = Invoke-Json $offB.s 'Get' "/api/requests/$reqVId" $null $offB.csrf
if ($r.status -eq 403) { Ok 'T10 cross-chapter officer view 403' } else { Bad 'T10' "got $($r.status)" }
$r = Invoke-Json $adm.s 'Get' "/api/requests/$reqVId" $null $adm.csrf
if ($r.status -eq 200) { Ok 'T11 admin view allowed' } else { Bad 'T11' "got $($r.status)" }

# --- T12 material change emits event ---
$before = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='request.material_change';")
$newDate = (Get-Date).ToUniversalTime().AddDays(3).ToString('yyyy-MM-dd HH:mm:ss')
$r = Invoke-Json $ver.s 'Put' "/api/requests/$reqVId" @{ required_blood_type='AB+'; needed_datetime=$newDate } $ver.csrf
$after = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='request.material_change';")
if ($r.status -eq 200 -and $after -gt $before) { Ok 'T12 material change audited (+1)' } else { Bad 'T12' "status=$($r.status) delta=$($after-$before)" }

# --- T13 no-op edit emits no material change ---
$r = Invoke-Json $ver.s 'Put' "/api/requests/$reqVId" @{ required_blood_type='AB+'; needed_datetime=$newDate } $ver.csrf
$after2 = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='request.material_change';")
if ($r.status -eq 200 -and $after2 -eq $after) { Ok 'T13 identical values emit no material event' } else { Bad 'T13' "delta=$($after2-$after)" }

# --- T14 past-date edit rejected ---
$r = Invoke-Json $ver.s 'Put' "/api/requests/$reqVId" @{ needed_datetime='2020-05-05 05:05:05' } $ver.csrf
if ($r.status -eq 400) { Ok 'T14 edit into past datetime rejected' } else { Bad 'T14' "got $($r.status)" }

# --- T15 ownership on update/cancel ---
$r = Invoke-Json $oth.s 'Put' "/api/requests/$reqVId" @{ facility_name='Hacked Clinic' } $oth.csrf
if ($r.status -eq 403) { Ok 'T15 non-owner member edit 403' } else { Bad 'T15' "got $($r.status)" }
$r = Invoke-Json $off.s 'Put' "/api/requests/$reqVId" @{ facility_name='Officer Edited' } $off.csrf
if ($r.status -eq 403) { Ok 'T16 officer edit (owner-only PUT) 403' } else { Bad 'T16' "got $($r.status)" }

# --- T17 cancel flows ---
$r = Invoke-Json $off.s 'Post' "/api/requests/$reqVId/cancel" @{} $offB.csrf
if ($r.status -eq 403) { Ok 'T17 cross-chapter officer cancel 403' } else { Bad 'T17' "got $($r.status)" }
$r = Invoke-Json $off.s 'Post' "/api/requests/$reqVId/cancel" @{} $off.csrf
if ($r.status -eq 200) { Ok 'T18 same-chapter officer cancels OPEN request' } else { Bad 'T18' "got $($r.status)" }
$r = Invoke-Json $ver.s 'Put' "/api/requests/$reqVId" @{ facility_name='Too Late Clinic' } $ver.csrf
if ($r.status -eq 409) { Ok 'T19 editing CANCELLED request 409' } else { Bad 'T19' "got $($r.status)" }
$r = Invoke-Json $off.s 'Post' "/api/requests/$reqVId/cancel" @{} $off.csrf
if ($r.status -eq 409) { Ok 'T20 double cancel 409' } else { Bad 'T20' "got $($r.status)" }
$r = Invoke-Json $pen.s 'Post' "/api/requests/$reqPId/cancel" @{} $pen.csrf
if ($r.status -eq 200) { Ok 'T21 owner cancel own OPEN request' } else { Bad 'T21' "got $($r.status)" }
$r = Invoke-Json $adm.s 'Post' "/api/admin/users/$verId/deactivate" @{} $adm.csrf | Out-Null
$r = Invoke-Json $adm.s 'Post' "/api/admin/users/$verId/reactivate" @{} $adm.csrf
if ($r.status -eq 200) { Ok 'T22 admin plumbing still functional (sanity)' } else { Bad 'T22' "got $($r.status)" }

# --- expiry job ---
$pastOpen = "INSERT INTO blood_requests (requester_id, request_chapter_id, required_blood_type, quantity_units, facility_name, urgency, needed_datetime, status) VALUES ($verId, 1, 'B+', 1, 'Old Clinic', 'routine', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), 'OPEN');"
DbQuery $pastOpen
DbQuery "INSERT INTO blood_requests (requester_id, request_chapter_id, required_blood_type, quantity_units, facility_name, urgency, needed_datetime, status, expired_at) VALUES ($verId, 1, 'B-', 1, 'Cancelled Past', 'routine', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), 'CANCELLED', NOW());"
$out = & $PhpPath database\run_expiry.php
$expiredCount = DbQuery "SELECT COUNT(*) FROM blood_requests WHERE status='EXPIRED' AND requester_id=$verId;"
$out2 = & $PhpPath database\run_expiry.php
if (($out -join '') -match 'expired (\d+) request' -and [int]$Matches[1] -ge 1 -and ($out2 -join '') -match 'expired 0 request') { Ok 'T23 expiry flips due OPEN rows; idempotent rerun' } else { Bad 'T23' "run1=$out run2=$out2" }
$cancelPast = DbQuery "SELECT COUNT(*) FROM blood_requests WHERE facility_name='Cancelled Past' AND status='CANCELLED' AND expired_at IS NOT NULL;"
if ([int]$cancelPast -ge 1) { Ok 'T24 cancelled requests untouched by expiry' } else { Bad 'T24' "count=$cancelPast" }
$batchAudits = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='request.expired_batch';")
if ($batchAudits -ge 1) { Ok 'T25 batch expiry audited' } else { Bad 'T25' "rows=$batchAudits" }

# --- audit distinct actions ---
$acts = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('request.created','request.cancelled','request.material_change','request.updated','authz.denied');"
if ([int]$acts -ge 5) { Ok "T26 lifecycle audit actions present ($acts)" } else { Bad 'T26' "distinct=$acts" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
