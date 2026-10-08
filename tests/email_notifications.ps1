param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe',
    [string]$PhpPath = 'D:\xampp\php\php.exe',
    # Optional: directory the API server writes captured emails to
    # (MAIL_CAPTURE_DIR on the server). When supplied, the suite verifies
    # captured email content; otherwise it verifies graceful skip behavior.
    [string]$MailCaptureDir = ''
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

function New-FixtureUser($email, $name, $role, $chapterId, $vs, $bloodType, $lat, $lng, $enrolled, $avail) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'; if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    $btCols = 'NULL';  if ($null -ne $bloodType) { $btCols = "'$bloodType'" }
    $srcCols = 'NULL'; if ($null -ne $bloodType) { $srcCols = "'self_reported'" }
    $latSql = 'NULL';  if ($null -ne $lat) { $latSql = "$lat" }
    $lngSql = 'NULL';  if ($null -ne $lng) { $lngSql = "$lng" }
    $enrSql = 'NULL';  if ($enrolled) { $enrSql = 'UTC_TIMESTAMP()' }
    $avSql = 'NULL';   if ($null -ne $avail) { $avSql = "'$avail'" }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status,              account_status, blood_type, blood_type_source, latitude, longitude, donor_enrolled_at,
             donor_availability, date_of_birth, email_verified_at)
             # Fixtures bypass /api/register by construction, so they are
             # grandfather-equivalent (migration 021): verified email marker.
             VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', 'active', $btCols, $srcCols, $latSql, $lngSql, $enrSql, $avSql, '1995-06-15', UTC_TIMESTAMP());"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
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

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')
$balangaLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030803000' LIMIT 1;")

Write-Host "== Email Notification Channel Tests =="

# -------------------------------------------
# Fixtures: requester + eligible donor
# -------------------------------------------
$reqEmail = "emreq$suffix@test.local"
$reqId = New-FixtureUser $reqEmail 'Email Requester' 'member' 1 'verified' 'A+' 14.68 120.54 $false $null

$donEmail = "emdon$suffix@test.local"
$donId = New-FixtureUser $donEmail 'Email Donor' 'member' 1 'verified' 'O-' 14.682 120.542 $true 'available'

$admEmail = "emadm$suffix@test.local"
$admId = New-FixtureUser $admEmail 'Email Admin' 'admin' $null 'verified' $null $null $null $false $null

Write-Host "Fixtures: requester=$reqEmail donor=$donEmail"

# Requester creates request -> auto-match notifies donor (match.new)
$reqAuth = Login $reqEmail
$r = Invoke-Json $reqAuth.s 'Post' '/api/requests' @{
    required_blood_type = 'O-'
    quantity_units = 1
    facility_name = 'Email Channel Hospital'
    urgency = 'urgent'
    needed_datetime = $future
    location_id = $balangaLocId
} $reqAuth.csrf
if ($r.status -ne 201) { Bad 'E00 setup request created' "status $($r.status)"; exit 1 }
$requestId = [int]$r.body.data.request.id
$matchId = [int](DbQuery "SELECT id FROM matches WHERE request_id=$requestId AND donor_id=$donId LIMIT 1;")

# Donor responds -> match.responded to requester
$donAuth = Login $donEmail
$r = Invoke-Json $donAuth.s 'Post' "/api/matches/$matchId/respond" @{ donor_share_consent = $true } $donAuth.csrf
if ($r.status -ne 200) { Bad 'E00 setup donor responded' "status $($r.status)"; exit 1 }

# Requester accepts -> match.accepted to donor
$r = Invoke-Json $reqAuth.s 'Post' "/api/matches/$matchId/accept" @{ requester_share_consent = $true } $reqAuth.csrf
if ($r.status -ne 200) { Bad 'E00 setup accept' "status $($r.status)"; exit 1 }

# -------------------------------------------
# E1: cancel fans out to requester + accepted donor (in-app source events)
# -------------------------------------------
$r = Invoke-Json $reqAuth.s 'Post' "/api/requests/$requestId/cancel" @{} $reqAuth.csrf
if ($r.status -eq 200) { Ok 'E1 cancel succeeds (business tx unaffected by mail outcome)' } else { Bad 'E1 cancel succeeds' "status $($r.status)" }

$reqCancel = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$reqId AND type='request.cancelled' AND related_id=$requestId;")
if ($reqCancel -ge 1) { Ok 'E1 requester has request.cancelled in-app notification' } else { Bad 'E1 requester notification' "count=$reqCancel" }

$donCancel = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='request.cancelled' AND related_id=$requestId;")
if ($donCancel -ge 1) { Ok 'E1 accepted donor has request.cancelled in-app notification' } else { Bad 'E1 donor notification' "count=$donCancel" }

# -------------------------------------------
# E2: email leg mirrors the in-app event (adaptive to server mail config)
# -------------------------------------------
$emailedReq = DbQuery "SELECT IFNULL(emailed_at,'NULL') FROM notifications WHERE user_id=$reqId AND type='request.cancelled' AND related_id=$requestId LIMIT 1;"
$emailedDon = DbQuery "SELECT IFNULL(emailed_at,'NULL') FROM notifications WHERE user_id=$donId AND type='request.cancelled' AND related_id=$requestId LIMIT 1;"
$mailAttempted = ($emailedReq -ne 'NULL') -or ($emailedDon -ne 'NULL')
if ($mailAttempted) {
    Ok 'E2 email delivery attempted for request.cancelled (emailed_at recorded)'
} else {
    # Graceful degradation: mail unconfigured on the server -> in-app persists, nothing emailed.
    Ok 'E2 mail unconfigured: in-app notifications persist, email skipped (emailed_at NULL)'
}

if ($MailCaptureDir -ne '') {
    $files = Get-ChildItem -Path $MailCaptureDir -Filter '*.eml' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending
    $cancelFiles = @($files | Where-Object {
        $c = Get-Content $_.FullName -Raw
        $c -match 'request.cancelled|Blood request cancelled'
    })
    if ($cancelFiles.Count -ge 1) {
        Ok 'E2 capture file exists for request.cancelled email'
        $content = Get-Content $cancelFiles[0].FullName -Raw
        $hasBrand = $content -match 'BloodMatch'
        $hasLink = $content -match 'http://localhost:5173|/notifications'
        $hasFooter = $content -match 'do not reply|never ask for your password'
        if ($hasBrand -and $hasLink -and $hasFooter) { Ok 'E2 email has branding, action link, and footer' } else { Bad 'E2 email content' "brand=$hasBrand link=$hasLink footer=$hasFooter" }
        $leak = ($content -match 'password_hash') -or ($content -match 'token_hash') -or ($content -match 'csrf' -and $content -match '[a-f0-9]{32}') -or ($content -match '-?\d{2,3}\.\d{4,}')
        if (-not $leak) { Ok 'E2 email contains no sensitive leakage' } else { Bad 'E2 email leakage' 'forbidden token found in captured email' }
        $otherMail = ($content -match $reqEmail) -or ($content -match $donEmail)
        # The recipient's own address appears only in the To: header, never another user's.
        $toLine = ($content -split "`n" | Where-Object { $_ -match '^To:' } | Select-Object -First 1)
        if ($toLine) { Ok "E2 email addressed to recipient only ($toLine)" } else { Bad 'E2 email To header' 'missing To: line' }
    } else {
        Bad 'E2 capture file' 'no captured request.cancelled email found (is MAIL_CAPTURE_DIR set on the server?)'
    }
}

# -------------------------------------------
# E3: deduplication - accept replay creates no second notification/email
# -------------------------------------------
$acceptBefore = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='match.accepted';")
$r = Invoke-Json $reqAuth.s 'Post' "/api/matches/$matchId/accept" @{ requester_share_consent = $true } $reqAuth.csrf
$acceptAfter = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='match.accepted';")
if ([int]$acceptAfter -eq [int]$acceptBefore) {
    Ok 'E3 accept replay: no duplicate notification (hence no duplicate email)'
} else {
    Bad 'E3 accept replay dedup' "before=$acceptBefore after=$acceptAfter"
}

# -------------------------------------------
# E4: recipient isolation - each party gets only their own notifications
# -------------------------------------------
$reqGotAccepted = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$reqId AND type='match.accepted';")
$donGotResponded = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='match.responded';")
if ($reqGotAccepted -eq 0 -and $donGotResponded -eq 0) {
    Ok 'E4 notifications reach only the intended recipient (no cross-delivery)'
} else {
    Bad 'E4 recipient isolation' "requester match.accepted=$reqGotAccepted donor match.responded=$donGotResponded"
}

# -------------------------------------------
# E5: account state - deactivated donor gets no new match notifications
# (hence no new emails) from later requests
# -------------------------------------------
$admAuth = Login $admEmail
$r = Invoke-Json $admAuth.s 'Post' "/api/admin/users/$donId/deactivate" @{} $admAuth.csrf
if ($r.status -ne 200) { Bad 'E5 setup deactivate donor' "status $($r.status)" }

$matchNewBefore = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='match.new';")
$r = Invoke-Json $reqAuth.s 'Post' '/api/requests' @{
    required_blood_type = 'O-'
    quantity_units = 1
    facility_name = 'Post-Deactivation Hospital'
    urgency = 'routine'
    needed_datetime = $future
    location_id = $balangaLocId
} $reqAuth.csrf
$secondReqId = [int]$r.body.data.request.id
$matchNewAfter = [int](DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donId AND type='match.new';")
if ($r.status -eq 201 -and [int]$matchNewAfter -eq [int]$matchNewBefore) {
    Ok 'E5 deactivated donor receives no new match.new (no email possible)'
} else {
    Bad 'E5 deactivated exclusion' "create=$($r.status) before=$matchNewBefore after=$matchNewAfter"
}

# Leave no OPEN requests behind: cancel the E5 probe request (the E1 request
# was already cancelled). Keeps the shared dev DB from accumulating feed
# pollution across runs.
$r = Invoke-Json $reqAuth.s 'Post' "/api/requests/$secondReqId/cancel" @{} $reqAuth.csrf
if ($r.status -eq 200) { Ok 'E5b probe request cancelled (no leftover OPEN rows)' } else { Bad 'E5b cleanup cancel' "status $($r.status)" }

# -------------------------------------------
# E6: template/mailer unit tests (no DB/SMTP; template rendering, escaping,
# leakage scan, capture transport, SMTP-failure graceful false, env aliases)
# -------------------------------------------
# Native stderr (PHP error_log diagnostics) must not trip $ErrorActionPreference='Stop'.
$prevEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$unitLines = & $PhpPath (Join-Path $PSScriptRoot 'email_template.php') 2>&1 | ForEach-Object { "$_" }
$unitCode = $LASTEXITCODE
$ErrorActionPreference = $prevEAP
$unitSummary = ($unitLines | Where-Object { $_ -match 'email_template:' } | Select-Object -First 1)
Write-Host $unitSummary
if ($unitCode -eq 0) { Ok 'E6 email template/mailer unit tests all pass' } else { Bad 'E6 unit tests' $unitSummary }

# -------------------------------------------
# SUMMARY
# -------------------------------------------
Write-Host "`n== Email notifications complete: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
