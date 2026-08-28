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
$todayDate = (Get-Date).ToUniversalTime().ToString('yyyy-MM-dd')

Write-Host "== Phase 12 Analytics, Demand Map & Dashboards Tests =="

# -------------------------------------------
# SECTION A -- Access Control & RBAC
# -------------------------------------------
Write-Host "`n--- A: Access Control & RBAC ---"

$anonS = New-Object Microsoft.PowerShell.Commands.WebRequestSession

# T01: Anonymous -> GET /api/officer/dashboard -> 401
$r = Invoke-Json $anonS 'Get' '/api/officer/dashboard' $null $null
if ($r.status -eq 401) { Ok 'T01 anon officer dashboard -> 401' } else { Bad 'T01 anon officer dashboard' "got $($r.status)" }

# T02: Anonymous -> GET /api/admin/dashboard -> 401
$r = Invoke-Json $anonS 'Get' '/api/admin/dashboard' $null $null
if ($r.status -eq 401) { Ok 'T02 anon admin dashboard -> 401' } else { Bad 'T02 anon admin dashboard' "got $($r.status)" }

# T03: Anonymous -> GET /api/demand-map -> 401
$r = Invoke-Json $anonS 'Get' '/api/demand-map' $null $null
if ($r.status -eq 401) { Ok 'T03 anon demand map -> 401' } else { Bad 'T03 anon demand map' "got $($r.status)" }

# T04: Anonymous -> GET /api/analytics/summary -> 401
$r = Invoke-Json $anonS 'Get' '/api/analytics/summary' $null $null
if ($r.status -eq 401) { Ok 'T04 anon analytics summary -> 401' } else { Bad 'T04 anon analytics summary' "got $($r.status)" }

# -------------------------------------------
# SECTION B -- Setup Fixtures & Test State
# -------------------------------------------
Write-Host "`n--- B: Setup Fixtures ---"

$admEmail = "p12adm$suffix@test.local"
$admId = New-FixtureUser $admEmail 'Admin Twelve' 'admin' $null 'verified' $null $null $null $false $null

$off1Email = "p12off1$suffix@test.local"
$off1Id = New-FixtureUser $off1Email 'Officer Ch1' 'officer' 1 'verified' $null $null $null $false $null

$off2Email = "p12off2$suffix@test.local"
$off2Id = New-FixtureUser $off2Email 'Officer Ch2' 'officer' 2 'verified' $null $null $null $false $null

$mem1Email = "p12mem1$suffix@test.local"
$mem1Id = New-FixtureUser $mem1Email 'Member Ch1' 'member' 1 'verified' 'A+' 14.80 120.53 $false $null

$mem2Email = "p12mem2$suffix@test.local"
$mem2Id = New-FixtureUser $mem2Email 'Member Ch2' 'member' 2 'verified' 'B+' 14.43 120.48 $false $null

# Pending member in Chapter 1 for verification queue metric
$pendMemEmail = "p12pend$suffix@test.local"
$pendMemId = New-FixtureUser $pendMemEmail 'Pending Member Ch1' 'member' 1 'pending' 'O+' 14.80 120.53 $false $null

# Enrolled donor 1 in Chapter 1 (Available)
$donor1Email = "p12don1$suffix@test.local"
$donor1Id = New-FixtureUser $donor1Email 'Donor Available Ch1' 'member' 1 'verified' 'A+' 14.801 120.531 $true 'available'

# Enrolled donor 2 in Chapter 1 (Standby)
$donor2Email = "p12don2$suffix@test.local"
$donor2Id = New-FixtureUser $donor2Email 'Donor Standby Ch1' 'member' 1 'verified' 'O-' 14.802 120.532 $true 'standby'
DbQuery "UPDATE users SET last_verified_donation_at = UTC_TIMESTAMP() WHERE id=$donor2Id;"

Write-Host "Fixtures created: Admin=$admEmail, Officer1(Ch1)=$off1Email, Officer2(Ch2)=$off2Email, Member1(Ch1)=$mem1Email, Donor1=$donor1Email, Donor2=$donor2Email"

# Member login & role access checks
$mem1Auth = Login $mem1Email
$mem2Auth = Login $mem2Email
$off1Auth = Login $off1Email
$off2Auth = Login $off2Email
$admAuth = Login $admEmail

# T05: Member -> GET /api/officer/dashboard -> 403
$r = Invoke-Json $mem1Auth.s 'Get' '/api/officer/dashboard' $null $null
if ($r.status -eq 403) { Ok 'T05 member officer dashboard -> 403' } else { Bad 'T05 member officer dashboard' "got $($r.status)" }

# T06: Member -> GET /api/admin/dashboard -> 403
$r = Invoke-Json $mem1Auth.s 'Get' '/api/admin/dashboard' $null $null
if ($r.status -eq 403) { Ok 'T06 member admin dashboard -> 403' } else { Bad 'T06 member admin dashboard' "got $($r.status)" }

# T07: Member -> GET /api/demand-map -> 403
$r = Invoke-Json $mem1Auth.s 'Get' '/api/demand-map' $null $null
if ($r.status -eq 403) { Ok 'T07 member demand map -> 403' } else { Bad 'T07 member demand map' "got $($r.status)" }

# T08: Member -> GET /api/analytics/summary -> 403
$r = Invoke-Json $mem1Auth.s 'Get' '/api/analytics/summary' $null $null
if ($r.status -eq 403) { Ok 'T08 member analytics summary -> 403' } else { Bad 'T08 member analytics summary' "got $($r.status)" }

# T09: Officer -> GET /api/admin/dashboard -> 403
$r = Invoke-Json $off1Auth.s 'Get' '/api/admin/dashboard' $null $null
if ($r.status -eq 403) { Ok 'T09 officer admin dashboard -> 403' } else { Bad 'T09 officer admin dashboard' "got $($r.status)" }

# -------------------------------------------
# Create controlled request state
# -------------------------------------------
# Ch1 Request 1: OPEN (A+, 2 units, routine)
$rReq1 = Invoke-Json $mem1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'A+'
    quantity_units = 2
    facility_name = 'Orani District Hospital'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $mem1Auth.csrf
$req1Id = $rReq1.body.data.request.id

# Ch1 Request 2: FULFILLED (A+, 1 unit)
$rReq2 = Invoke-Json $mem1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'A+'
    quantity_units = 1
    facility_name = 'Orani Clinic'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $mem1Auth.csrf
$req2Id = $rReq2.body.data.request.id
DbQuery "UPDATE blood_requests SET status = 'FULFILLED' WHERE id=$req2Id;"

# Ch1 Request 3: CANCELLED (B+, 1 unit)
$rReq3 = Invoke-Json $mem1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'B+'
    quantity_units = 1
    facility_name = 'Orani Center'
    urgency = 'urgent'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $mem1Auth.csrf
$req3Id = $rReq3.body.data.request.id
Invoke-Json $mem1Auth.s 'Post' "/api/requests/$req3Id/cancel" $null $mem1Auth.csrf | Out-Null

# Ch1 Request 4: EXPIRED (AB+, 1 unit)
$rReq4 = Invoke-Json $mem1Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'AB+'
    quantity_units = 1
    facility_name = 'Orani Health'
    urgency = 'routine'
    needed_datetime = $future
    latitude = 14.80
    longitude = 120.53
} $mem1Auth.csrf
$req4Id = $rReq4.body.data.request.id
DbQuery "UPDATE blood_requests SET status = 'EXPIRED', expired_at = UTC_TIMESTAMP() WHERE id=$req4Id;"

# Ch2 Request 5: OPEN (B+, 3 units, critical)
$rReq5 = Invoke-Json $mem2Auth.s 'Post' '/api/requests' @{
    required_blood_type = 'B+'
    quantity_units = 3
    facility_name = 'Mariveles Emergency Hospital'
    urgency = 'critical'
    needed_datetime = $future
    latitude = 14.43
    longitude = 120.48
} $mem2Auth.csrf
$req5Id = $rReq5.body.data.request.id

# -------------------------------------------
# SECTION C -- FR-15 Regional Blood Demand Map
# -------------------------------------------
Write-Host "`n--- C: FR-15 Regional Blood Demand Map ---"

# T10: Officer 1 gets demand map -> scoped strictly to Chapter 1
$r = Invoke-Json $off1Auth.s 'Get' '/api/demand-map' $null $null
if ($r.status -eq 200) { Ok 'T10 officer 1 demand map -> 200' } else { Bad 'T10 officer 1 demand map' "got $($r.status)" }
$chList = $r.body.data.chapters
$ch1Entry = $chList | Where-Object { $_.chapter_id -eq 1 }

# T11: Officer 1 receives only 1 chapter (Chapter 1)
if ($chList.Count -eq 1 -and $chList[0].chapter_id -eq 1) {
    Ok 'T11 officer 1 demand map scoped to Chapter 1 only'
} else {
    Bad 'T11 officer 1 demand map scoping' "expected 1 chapter (ID 1), got $($chList.Count)"
}

# T12: Demand map only counts OPEN requests (excludes FULFILLED, CANCELLED, EXPIRED)
# Chapter 1 has 1 OPEN request (2 units A+). Total units should be >= 2.
if ($ch1Entry.open_requests_count -ge 1 -and $ch1Entry.total_units_needed -ge 2) {
    Ok 'T12 demand map counts only OPEN requests'
} else {
    Bad 'T12 demand map OPEN count' "count=$($ch1Entry.open_requests_count), units=$($ch1Entry.total_units_needed)"
}

# T13: Demand map blood type breakdown accuracy
if ($ch1Entry.blood_type_counts.'A+' -ge 2) {
    Ok 'T13 demand map A+ units breakdown correct'
} else {
    Bad 'T13 demand map A+ breakdown' "A+ units=$($ch1Entry.blood_type_counts.'A+')"
}

# T14: Privacy check: No individual coordinates or request IDs leaked
$rawMap = $r.raw
if ($rawMap -notmatch '"requester_id"' -and $rawMap -notmatch '"facility_name"') {
    Ok 'T14 demand map privacy safe: no individual request identities'
} else {
    Bad 'T14 demand map privacy' "contains individual fields"
}

# T15: Centroid coordinates present and accurate
if ($ch1Entry.latitude -eq 14.800300 -and $ch1Entry.longitude -eq 120.533600) {
    Ok 'T15 demand map uses canonical chapter centroids'
} else {
    Bad 'T15 centroid coordinates' "lat=$($ch1Entry.latitude), lng=$($ch1Entry.longitude)"
}

# T16: Officer 1 passing ?chapter_id=2 blocked with 403
$r = Invoke-Json $off1Auth.s 'Get' '/api/demand-map?chapter_id=2' $null $null
if ($r.status -eq 403) {
    Ok 'T16 officer 1 query ?chapter_id=2 blocked with 403'
} else {
    Bad 'T16 officer 1 ?chapter_id=2' "got $($r.status)"
}

# T17: Admin gets demand map -> sees all 3 chapters
$r = Invoke-Json $admAuth.s 'Get' '/api/demand-map' $null $null
if ($r.status -eq 200 -and $r.body.data.chapters.Count -eq 3) {
    Ok 'T17 admin demand map returns all 3 chapters'
} else {
    Bad 'T17 admin demand map' "count=$($r.body.data.chapters.Count)"
}

# T18: Demand map urgency filter works
$r = Invoke-Json $admAuth.s 'Get' '/api/demand-map?urgency=critical' $null $null
$ch2Critical = $r.body.data.chapters | Where-Object { $_.chapter_id -eq 2 }
if ($ch2Critical.open_requests_count -ge 1 -and $ch2Critical.urgency_counts.critical -ge 1) {
    Ok 'T18 demand map urgency=critical filter works'
} else {
    Bad 'T18 demand map urgency filter' "ch2 open=$($ch2Critical.open_requests_count)"
}

# -------------------------------------------
# SECTION D -- FR-19 Analytics Metrics & Rate Verification
# -------------------------------------------
Write-Host "`n--- D: FR-19 Analytics Metrics & Rates ---"

# T19: Officer 1 gets analytics summary -> 200
$r = Invoke-Json $off1Auth.s 'Get' '/api/analytics/summary' $null $null
if ($r.status -eq 200) { Ok 'T19 officer 1 analytics summary -> 200' } else { Bad 'T19 officer 1 analytics' "got $($r.status)" }
$anData = $r.body.data
$reqVol = $anData.request_volume

# T20: Total resolved requests formula excludes OPEN
# In Ch1: fulfilled >= 1, cancelled >= 1, expired >= 1 -> resolved >= 3
if ($reqVol.resolved_requests -ge 3 -and $reqVol.resolved_requests -eq ($reqVol.fulfilled_requests + $reqVol.cancelled_requests + $reqVol.expired_requests)) {
    Ok 'T20 resolved requests denominator excludes OPEN'
} else {
    Bad 'T20 resolved denominator' "resolved=$($reqVol.resolved_requests), ful=$($reqVol.fulfilled_requests), can=$($reqVol.cancelled_requests), exp=$($reqVol.expired_requests)"
}

# T21: Fulfillment rate formula verified: FULFILLED / RESOLVED * 100
$expectedFulfillment = [math]::Round(($reqVol.fulfilled_requests / $reqVol.resolved_requests) * 100, 1)
if ([math]::Abs($reqVol.fulfillment_rate_percent - $expectedFulfillment) -lt 0.2) {
    Ok "T21 fulfillment rate accurate ($($reqVol.fulfillment_rate_percent)%)"
} else {
    Bad 'T21 fulfillment rate' "expected $expectedFulfillment, got $($reqVol.fulfillment_rate_percent)"
}

# T22: Cancellation rate formula verified: CANCELLED / RESOLVED * 100
$expectedCancellation = [math]::Round(($reqVol.cancelled_requests / $reqVol.resolved_requests) * 100, 1)
if ([math]::Abs($reqVol.cancellation_rate_percent - $expectedCancellation) -lt 0.2) {
    Ok "T22 cancellation rate accurate ($($reqVol.cancellation_rate_percent)%)"
} else {
    Bad 'T22 cancellation rate' "expected $expectedCancellation, got $($reqVol.cancellation_rate_percent)"
}

# T23: Expiration rate formula verified: EXPIRED / RESOLVED * 100
$expectedExpiration = [math]::Round(($reqVol.expired_requests / $reqVol.resolved_requests) * 100, 1)
if ([math]::Abs($reqVol.expiration_rate_percent - $expectedExpiration) -lt 0.2) {
    Ok "T23 expiration rate accurate ($($reqVol.expiration_rate_percent)%)"
} else {
    Bad 'T23 expiration rate' "expected $expectedExpiration, got $($reqVol.expiration_rate_percent)"
}

# T24: Donor pool availability evaluated with Phase 9 standby/cooldown rules
$dPool = $anData.donor_pool
if ($dPool.available -ge 1 -and $dPool.standby -ge 1) {
    Ok "T24 donor pool reflects standby evaluation (available=$($dPool.available), standby=$($dPool.standby))"
} else {
    Bad 'T24 donor pool availability' "avail=$($dPool.available), standby=$($dPool.standby)"
}

# T25: Officer 1 cannot expand scope with ?chapter_id=2 -> 403
$r = Invoke-Json $off1Auth.s 'Get' '/api/analytics/summary?chapter_id=2' $null $null
if ($r.status -eq 403) { Ok 'T25 officer 1 ?chapter_id=2 analytics blocked with 403' } else { Bad 'T25 officer 1 chapter_id manipulation' "got $($r.status)" }

# T26: Admin gets global analytics summary with cross-chapter data
$r = Invoke-Json $admAuth.s 'Get' '/api/analytics/summary' $null $null
if ($r.status -eq 200 -and $r.body.data.request_volume.total_requests -gt $reqVol.total_requests) {
    Ok 'T26 admin global analytics aggregates all chapters'
} else {
    Bad 'T26 admin global analytics' "admin total=$($r.body.data.request_volume.total_requests), off1 total=$($reqVol.total_requests)"
}

# -------------------------------------------
# SECTION E -- FR-20 Officer & Admin Dashboards
# -------------------------------------------
Write-Host "`n--- E: FR-20 Officer & Admin Dashboards ---"

# T27: Officer 1 dashboard contains required sections
$r = Invoke-Json $off1Auth.s 'Get' '/api/officer/dashboard' $null $null
if ($r.status -eq 200) { Ok 'T27 officer dashboard -> 200' } else { Bad 'T27 officer dashboard' "got $($r.status)" }
$offDash = $r.body.data

# T28: Officer dashboard metrics reflect Chapter 1 operational queues
if ($offDash.chapter.id -eq 1 -and $offDash.metrics.pending_verifications -ge 1 -and $offDash.metrics.active_open_requests -ge 1) {
    Ok "T28 officer dashboard queues scoped to Chapter 1 (pending verifications=$($offDash.metrics.pending_verifications), open reqs=$($offDash.metrics.active_open_requests))"
} else {
    Bad 'T28 officer dashboard metrics' "chapter=$($offDash.chapter.id), pv=$($offDash.metrics.pending_verifications), open=$($offDash.metrics.active_open_requests)"
}

# T29: Officer 1 ?chapter_id=2 rejected on dashboard -> 403
$r = Invoke-Json $off1Auth.s 'Get' '/api/officer/dashboard?chapter_id=2' $null $null
if ($r.status -eq 403) { Ok 'T29 officer dashboard ?chapter_id=2 blocked with 403' } else { Bad 'T29 officer dashboard ?chapter_id=2' "got $($r.status)" }

# T30: Admin dashboard contains system-wide metrics and chapter comparison
$r = Invoke-Json $admAuth.s 'Get' '/api/admin/dashboard' $null $null
if ($r.status -eq 200) { Ok 'T30 admin dashboard -> 200' } else { Bad 'T30 admin dashboard' "got $($r.status)" }
$admDash = $r.body.data

# T31: Admin dashboard chapters comparison includes all 3 chapters
if ($admDash.chapters_summary.Count -eq 3 -and $admDash.overview.total_users -ge 5) {
    Ok 'T31 admin dashboard contains cross-chapter comparison (3 chapters)'
} else {
    Bad 'T31 admin dashboard chapters summary' "count=$($admDash.chapters_summary.Count), total_users=$($admDash.overview.total_users)"
}

# -------------------------------------------
# SECTION F -- Response Envelope & Privacy
# -------------------------------------------
Write-Host "`n--- F: Response Envelope & Privacy ---"

# T32: Response envelope format conforms to standard
if ($offDash.chapter.name -eq 'Mt. Samat Chapter' -and $offDash.chapter.municipality -eq 'Orani') {
    Ok 'T32 canonical chapter display convention verified ({name} ({municipality}))'
} else {
    Bad 'T32 canonical chapter name' "name=$($offDash.chapter.name), muni=$($offDash.chapter.municipality)"
}

Write-Host "`n== Phase 12 complete: $script:pass passed, $script:fail failed =="
if ($script:fail -gt 0) { exit 1 } else { exit 0 }
