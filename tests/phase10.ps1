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
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $csrf }
}

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host "== Phase 10 Notifications and Email Tests =="

# -------------------------------------------
# SECTION A -- Structural assertions
# -------------------------------------------
Write-Host "`n--- A: Structural ---"

# T01: notifications table exists
$cols = DbQuery "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='notifications' ORDER BY ORDINAL_POSITION;"
$expected = @('id','user_id','type','title','body','related_type','related_id','dedup_key','generation','emailed_at','read_at','created_at')
$allPresent = $true
foreach ($c in $expected) {
    if ($cols -notcontains $c) { $allPresent = $false; break }
}
if ($allPresent) { Ok 'T01 notifications table columns' } else { Bad 'T01 notifications table columns' "missing columns: expected $($expected -join ','), got $($cols -join ',')" }

# T02: UNIQUE(dedup_key, generation) index exists
$idx = DbQuery "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='notifications' AND INDEX_NAME='uq_notifications_dedup';"
if ($idx) { Ok 'T02 dedup unique index exists' } else { Bad 'T02 dedup unique index exists' 'index uq_notifications_dedup not found' }

# T03: FK to users exists
$fk = DbQuery "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='notifications' AND COLUMN_NAME='user_id' AND REFERENCED_TABLE_NAME='users';"
if ($fk) { Ok 'T03 FK notifications.user_id -> users.id' } else { Bad 'T03 FK notifications.user_id -> users.id' 'missing FK' }

# -------------------------------------------
# SECTION B -- Fixtures
# -------------------------------------------
Write-Host "`n--- B: Setup fixtures ---"

$admEmail = "p10adm$suffix@test.local"
New-FixtureUser $admEmail 'Admin Ten' 'admin' $null 'verified' $null $null $null $false $null | Out-Null

$offEmail = "p10off$suffix@test.local"
New-FixtureUser $offEmail 'Officer Ten' 'officer' 1 'verified' $null $null $null $false $null | Out-Null

$memEmail = "p10mem$suffix@test.local"
$memId = New-FixtureUser $memEmail 'Member Ten' 'member' 1 'verified' 'A+' 14.68 120.54 $false $null

$mem2Email = "p10mem2$suffix@test.local"
$mem2Id = New-FixtureUser $mem2Email 'Member Ten B' 'member' 1 'pending' 'A+' 14.681 120.541 $false $null

$donorEmail = "p10donor$suffix@test.local"
$donorId = New-FixtureUser $donorEmail 'Donor Ten' 'member' 1 'verified' 'O-' 14.682 120.542 $true 'available'

Write-Host "Fixtures created: admin=$admEmail, officer=$offEmail, member=$memEmail, member2=$mem2Email, donor=$donorEmail"

# -------------------------------------------
# SECTION C -- Access control
# -------------------------------------------
Write-Host "`n--- C: Access control ---"

# T04: Anonymous -> notification list -> 401
$anonS = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-Json $anonS 'Get' '/api/notifications' $null $null
if ($r.status -eq 401) { Ok 'T04 anon list -> 401' } else { Bad 'T04 anon list -> 401' "got $($r.status)" }

# T05: Anonymous -> unread count -> 401
$r = Invoke-Json $anonS 'Get' '/api/notifications/unread-count' $null $null
if ($r.status -eq 401) { Ok 'T05 anon unread-count -> 401' } else { Bad 'T05 anon unread-count -> 401' "got $($r.status)" }

# T06: Anonymous -> mark read -> 401/403 (CSRF first)
$r = Invoke-Json $anonS 'Post' '/api/notifications/1/read' $null $null
if ($r.status -eq 401 -or $r.status -eq 403) { Ok 'T06 anon mark-read -> blocked' } else { Bad 'T06 anon mark-read -> blocked' "got $($r.status)" }

# -------------------------------------------
# SECTION D -- Event wiring: match generation -> notifications
# -------------------------------------------
Write-Host "`n--- D: Match-generation notifications ---"

# Clear existing notifications for donor
DbQuery "DELETE FROM notifications WHERE user_id=$donorId;" 2>$null

# Member creates a request -> auto-match -> donor notified
$memAuth = Login $memEmail
$r = Invoke-Json $memAuth.s 'Post' '/api/requests' @{
    required_blood_type = 'O-'
    quantity_units = 1
    facility_name = 'Phase 10 Hospital'
    urgency = 'urgent'
    needed_datetime = $future
    latitude = 14.683
    longitude = 120.543
} $memAuth.csrf

if ($r.status -eq 201) { Ok 'T07 request created -> matching ran' } else { Bad 'T07 request created' "status $($r.status)" }
$requestId = $r.body.data.request.id

# T08: Donor should have a match.new notification
$donorAuth = Login $donorEmail
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?page_size=50' $null $null
$matchNotifs = @($r.body.data.notifications | Where-Object { $_.type -eq 'match.new' })
if ($matchNotifs.Count -ge 1) { Ok 'T08 donor received match.new notification' } else { Bad 'T08 donor received match.new notification' "found $($matchNotifs.Count)" }

# T09: Match notification has correct related_type/related_id
$mn = $matchNotifs[0]
if ($mn.related_type -eq 'blood_request' -and $mn.related_id -eq $requestId) {
    Ok 'T09 match notification related_type=blood_request, related_id=request'
} else {
    Bad 'T09 match notification related fields' "type=$($mn.related_type) id=$($mn.related_id) expected blood_request/$requestId"
}

# T10: Unread count for donor > 0
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications/unread-count' $null $null
if ($r.body.data.unread_count -gt 0) { Ok 'T10 donor unread_count > 0' } else { Bad 'T10 donor unread_count' "got $($r.body.data.unread_count)" }

# -------------------------------------------
# SECTION E -- Deduplication
# -------------------------------------------
Write-Host "`n--- E: Deduplication ---"

# T11: Re-match same request -> same generation -> no duplicate notification
$notifsBefore = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donorId AND type='match.new';"
$offAuth = Login $offEmail
$r = Invoke-Json $offAuth.s 'Post' "/api/officer/requests/$requestId/re-match" @{} $offAuth.csrf
$notifsAfter = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donorId AND type='match.new';"
# Re-match without generation bump should NOT create a new row (INSERT IGNORE on same dedup_key+generation)
if ([int]$notifsAfter -eq [int]$notifsBefore) {
    Ok 'T11 re-match same gen: no duplicate notification'
} else {
    Bad 'T11 re-match same gen: no duplicate' "before=$notifsBefore after=$notifsAfter"
}

# T12: Non-NULL generation on all notifications (NULL generation dedup trap)
$nullGens = DbQuery "SELECT COUNT(*) FROM notifications WHERE generation IS NULL;"
if ([int]$nullGens -eq 0) { Ok 'T12 no NULL generation rows' } else { Bad 'T12 NULL generation rows exist' "count=$nullGens" }

# -------------------------------------------
# SECTION F -- Notification API: mark read / mark all
# -------------------------------------------
Write-Host "`n--- F: Mark read ---"

# T13: Mark single notification read
$notifId = $null
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?read=unread&page_size=1' $null $null
if ($r.body.data.notifications.Count -ge 1) {
    $notifId = $r.body.data.notifications[0].id
    $r2 = Invoke-Json $donorAuth.s 'Post' "/api/notifications/$notifId/read" @{} $donorAuth.csrf
    if ($r2.status -eq 200) { Ok 'T13 mark single read -> 200' } else { Bad 'T13 mark single read' "status $($r2.status)" }
} else {
    Bad 'T13 mark single read' 'no unread notifications to test'
}

# T14: Mark-read idempotent (second call on same notification still 200)
if ($notifId) {
    $r = Invoke-Json $donorAuth.s 'Post' "/api/notifications/$notifId/read" @{} $donorAuth.csrf
    if ($r.status -eq 200) { Ok 'T14 mark-read idempotent' } else { Bad 'T14 mark-read idempotent' "status $($r.status)" }
}

# T15: Cross-user mark-read -> 404 (no existence leak)
$memAuth2 = Login $memEmail
if ($notifId) {
    $r = Invoke-Json $memAuth2.s 'Post' "/api/notifications/$notifId/read" @{} $memAuth2.csrf
    if ($r.status -eq 404) { Ok 'T15 cross-user mark-read -> 404' } else { Bad 'T15 cross-user mark-read' "status $($r.status)" }
} else {
    Bad 'T15 cross-user mark-read' 'no notifId from T13'
}

# T16: Mark all read
$r = Invoke-Json $donorAuth.s 'Post' '/api/notifications/read-all' @{} $donorAuth.csrf
if ($r.status -eq 200) { Ok 'T16 mark-all-read -> 200' } else { Bad 'T16 mark-all-read' "status $($r.status)" }

# T17: Unread count now 0
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications/unread-count' $null $null
if ($r.body.data.unread_count -eq 0) { Ok 'T17 unread count 0 after mark-all' } else { Bad 'T17 unread count 0' "got $($r.body.data.unread_count)" }

# -------------------------------------------
# SECTION G -- Filter API
# -------------------------------------------
Write-Host "`n--- G: Filters ---"

# T18: Filter by type=match.new
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?type=match.new' $null $null
$allMatch = $true
foreach ($n in $r.body.data.notifications) { if ($n.type -ne 'match.new') { $allMatch = $false; break } }
if ($r.status -eq 200 -and $allMatch) { Ok 'T18 filter type=match.new works' } else { Bad 'T18 filter type=match.new' "status=$($r.status) allMatch=$allMatch" }

# T19: Filter by read=read
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?read=read' $null $null
$allRead = $true
foreach ($n in $r.body.data.notifications) { if ($null -eq $n.read_at) { $allRead = $false; break } }
if ($r.status -eq 200 -and $allRead) { Ok 'T19 filter read=read works' } else { Bad 'T19 filter read=read' "status=$($r.status)" }

# T20: Filter by read=unread (all should have been marked read by now)
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?read=unread' $null $null
if ($r.status -eq 200 -and $r.body.data.total -eq 0) { Ok 'T20 filter read=unread: 0 after mark-all' } else { Bad 'T20 filter read=unread' "total=$($r.body.data.total)" }

# -------------------------------------------
# SECTION H -- Verification decision notification
# -------------------------------------------
Write-Host "`n--- H: Verification-decision notification ---"

# Officer approves mem2 (pending)
DbQuery "DELETE FROM notifications WHERE user_id=$mem2Id;" 2>$null
$offAuth = Login $offEmail
$r = Invoke-Json $offAuth.s 'Post' "/api/officer/verifications/$mem2Id/decision" @{ decision = 'verified' } $offAuth.csrf
if ($r.status -eq 200) { Ok 'T21 verification approved' } else { Bad 'T21 verification approved' "status $($r.status)" }

# T22: Member2 has a verification.decision notification
$mem2Auth = Login $mem2Email
$r = Invoke-Json $mem2Auth.s 'Get' '/api/notifications?type=verification.decision' $null $null
if ($r.body.data.total -ge 1) { Ok 'T22 verification notification received' } else { Bad 'T22 verification notification received' "total=$($r.body.data.total)" }

# T23: Verification notification has dedup_key (check DB)
$dk = DbQuery "SELECT dedup_key FROM notifications WHERE user_id=$mem2Id AND type='verification.decision' ORDER BY id DESC LIMIT 1;"
if ($dk -and $dk.ToString().StartsWith('verification:')) { Ok 'T23 verification dedup_key set' } else { Bad 'T23 verification dedup_key' "got $dk" }

# -------------------------------------------
# SECTION I -- Account deactivation notification
# -------------------------------------------
Write-Host "`n--- I: Account status notification ---"

$admAuth = Login $admEmail

# T24: Deactivate mem2
$r = Invoke-Json $admAuth.s 'Post' "/api/admin/users/$mem2Id/deactivate" @{} $admAuth.csrf
if ($r.status -eq 200) { Ok 'T24 deactivate mem2' } else { Bad 'T24 deactivate mem2' "status $($r.status)" }

# Check notification
$accNotif = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$mem2Id AND type='account.status_changed';"
if ([int]$accNotif -ge 1) { Ok 'T25 account.status_changed notification created' } else { Bad 'T25 account.status_changed notification' "count=$accNotif" }

# T26: Account dedup_key contains audit ID
$accDk = DbQuery "SELECT dedup_key FROM notifications WHERE user_id=$mem2Id AND type='account.status_changed' ORDER BY id DESC LIMIT 1;"
if ($accDk -and $accDk.ToString().StartsWith('account:')) { Ok 'T26 account dedup_key with audit ID' } else { Bad 'T26 account dedup_key' "got $accDk" }

# T27: Reactivate -> creates a DIFFERENT notification (unique dedup_key)
$r = Invoke-Json $admAuth.s 'Post' "/api/admin/users/$mem2Id/reactivate" @{} $admAuth.csrf
$accNotifAfter = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$mem2Id AND type='account.status_changed';"
if ([int]$accNotifAfter -ge 2) { Ok 'T27 reactivation creates separate notification' } else { Bad 'T27 reactivation notification' "count=$accNotifAfter (expected >=2)" }

# -------------------------------------------
# SECTION J -- Request cancel notification
# -------------------------------------------
Write-Host "`n--- J: Request cancel notification ---"

# Create a second request as member, then cancel it
$memAuth = Login $memEmail
$r = Invoke-Json $memAuth.s 'Post' '/api/requests' @{
    required_blood_type = 'A+'
    quantity_units = 1
    facility_name = 'Cancel Hospital'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.68
    longitude = 120.54
} $memAuth.csrf
$cancelReqId = $r.body.data.request.id

$r = Invoke-Json $memAuth.s 'Post' "/api/requests/$cancelReqId/cancel" @{} $memAuth.csrf
if ($r.status -eq 200) { Ok 'T28 request cancelled' } else { Bad 'T28 request cancelled' "status $($r.status)" }

# T29: Requester receives request.cancelled notification
$cancelNotif = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$memId AND type='request.cancelled' AND related_id=$cancelReqId;"
if ([int]$cancelNotif -ge 1) { Ok 'T29 request.cancelled notification' } else { Bad 'T29 request.cancelled notification' "count=$cancelNotif" }

# T30: Cancel notification dedup_key format
$cancelDk = DbQuery "SELECT dedup_key FROM notifications WHERE user_id=$memId AND type='request.cancelled' AND related_id=$cancelReqId LIMIT 1;"
$expectedDk = "request:${cancelReqId}:cancelled"
if ($cancelDk -and $cancelDk.ToString().Trim() -eq $expectedDk) { Ok 'T30 cancel dedup_key correct' } else { Bad 'T30 cancel dedup_key' "got=$cancelDk expected=$expectedDk" }

# -------------------------------------------
# SECTION K -- Donation notification
# -------------------------------------------
Write-Host "`n--- K: Donation notification ---"

# Donor responds to match, submits donation report, officer confirms
$matchRow = DbQuery "SELECT id FROM matches WHERE request_id=$requestId AND donor_id=$donorId LIMIT 1;"
if ($matchRow) {
    $matchId = [int]$matchRow

    # Donor responds
    $donorAuth = Login $donorEmail
    $r = Invoke-Json $donorAuth.s 'Post' "/api/matches/$matchId/respond" @{} $donorAuth.csrf
    if ($r.status -eq 200) { Ok 'T31 donor responded to match' } else { Bad 'T31 donor responded' "status $($r.status)" }

    # Donor submits report
    $r = Invoke-Json $donorAuth.s 'Post' '/api/donation-reports' @{ match_id = $matchId; note = 'Phase 10 test donation' } $donorAuth.csrf
    if ($r.status -eq 201) { Ok 'T32 donation report submitted' } else { Bad 'T32 donation report' "status $($r.status)" }
    $reportId = $r.body.data.report.id

    # Officer confirms
    DbQuery "DELETE FROM notifications WHERE user_id=$donorId AND type LIKE 'donation.%';" 2>$null
    $offAuth = Login $offEmail
    $r = Invoke-Json $offAuth.s 'Post' "/api/officer/donation-reports/$reportId/confirm" @{} $offAuth.csrf
    if ($r.status -eq 200) { Ok 'T33 donation confirmed' } else { Bad 'T33 donation confirmed' "status $($r.status)" }

    # T34: Donor received donation.confirmed notification
    $donNotif = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$donorId AND type='donation.confirmed';"
    if ([int]$donNotif -ge 1) { Ok 'T34 donation.confirmed notification' } else { Bad 'T34 donation.confirmed notification' "count=$donNotif" }

    # T35: Donation dedup_key format
    $donDk = DbQuery "SELECT dedup_key FROM notifications WHERE user_id=$donorId AND type='donation.confirmed' ORDER BY id DESC LIMIT 1;"
    $expectedDonDk = "donation:${reportId}:confirmed"
    if ($donDk -and $donDk.ToString().Trim() -eq $expectedDonDk) { Ok 'T35 donation dedup_key correct' } else { Bad 'T35 donation dedup_key' "got=$donDk expected=$expectedDonDk" }
} else {
    Bad 'T31-T35 donation flow' 'no match found for donor -> request'
}

# -------------------------------------------
# SECTION L -- Expiry notification
# -------------------------------------------
Write-Host "`n--- L: Expiry notification ---"

# Create a request with past needed_datetime via DB
$pastDt = (Get-Date).ToUniversalTime().AddHours(-1).ToString('yyyy-MM-dd HH:mm:ss')
DbQuery "INSERT INTO blood_requests (requester_id, request_chapter_id, required_blood_type, quantity_units, facility_name, urgency, needed_datetime, status, review_status) VALUES ($memId, 1, 'A+', 1, 'Expired Hospital', 'routine', '$pastDt', 'OPEN', 'not_required');"
$expReqId = DbQuery "SELECT id FROM blood_requests WHERE facility_name='Expired Hospital' AND requester_id=$memId ORDER BY id DESC LIMIT 1;"

# Run expiry CLI
$projectRoot = (Get-Item $PSScriptRoot).Parent.FullName
& $PhpPath "$projectRoot/database/run_expiry.php" 2>$null

# T36: Expiry notification created
$expNotif = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$memId AND type='request.expired' AND related_id=$expReqId;"
if ([int]$expNotif -ge 1) { Ok 'T36 request.expired notification' } else { Bad 'T36 request.expired notification' "count=$expNotif" }

# T37: Expiry dedup_key format
$expDk = DbQuery "SELECT dedup_key FROM notifications WHERE user_id=$memId AND type='request.expired' AND related_id=$expReqId LIMIT 1;"
$expectedExpDk = "request:${expReqId}:expired"
if ($expDk -and $expDk.ToString().Trim() -eq $expectedExpDk) { Ok 'T37 expiry dedup_key correct' } else { Bad 'T37 expiry dedup_key' "got=$expDk expected=$expectedExpDk" }

# -------------------------------------------
# SECTION M -- Pagination
# -------------------------------------------
Write-Host "`n--- M: Pagination ---"

# T38: Pagination metadata present
$donorAuth = Login $donorEmail
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?page=1&page_size=2' $null $null
if ($r.status -eq 200 -and $null -ne $r.body.data.total -and $null -ne $r.body.data.notifications) {
    Ok 'T38 pagination: total and notifications present'
} else {
    Bad 'T38 pagination fields' "status=$($r.status)"
}

# T39: Page size respected
$returned = $r.body.data.notifications.Count
if ($returned -le 2) { Ok 'T39 page_size respected' } else { Bad 'T39 page_size' "returned $returned, expected <=2" }

# -------------------------------------------
# SECTION N -- Notification shapes
# -------------------------------------------
Write-Host "`n--- N: Response shape ---"

# T40: Notification JSON has required fields
$r = Invoke-Json $donorAuth.s 'Get' '/api/notifications?page_size=1' $null $null
if ($r.body.data.notifications.Count -ge 1) {
    $n = $r.body.data.notifications[0]
    $hasFields = ($null -ne $n.id) -and ($null -ne $n.type) -and ($null -ne $n.title) -and ($null -ne $n.body) -and ($null -ne $n.created_at)
    if ($hasFields) { Ok 'T40 notification JSON shape' } else { Bad 'T40 notification JSON shape' 'missing required fields' }
} else {
    Bad 'T40 notification JSON shape' 'no notifications returned'
}

# T41: No sensitive fields exposed (password_hash, etc)
$raw = $r.raw
if ($raw -notmatch 'password_hash' -and $raw -notmatch 'token_hash' -and $raw -notmatch 'dedup_key') {
    Ok 'T41 no sensitive fields in notification response'
} else {
    Bad 'T41 sensitive field exposure' 'found password_hash or token_hash or dedup_key in response'
}

# -------------------------------------------
# SECTION O -- Email configuration
# -------------------------------------------
Write-Host "`n--- O: Email config ---"

# T42: .env.example includes SMTP variables
$envExample = Get-Content "$projectRoot/.env.example" -Raw
if ($envExample -match 'MAIL_HOST=' -and $envExample -match 'MAIL_PORT=' -and $envExample -match 'MAIL_FROM=') {
    Ok 'T42 .env.example includes SMTP vars'
} else {
    Bad 'T42 .env.example SMTP vars' 'missing MAIL_HOST/MAIL_PORT/MAIL_FROM'
}

# -------------------------------------------
# SECTION P -- AuditLogger returns ID
# -------------------------------------------
Write-Host "`n--- P: AuditLogger ---"

# T43: Audit log entries have auto-incrementing IDs (verify recent entries exist)
$auditCount = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'notification%' OR action LIKE 'verification%' OR action LIKE 'admin.user%' OR action LIKE 'request.%';"
if ([int]$auditCount -gt 0) { Ok 'T43 audit_log entries created during test' } else { Bad 'T43 audit_log entries' "count=$auditCount" }

# -------------------------------------------
# SUMMARY
# -------------------------------------------
Write-Host "`n== Phase 10 complete: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
