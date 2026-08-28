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

Write-Host "== Phase 9 standby & cooldown audit =="

# fixtures
$admEmail = "p9adm$suffix@test.local"; New-FixtureUser $admEmail 'Admin Nine' 'admin' $null 'active' $null $null $null $false $null | Out-Null
$offEmail = "p9off$suffix@test.local"
New-FixtureUser $offEmail 'Officer Nine' 'officer' 1 'verified' $null $null $null $false $null | Out-Null
$reqEmail = "p9req$suffix@test.local"
New-FixtureUser $reqEmail 'Requestor Nine' 'member' 1 'verified' 'A+' 14.68 120.54 $false $null | Out-Null

$dEmail = "p9donor$suffix@test.local"
$dId = New-FixtureUser $dEmail 'Cooldown Donor' 'member' 1 'verified' 'A+' 14.681 120.541 $true 'available'
$dNREmail = "p9nr$suffix@test.local"
$dNR = New-FixtureUser $dNREmail 'Non Responder' 'member' 1 'verified' 'O+' 14.679 120.539 $true 'available'

$reqSess = Login $reqEmail
$dSess = Login $dEmail
$off = Login $offEmail

# ===== T1 confirm -> standby written atomically =====
$r = Invoke-Json $reqSess.s 'Post' '/api/requests' @{
    required_blood_type='A+'; quantity_units=1; facility_name='Nine General';
    needed_datetime=$future; latitude=14.68; longitude=120.54
} $reqSess.csrf
$reqId = $r.body.data.request.id
$r = Invoke-Json $reqSess.s 'Get' "/api/requests/$reqId/matches" $null $reqSess.csrf
$mD = $r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dId" } | Select-Object -First 1
$mNR = $r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dNR" } | Select-Object -First 1
if ($null -eq $mD -or $null -eq $mNR) { throw "fixture matches missing" }

Invoke-Json $dSess.s 'Post' "/api/matches/$($mD.match_id)/respond" @{} $dSess.csrf | Out-Null
$r = Invoke-Json $dSess.s 'Post' '/api/donation-reports' @{ match_id = $mD.match_id; note = 'Phase nine confirmation.' } $dSess.csrf
$repD = $r.body.data.report.id
$r = Invoke-Json $off.s 'Post' "/api/officer/donation-reports/$repD/confirm" @{} $off.csrf
$row = DbQuery "SELECT CONCAT(IF(last_verified_donation_at IS NULL,'N','Y'),'|',donor_availability) FROM users WHERE id=$dId;"
if ($r.status -eq 200 -and $row -eq 'Y|standby') { Ok 'T1 confirmation sets last_verified_donation_at + standby in tx' } else { Bad 'T1' "row=$row status=$($r.status)" }

# ===== T2 immediate window blocked (standby) =====
$r = Invoke-Json $dSess.s 'Get' '/api/profile' $null $dSess.csrf
$w = $r.body.data.profile.availability_window
if ($w.blocked -eq $true -and $w.which -eq 'standby') { Ok 'T2 immediate: blocked=standby' } else { Bad 'T2' "w=$(($w | ConvertTo-Json -Compress))" }

# ===== T3 toggle while blocked -> 409 + no row change =====
$r = Invoke-Json $dSess.s 'Post' '/api/profile/donor-availability' @{ availability = 'available' } $dSess.csrf
$dbAv = DbQuery "SELECT donor_availability FROM users WHERE id=$dId;"
if ($r.status -eq 409 -and $dbAv -eq 'standby' -and $r.body.error.details.availability_window.which -eq 'standby') {
    Ok 'T3 blocked toggle 409 + payload + unchanged row'
} else { Bad 'T3' "status=$($r.status) db=$dbAv" }

# ===== T4 matching excludes standby-blocked donor =====
$r = Invoke-Json $reqSess.s 'Post' '/api/requests' @{
    required_blood_type='A+'; quantity_units=1; facility_name='Probe Clinic';
    needed_datetime=$future; latitude=14.68; longitude=120.54
} $reqSess.csrf
$probeReq = $r.body.data.request.id
$r = Invoke-Json $reqSess.s 'Get' "/api/requests/$probeReq/matches" $null $reqSess.csrf
$foundBlocked = @($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dId" }).Count
if ($r.status -ne 200) { Bad 'T4' "GET failed $($r.status): $($r.raw)" }
elseif ($foundBlocked -eq 0) { Ok 'T4 standby-blocked donor excluded from pool' } else { Bad 'T4' 'blocked donor present' }

# ===== T5 non-response does NOT create standby (negative test) =====
$nrAv = DbQuery "SELECT donor_availability FROM users WHERE id=$dNR;"
if ($nrAv -eq 'available') { Ok 'T5 non-response left donor availability untouched' } else { Bad 'T5' "avail=$nrAv" }

# ===== T6 41h59m still blocked (standby) — clock via anchor shift =====
DbQuery "UPDATE users SET last_verified_donation_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 41 HOUR) - INTERVAL 59 MINUTE WHERE id=$dId;"
$r = Invoke-Json $dSess.s 'Get' '/api/profile' $null $dSess.csrf
$w = $r.body.data.profile.availability_window
if ($w.blocked -eq $true -and $w.which -eq 'standby') { Ok 'T6 41h59m still standby-blocked' } else { Bad 'T6' "which=$($w.which)" }

# ===== T7 beyond 42h: standby lifted, cooldown active =====
DbQuery "UPDATE users SET last_verified_donation_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 43 HOUR) WHERE id=$dId;"
$r = Invoke-Json $dSess.s 'Get' '/api/profile' $null $dSess.csrf
$w = $r.body.data.profile.availability_window
if ($w.blocked -eq $true -and $w.which -eq 'cooldown') { Ok 'T7 43h: standby over, cooldown blocking (~87d left)' } else { Bad 'T7' "which=$($w.which)" }
$r = Invoke-Json $dSess.s 'Post' '/api/profile/donor-availability' @{ availability = 'available' } $dSess.csrf
if ($r.status -eq 409 -and $r.body.error.details.availability_window.which -eq 'cooldown') { Ok 'T8 cooldown blocks toggle too' } else { Bad 'T8' "got $($r.status)" }
$r = Invoke-Json $off.s 'Post' "/api/officer/requests/$probeReq/re-match" @{} $off.csrf
$r = Invoke-Json $reqSess.s 'Get' "/api/requests/$probeReq/matches" $null $reqSess.csrf
$stillOut = @($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dId" }).Count
if ($stillOut -eq 0) { Ok 'T9 cooldown-blocked donor excluded from pool' } else { Bad 'T9' 'present' }

# ===== T10 configurability: standby_hours change honored =====
DbQuery "UPDATE system_settings SET value='24' WHERE setting_key='standby_hours';"
$r = Invoke-Json $dSess.s 'Get' '/api/profile' $null $dSess.csrf
$w = $r.body.data.profile.availability_window
if ($w.blocked -eq $true -and $w.which -eq 'cooldown') {
    # with standby_hours=24 and lvd 43h ago standby is expired either way; verify a fresh-donor scenario:
    $dCfgEmail = "p9cfg$suffix@test.local"
    $dCfg = New-FixtureUser $dCfgEmail 'Config Donor' 'member' 1 'verified' 'A+' 14.682 120.542 $true 'available'
    DbQuery "UPDATE users SET last_verified_donation_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 HOUR) WHERE id=$dCfg;"
    $cSess = Login $dCfgEmail
    $r = Invoke-Json $cSess.s 'Get' '/api/profile' $null $cSess.csrf
    $wc = $r.body.data.profile.availability_window
    if ($wc.blocked -eq $true -and $wc.which -eq 'cooldown') { Ok 'T10a standby_hours=24 lifts standby (donor now cooldown-blocked instead)' } else { Bad 'T10a' "which=$($wc.which)" }
    DbQuery "UPDATE system_settings SET value='48' WHERE setting_key='standby_hours';"
    $r = Invoke-Json $cSess.s 'Get' '/api/profile' $null $cSess.csrf
    $wc = $r.body.data.profile.availability_window
    if ($wc.blocked -eq $true -and $wc.which -eq 'standby') { Ok 'T10b standby_hours=48 re-blocks same donor' } else { Bad 'T10b' "which=$($wc.which)" }
    DbQuery "UPDATE system_settings SET value='42' WHERE setting_key='standby_hours';"
} else { Bad 'T10 setup' "unexpected which=$($w.which)" }

# ===== T11 cooldown expiry -> matchable WITHOUT mutating stored standby (read model) =====
$storedBefore = DbQuery "SELECT donor_availability FROM users WHERE id=$dId;"
DbQuery "UPDATE users SET last_verified_donation_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 91 DAY) WHERE id=$dId;"
$r = Invoke-Json $dSess.s 'Get' '/api/profile' $null $dSess.csrf
$w = $r.body.data.profile.availability_window
$storedAfter = DbQuery "SELECT donor_availability FROM users WHERE id=$dId;"
if ($w.blocked -eq $false -and $storedAfter -eq 'standby' -and $storedBefore -eq 'standby') {
    Ok 'T11 windows expired: unblocked while stored standby untouched (read model)'
} else { Bad 'T11' "blocked=$($w.blocked) stored=$storedAfter" }

$r = Invoke-Json $off.s 'Post' "/api/officer/requests/$probeReq/re-match" @{} $off.csrf
if ($r.status -ne 200) { Bad 'T12a rematch call' "status=$($r.status) raw=$($r.raw)" }
$r = Invoke-Json $reqSess.s 'Get' "/api/requests/$probeReq/matches" $null $reqSess.csrf
if ($r.status -ne 200) { Bad 'T12b GET' "status=$($r.status) raw=$($r.raw)" }
$backIn = @($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$dId" }).Count
if ($backIn -ge 1) { Ok 'T12 expired-windows standby donor re-enters pool via read model' } else { Bad 'T12' "absent; count=$(@($r.body.data.matches).Count)" }

# ===== T13 availability transitions after expiry =====
$r = Invoke-Json $dSess.s 'Post' '/api/profile/donor-availability' @{ availability = 'unavailable' } $dSess.csrf
$dbAv = DbQuery "SELECT donor_availability FROM users WHERE id=$dId;"
if ($r.status -eq 200 -and $dbAv -eq 'unavailable') { Ok 'T13 unavailable allowed when otherwise eligible' } else { Bad 'T13' "got $($r.status)" }
$r = Invoke-Json $dSess.s 'Post' '/api/profile/donor-availability' @{ availability = 'available' } $dSess.csrf
$dbAv = DbQuery "SELECT donor_availability FROM users WHERE id=$dId;"
if ($r.status -eq 200 -and $dbAv -eq 'available') { Ok 'T14 available allowed after windows end' } else { Bad 'T14' "got $($r.status)" }

# ===== T15 audits =====
$acts = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('donation.confirmed','donor.availability_changed','match.manual_rematch','request.created');"
if ([int]$acts -ge 4) { Ok "T15 audit events present ($acts distinct)" } else { Bad 'T15' "distinct=$acts" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
