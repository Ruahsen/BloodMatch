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
        if ($null -ne $body) {
            $bodyJson = ($body | ConvertTo-Json -Depth 5)
            $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method $method -Body $bodyJson -ContentType 'application/json' -Headers $headers -WebSession $session -TimeoutSec 15 -UseBasicParsing
        } else {
            $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method $method -Headers $headers -WebSession $session -TimeoutSec 15 -UseBasicParsing
        }
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json); raw = $res.Content; headers = $res.Headers }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $status = [int]$resp.StatusCode
        try {
            $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
            $raw = $reader.ReadToEnd()
        } catch { $raw = '' }
        try { $parsed = $raw | ConvertFrom-Json } catch { $parsed = $null }
        $hdrs = @{}
        if ($resp.Headers) {
            foreach ($k in $resp.Headers.Keys) { $hdrs[$k] = $resp.Headers[$k] }
        }
        return @{ status = $status; body = $parsed; raw = $raw; headers = $hdrs }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

function New-FixtureUser($email, $name, $role, $chapterId, $vs, $bloodType, $lat, $lng, $enrolled, $avail) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'; if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    $btCols = 'NULL';  if ($null -ne $bloodType) { $btCols = "'$bloodType'" }
    $srcCols = 'NULL'; if ($null -ne $bloodType) { $srcCols = "'self_reported'" }
    $latSql = 'NULL';  if ($null -ne $lat) { $latSql = "$lat" }
    $lngSql = 'NULL';  if ($null -ne $lng) { $lngSql = "$lng" }
    $enrSql = 'NULL';  if ($enrolled) { $enrSql = 'UTC_TIMESTAMP()' }
    $avSql = 'NULL';   if ($null -ne $avail) { $avSql = "'$avail'" }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status, blood_type, blood_type_source, latitude, longitude, donor_enrolled_at, donor_availability, date_of_birth)
             VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', 'active', $btCols, $srcCols, $latSql, $lngSql, $enrSql, $avSql, '1995-06-15');"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
}

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status)) raw: $($r.raw)" }
    return @{ s = $s; csrf = $csrf; loginResponse = $r }
}

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host "== Phase 16 Security & Performance Hardening Tests =="

# -------------------------------------------
# SECTION A -- Session Hardening & Cookie Deletion (SEC-MED-01)
# -------------------------------------------
Write-Host "`n--- A: Session Hardening & Cookie Deletion (SEC-MED-01) ---"

$userEmail = "p16sec$suffix@test.local"
$userId = New-FixtureUser $userEmail 'Security User' 'member' 1 'verified' 'O+' 14.80 120.53 $true 'available'

# T01: Login returns valid session cookie
$userAuth = Login $userEmail
$cookies = $userAuth.s.Cookies.GetCookies((New-Object System.Uri("$BaseUrl")))
$sessionCookie = $cookies | Where-Object { $_.Name -eq 'bloodmatch_session' }
if ($null -ne $sessionCookie -and $sessionCookie.Value.Length -gt 0) {
    Ok 'T01 login establishes bloodmatch_session cookie'
} else {
    Bad 'T01 login session cookie' 'cookie missing or empty'
}

# T02: Authenticated request works
$rMe = Invoke-Json $userAuth.s 'Get' '/api/auth/me' $null $null
if ($rMe.status -eq 200 -and $rMe.body.data.user.email -eq $userEmail) {
    Ok 'T02 authenticated call /api/auth/me works'
} else {
    Bad 'T02 /api/auth/me' "status=$($rMe.status)"
}

# T03: Logout call succeeds
$rLogout = Invoke-Json $userAuth.s 'Post' '/api/logout' $null $userAuth.csrf
if ($rLogout.status -eq 200) {
    Ok 'T03 POST /api/logout -> 200'
} else {
    Bad 'T03 POST /api/logout' "status=$($rLogout.status)"
}

# T04: Session cookie is destroyed on server (subsequent request returns 401)
$rMeAfter = Invoke-Json $userAuth.s 'Get' '/api/auth/me' $null $null
if ($rMeAfter.status -eq 401) {
    Ok 'T04 post-logout request rejected with 401'
} else {
    Bad 'T04 post-logout request' "status=$($rMeAfter.status)"
}

# -------------------------------------------
# SECTION B -- Security Headers (SEC-LOW-01)
# -------------------------------------------
Write-Host "`n--- B: Security Headers (SEC-LOW-01) ---"

$rHealth = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' '/api/health' $null $null
$hdrs = $rHealth.headers

# T05: X-Content-Type-Options: nosniff
if ($hdrs['X-Content-Type-Options'] -eq 'nosniff') {
    Ok 'T05 X-Content-Type-Options: nosniff present'
} else {
    Bad 'T05 X-Content-Type-Options' "got $($hdrs['X-Content-Type-Options'])"
}

# T06: X-Frame-Options: DENY
if ($hdrs['X-Frame-Options'] -eq 'DENY') {
    Ok 'T06 X-Frame-Options: DENY present'
} else {
    Bad 'T06 X-Frame-Options' "got $($hdrs['X-Frame-Options'])"
}

# T07: Content-Security-Policy present and contains default-src 'self'
$csp = $hdrs['Content-Security-Policy']
if ($null -ne $csp -and $csp -match "default-src 'self'") {
    Ok "T07 Content-Security-Policy present ($csp)"
} else {
    Bad 'T07 Content-Security-Policy' "got $csp"
}

# T08: Permissions-Policy present
$pp = $hdrs['Permissions-Policy']
if ($null -ne $pp -and $pp -match 'geolocation') {
    Ok "T08 Permissions-Policy present ($pp)"
} else {
    Bad 'T08 Permissions-Policy' "got $pp"
}

# -------------------------------------------
# SECTION C -- Mutation Rate Limiting (SEC-LOW-02)
# -------------------------------------------
Write-Host "`n--- C: Mutation Rate Limiting (SEC-LOW-02) ---"

$floodUserEmail = "p16flood$suffix@test.local"
$floodUserId = New-FixtureUser $floodUserEmail 'Flood User' 'member' 1 'verified' 'A+' 14.80 120.53 $false $null
$floodAuth = Login $floodUserEmail

# T09: Blood request creation rate limiting (10 allowed, 11th blocked with 429)
$reqCreatedCount = 0
$reqRateLimited = $false
for ($i = 1; $i -le 12; $i++) {
    $r = Invoke-Json $floodAuth.s 'Post' '/api/requests' @{
        required_blood_type = 'A+'
        quantity_units = 1
        facility_name = "Facility $i"
        urgency = 'routine'
        needed_datetime = $future
        latitude = 14.80
        longitude = 120.53
    } $floodAuth.csrf
    if ($r.status -eq 201) {
        $reqCreatedCount++
    } elseif ($r.status -eq 429) {
        $reqRateLimited = $true
        break
    }
}

if ($reqRateLimited -and $reqCreatedCount -ge 8) {
    Ok "T09 blood request creation rate limit enforced (created=$reqCreatedCount, got 429)"
} else {
    Bad 'T09 blood request rate limit' "created=$reqCreatedCount, rateLimited=$reqRateLimited"
}

# -------------------------------------------
# SECTION D -- IDOR & Authorization Hardening Probes
# -------------------------------------------
Write-Host "`n--- D: IDOR & Authorization Probes ---"

$user1Email = "p16u1$suffix@test.local"
$user1Id = New-FixtureUser $user1Email 'User One' 'member' 1 'verified' 'B+' 14.80 120.53 $false $null
$user1Auth = Login $user1Email

$user2Email = "p16u2$suffix@test.local"
$user2Id = New-FixtureUser $user2Email 'User Two' 'member' 2 'verified' 'O+' 14.43 120.48 $false $null
$user2Auth = Login $user2Email

# Create a request owned by user 1
$rReq = Invoke-Json $user1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'B+'
    quantity_units = 1
    facility_name = 'User 1 Clinic'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $user1Auth.csrf
$reqId = $rReq.body.data.request.id

# T10: User 2 cannot cancel User 1's request -> 403
$rCancel = Invoke-Json $user2Auth.s 'Post' "/api/requests/$reqId/cancel" $null $user2Auth.csrf
if ($rCancel.status -eq 403) {
    Ok 'T10 IDOR probe: foreign user cannot cancel request (403)'
} else {
    Bad 'T10 IDOR cancel' "got $($rCancel.status)"
}

# T11: User 2 cannot update User 1's request -> 403
$rUpdate = Invoke-Json $user2Auth.s 'Put' "/api/requests/$reqId" @{ facility_name = 'Hacked Facility' } $user2Auth.csrf
if ($rUpdate.status -eq 403) {
    Ok 'T11 IDOR probe: foreign user cannot update request (403)'
} else {
    Bad 'T11 IDOR update' "got $($rUpdate.status)"
}

# -------------------------------------------
# SECTION E -- Database Indexes & Integrity (PERF-01)
# -------------------------------------------
Write-Host "`n--- E: Database Indexes & Integrity (PERF-01) ---"

# T12: Check composite index on blood_requests
$idxBreq = DbQuery "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='blood_requests' AND INDEX_NAME='idx_breq_chapter_status_created' LIMIT 1;"
if ($idxBreq -eq 'idx_breq_chapter_status_created') {
    Ok 'T12 composite index idx_breq_chapter_status_created exists on blood_requests'
} else {
    Bad 'T12 index on blood_requests' "got '$idxBreq'"
}

# T13: Check composite index on audit_log
$idxAudit = DbQuery "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='audit_log' AND INDEX_NAME='idx_audit_target_created' LIMIT 1;"
if ($idxAudit -eq 'idx_audit_target_created') {
    Ok 'T13 composite index idx_audit_target_created exists on audit_log'
} else {
    Bad 'T13 index on audit_log' "got '$idxAudit'"
}

# T14: Append-only trigger protects audit_log against UPDATE
$sampleId = DbQuery "SELECT id FROM audit_log ORDER BY id DESC LIMIT 1;"
$updatePhp = "try { `$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=bloodmatch_dev', 'root', ''); `$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); `$pdo->exec('UPDATE audit_log SET action=\'hacked\' WHERE id=$sampleId'); echo 'ALLOWED'; } catch (PDOException `$e) { echo 'BLOCKED: ' . `$e->getMessage(); }"
$outUpdate = & $PhpPath -r $updatePhp
if ($outUpdate -match '45000' -or $outUpdate -match 'append-only: UPDATE denied') {
    Ok 'T14 audit_log UPDATE blocked by database trigger (SQLSTATE 45000)'
} else {
    Bad 'T14 audit_log UPDATE' "got $outUpdate"
}

# T15: Append-only trigger protects audit_log against DELETE
$deletePhp = "try { `$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=bloodmatch_dev', 'root', ''); `$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); `$pdo->exec('DELETE FROM audit_log WHERE id=$sampleId'); echo 'ALLOWED'; } catch (PDOException `$e) { echo 'BLOCKED: ' . `$e->getMessage(); }"
$outDelete = & $PhpPath -r $deletePhp
if ($outDelete -match '45000' -or $outDelete -match 'append-only: DELETE denied') {
    Ok 'T15 audit_log DELETE blocked by database trigger (SQLSTATE 45000)'
} else {
    Bad 'T15 audit_log DELETE' "got $outDelete"
}

Write-Host "`n== Phase 16 Security Suite complete: $script:pass passed, $script:fail failed =="
if ($script:fail -gt 0) { exit 1 } else { exit 0 }
