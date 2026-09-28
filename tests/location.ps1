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
    $params = @{
        Uri = "$BaseUrl$uri"; Method = $method; WebSession = $session
        TimeoutSec = 10; UseBasicParsing = $true
    }
    if ($null -ne $body) {
        $params['Body'] = ($body | ConvertTo-Json)
        $params['ContentType'] = 'application/json'
    }
    if ($headers.Count -gt 0) { $params['Headers'] = $headers }
    try {
        $res = Invoke-WebRequest @params
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json); raw = $res.Content }
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
        return @{ status = $status; body = $parsed; raw = $raw }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $csrf }
}

function New-FixtureUser($email, $name, $chapterId, $vs, $bloodType) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status, blood_type, blood_type_source, date_of_birth) VALUES ('$email', '$hash', '$name', 'member', $chapterId, '$vs', 'active', '$bloodType', 'self_reported', '1995-06-15');"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
}

$suffix = "$(Get-Random)"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host "== Location reference (municipality/barangay) audit =="

# --- L01 reference endpoints ---
$s0 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-Json $s0 'Get' '/api/locations/municipalities' $null $null
$orani = @($r.body.data.municipalities | Where-Object { $_.psgc_code -eq '030809000' })
if ($r.status -eq 200 -and $r.body.data.municipalities.Count -eq 12 -and $orani.Count -eq 1) {
    Ok 'L01 12 Bataan municipalities listed (Orani present)'
} else { Bad 'L01' "got $($r.status) count=$($r.body.data.municipalities.Count)" }
$oraniLocId = $orani[0].location_id
$balangaLocId = (@($r.body.data.municipalities | Where-Object { $_.psgc_code -eq '030803000' }))[0].location_id

$r = Invoke-Json $s0 'Get' '/api/locations/barangays?municipality_code=030809000' $null $null
$tugatog = @($r.body.data.barangays | Where-Object { $_.psgc_code -eq '030809023' })
if ($r.status -eq 200 -and $r.body.data.barangays.Count -eq 29 -and $tugatog.Count -eq 1) {
    Ok 'L02 Orani barangays listed (29, Tugatog present)'
} else { Bad 'L02' "got $($r.status) count=$($r.body.data.barangays.Count)" }
$tugatogLocId = $tugatog[0].location_id

$r = Invoke-Json $s0 'Get' '/api/locations/barangays?municipality_code=999999999' $null $null
if ($r.status -eq 400) { Ok 'L03 invalid municipality rejected (400)' } else { Bad 'L03' "got $($r.status)" }
$r = Invoke-Json $s0 'Get' '/api/locations/barangays' $null $null
if ($r.status -eq 400) { Ok 'L04 missing municipality rejected (400)' } else { Bad 'L04' "got $($r.status)" }

# --- fixtures: verified member (enrolled donor) + legacy coords-only donor ---
$memEmail = "locmem$suffix@test.local"
$memId = New-FixtureUser $memEmail 'Loc Member' 1 'verified' 'O+'
$mem = Login $memEmail
$legEmail = "locleg$suffix@test.local"
$legId = New-FixtureUser $legEmail 'Legacy Coords' 1 'verified' 'O+'
DbQuery "UPDATE users SET latitude=14.681, longitude=120.541, donor_enrolled_at=UTC_TIMESTAMP(), donor_availability='available' WHERE id=$legId;"

# --- L05 profile: municipality-only resolves coords ---
$r = Invoke-Json $mem.s 'Put' '/api/profile' @{ full_name = 'Loc Member'; location_id = $oraniLocId } $mem.csrf
if ($r.status -eq 200 -and $r.body.data.profile.location.municipality_name -eq 'Orani' -and $r.body.data.profile.latitude -eq 14.8) {
    Ok 'L05 profile municipality resolves to Orani reference coords'
} else { Bad 'L05' "got $($r.status): $($r.raw)" }

# --- L06 profile: barangay resolves ---
$r = Invoke-Json $mem.s 'Put' '/api/profile' @{ full_name = 'Loc Member'; location_id = $tugatogLocId } $mem.csrf
if ($r.status -eq 200 -and $r.body.data.profile.location.barangay_name -eq 'Tugatog') {
    Ok 'L06 profile barangay (Orani -> Tugatog) saved with names'
} else { Bad 'L06' "got $($r.status): $($r.raw)" }

# --- L07 invalid location rejected ---
$r = Invoke-Json $mem.s 'Put' '/api/profile' @{ location_id = 999999 } $mem.csrf
if ($r.status -eq 400 -and $r.body.error.details.location_id) { Ok 'L07 invalid location_id rejected' } else { Bad 'L07' "got $($r.status)" }

# --- L08 raw coordinate injection rejected ---
$r = Invoke-Json $mem.s 'Put' '/api/profile' @{ latitude = 14.5; longitude = 120.5 } $mem.csrf
if ($r.status -eq 400 -and $r.body.error.details.location_id) { Ok 'L08 arbitrary coordinates rejected on profile' } else { Bad 'L08' "got $($r.status)" }

# --- L09 request with municipality-only location ---
$r = Invoke-Json $mem.s 'Post' '/api/profile/enroll-donor' @{} $mem.csrf
if ($r.status -ne 200) { throw "enroll failed ($($r.status))" }
$r = Invoke-Json $mem.s 'Post' '/api/requests' @{
    required_blood_type='O+'; facility_name='Orani Clinic'; needed_datetime=$future; location_id=$oraniLocId
} $mem.csrf
if ($r.status -eq 201 -and $r.body.data.request.location.municipality_name -eq 'Orani' -and $r.body.data.request.latitude -eq 14.8) {
    Ok 'L09 request municipality resolves coords + location names'
} else { Bad 'L09' "got $($r.status): $($r.raw)" }
$reqId = $r.body.data.request.id

# --- L10 request without location still allowed (optional preserved) ---
$r = Invoke-Json $mem.s 'Post' '/api/requests' @{
    required_blood_type='O+'; facility_name='NoLoc Clinic'; needed_datetime=$future
} $mem.csrf
if ($r.status -eq 201 -and $null -eq $r.body.data.request.location -and $null -eq $r.body.data.request.latitude) {
    Ok 'L10 request without location stays optional (null coords)'
} else { Bad 'L10' "got $($r.status): $($r.raw)" }

# --- L11 request raw coordinates rejected ---
$r = Invoke-Json $mem.s 'Post' '/api/requests' @{
    required_blood_type='O+'; facility_name='Bad Clinic'; needed_datetime=$future; latitude=1.0; longitude=2.0
} $mem.csrf
if ($r.status -eq 400 -and $r.body.error.details.location_id) { Ok 'L11 arbitrary coordinates rejected on request' } else { Bad 'L11' "got $($r.status)" }

# --- L12 request location edit regenerates (material change) ---
$r = Invoke-Json $mem.s 'Put' "/api/requests/$reqId" @{ location_id = $balangaLocId } $mem.csrf
$mat = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='request.material_change' AND target_id='$reqId';"
$coords = DbQuery "SELECT CONCAT(latitude,'|',longitude) FROM blood_requests WHERE id=$reqId;"
if ($r.status -eq 200 -and [int]$mat -ge 1 -and $coords -like '14.683333*|120.533333*') {
    Ok 'L12 location edit is material: coords re-resolved + regeneration audited'
} else { Bad 'L12' "got $($r.status) mat=$mat coords=$coords" }

# --- L13 member-facing APIs expose no raw coordinates ---
$r = Invoke-Json $mem.s 'Get' "/api/requests/$reqId/matches" $null $mem.csrf
$forbidden = 'latitude|"phone"|"email"|password|document'
if ($r.status -eq 200 -and $r.raw -notmatch $forbidden -and $r.raw -match 'approximate_distance_km') {
    Ok 'L13 match results privacy-safe (no raw coordinates)'
} else { Bad 'L13' 'forbidden field detected' }

# --- L14 legacy record (coords, no location_id) still matches ---
# (legacy donor created up-front so request creation included it)
$r = Invoke-Json $mem.s 'Get' "/api/requests/$reqId/matches" $null $mem.csrf
$legEntry = @($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$legId" })
if ($legEntry.Count -eq 1 -and $null -ne $legEntry[0].approximate_distance_km) {
    Ok 'L14 legacy coords-only donor still matched with distance'
} else { Bad 'L14' "found $($legEntry.Count)" }

# --- L15 barangay resolves to its municipality's reference point (honest granularity) ---
$muniCoords = DbQuery "SELECT CONCAT(latitude,'|',longitude) FROM bataan_locations WHERE psgc_code='030809000' LIMIT 1;"
$brgyCoords = DbQuery "SELECT CONCAT(latitude,'|',longitude) FROM bataan_locations WHERE psgc_code='030809023' LIMIT 1;"
if ($brgyCoords -eq $muniCoords) { Ok 'L15 Tugatog shares Orani municipal reference point (documented)' } else { Bad 'L15' "muni=$muniCoords brgy=$brgyCoords" }

# --- L16 selector APIs disclose no coordinates ---
$r = Invoke-Json $s0 'Get' '/api/locations/municipalities' $null $null
$r2 = Invoke-Json $s0 'Get' '/api/locations/barangays?municipality_code=030809000' $null $null
if ($r.raw -notmatch 'latitude' -and $r.raw -notmatch 'longitude' -and $r2.raw -notmatch 'latitude' -and $r2.raw -notmatch 'longitude') {
    Ok 'L16 location selector APIs expose no coordinates'
} else { Bad 'L16' 'coordinate disclosure in selector API' }

# --- L17-L20 donor location change reorders live matches without new generation ---
$samalLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030812000' LIMIT 1;")
$abucayLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030801000' LIMIT 1;")
$marivelesLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030807000' LIMIT 1;")
$aEmail = "loca$suffix@test.local"
$aId = New-FixtureUser $aEmail 'Donor Alpha' 1 'verified' 'O+'
$a = Login $aEmail
Invoke-Json $a.s 'Put' '/api/profile' @{ full_name = 'Donor Alpha'; location_id = $samalLocId } $a.csrf | Out-Null
Invoke-Json $a.s 'Post' '/api/profile/enroll-donor' @{} $a.csrf | Out-Null
$bEmail = "locb$suffix@test.local"
$bId = New-FixtureUser $bEmail 'Donor Beta' 1 'verified' 'O+' | Out-Null
$bId = DbQuery "SELECT id FROM users WHERE email='$bEmail';"
$b = Login $bEmail
Invoke-Json $b.s 'Put' '/api/profile' @{ full_name = 'Donor Beta'; location_id = $abucayLocId } $b.csrf | Out-Null
Invoke-Json $b.s 'Post' '/api/profile/enroll-donor' @{} $b.csrf | Out-Null
$r = Invoke-Json $mem.s 'Post' '/api/requests' @{
    required_blood_type='O+'; facility_name='Orani Reorder Clinic'; needed_datetime=$future; location_id=$oraniLocId
} $mem.csrf
$reReqId = $r.body.data.request.id
$r = Invoke-Json $mem.s 'Get' "/api/requests/$reReqId/matches" $null $mem.csrf
$order1 = @($r.body.data.matches | ForEach-Object { $_.donor_reference })
$aFirst = [array]::IndexOf($order1, "donor-$aId") -lt [array]::IndexOf($order1, "donor-$bId")
$genBefore = DbQuery "SELECT generation FROM matches WHERE request_id=$reReqId AND donor_id=$aId;"
$notifBefore = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$aId AND related_id=$reReqId;"
if ($aFirst) { Ok 'L17 nearer donor (Samal) ranks before farther donor (Abucay)' } else { Bad 'L17' "order=$($order1 -join ',')" }
$r = Invoke-Json $a.s 'Put' '/api/profile' @{ full_name = 'Donor Alpha'; location_id = $marivelesLocId } $a.csrf
$hasRefresh = ($r.body.data.matches_refreshed -contains $reReqId)
$r = Invoke-Json $mem.s 'Get' "/api/requests/$reReqId/matches" $null $mem.csrf
$order2 = @($r.body.data.matches | ForEach-Object { $_.donor_reference })
$bFirst = [array]::IndexOf($order2, "donor-$bId") -lt [array]::IndexOf($order2, "donor-$aId")
$aDist = ($r.body.data.matches | Where-Object { $_.donor_reference -eq "donor-$aId" }).approximate_distance_km
$genAfter = DbQuery "SELECT generation FROM matches WHERE request_id=$reReqId AND donor_id=$aId;"
$notifAfter = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$aId AND related_id=$reReqId;"
if ($hasRefresh) { Ok 'L18 profile update reports refreshed request' } else { Bad 'L18' 'matches_refreshed missing' }
if ($bFirst -and $aDist -gt 30) { Ok "L19 order flipped after move (Alpha now ~$aDist km)" } else { Bad 'L19' "order=$($order2 -join ',') dist=$aDist" }
if ($genAfter -eq $genBefore -and $notifAfter -eq $notifBefore) { Ok "L20 no generation bump, no duplicate notification (gen $genAfter, notifs $notifAfter)" } else { Bad 'L20' "gen=$genBefore->$genAfter notif=$notifBefore->$notifAfter" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
