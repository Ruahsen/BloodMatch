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
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status)) raw: $($r.raw)" }
    return @{ s = $s; csrf = $csrf }
}

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host "== Phase 11 Audit Logging Coverage Completion Tests =="

# -------------------------------------------
# SECTION A -- Schema & Tamper Resistance
# -------------------------------------------
Write-Host "`n--- A: Schema and Tamper Resistance ---"

# T01: audit_log table exists with required columns
$cols = DbQuery "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='bloodmatch_dev' AND TABLE_NAME='audit_log' ORDER BY ORDINAL_POSITION;"
$expected = @('id','actor_id','action','target_type','target_id','context','created_at')
$allPresent = $true
foreach ($c in $expected) {
    if ($cols -notcontains $c) { $allPresent = $false; break }
}
if ($allPresent) { Ok 'T01 audit_log table columns' } else { Bad 'T01 audit_log table columns' "missing columns" }

# T02: Triggers exist
$trigs = DbQuery "SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA='bloodmatch_dev' AND EVENT_OBJECT_TABLE='audit_log';"
if ($trigs -contains 'audit_log_block_update' -and $trigs -contains 'audit_log_block_delete') {
    Ok 'T02 append-only triggers exist'
} else {
    Bad 'T02 append-only triggers' "missing triggers: $trigs"
}

# T03: UPDATE rejected by trigger
$sampleId = DbQuery "SELECT id FROM audit_log ORDER BY id DESC LIMIT 1;"
$updatePhp = "try { `$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=bloodmatch_dev', 'root', ''); `$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); `$pdo->exec('UPDATE audit_log SET action=\'tampered\' WHERE id=$sampleId'); echo 'ALLOWED'; } catch (PDOException `$e) { echo 'BLOCKED: ' . `$e->getMessage(); }"
$updateRes = & $PhpPath -r $updatePhp
if ($updateRes -match '45000' -or $updateRes -match 'append-only: UPDATE denied') {
    Ok 'T03 UPDATE audit_log blocked by trigger'
} else {
    Bad 'T03 UPDATE audit_log blocked' "expected trigger error, got: $updateRes"
}

# T04: DELETE rejected by trigger
$deletePhp = "try { `$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=bloodmatch_dev', 'root', ''); `$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); `$pdo->exec('DELETE FROM audit_log WHERE id=$sampleId'); echo 'ALLOWED'; } catch (PDOException `$e) { echo 'BLOCKED: ' . `$e->getMessage(); }"
$deleteRes = & $PhpPath -r $deletePhp
if ($deleteRes -match '45000' -or $deleteRes -match 'append-only: DELETE denied') {
    Ok 'T04 DELETE audit_log blocked by trigger'
} else {
    Bad 'T04 DELETE audit_log blocked' "expected trigger error, got: $deleteRes"
}

# -------------------------------------------
# SECTION B -- Setup Fixtures
# -------------------------------------------
Write-Host "`n--- B: Setup Fixtures ---"

$admEmail = "p11adm$suffix@test.local"
$admId = New-FixtureUser $admEmail 'Admin Eleven' 'admin' $null 'verified' $null $null $null $false $null

$off1Email = "p11off1$suffix@test.local"
$off1Id = New-FixtureUser $off1Email 'Officer Ch1' 'officer' 1 'verified' $null $null $null $false $null

$off2Email = "p11off2$suffix@test.local"
$off2Id = New-FixtureUser $off2Email 'Officer Ch2' 'officer' 2 'verified' $null $null $null $false $null

$mem1Email = "p11mem1$suffix@test.local"
$mem1Id = New-FixtureUser $mem1Email 'Member Ch1' 'member' 1 'verified' 'A+' 14.80 120.53 $false $null

$mem2Email = "p11mem2$suffix@test.local"
$mem2Id = New-FixtureUser $mem2Email 'Member Ch2' 'member' 2 'verified' 'B+' 14.43 120.48 $false $null

Write-Host "Fixtures created: Admin=$admEmail, Officer1(Ch1)=$off1Email, Officer2(Ch2)=$off2Email, Member1(Ch1)=$mem1Email, Member2(Ch2)=$mem2Email"

# -------------------------------------------
# SECTION C -- Access Control & RBAC
# -------------------------------------------
Write-Host "`n--- C: Access Control ---"

# T05: Unauthenticated -> GET /api/admin/audit-logs -> 401
$anonS = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-Json $anonS 'Get' '/api/admin/audit-logs' $null $null
if ($r.status -eq 401) { Ok 'T05 anon admin audit-logs -> 401' } else { Bad 'T05 anon admin audit-logs' "got $($r.status)" }

# T06: Unauthenticated -> GET /api/officer/audit-logs -> 401
$r = Invoke-Json $anonS 'Get' '/api/officer/audit-logs' $null $null
if ($r.status -eq 401) { Ok 'T06 anon officer audit-logs -> 401' } else { Bad 'T06 anon officer audit-logs' "got $($r.status)" }

# T07: Member -> GET /api/admin/audit-logs -> 403
$mem1Auth = Login $mem1Email
$r = Invoke-Json $mem1Auth.s 'Get' '/api/admin/audit-logs' $null $null
if ($r.status -eq 403) { Ok 'T07 member admin audit-logs -> 403' } else { Bad 'T07 member admin audit-logs' "got $($r.status)" }

# T08: Member -> GET /api/officer/audit-logs -> 403
$r = Invoke-Json $mem1Auth.s 'Get' '/api/officer/audit-logs' $null $null
if ($r.status -eq 403) { Ok 'T08 member officer audit-logs -> 403' } else { Bad 'T08 member officer audit-logs' "got $($r.status)" }

# T09: Officer -> GET /api/admin/audit-logs -> 403
$off1Auth = Login $off1Email
$r = Invoke-Json $off1Auth.s 'Get' '/api/admin/audit-logs' $null $null
if ($r.status -eq 403) { Ok 'T09 officer admin audit-logs -> 403' } else { Bad 'T09 officer admin audit-logs' "got $($r.status)" }

# -------------------------------------------
# SECTION D -- Event Generation & Scoping
# -------------------------------------------
Write-Host "`n--- D: Event Generation & Scoping ---"

# Generate distinct events in Chapter 1 and Chapter 2
$rReq1 = Invoke-Json $mem1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'A+'
    quantity_units = 1
    facility_name = 'Orani Medical Center'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $mem1Auth.csrf
$req1Id = $rReq1.body.data.request.id

$mem2Auth = Login $mem2Email
$rReq2 = Invoke-Json $mem2Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'B+'
    quantity_units = 1
    facility_name = 'Mariveles Hospital'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.43
    longitude = 120.48
} $mem2Auth.csrf
$req2Id = $rReq2.body.data.request.id

# Trigger an unauthenticated failure
$csrfAnon = Get-Csrf $anonS
Invoke-Json $anonS 'Post' '/api/login' @{ email = "nonexistent$suffix@test.local"; password = 'WrongPassword99' } $csrfAnon | Out-Null

# -------------------------------------------
# SECTION E -- Officer Chapter Scoping & Anti-Leak
# -------------------------------------------
Write-Host "`n--- E: Officer Chapter Scoping ---"

# T10: Officer 1 gets 200 on officer audit-logs
$r = Invoke-Json $off1Auth.s 'Get' '/api/officer/audit-logs?page_size=100' $null $null
if ($r.status -eq 200) { Ok 'T10 officer 1 list -> 200' } else { Bad 'T10 officer 1 list' "got $($r.status)" }
$off1Logs = $r.body.data.logs

# T11: Officer 1 sees Chapter 1 request event
$ch1ReqLog = @($off1Logs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req1Id" })
if ($ch1ReqLog.Count -ge 1) { Ok 'T11 officer 1 sees Ch1 request event' } else { Bad 'T11 officer 1 sees Ch1 request' "count=$($ch1ReqLog.Count)" }

# T12: Officer 1 does NOT see Chapter 2 request event
$ch2ReqLog = @($off1Logs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req2Id" })
if ($ch2ReqLog.Count -eq 0) { Ok 'T12 officer 1 does NOT see Ch2 request event' } else { Bad 'T12 officer 1 sees Ch2 request' "leaked $($ch2ReqLog.Count) entries" }

# T13: Officer 1 does NOT see Chapter 2 member events
$ch2MemLog = @($off1Logs | Where-Object { $_.target_type -eq 'user' -and $_.target_id -eq "$mem2Id" })
if ($ch2MemLog.Count -eq 0) { Ok 'T13 officer 1 does NOT see Ch2 member events' } else { Bad 'T13 officer 1 sees Ch2 member' "leaked $($ch2MemLog.Count) entries" }

# T14: Officer 1 does NOT see unauthenticated/system-wide events (actor_id=NULL like login failure)
$anonLogs = @($off1Logs | Where-Object { $null -eq $_.actor_id -and $_.action -eq 'auth.login.failed' })
if ($anonLogs.Count -eq 0) { Ok 'T14 officer 1 does NOT see unauthenticated login failures' } else { Bad 'T14 officer sees anon events' "found $($anonLogs.Count)" }

# T15: Anti-enumeration: Officer 1 requesting ?chapter_id=2 rejected or returns 0 rows
$r = Invoke-Json $off1Auth.s 'Get' '/api/officer/audit-logs?chapter_id=2' $null $null
if ($r.status -eq 403 -or ($r.status -eq 200 -and $r.body.data.total -eq 0)) {
    Ok 'T15 officer 1 ?chapter_id=2 blocked / 0 rows'
} else {
    Bad 'T15 officer cross-chapter query' "status=$($r.status) total=$($r.body.data.total)"
}

# T16: Anti-enumeration: Officer 1 filtering on target_id=CH2_REQ returns total=0
$r = Invoke-Json $off1Auth.s 'Get' "/api/officer/audit-logs?target_type=blood_request&target_id=$req2Id" $null $null
if ($r.status -eq 200 -and $r.body.data.total -eq 0) {
    Ok 'T16 officer 1 query on Ch2 target returns total=0'
} else {
    Bad 'T16 officer query Ch2 target' "total=$($r.body.data.total)"
}

# -------------------------------------------
# SECTION E -- Officer 2 Verification (Chapter 2)
# -------------------------------------------
Write-Host "`n--- E: Officer 2 Scoping ---"

$off2Auth = Login $off2Email
$r = Invoke-Json $off2Auth.s 'Get' '/api/officer/audit-logs?page_size=100' $null $null
$off2Logs = $r.body.data.logs

# T17: Officer 2 sees Chapter 2 request
$ch2SeenByOff2 = @($off2Logs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req2Id" })
if ($ch2SeenByOff2.Count -ge 1) { Ok 'T17 officer 2 sees Ch2 request event' } else { Bad 'T17 officer 2 sees Ch2 request' "count=$($ch2SeenByOff2.Count)" }

# T18: Officer 2 does NOT see Chapter 1 request
$ch1SeenByOff2 = @($off2Logs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req1Id" })
if ($ch1SeenByOff2.Count -eq 0) { Ok 'T18 officer 2 does NOT see Ch1 request event' } else { Bad 'T18 officer 2 sees Ch1 request' "leaked count" }

# -------------------------------------------
# SECTION F -- Admin Full Visibility & Filtering
# -------------------------------------------
Write-Host "`n--- F: Admin Visibility and Filtering ---"

$admAuth = Login $admEmail

# T19: Admin sees global audit log (200)
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/audit-logs?page_size=100' $null $null
if ($r.status -eq 200 -and $r.body.data.total -gt 0) { Ok 'T19 admin sees global audit log' } else { Bad 'T19 admin global list' "status=$($r.status)" }
$admLogs = $r.body.data.logs

# T20: Admin sees both Ch1 and Ch2 request events
$admCh1 = @($admLogs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req1Id" })
$admCh2 = @($admLogs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req2Id" })
if ($admCh1.Count -ge 1 -and $admCh2.Count -ge 1) {
    Ok 'T20 admin sees events from all chapters'
} else {
    Bad 'T20 admin sees all chapters' "ch1=$($admCh1.Count) ch2=$($admCh2.Count)"
}

# T21: Admin sees unauthenticated login failures
$admAnon = @($admLogs | Where-Object { $_.action -eq 'auth.login.failed' })
if ($admAnon.Count -ge 1) { Ok 'T21 admin sees unauthenticated login failure events' } else { Bad 'T21 admin sees anon events' "count=0" }

# T22: Admin filter by action exact (request.created)
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/audit-logs?action=request.created' $null $null
$allCreated = $true
foreach ($l in $r.body.data.logs) { if ($l.action -ne 'request.created') { $allCreated = $false; break } }
if ($r.status -eq 200 -and $r.body.data.logs.Count -ge 1 -and $allCreated) {
    Ok 'T22 admin filter by action=request.created'
} else {
    Bad 'T22 admin filter action' "status=$($r.status) allCreated=$allCreated"
}

# T23: Admin filter by action prefix (request.*)
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/audit-logs?action=request.*' $null $null
$allReqPrefix = $true
foreach ($l in $r.body.data.logs) { if (-not $l.action.StartsWith('request.')) { $allReqPrefix = $false; break } }
if ($r.status -eq 200 -and $r.body.data.logs.Count -ge 1 -and $allReqPrefix) {
    Ok 'T23 admin filter by action prefix (request.*)'
} else {
    Bad 'T23 admin filter action prefix' "status=$($r.status) allReqPrefix=$allReqPrefix"
}

# T24: Admin filter by actor_id
$r = Invoke-Json $admAuth.s 'Get' "/api/admin/audit-logs?actor_id=$mem1Id" $null $null
$allMem1 = $true
foreach ($l in $r.body.data.logs) { if ($l.actor_id -ne [int]$mem1Id) { $allMem1 = $false; break } }
if ($r.status -eq 200 -and $r.body.data.logs.Count -ge 1 -and $allMem1) {
    Ok 'T24 admin filter by actor_id'
} else {
    Bad 'T24 admin filter actor_id' "status=$($r.status) allMem1=$allMem1"
}

# T25: Admin filter by target_type and target_id
$r = Invoke-Json $admAuth.s 'Get' "/api/admin/audit-logs?target_type=blood_request&target_id=$req1Id" $null $null
if ($r.status -eq 200 -and $r.body.data.logs.Count -ge 1) {
    Ok 'T25 admin filter by target_type & target_id'
} else {
    Bad 'T25 admin filter target' "count=$($r.body.data.logs.Count)"
}

# T26: Admin filter by chapter_id=1
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/audit-logs?chapter_id=1&page_size=100' $null $null
$ch1Logs = $r.body.data.logs
$ch2Leak = @($ch1Logs | Where-Object { $_.target_type -eq 'blood_request' -and $_.target_id -eq "$req2Id" })
if ($r.status -eq 200 -and $ch2Leak.Count -eq 0) {
    Ok 'T26 admin filter by chapter_id=1 scopes correctly'
} else {
    Bad 'T26 admin filter chapter_id' "leaked ch2 in ch1 filter"
}

# -------------------------------------------
# SECTION G -- Pagination & Date Filtering
# -------------------------------------------
Write-Host "`n--- G: Pagination and Date Filtering ---"

# T27: Pagination metadata
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/audit-logs?page=1&page_size=2' $null $null
if ($r.status -eq 200 -and $r.body.data.page -eq 1 -and $r.body.data.page_size -eq 2 -and $null -ne $r.body.data.total -and $null -ne $r.body.data.total_pages) {
    Ok 'T27 pagination metadata structure'
} else {
    Bad 'T27 pagination structure' "status=$($r.status)"
}

# T28: Page size limit respected
if ($r.body.data.logs.Count -le 2) { Ok 'T28 page_size limit respected' } else { Bad 'T28 page_size limit' "returned $($r.body.data.logs.Count)" }

# T29: Date range filter (from past to future)
$pastDate = (Get-Date).ToUniversalTime().AddDays(-1).ToString('yyyy-MM-dd')
$futureDate = (Get-Date).ToUniversalTime().AddDays(1).ToString('yyyy-MM-dd')
$r = Invoke-Json $admAuth.s 'Get' "/api/admin/audit-logs?date_from=$pastDate&date_to=$futureDate" $null $null
if ($r.status -eq 200 -and $r.body.data.logs.Count -ge 1) {
    Ok 'T29 date range filter works'
} else {
    Bad 'T29 date range filter' "status=$($r.status) count=$($r.body.data.logs.Count)"
}

# -------------------------------------------
# SECTION H -- Privacy & Sanitization
# -------------------------------------------
Write-Host "`n--- H: Privacy and Sanitization ---"

# T30: No sensitive credentials in audit responses
$raw = $r.raw
$clean = ($raw -notmatch 'password_hash') -and ($raw -notmatch 'token_hash') -and ($raw -notmatch 'MAIL_PASS') -and ($raw -notmatch 'DB_PASS')
if ($clean) { Ok 'T30 response free of sensitive credentials' } else { Bad 'T30 credential exposure' 'found sensitive key in response' }

# T31: Context field is valid sanitized JSON object
$hasContext = $false
foreach ($l in $admLogs) {
    if ($null -ne $l.context) { $hasContext = $true; break }
}
if ($hasContext) { Ok 'T31 sanitized context object present in response' } else { Bad 'T31 context object' 'no context found' }

# -------------------------------------------
# SUMMARY
# -------------------------------------------
Write-Host "`n== Phase 11 complete: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
