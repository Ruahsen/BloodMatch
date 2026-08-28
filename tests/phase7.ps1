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
    $btSql = 'NULL';   if ($null -ne $bloodType) { $btSql = "'$bloodType','self_reported'" }
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

Write-Host "== Phase 7 compatibility & matching engine audit =="

# --- M: full 8-type matrix via reference endpoint ---
$adminEmail = "p7adm$suffix@test.local"; $adminId = New-FixtureUser $adminEmail 'Admin Seven' 'admin' $null 'active' $null $null $null $false $null
$adm = Login $adminEmail

$r = Invoke-Json $adm.s 'Get' '/api/compatibility-matrix' $null $adm.csrf
if ($r.status -ne 200) { throw "matrix endpoint failed ($($r.status))" }
$m = $r.body.data.matrix

$expected = @{
    'O-'  = @('O-')
    'O+'  = @('O+','O-')
    'A-'  = @('A-','O-')
    'A+'  = @('A+','A-','O+','O-')
    'B-'  = @('B-','O-')
    'B+'  = @('B+','B-','O+','O-')
    'AB-' = @('AB-','A-','B-','O-')
    'AB+' = @('AB+','AB-','A+','A-','B+','B-','O+','O-')
}
$i = 0
foreach ($k in $expected.Keys) {
    $i++
    $got = @($m.$k) | Sort-Object
    $want = @($expected[$k]) | Sort-Object
    $diff = Compare-Object $got $want
    if ($null -eq $diff) { Ok "M$i matrix $k" } else { Bad "M$i matrix $k" "got $($got -join ',')" }
}

# --- fixtures for matching ---
# requestor: verified ch1 near Balanga
$reqEmail = "p7req$suffix@test.local"
$reqId = New-FixtureUser $reqEmail 'Requestor Seven' 'member' 1 'verified' 'A+' 14.680000 120.540000 $false $null

# donors
$dOkEmail      = "p7dok$suffix@test.local";     $dOk      = New-FixtureUser $dOkEmail 'Near Compatible' 'member' 1 'verified' 'A+' 14.681000 120.541000 $true 'available'
$dCrossEmail   = "p7cross$suffix@test.local";   $dCross   = New-FixtureUser $dCrossEmail 'Cross Chapter O+' 'member' 2 'verified' 'O+' 14.435000 120.486700 $true 'available'
$dBadEmail     = "p7bad$suffix@test.local";     $dBad     = New-FixtureUser $dBadEmail 'Incompatible Nearby' 'member' 1 'verified' 'B+' 14.680500 120.540500 $true 'available'
$dUnvEmail     = "p7unv$suffix@test.local";     $dUnv     = New-FixtureUser $dUnvEmail 'Unverified Donor' 'member' 1 'unverified' 'A+' 14.68 120.54 $true 'available'
$dPenEmail     = "p7pen$suffix@test.local";     $dPen     = New-FixtureUser $dPenEmail 'Pending Donor' 'member' 1 'pending' 'A-' 14.68 120.54 $true 'available'
$dRejEmail     = "p7rej$suffix@test.local";     $dRej     = New-FixtureUser $dRejEmail 'Rejected Donor' 'member' 1 'rejected' 'O+' 14.68 120.54 $true 'available'
$dDeactEmail   = "p7deact$suffix@test.local";   $dDeact   = New-FixtureUser $dDeactEmail 'Deactivated Donor' 'member' 1 'verified' 'A+' 14.68 120.54 $true 'available'
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=NOW() WHERE id=$dDeact;"
$dSbEmail      = "p7sb$suffix@test.local";      $dSb      = New-FixtureUser $dSbEmail 'Standby Donor' 'member' 1 'verified' 'A-' 14.68 120.54 $true 'standby'
$dUnEmail      = "p7un$suffix@test.local";      $dUn      = New-FixtureUser $dUnEmail 'Unavailable Donor' 'member' 1 'verified' 'O+' 14.68 120.54 $true 'unavailable'
$dNeEmail      = "p7ne$suffix@test.local";      $dNe      = New-FixtureUser $dNeEmail 'Not Enrolled' 'member' 1 'verified' 'A+' 14.68 120.54 $false $null
$dNoCEmail     = "p7noc$suffix@test.local";     $dNoC     = New-FixtureUser $dNoCEmail 'No Coords Donor' 'member' 1 'verified' 'O-' $null $null $true 'available'

$offEmail = "p7off$suffix@test.local"; $offId = New-FixtureUser $offEmail 'Officer Seven' 'officer' 1 'verified' $null $null $null $false $null
$off = Login $offEmail

# --- T1 creation auto-runs first generation ---
$reqSess = Login $reqEmail
$r = Invoke-Json $reqSess.s 'Post' '/api/requests' @{
    required_blood_type='A+'; facility_name='Balanga General'; needed_datetime=$future;
    latitude=14.680000; longitude=120.540000
} $reqSess.csrf
if ($r.status -eq 201 -and $r.body.data.matching.generation -eq 1) { Ok 'T1 creation auto-generates generation 1' } else { Bad 'T1' "status=$($r.status) match=$($r.body.data.matching | ConvertTo-Json -Compress)" }
$reqRowId = $r.body.data.request.id

# --- T2 pool composition & privacy ---
$r = Invoke-Json $reqSess.s 'Get' "/api/requests/$reqRowId/matches" $null $reqSess.csrf
$ids = @($r.body.data.matches | ForEach-Object { $_.donor_reference })
$has = { param($x) $ids -contains "donor-$x" }
if (
    (& $has $dOk) -and (& $has $dCross) -and (& $has $dNoC) -and
    (-not (& $has $dBad)) -and (-not (& $has $dUnv)) -and (-not (& $has $dPen)) -and
    (-not (& $has $dRej)) -and (-not (& $has $dDeact)) -and (-not (& $has $dSb)) -and
    (-not (& $has $dUn)) -and (-not (& $has $dNe))
) { Ok 'T2 pool: eligible in, all excluded categories out' } else { Bad 'T2' "ids=$($ids -join ',')" }

# --- T3 incompatible nearby never outranks compatible ---
$nearBadRank = ($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dBad" })
$okEntry = $r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dOk" }
if ($null -eq $nearBadRank -and $null -ne $okEntry) { Ok 'T3 nearby incompatible absent; compatible present' } else { Bad 'T3' 'incompatible appeared or compatible missing' }

# --- T4 ranking: located same-chapter first, cross-chapter next, unlocated after ---
$okIdx = [array]::IndexOf(($r.body.data.matches | ForEach-Object { $_.donor_reference }), "donor-$dOk")
$crossIdx = [array]::IndexOf(($r.body.data.matches | ForEach-Object { $_.donor_reference }), "donor-$dCross")
$nocIdx = [array]::IndexOf(($r.body.data.matches | ForEach-Object { $_.donor_reference }), "donor-$dNoC")
if ($okIdx -lt $crossIdx -and $crossIdx -lt $nocIdx) { Ok 'T4 ranking: same-chapter < cross-chapter < unlocated' } else { Bad 'T4' "ok=$okIdx cross=$crossIdx noc=$nocIdx" }

# --- T5 privacy serialization ---
$forbidden = 'latitude|longitude|"phone"|"email"|password|document'
if ($r.raw -notmatch $forbidden -and $r.raw -match 'approximate_distance_km') { Ok 'T5 privacy-safe serialization' } else { Bad 'T5' 'forbidden field detected' }
$dist = ($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dOk" }).approximate_distance_km
if ($dist -ne $null -and $dist -gt 0 -and $dist -lt 50) { Ok "T6 haversine plausible distance ($dist km)" } else { Bad 'T6' "dist=$dist" }
$noCdist = ($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dNoC" }).approximate_distance_km
if ($null -eq $noCdist) { Ok 'T7 missing donor coordinates -> NULL distance still matched' } else { Bad 'T7' "dist=$noCdist" }

# --- T8 enrollment gate via API ---
$unvEnrollEmail = "p7unvenroll$suffix@test.local"
New-FixtureUser $unvEnrollEmail 'Unverified Enrollee' 'member' 1 'unverified' 'O+' 14.70 120.55 $false $null | Out-Null
$ue = Login $unvEnrollEmail
$r = Invoke-Json $ue.s 'Post' '/api/profile/enroll-donor' @{} $ue.csrf
if ($r.status -eq 409) { Ok 'T8a unverified member cannot enroll' } else { Bad 'T8a' "got $($r.status)" }
$verEnrollEmail = "p7verenroll$suffix@test.local"
$verEnrollId = New-FixtureUser $verEnrollEmail 'Later Enrollee' 'member' 1 'verified' 'O+' 14.70 120.55 $false $null
$ve = Login $verEnrollEmail
$r = Invoke-Json $ve.s 'Post' '/api/profile/enroll-donor' @{} $ve.csrf
$dbE = DbQuery "SELECT CONCAT(IF(donor_enrolled_at IS NULL,'N','Y'),'|',donor_availability) FROM users WHERE id=$verEnrollId;"
if ($r.status -eq 200 -and $dbE -eq 'Y|available') { Ok 'T8b verified member enrolls via API' } else { Bad 'T8b' "db=$dbE" }

# --- T9 material change bumps generation; manual rematch does not ---
$r = Invoke-Json $reqSess.s 'Put' "/api/requests/$reqRowId" @{ urgency = 'critical' } $reqSess.csrf
$r2 = Invoke-Json $reqSess.s 'Get' "/api/requests/$reqRowId/matches" $null $reqSess.csrf
$genAfterMaterial = ($r2.body.data.matches | Select-Object -First 1).generation
if ($genAfterMaterial -ge 2) { Ok "T9 material change bumped generation -> $genAfterMaterial" } else { Bad 'T9' "gen=$genAfterMaterial" }
$r = Invoke-Json $off.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $off.csrf
$r3 = Invoke-Json $reqSess.s 'Get' "/api/requests/$reqRowId/matches" $null $reqSess.csrf
$genAfterRematch = ($r3.body.data.matches | Select-Object -First 1).generation
if ($r.status -eq 200 -and $genAfterRematch -eq $genAfterMaterial) { Ok 'T10 non-material manual re-match keeps generation' } else { Bad 'T10' "gen=$genAfterRematch expected $genAfterMaterial" }
$rows = DbQuery "SELECT COUNT(*) FROM matches WHERE request_id=$reqRowId AND donor_id=$dOk;"
if ([int]$rows -eq 1) { Ok 'T11 unique (request,donor): single persistent row' } else { Bad 'T11' "rows=$rows" }

# --- T12 newly enrolled donor appears on next run without bumping gen ---
$beforeCnt = @($r3.body.data.matches).Count
$r = Invoke-Json $off.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $off.csrf
$r4 = Invoke-Json $reqSess.s 'Get' "/api/requests/$reqRowId/matches" $null $reqSess.csrf
$foundNew = @($r4.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$verEnrollId" }).Count
if ($foundNew -eq 1) { Ok 'T13 newly enrolled donor inserted on re-match' } else { Bad 'T13' 'missing from refreshed set' }
$genStill = ($r4.body.data.matches | Select-Object -First 1).generation
if ($genStill -eq $genAfterMaterial) { Ok 'T14 refresh kept same generation' } else { Bad 'T14' "gen=$genStill" }

# --- T15 closure retains history ---
DbQuery "UPDATE users SET donor_availability='unavailable' WHERE id=$dCross;"
$r = Invoke-Json $off.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $off.csrf
$closed = DbQuery "SELECT COUNT(*) FROM matches WHERE request_id=$reqRowId AND donor_id=$dCross AND status='CLOSED';"
if ([int]$closed -eq 1) { Ok 'T15 unavailable donor match closed, row retained' } else { Bad 'T15' "closed=$closed" }

# --- T16 authz on re-match ---
$memOtherEmail = "p7memother$suffix@test.local"
$memOtherId = New-FixtureUser $memOtherEmail 'Plain Member' 'member' 1 'verified' 'O+' $null $null $true 'available'
$mo = Login $memOtherEmail
$r = Invoke-Json $mo.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $mo.csrf
if ($r.status -eq 403) { Ok 'T16 member re-match denied 403' } else { Bad 'T16' "got $($r.status)" }
$offBEmail = "p7offb$suffix@test.local"
New-FixtureUser $offBEmail 'Officer Other Ch' 'officer' 2 'verified' $null $null $null $false $null | Out-Null
$ob = Login $offBEmail
$r = Invoke-Json $ob.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $ob.csrf
if ($r.status -eq 403) { Ok 'T17 cross-chapter officer re-match denied' } else { Bad 'T17' "got $($r.status)" }
$r = Invoke-Json $adm.s 'Post' "/api/officer/requests/$reqRowId/re-match" @{} $adm.csrf
if ($r.status -eq 200) { Ok 'T18 admin re-match system-wide allowed' } else { Bad 'T18' "got $($r.status)" }

# --- T19 AB+ universal recipient receives all types ---
$abReqEmail = "p7ab$suffix@test.local"
$abReqId = New-FixtureUser $abReqEmail 'AB Requestor' 'member' 1 'verified' 'AB+' 14.68 120.54 $false $null
$abs = Login $abReqEmail
$r = Invoke-Json $abs.s 'Post' '/api/requests' @{
    required_blood_type='AB+'; facility_name='Balanga General'; needed_datetime=$future;
    latitude=14.680000; longitude=120.540000
} $abs.csrf
if ($r.status -eq 201) { Ok 'T19 AB+ request created with auto-generation' } else { Bad 'T19' "got $($r.status)" }

# --- T20 audits ---
$acts = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('match.generation','match.manual_rematch','donor.enrolled','request.material_change');"
if ([int]$acts -ge 4) { Ok "T20 audit events recorded ($acts distinct)" } else { Bad 'T20' "distinct=$acts" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
