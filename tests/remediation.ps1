param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe',
    [string]$PhpPath = 'D:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$script:pass = 0
$script:fail = 0
$script:skip = 0

function Ok($name) { $script:pass++; Write-Host "PASS  $name" }
function Bad($name, $why) { $script:fail++; Write-Host "FAIL  $name -> $why" }
function Skip($name, $why) { $script:skip++; Write-Host "SKIP  $name -> $why" }

function Get-Csrf($session) {
    $r = Invoke-RestMethod -Uri "$BaseUrl/api/csrf" -Method Get -WebSession $session -TimeoutSec 10 -UseBasicParsing
    return $r.data.csrf_token
}

function Invoke-Json($session, $method, $uri, $body, $csrf) {
    $headers = @{}
    if ($csrf) { $headers['X-CSRF-Token'] = $csrf }
    try {
        $args = @{ Uri = "$BaseUrl$uri"; Method = $method; WebSession = $session; TimeoutSec = 20; UseBasicParsing = $true }
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

function New-FixtureUser($email, $name, $role, $chapterId, $vs, $bloodType, $lat, $lng, $enrolled, $avail, $acct = 'active') {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'; if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    $btCols = 'NULL';  if ($null -ne $bloodType) { $btCols = "'$bloodType'" }
    $srcCols = 'NULL'; if ($null -ne $bloodType) { $srcCols = "'self_reported'" }
    $latSql = 'NULL';  if ($null -ne $lat) { $latSql = "$lat" }
    $lngSql = 'NULL';  if ($null -ne $lng) { $lngSql = "$lng" }
    $enrSql = 'NULL';  if ($enrolled) { $enrSql = 'UTC_TIMESTAMP()' }
    $avSql = 'NULL';   if ($null -ne $avail) { $avSql = "'$avail'" }
    $deactSql = 'NULL'; if ($acct -eq 'deactivated') { $deactSql = 'UTC_TIMESTAMP()' }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status,              account_status, deactivated_at, blood_type, blood_type_source, latitude, longitude, donor_enrolled_at,
             donor_availability, date_of_birth, email_verified_at)
             # Fixtures bypass /api/register by construction, so they are
             # grandfather-equivalent (migration 021): verified email marker.
             VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', '$acct', $deactSql, $btCols, $srcCols, $latSql, $lngSql, $enrSql, $avSql, '1995-06-15', UTC_TIMESTAMP());"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
}

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    # Server rotates CSRF at the login privilege boundary: adopt the fresh
    # token from the login response (falls back to an explicit re-fetch).
    $csrf = $r.body.data.csrf_token
    if ([string]::IsNullOrEmpty($csrf)) { $csrf = Get-Csrf $s }
    return @{ s = $s; csrf = $csrf }
}

function New-Req($sess, $bt, $qty, $fac, $urg, $nd, $loc) {
    $body = @{ required_blood_type = $bt; quantity_units = $qty; facility_name = $fac; needed_datetime = $nd; urgency = $urg }
    if ($null -ne $loc) { $body['location_id'] = $loc }
    $r = Invoke-Json $sess.s 'Post' '/api/requests' $body $sess.csrf
    if ($r.status -ne 201) { throw "request create failed: $($r.raw)" }
    return [int]$r.body.data.request.id
}

function Respond($sess, $matchId) {
    return Invoke-Json $sess.s 'Post' "/api/matches/$matchId/respond" @{ donor_share_consent = $true } $sess.csrf
}

function AcceptM($sess, $matchId) {
    return Invoke-Json $sess.s 'Post' "/api/matches/$matchId/accept" @{ requester_share_consent = $true } $sess.csrf
}

$suffix = "$(Get-Random)"
$fx = "REM$suffix"
$future = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')
$balangaLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030803000' LIMIT 1;")

Write-Host "== Remediation regression suite (audit follow-up) =="

# ---------- fixtures ----------
$reqEmail = "remreq$suffix@test.local"
New-FixtureUser $reqEmail 'Rem Requestor' 'member' 1 'verified' 'B+' 14.68 120.54 $false $null | Out-Null
$reqBEmail = "remreqb$suffix@test.local"
New-FixtureUser $reqBEmail 'Rem Requestor B' 'member' 1 'verified' 'B+' 14.68 120.54 $false $null | Out-Null
$reqCEmail = "remreqc$suffix@test.local"
New-FixtureUser $reqCEmail 'Rem Requestor C' 'member' 1 'verified' 'B+' 14.68 120.54 $false $null | Out-Null
$dAEmail = "remda$suffix@test.local";  $dA = New-FixtureUser $dAEmail 'Rem Donor A' 'member' 1 'verified' 'B+' 14.6765 120.5361 $true 'available'
$dBEmail = "remdb$suffix@test.local";  $dB = New-FixtureUser $dBEmail 'Rem Donor B' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$dCEmail = "remdc$suffix@test.local";  $dC = New-FixtureUser $dCEmail 'Rem Donor C' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$ofEmail = "remof$suffix@test.local";  New-FixtureUser $ofEmail 'Rem Officer' 'officer' 1 'verified' $null $null $null $false $null | Out-Null
$adEmail = "remad$suffix@test.local";  New-FixtureUser $adEmail 'Rem Admin' 'admin' $null 'verified' $null $null $null $false $null | Out-Null

$req = Login $reqEmail
$reqB = Login $reqBEmail
$reqC = Login $reqCEmail
$dASess = Login $dAEmail
$dBSess = Login $dBEmail
$dCSess = Login $dCEmail
$ofSess = Login $ofEmail
$adSess = Login $adEmail

# ---------- R01 confirm-after-CLOSED rejected, no side effects (C-01) ----------
$r1 = New-Req $req 'B+' 2 "$fx CloseCase" 'urgent' $future $balangaLocId
$m1 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r1 AND donor_id=$dA LIMIT 1;")
Respond $dASess $m1 | Out-Null
DbQuery "UPDATE matches SET status='CLOSED' WHERE id=$m1;" | Out-Null
$rep = Invoke-Json $dASess.s 'Post' '/api/donation-reports' @{ match_id = $m1 } $dASess.csrf
$st1 = DbQuery "SELECT status FROM matches WHERE id=$m1;"
$lvd1 = DbQuery "SELECT CONCAT(IFNULL(last_verified_donation_at,'NULL'),'|',IFNULL(donor_availability,'NULL')) FROM users WHERE id=$dA;"
if ($rep.status -eq 409 -and $st1 -eq 'CLOSED' -and $lvd1 -like 'NULL|*') { Ok 'R01 submit on CLOSED match rejected' } else { Bad 'R01' "submit=$($rep.status) match=$st1 donor=$lvd1" }

# ---------- R02 confirm-after-WITHDRAWN rejected, terminal preserved (C-01) ----------
$r2 = New-Req $req 'B+' 2 "$fx WithdrawCase" 'urgent' $future $balangaLocId
$m2 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r2 AND donor_id=$dB LIMIT 1;")
Respond $dBSess $m2 | Out-Null
$rp2 = Invoke-Json $dBSess.s 'Post' '/api/donation-reports' @{ match_id = $m2 } $dBSess.csrf
$rp2Id = [int]$rp2.body.data.report.id
$w2 = Invoke-Json $dBSess.s 'Post' "/api/matches/$m2/withdraw" @{} $dBSess.csrf
$cf2 = Invoke-Json $ofSess.s 'Post' "/api/officer/donation-reports/$rp2Id/confirm" @{} $ofSess.csrf
$st2 = DbQuery "SELECT status FROM matches WHERE id=$m2;"
$lvd2 = DbQuery "SELECT IFNULL(last_verified_donation_at,'NULL') FROM users WHERE id=$dB;"
if ($w2.status -eq 200 -and $cf2.status -eq 409 -and $st2 -eq 'WITHDRAWN' -and $lvd2 -eq 'NULL') { Ok 'R02 confirm-after-withdraw rejected, WITHDRAWN terminal, no side effects' } else { Bad 'R02' "w=$($w2.status) cf=$($cf2.status) st=$st2 lvd=$lvd2" }

# ---------- R03 confirm-after-cancel rejected (C-01) ----------
$r3 = New-Req $req 'B+' 1 "$fx CancelCase" 'routine' $future $balangaLocId
$m3 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r3 AND donor_id=$dC LIMIT 1;")
Respond $dCSess $m3 | Out-Null
$rp3 = Invoke-Json $dCSess.s 'Post' '/api/donation-reports' @{ match_id = $m3 } $dCSess.csrf
$rp3Id = [int]$rp3.body.data.report.id
Invoke-Json $req.s 'Post' "/api/requests/$r3/cancel" @{} $req.csrf | Out-Null
$cf3 = Invoke-Json $ofSess.s 'Post' "/api/officer/donation-reports/$rp3Id/confirm" @{} $ofSess.csrf
$st3 = DbQuery "SELECT status FROM matches WHERE id=$m3;"
if ($cf3.status -eq 409 -and ($st3 -eq 'CLOSED')) { Ok 'R03 confirm-after-cancel rejected' } else { Bad 'R03' "cf=$($cf3.status) st=$st3" }

# ---------- R04 capacity across accept + confirm (C-02) ----------
$r4 = New-Req $req 'B+' 1 "$fx CapCase" 'urgent' $future $balangaLocId
$m4a = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r4 AND donor_id=$dA LIMIT 1;")
$m4b = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r4 AND donor_id=$dB LIMIT 1;")
Respond $dASess $m4a | Out-Null
Respond $dBSess $m4b | Out-Null
$a1 = AcceptM $req $m4a
$a2 = AcceptM $req $m4b
$rp4 = Invoke-Json $dBSess.s 'Post' '/api/donation-reports' @{ match_id = $m4b } $dBSess.csrf
$rp4Id = [int]$rp4.body.data.report.id
$cf4 = Invoke-Json $ofSess.s 'Post' "/api/officer/donation-reports/$rp4Id/confirm" @{} $ofSess.csrf
$sum4 = DbQuery "SELECT CONCAT(SUM(status='ACCEPTED'),'+',SUM(status='COMPLETED')) FROM matches WHERE request_id=$r4;"
if ($a1.status -eq 200 -and $a2.status -eq 409 -and $cf4.status -eq 409 -and $sum4 -eq '1+0') { Ok 'R04 capacity held across accept and confirm paths' } else { Bad 'R04' "a1=$($a1.status) a2=$($a2.status) cf=$($cf4.status) sum=$sum4" }

# ---------- R05 duplicate donation report rejected (M-03) ----------
$r5 = New-Req $req 'B+' 1 "$fx DupReport" 'routine' $future $balangaLocId
$m5 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r5 AND donor_id=$dC LIMIT 1;")
Respond $dCSess $m5 | Out-Null
$s5a = Invoke-Json $dCSess.s 'Post' '/api/donation-reports' @{ match_id = $m5 } $dCSess.csrf
$s5b = Invoke-Json $dCSess.s 'Post' '/api/donation-reports' @{ match_id = $m5 } $dCSess.csrf
$n5 = DbQuery "SELECT COUNT(*) FROM donation_reports WHERE match_id=$m5 AND status='PENDING';"
if ($s5a.status -eq 201 -and $s5b.status -eq 409 -and $n5 -eq '1') { Ok 'R05 duplicate PENDING report rejected, exactly one row' } else { Bad 'R05' "a=$($s5a.status) b=$($s5b.status) n=$n5" }

# ---------- R06 withdraw terminal: re-respond rejected (state machine) ----------
$r6 = New-Req $req 'B+' 1 "$fx WdTerminal" 'routine' $future $balangaLocId
$m6 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r6 AND donor_id=$dA LIMIT 1;")
Respond $dASess $m6 | Out-Null
Invoke-Json $dASess.s 'Post' "/api/matches/$m6/withdraw" @{} $dASess.csrf | Out-Null
$rr6 = Respond $dASess $m6
$st6 = DbQuery "SELECT status FROM matches WHERE id=$m6;"
if ($rr6.status -eq 409 -and $st6 -eq 'WITHDRAWN') { Ok 'R06 WITHDRAWN terminal, re-respond rejected' } else { Bad 'R06' "rr=$($rr6.status) st=$st6" }

# ---------- R07 reset-request throttle identical for known/unknown (H-01) ----------
$unknownEmail = "remunknown$suffix@test.local"
$seqU = @()
for ($i = 1; $i -le 6; $i++) {
    $su = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $rr = Invoke-Json $su 'Post' '/api/password-reset/request' @{ email = $unknownEmail } (Get-Csrf $su)
    $seqU += $rr.status
}
$knownEmail = "remknown$suffix@test.local"
New-FixtureUser $knownEmail 'Rem Known' 'member' 1 'verified' 'O+' 14.68 120.54 $false $null | Out-Null
$seqK = @()
for ($i = 1; $i -le 6; $i++) {
    $s2 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $rr = Invoke-Json $s2 'Post' '/api/password-reset/request' @{ email = $knownEmail } (Get-Csrf $s2)
    $seqK += $rr.status
}
$u = ($seqU -join ','); $k = ($seqK -join ',')
if ($u -eq $k -and $seqU[5] -eq 429) { Ok "R07 reset throttle identical known/unknown ($k)" } else { Bad 'R07' "unknown=$u known=$k" }

# ---------- R08 atomic token consume: two concurrent confirms, one wins (H-02) ----------
$tokEmail = "remtok$suffix@test.local"
$tokUid = New-FixtureUser $tokEmail 'Rem Token' 'member' 1 'verified' 'O+' 14.68 120.54 $false $null
$tokPlain = 'a' * 64
$tokHash = (& $PhpPath -r "echo hash('sha256', '$tokPlain');")
DbQuery "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($tokUid, '$tokHash', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE));" | Out-Null
$confirmJob = {
    param($BaseUrl, $tok)
    try {
        $s = $null
        $csrfRes = Invoke-WebRequest -Uri "$BaseUrl/api/csrf" -Method Get -SessionVariable s -TimeoutSec 10 -UseBasicParsing
        $csrf = ($csrfRes.Content | ConvertFrom-Json).data.csrf_token
        $r = Invoke-WebRequest -Uri "$BaseUrl/api/password-reset/confirm" -Method Post -Body (@{ token = $tok; password = 'NewPass123' } | ConvertTo-Json) -ContentType 'application/json' -Headers @{ 'X-CSRF-Token' = $csrf } -WebSession $s -TimeoutSec 20 -UseBasicParsing
        return [int]$r.StatusCode
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { return -1 }
        return [int]$resp.StatusCode
    }
}
try {
    $j1 = Start-Job -ScriptBlock $confirmJob -ArgumentList $BaseUrl, $tokPlain
    $j2 = Start-Job -ScriptBlock $confirmJob -ArgumentList $BaseUrl, $tokPlain
    Wait-Job $j1, $j2 | Out-Null
    $c1 = Receive-Job $j1; $c2 = Receive-Job $j2
    Remove-Job $j1, $j2 -Force
    $codes = @($c1, $c2) | Sort-Object
    if (($codes -join ',') -eq '200,400') { Ok 'R08 concurrent confirms: exactly one success, one invalid' } else { Bad 'R08' "codes=$c1,$c2" }
} catch {
    Skip 'R08 concurrent confirms' 'background jobs unsupported in this host'
}
$reuseSess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$reuse = Invoke-Json $reuseSess 'Post' '/api/password-reset/confirm' @{ token = $tokPlain; password = 'NewPass123' } (Get-Csrf $reuseSess)
if ($reuse.status -eq 400) { Ok 'R08b consumed token not reusable' } else { Bad 'R08b' "got $($reuse.status)" }

# ---------- R09 reset on deactivated account rejected (H-08) ----------
$dxEmail = "remdx$suffix@test.local"
$dxUid = New-FixtureUser $dxEmail 'Rem Deact' 'member' 1 'verified' 'O+' 14.68 120.54 $false $null
$dxPlain = 'b' * 64
$dxHash = (& $PhpPath -r "echo hash('sha256', '$dxPlain');")
DbQuery "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($dxUid, '$dxHash', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE));" | Out-Null
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=UTC_TIMESTAMP() WHERE id=$dxUid;" | Out-Null
$dxSess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$dxr = Invoke-Json $dxSess 'Post' '/api/password-reset/confirm' @{ token = $dxPlain; password = 'NewPass123' } (Get-Csrf $dxSess)
if ($dxr.status -eq 403) { Ok 'R09 reset confirm on deactivated account rejected' } else { Bad 'R09' "got $($dxr.status)" }

# ---------- R10 session revocation on deactivate (H-08) ----------
$svEmail = "remsv$suffix@test.local"
$svUid = New-FixtureUser $svEmail 'Rem SessVer' 'member' 1 'verified' 'O+' 14.68 120.54 $false $null
$svSess = Login $svEmail
$meBefore = Invoke-Json $svSess.s 'Get' '/api/auth/me' $null $null
$deac = Invoke-Json $adSess.s 'Post' "/api/admin/users/$svUid/deactivate" @{} $adSess.csrf
$meAfter = Invoke-Json $svSess.s 'Get' '/api/auth/me' $null $null
if ($meBefore.status -eq 200 -and $deac.status -eq 200 -and $meAfter.status -eq 403) { Ok 'R10 deactivated session revoked via version check' } else { Bad 'R10' "before=$($meBefore.status) deac=$($deac.status) after=$($meAfter.status)" }

# ---------- R11 DOB change unenrolls under-16 donor (H-03) ----------
$ageEmail = "remage$suffix@test.local"
$ageUid = New-FixtureUser $ageEmail 'Rem Age' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$ageSess = Login $ageEmail
$r11 = New-Req $req 'B+' 1 "$fx AgeCase" 'routine' $future $balangaLocId
$m11 = DbQuery "SELECT id FROM matches WHERE request_id=$r11 AND donor_id=$ageUid LIMIT 1;"
$dob = Invoke-Json $ageSess.s 'Put' '/api/profile' @{ date_of_birth = '2015-01-01' } $ageSess.csrf
$enr11 = DbQuery "SELECT IFNULL(donor_enrolled_at,'NULL') FROM users WHERE id=$ageUid;"
$rr11 = Invoke-Json $ageSess.s 'Post' "/api/requests/$r11/respond" @{ donor_share_consent = $true } $ageSess.csrf
if ($dob.status -eq 200 -and $enr11 -eq 'NULL' -and $rr11.status -eq 409) { Ok 'R11 under-16 DOB change unenrolls donor, respond blocked' } else { Bad 'R11' "dob=$($dob.status) enr=$enr11 respond=$($rr11.status)" }

# ---------- R12 stale RESPONDED cannot be accepted after ineligibility (C-03) ----------
$stEmail = "remst$suffix@test.local"
$stUid = New-FixtureUser $stEmail 'Rem Stale' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$stSess = Login $stEmail
$r12 = New-Req $reqB 'B+' 1 "$fx StaleAccept" 'urgent' $future $balangaLocId
$m12 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r12 AND donor_id=$stUid LIMIT 1;")
Respond $stSess $m12 | Out-Null
DbQuery "UPDATE users SET donor_availability='unavailable' WHERE id=$stUid;" | Out-Null
$a12 = AcceptM $reqB $m12
if ($a12.status -eq 409) { Ok 'R12 stale RESPONDED (now unavailable) cannot be accepted' } else { Bad 'R12' "got $($a12.status)" }

# ---------- R13 verification rejection blocks pending RESPONDED acceptance (C-03) ----------
$rjEmail = "remrj$suffix@test.local"
$rjUid = New-FixtureUser $rjEmail 'Rem Reject' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$rjSess = Login $rjEmail
$r13 = New-Req $reqB 'B+' 1 "$fx RejCase" 'urgent' $future $balangaLocId
$m13 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r13 AND donor_id=$rjUid LIMIT 1;")
Respond $rjSess $m13 | Out-Null
DbQuery "UPDATE users SET verification_status='pending' WHERE id=$rjUid;" | Out-Null
$dec13 = Invoke-Json $ofSess.s 'Post' "/api/officer/verifications/$rjUid/decision" @{ decision = 'rejected'; reason = 'test rejection' } $ofSess.csrf
$a13 = AcceptM $reqB $m13
$st13 = DbQuery "SELECT status FROM matches WHERE id=$m13;"
if ($dec13.status -eq 200 -and $a13.status -eq 409 -and $st13 -eq 'RESPONDED') { Ok 'R13 rejected donor RESPONDED stays but cannot be accepted' } else { Bad 'R13' "dec=$($dec13.status) acc=$($a13.status) st=$st13" }

# ---------- R14 request detail hides coords from officers, shows to owner (H-04) ----------
$r14 = New-Req $reqB 'B+' 1 "$fx CoordCase" 'routine' $future $balangaLocId
$own14 = Invoke-Json $reqB.s 'Get' "/api/requests/$r14" $null $reqB.csrf
$off14 = Invoke-Json $ofSess.s 'Get' "/api/requests/$r14" $null $ofSess.csrf
$ownLat = $own14.body.data.request.latitude
$offLat = $off14.body.data.request.latitude
$offLoc = $off14.body.data.request.location
if ($own14.status -eq 200 -and $ownLat -ne $null -and $off14.status -eq 200 -and $offLat -eq $null -and $offLoc.municipality_name -ne $null) { Ok 'R14 coords owner-only; officer gets labels' } else { Bad 'R14' "own=$ownLat off=$offLat" }

# ---------- R15 feed has no private keys (schema-aware privacy scan) ----------
$feed15 = Invoke-Json $dASess.s 'Get' "/api/requests/feed?page_size=5" $null $dASess.csrf
$badKeys = @()
if ($feed15.status -eq 200) {
    foreach ($item in @($feed15.body.data.requests)) {
        foreach ($prop in $item.PSObject.Properties.Name) {
            if ($prop -in @('email', 'phone', 'latitude', 'longitude', 'password', 'document', 'donor_share_consent', 'requester_share_consent')) {
                $badKeys += $prop
            }
        }
    }
}
if ($feed15.status -eq 200 -and $badKeys.Count -eq 0) { Ok 'R15 feed payload carries no private keys' } else { Bad 'R15' "status=$($feed15.status) bad=$($badKeys -join ',')" }

# ---------- R16 contact authorization matrix (privacy) ----------
$r16 = New-Req $reqB 'B+' 1 "$fx ContactCase" 'urgent' $future $balangaLocId
$m16 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r16 AND donor_id=$dA LIMIT 1;")
Respond $dASess $m16 | Out-Null
$stEmail2 = "remstr$suffix@test.local"
New-FixtureUser $stEmail2 'Rem Stranger' 'member' 2 'verified' 'O+' 14.43 120.48 $false $null | Out-Null
$strSess = Login $stEmail2
$cStr = Invoke-Json $strSess.s 'Get' "/api/matches/$m16/contact" $null $strSess.csrf
$cPre = Invoke-Json $dASess.s 'Get' "/api/matches/$m16/contact" $null $dASess.csrf
AcceptM $reqB $m16 | Out-Null
$cPost = Invoke-Json $dASess.s 'Get' "/api/matches/$m16/contact" $null $dASess.csrf
$hasBoth = ($cPost.status -eq 200 -and $cPost.body.data.contact.donor_email -ne $null -and $cPost.body.data.contact.requester_email -ne $null)
Invoke-Json $reqB.s 'Post' "/api/matches/$m16/unaccept" @{} $reqB.csrf | Out-Null
$cRev = Invoke-Json $dASess.s 'Get' "/api/matches/$m16/contact" $null $dASess.csrf
if ($cStr.status -eq 404 -and $cPre.status -eq 403 -and $hasBoth -and $cRev.status -eq 403) { Ok 'R16 contact: stranger 404, pre-accept 403, accepted 200, revoked after unaccept 403' } else { Bad 'R16' "str=$($cStr.status) pre=$($cPre.status) post=$($cPost.status) rev=$($cRev.status)" }

# ---------- R17 fulfillment notifies requester (M-07) ----------
$r17 = New-Req $reqB 'B+' 1 "$fx FulfillNote" 'urgent' $future $balangaLocId
$m17 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r17 AND donor_id=$dB LIMIT 1;")
Respond $dBSess $m17 | Out-Null
AcceptM $reqB $m17 | Out-Null
$rp17 = Invoke-Json $dBSess.s 'Post' '/api/donation-reports' @{ match_id = $m17 } $dBSess.csrf
$cf17 = Invoke-Json $ofSess.s 'Post' "/api/officer/donation-reports/$([int]$rp17.body.data.report.id)/confirm" @{} $ofSess.csrf
$reqSt17 = DbQuery "SELECT status FROM blood_requests WHERE id=$r17;"
$note17 = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=(SELECT requester_id FROM blood_requests WHERE id=$r17) AND type='request.fulfilled' AND related_id=$r17;"
if ($cf17.status -eq 200 -and $reqSt17 -eq 'FULFILLED' -and $note17 -ge '1') { Ok 'R17 fulfillment closes request and notifies requester' } else { Bad 'R17' "cf=$($cf17.status) st=$reqSt17 notes=$note17" }

# ---------- R18 matches + my/requests pagination metadata (M-11) ----------
$pg18 = Invoke-Json $reqB.s 'Get' "/api/requests/$r17/matches?page=1&page_size=1" $null $reqB.csrf
$mine18 = Invoke-Json $req.s 'Get' '/api/my/requests?page=1&page_size=2' $null $req.csrf
if ($pg18.status -eq 200 -and $pg18.body.data.total -ge 1 -and $pg18.body.data.page -eq 1 -and $mine18.status -eq 200 -and $mine18.body.data.total -ge 1) { Ok 'R18 matches + my/requests pagination metadata present' } else { Bad 'R18' "m=$($pg18.status) mine=$($mine18.status)" }

# ---------- R19 validation boundaries + envelopes (M-14) ----------
$v19a = Invoke-Json $req.s 'Put' '/api/profile' @{ phone = 'x' * 60 } $req.csrf
$regSess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$regCsrf = Get-Csrf $regSess
$v19b = Invoke-Json $regSess 'Post' '/api/register' @{ full_name = 'Rem Bad'; email = "rembad$suffix@test.local"; password = 'Str0ngPass1'; chapter_id = 1; date_of_birth = '1995-01-01'; blood_type = 'O+'; phone = '!!!'; privacy_acknowledged = $true } $regCsrf
$v19c = Invoke-Json $ofSess.s 'Post' "/api/officer/verifications/999999/decision" @{ decision = 'maybe' } $ofSess.csrf
$env19 = ($v19c.body -ne $null -and $v19c.body.success -eq $false)
if ($v19a.status -eq 400 -and $v19b.status -eq 400 -and $v19c.status -eq 400 -and $env19) { Ok 'R19 phone/register/decision validation 400 with envelope' } else { Bad 'R19' "a=$($v19a.status) b=$($v19b.status) c=$($v19c.status)" }

# ---------- R20 CSRF-negative rejected (security) ----------
# NOTE: PowerShell persists -Headers into the WebSession object, so a
# "no header" probe must strip the persisted token first; otherwise the
# request carries a valid token and the test is meaningless.
$csrfProbe = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrfProbeCsrf = Get-Csrf $csrfProbe
$csrfProbeLogin = Invoke-Json $csrfProbe 'Post' '/api/login' @{ email = $reqEmail; password = 'Str0ngPass1' } $csrfProbeCsrf
$csrfProbe.Headers.Remove('X-CSRF-Token') | Out-Null
$csrfBody = ''
try {
    $noCsrf = Invoke-WebRequest -Uri "$BaseUrl/api/requests" -Method Post -Body (@{ required_blood_type = 'O+'; quantity_units = 1; facility_name = 'Valid Facility'; needed_datetime = $future; urgency = 'routine' } | ConvertTo-Json) -ContentType 'application/json' -WebSession $csrfProbe -TimeoutSec 10 -UseBasicParsing
    $csrfSt = [int]$noCsrf.StatusCode
} catch {
    $csrfSt = [int]$_.Exception.Response.StatusCode
    try {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $csrfBody = $reader.ReadToEnd()
    } catch { $csrfBody = '' }
}
if ($csrfSt -eq 403) { Ok 'R20 state-changing POST without CSRF rejected 403' } else { Bad 'R20' "got $csrfSt body=$csrfBody" }

# ---------- R21 notification dedup columns NOT NULL (H-07 schema) ----------
$nn21 = DbQuery "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='bloodmatch_dev' AND table_name='notifications' AND column_name IN ('dedup_key','generation') AND is_nullable='NO';"
if ($nn21 -eq '2') { Ok 'R21 notification dedup columns NOT NULL enforced' } else { Bad 'R21' "nullable-cols=$nn21" }

# ---------- R22 analytics + demand-map contract keys (H-05) ----------
$dm22 = Invoke-Json $ofSess.s 'Get' '/api/demand-map?days=30' $null $ofSess.csrf
$an22 = Invoke-Json $ofSess.s 'Get' '/api/analytics/summary' $null $ofSess.csrf
$dmOk = ($dm22.status -eq 200 -and $dm22.body.data.chapters.Count -ge 1 -and $dm22.body.data.chapters[0].blood_type_counts -ne $null -and $dm22.body.data.chapters[0].open_requests_count -ne $null)
$anOk = ($an22.status -eq 200 -and $an22.body.data.request_volume.total_requests -ne $null -and $an22.body.data.demand_by_blood_type.'O+'.requests -ne $null -and $an22.body.data.daily_request_trend -ne $null)
if ($dmOk -and $anOk) { Ok 'R22 demand-map + analytics contract keys present' } else { Bad 'R22' "dm=$($dm22.status) an=$($an22.status)" }

# ---------- R23 expiry sweep closes matches + notifies (M-09) ----------
$r23 = New-Req $reqC 'B+' 1 "$fx ExpireCase" 'routine' $future $balangaLocId
$m23 = DbQuery "SELECT id FROM matches WHERE request_id=$r23 AND donor_id=$dA LIMIT 1;"
$past = (Get-Date).ToUniversalTime().AddMinutes(-5).ToString('yyyy-MM-dd HH:mm:ss')
DbQuery "UPDATE blood_requests SET needed_datetime='$past' WHERE id=$r23;" | Out-Null
# Swallow mailer stderr diagnostics without tripping $ErrorActionPreference='Stop'.
$prevEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $PhpPath "$PSScriptRoot/../database/run_expiry.php" 2>&1 | ForEach-Object { "$_" } | Out-Null
$ErrorActionPreference = $prevEAP
$st23 = DbQuery "SELECT status FROM blood_requests WHERE id=$r23;"
$mm23 = DbQuery "SELECT status FROM matches WHERE id=$m23;"
$nt23 = DbQuery "SELECT COUNT(*) FROM notifications WHERE related_type='blood_request' AND related_id=$r23 AND type='request.expired';"
if ($st23 -eq 'EXPIRED' -and $mm23 -eq 'CLOSED' -and $nt23 -ge '1') { Ok 'R23 expiry closes request + matches with notice' } else { Bad 'R23' "req=$st23 match=$mm23 notes=$nt23" }

# ---------- R24 deactivation closes ACCEPTED + revokes contact ----------
$dvEmail = "remdv$suffix@test.local"
$dvUid = New-FixtureUser $dvEmail 'Rem DeactV' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available'
$dvSess = Login $dvEmail
$r24 = New-Req $reqC 'B+' 1 "$fx DeactCase" 'urgent' $future $balangaLocId
$m24 = [int](DbQuery "SELECT id FROM matches WHERE request_id=$r24 AND donor_id=$dvUid LIMIT 1;")
Respond $dvSess $m24 | Out-Null
AcceptM $reqC $m24 | Out-Null
$dc24 = Invoke-Json $adSess.s 'Post' "/api/admin/users/$dvUid/deactivate" @{} $adSess.csrf
$st24 = DbQuery "SELECT status FROM matches WHERE id=$m24;"
$ct24 = Invoke-Json $reqC.s 'Get' "/api/matches/$m24/contact" $null $reqC.csrf
if ($dc24.status -eq 200 -and $st24 -eq 'CLOSED' -and $ct24.status -eq 403) { Ok 'R24 deactivation closes ACCEPTED and revokes contact' } else { Bad 'R24' "dc=$($dc24.status) st=$st24 ct=$($ct24.status)" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed, $($script:skip) skipped =="
if ($script:fail -gt 0) { exit 1 }
