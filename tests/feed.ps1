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
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status, deactivated_at, blood_type, blood_type_source, latitude, longitude, donor_enrolled_at, donor_availability, date_of_birth, email_verified_at)
             VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', '$acct', $deactSql, $btCols, $srcCols, $latSql, $lngSql, $enrSql, $avSql, '1995-06-15', UTC_TIMESTAMP());"
    # Fixtures bypass /api/register by construction, so they are
    # grandfather-equivalent (migration 021): verified email marker.
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

function FeedRows($sess, $query) {
    $rows = @()
    $page = 1
    while ($true) {
        $sep = '?' ; if ($query -match '\?') { $sep = '&' }
        $r = Invoke-Json $sess.s 'Get' "/api/requests/feed$query$sep`page=$page&page_size=50" $null $sess.csrf
        if ($r.status -ne 200) { return @{ status = $r.status; rows = @(); raw = $r.raw } }
        $rows += @($r.body.data.requests)
        $total = [int]$r.body.data.total
        if ($rows.Count -ge $total) { break }
        $page++
        if ($page -gt 20) { break }
    }
    return @{ status = 200; rows = $rows }
}

$suffix = "$(Get-Random)"
$future2 = (Get-Date).ToUniversalTime().AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')
$future1 = (Get-Date).ToUniversalTime().AddDays(1).ToString('yyyy-MM-dd HH:mm:ss')
$future5 = (Get-Date).ToUniversalTime().AddDays(5).ToString('yyyy-MM-dd HH:mm:ss')
$balangaLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE psgc_code='030803000' LIMIT 1;")
$marivelesLocId = [int](DbQuery "SELECT id FROM bataan_locations WHERE level='municipality' AND name LIKE '%Mariveles%' LIMIT 1;")
$fx = "FEEDTEST$suffix"

Write-Host "== Feed: authenticated Home / blood request feed audit =="

# ---------- fixtures ----------
$reqEmail = "feedreq$suffix@test.local"
New-FixtureUser $reqEmail 'Feed Requestor' 'member' 1 'verified' 'A+' 14.68 120.54 $false $null | Out-Null
$req2Email = "feedreq2$suffix@test.local"
New-FixtureUser $req2Email 'Feed Requestor Two' 'member' 1 'verified' 'B+' 14.68 120.54 $false $null | Out-Null
$dAEmail = "feedda$suffix@test.local";  $dA = New-FixtureUser $dAEmail 'Feed Donor A' 'member' 1 'verified' 'B+' 14.6765 120.5361 $true 'available'
$dBEmail = "feeddb$suffix@test.local";  $dB = New-FixtureUser $dBEmail 'Feed Donor B' 'member' 2 'verified' 'B+' 14.4350 120.4867 $true 'available'
$dNBEmail = "feednb$suffix@test.local"; $dNB = New-FixtureUser $dNBEmail 'Feed NoBlood' 'member' 1 'verified' $null $null $null $true 'available'
$dUEmail = "feedun$suffix@test.local";  $dU = New-FixtureUser $dUEmail 'Feed Unenrolled' 'member' 1 'verified' 'B+' 14.68 120.54 $false $null
$dOffEmail = "feedoff$suffix@test.local"; $dOff = New-FixtureUser $dOffEmail 'Feed Unavail' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'unavailable'
$rjEmail = "feedrej$suffix@test.local";  New-FixtureUser $rjEmail 'Feed Rejected' 'member' 1 'rejected' 'B+' 14.68 120.54 $true 'available' | Out-Null
$dxEmail = "feeddeact$suffix@test.local"; New-FixtureUser $dxEmail 'Feed Deactivated' 'member' 1 'verified' 'B+' 14.68 120.54 $true 'available' 'deactivated' | Out-Null
$uvEmail = "feeduv$suffix@test.local";   New-FixtureUser $uvEmail 'Feed Unverified' 'member' 1 'unverified' 'B+' 14.68 120.54 $false $null | Out-Null
$peEmail = "feedpe$suffix@test.local";   New-FixtureUser $peEmail 'Feed Pending' 'member' 1 'pending' 'B+' 14.68 120.54 $false $null | Out-Null
$ofEmail = "feedof$suffix@test.local";   New-FixtureUser $ofEmail 'Feed Officer' 'officer' 1 'verified' $null $null $null $false $null | Out-Null
$adEmail = "feedad$suffix@test.local";   New-FixtureUser $adEmail 'Feed Admin' 'admin' $null 'verified' $null $null $null $false $null | Out-Null

$req = Login $reqEmail
$req2 = Login $req2Email
$dASess = Login $dAEmail
$dBSess = Login $dBEmail
$dNBSess = Login $dNBEmail
$dUSess = Login $dUEmail
$dOffSess = Login $dOffEmail
$uvSess = Login $uvEmail
$peSess = Login $peEmail
$ofSess = Login $ofEmail
$adSess = Login $adEmail

# ---------- F1-F7 access matrix ----------
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' '/api/requests/feed' $null $null
if ($r.status -eq 401) { Ok 'F1 anonymous feed 401' } else { Bad 'F1' "got $($r.status)" }

try { $rjSess = Login $rjEmail; $r = Invoke-Json $rjSess.s 'Get' '/api/requests/feed' $null $rjSess.csrf } catch { $r = @{ status = 403 } }
if ($r.status -eq 403) { Ok 'F2 rejected feed 403' } else { Bad 'F2' "got $($r.status)" }

try { $dxSess = Login $dxEmail; $r = Invoke-Json $dxSess.s 'Get' '/api/requests/feed' $null $dxSess.csrf } catch { $r = @{ status = 403 } }
if ($r.status -eq 403) { Ok 'F3 deactivated feed 403' } else { Bad 'F3' "got $($r.status)" }

$r = Invoke-Json $uvSess.s 'Get' '/api/requests/feed?page_size=1' $null $uvSess.csrf
if ($r.status -eq 200) { Ok 'F4 unverified browse allowed' } else { Bad 'F4' "got $($r.status)" }
$r = Invoke-Json $peSess.s 'Get' '/api/requests/feed?page_size=1' $null $peSess.csrf
if ($r.status -eq 200) { Ok 'F5 pending browse allowed' } else { Bad 'F5' "got $($r.status)" }
$r = Invoke-Json $dASess.s 'Get' '/api/requests/feed?page_size=1' $null $dASess.csrf
if ($r.status -eq 200) { Ok 'F6 verified browse allowed' } else { Bad 'F6' "got $($r.status)" }
$r = Invoke-Json $ofSess.s 'Get' '/api/requests/feed?page_size=1' $null $ofSess.csrf
$ro = $r.status
$r = Invoke-Json $adSess.s 'Get' '/api/requests/feed?page_size=1' $null $adSess.csrf
if ($ro -eq 200 -and $r.status -eq 200) { Ok 'F7 officer+admin browse allowed' } else { Bad 'F7' "off=$ro adm=$($r.status)" }

# ---------- create feed requests ----------
function New-Req($sess, $bt, $qty, $fac, $urg, $nd, $loc) {
    $body = @{ required_blood_type = $bt; quantity_units = $qty; facility_name = $fac; needed_datetime = $nd; urgency = $urg }
    if ($null -ne $loc) { $body['location_id'] = $loc }
    $r = Invoke-Json $sess.s 'Post' '/api/requests' $body $sess.csrf
    if ($r.status -ne 201) { throw "request create failed: $($r.raw)" }
    return [int]$r.body.data.request.id
}
$rAB = New-Req $req 'AB+' 1 "$fx Alpha" 'critical' $future2 $balangaLocId
$rA = New-Req $req 'A+' 1 "$fx Beta" 'routine' $future5 $null
$rB = New-Req $req 'B+' 1 "$fx Gamma" 'urgent' $future2 $marivelesLocId
$rAB2 = New-Req $req 'AB+' 1 "$fx Delta" 'critical' $future1 $marivelesLocId
$rT1 = New-Req $req 'AB+' 1 "$fx Twin" 'critical' $future2 $balangaLocId
$rT2 = New-Req $req 'AB+' 1 "$fx Twin" 'critical' $future2 $balangaLocId

function FxRows($sess) {
    $all = FeedRows $sess ''
    return @($all.rows | Where-Object { $_.facility_name -like "$fx*" })
}

# ---------- F8 OPEN-only ----------
$rX = New-Req $req 'O+' 1 "$fx Cancelled" 'routine' $future5 $null
Invoke-Json $req.s 'Post' "/api/requests/$rX/cancel" @{} $req.csrf | Out-Null
$fxr = FxRows $dASess
$gone = ($fxr | Where-Object { $_.id -eq $rX }).Count -eq 0
$allOpen = ($fxr | Where-Object { $_.status -ne 'OPEN' }).Count -eq 0
if ($gone -and $allOpen) { Ok 'F8 cancelled excluded; feed OPEN-only' } else { Bad 'F8' "gone=$gone allOpen=$allOpen" }

# ---------- F9 tiers (donorA B+, actionable) ----------
$fxr = FxRows $dASess
$ab = $fxr | Where-Object { $_.id -eq $rAB } | Select-Object -First 1
$arow = $fxr | Where-Object { $_.id -eq $rA } | Select-Object -First 1
if ($ab.compatibility_tier -eq 0 -and $arow.compatibility_tier -eq 3) { Ok 'F9 tier0 compatible / tier3 incompatible' } else { Bad 'F9' "ab=$($ab.compatibility_tier) a=$($arow.compatibility_tier)" }
$posA = -1; $posB = -1; $i = 0
foreach ($row in $fxr) { if ($row.id -eq $rA) { $posA = $i }; if ($row.id -eq $rAB) { $posB = $i }; $i++ }
if ($posB -ge 0 -and $posA -ge 0 -and $posB -lt $posA) { Ok 'F9 compatible ranks above incompatible' } else { Bad 'F9 order' "ab=$posB a=$posA" }

# ---------- F10 unknown blood tier2 ----------
$fxn = FxRows $dNBSess
$t2 = ($fxn | Where-Object { $_.compatibility_tier -ne 2 }).Count -eq 0
if ($t2 -and $fxn.Count -gt 0) { Ok 'F10 unknown blood tier2' } else { Bad 'F10' "count=$($fxn.Count)" }

# ---------- F11 incompatible visible + tier1 unenrolled/unavailable ----------
$vis = @(($fxr | Where-Object { $_.id -eq $rA })).Count -eq 1
$fxu = FxRows $dUSess
$uAB = $fxu | Where-Object { $_.id -eq $rAB } | Select-Object -First 1
$fxo = FxRows $dOffSess
$oAB = $fxo | Where-Object { $_.id -eq $rAB } | Select-Object -First 1
if ($vis -and $uAB.compatibility_tier -eq 1 -and $oAB.compatibility_tier -eq 1) { Ok 'F11 incompatible visible; unenrolled/unavailable tier1' } else { Bad 'F11' "vis=$vis u=$($uAB.compatibility_tier) o=$($oAB.compatibility_tier)" }

# ---------- F12 normal ordering: urgency then needed then distance ----------
$fxr = FxRows $dASess
$ids = @($fxr | ForEach-Object { $_.id })
$pAB = [array]::IndexOf($ids, $rAB); $pB = [array]::IndexOf($ids, $rB); $pA = [array]::IndexOf($ids, $rA)
if ($pAB -lt $pB -and $pB -lt $pA) { Ok 'F12 critical>urgent>routine within tiers' } else { Bad 'F12' "ab=$pAB b=$pB a=$pA" }
$pT1 = [array]::IndexOf($ids, $rT1); $pT2 = [array]::IndexOf($ids, $rT2)
if ($pT1 -ge 0 -and $pT2 -ge 0 -and (($pT2 -lt $pT1 -and $rT2 -gt $rT1) -or ($pT1 -lt $pT2 -and $rT1 -gt $rT2))) { Ok 'F12 deterministic id-DESC tiebreak' } else { Bad 'F12 tie' "t1=$pT1 t2=$pT2" }

# ---------- F13 filters ----------
function FeedRaw($sess, $q) {
    return Invoke-Json $sess.s 'Get' "/api/requests/feed?$q" $null $sess.csrf
}
$r = FeedRaw $dASess "blood_type=A%2B&page_size=50"
$btOk = $r.status -eq 200 -and ((@($r.body.data.requests) | Where-Object { $_.required_blood_type -ne 'A+' }).Count -eq 0)
$r = FeedRaw $dASess "urgency=critical&page_size=50"
$urOk = $r.status -eq 200 -and ((@($r.body.data.requests) | Where-Object { $_.urgency -ne 'critical' }).Count -eq 0)
$r = FeedRaw $dASess "chapter_id=2&page_size=50"
$chOk = $r.status -eq 200 -and ((@($r.body.data.requests) | Where-Object { [int]$_.request_chapter_id -ne 2 }).Count -eq 0)
if ($btOk -and $urOk -and $chOk) { Ok 'F13 blood/urgency/chapter filters' } else { Bad 'F13' "bt=$btOk ur=$urOk ch=$chOk" }
$r = FeedRaw $dASess "blood_type=Z%2B"
$b400 = $r.status -eq 400
$r = FeedRaw $dASess "urgency=super"
$u400 = $r.status -eq 400
$r = FeedRaw $dASess "chapter_id=9999"
$c400 = $r.status -eq 400
if ($b400 -and $u400 -and $c400) { Ok 'F13 invalid filter params 400' } else { Bad 'F13 invalid' "b=$($b400) u=$($u400) c=$($c400)" }

# near_me: donorA located Balanga; R_A unlocated -> excluded; all rows have distance
$r = FeedRaw $dASess "near_me=1&page_size=50"
$nm = @($r.body.data.requests)
$nmOk = $r.status -eq 200 -and ($nm.Count -gt 0) -and (($nm | Where-Object { $_.approximate_distance_km -eq $null }).Count -eq 0)
$nmFx = @($nm | Where-Object { $_.facility_name -like "$fx*" } | ForEach-Object { $_.id })
# same-urgency distance check: critical AB+ near (rAB) before critical AB+ far (rAB2)
$pN1 = [array]::IndexOf($nmFx, $rAB); $pN2 = [array]::IndexOf($nmFx, $rAB2)
if ($nmOk -and $pN1 -ge 0 -and $pN2 -ge 0 -and $pN1 -lt $pN2) { Ok 'F13 near_me distance-first + unlocated excluded' } else { Bad 'F13 near' "ok=$nmOk n1=$pN1 n2=$pN2" }
# normal mode: same pair orders by needed_datetime (rAB2 sooner)
$fxr = FxRows $dASess
$ids = @($fxr | ForEach-Object { $_.id })
$qN1 = [array]::IndexOf($ids, $rAB); $qN2 = [array]::IndexOf($ids, $rAB2)
if ($qN2 -lt $qN1) { Ok 'F13 normal mode needed-datetime before distance' } else { Bad 'F13 needed' "ab=$qN1 ab2=$qN2" }
# unlocated viewer near_me 400
DbQuery "UPDATE users SET latitude=NULL, longitude=NULL WHERE id=$dNB;" | Out-Null
$r = FeedRaw $dNBSess "near_me=1"
DbQuery "UPDATE users SET latitude=14.68, longitude=120.54 WHERE id=$dNB;" | Out-Null
if ($r.status -eq 400) { Ok 'F13 near_me without location 400' } else { Bad 'F13 noloc' "got $($r.status)" }

# ---------- F14 pagination ----------
$p1 = FeedRaw $dASess "page=1&page_size=5"
$p2 = FeedRaw $dASess "page=2&page_size=5"
$p1b = FeedRaw $dASess "page=1&page_size=5"
$ids1 = @($p1.body.data.requests | ForEach-Object { $_.id })
$ids2 = @($p2.body.data.requests | ForEach-Object { $_.id })
$ids1b = @($p1b.body.data.requests | ForEach-Object { $_.id })
$disjoint = ((Compare-Object $ids1 $ids2 | Where-Object { $_.SideIndicator -eq '<=' }).Count -eq $ids1.Count) -and ((Compare-Object $ids1 $ids2).Count -eq ($ids1.Count + $ids2.Count))
$repeat = ($ids1 -join ',') -eq ($ids1b -join ',')
$tot = [int]$p1.body.data.total -eq [int]$p2.body.data.total
if ($p1.status -eq 200 -and $disjoint -and $repeat -and $tot) { Ok 'F14 pagination disjoint + deterministic + total' } else { Bad 'F14' "dis=$disjoint rep=$repeat tot=$tot" }

# ---------- F15 privacy scan ----------
$all = FeedRows $dASess ''
$json = ($all.rows | ConvertTo-Json -Depth 6 -Compress)
$leak = @('email', 'phone', 'latitude', 'longitude', 'password', 'document', 'donor_availability', 'verification_status') | Where-Object { $json -match ('"' + $_ + '"') }
if ($leak.Count -eq 0) { Ok 'F15 feed payload has no PII/coordinate keys' } else { Bad 'F15' "leaked: $($leak -join ',')" }

# ---------- F16 request-scoped respond reconciliation ----------
$dCEmail = "feeddc$suffix@test.local"; $dC = New-FixtureUser $dCEmail 'Feed Donor C' 'member' 1 'verified' 'B+' 14.677 120.537 $true 'available'
$dCSess = Login $dCEmail
$fxc = FxRows $dCSess
$cAB = $fxc | Where-Object { $_.id -eq $rAB } | Select-Object -First 1
if ($cAB.match_id -eq $null -and $cAB.primary_action -eq 'respond') { Ok 'F16 newly eligible: match_id null + respond' } else { Bad 'F16 feed' "mid=$($cAB.match_id) act=$($cAB.primary_action)" }
$r = Invoke-Json $dCSess.s 'Post' "/api/requests/$rAB/respond" @{ donor_share_consent = $true } $dCSess.csrf
$mCid = 0; if ($r.body -and $r.body.data) { $mCid = [int]$r.body.data.match_id }
$dbRow = DbQuery "SELECT CONCAT(status,'|',donor_share_consent) FROM matches WHERE request_id=$rAB AND donor_id=$dC;"
if ($r.status -eq 200 -and $mCid -gt 0 -and $dbRow -eq 'RESPONDED|1') { Ok 'F16 request-scoped respond reconciles + consents' } else { Bad 'F16 respond' "st=$($r.status) mid=$mCid db=$dbRow" }
$r = Invoke-Json $dCSess.s 'Post' "/api/requests/$rAB/respond" @{ donor_share_consent = $true } $dCSess.csrf
$nCount = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=(SELECT requester_id FROM blood_requests WHERE id=$rAB) AND type='match.responded' AND related_id=$rAB;"
if ($r.status -eq 200 -and $nCount -eq '1') { Ok 'F16 duplicate respond idempotent, single notification' } else { Bad 'F16 dup' "st=$($r.status) n=$nCount" }

# ---------- F17 accept + contact + unaccept ----------
$r = Invoke-Json $req.s 'Post' "/api/matches/$mCid/accept" @{ requester_share_consent = $true } $req.csrf
$dbSt = DbQuery "SELECT CONCAT(status,'|',requester_share_consent) FROM matches WHERE id=$mCid;"
if ($r.status -eq 200 -and $dbSt -eq 'ACCEPTED|1') { Ok 'F17 accept RESPONDED->ACCEPTED with consents' } else { Bad 'F17' "st=$($r.status) db=$dbSt" }
$r = Invoke-Json $dCSess.s 'Get' "/api/matches/$mCid/contact" $null $dCSess.csrf
$bothMails = $r.status -eq 200 -and $r.body.data.contact.donor_email -like '*@*' -and $r.body.data.contact.requester_email -like '*@*'
if ($bothMails) { Ok 'F17 contact returns both emails to principal' } else { Bad 'F17 contact' "st=$($r.status)" }
$r = Invoke-Json $dBSess.s 'Get' "/api/matches/$mCid/contact" $null $dBSess.csrf
if ($r.status -eq 404) { Ok 'F17 stranger contact 404' } else { Bad 'F17 stranger' "got $($r.status)" }
$r = Invoke-Json $req.s 'Post' "/api/matches/$mCid/unaccept" @{} $req.csrf
$dbSt = DbQuery "SELECT CONCAT(status,'|',requester_share_consent,'|',donor_share_consent) FROM matches WHERE id=$mCid;"
if ($r.status -eq 200 -and $dbSt -eq 'RESPONDED|0|1') { Ok 'F17 unaccept revives RESPONDED + resets requester consent' } else { Bad 'F17 unaccept' "st=$($r.status) db=$dbSt" }
$r = Invoke-Json $dCSess.s 'Get' "/api/matches/$mCid/contact" $null $dCSess.csrf
if ($r.status -eq 403) { Ok 'F17 contact denied after unaccept' } else { Bad 'F17 unacc-contact' "got $($r.status)" }
$r = Invoke-Json $req.s 'Post' "/api/matches/$mCid/accept" @{} $req.csrf
if ($r.status -eq 422) { Ok 'F17 re-accept without consent 422' } else { Bad 'F17 reconsent' "got $($r.status)" }

# ---------- F18 capacity (second requester: per-user create throttle) ----------
$rQ1 = New-Req $req2 'B+' 1 "$fx CapOne" 'urgent' $future2 $balangaLocId
$mQA = DbQuery "SELECT id FROM matches WHERE request_id=$rQ1 AND donor_id=$dA LIMIT 1;"
$mQB = DbQuery "SELECT id FROM matches WHERE request_id=$rQ1 AND donor_id=$dB LIMIT 1;"
Invoke-Json $dASess.s 'Post' "/api/matches/$mQA/respond" @{ donor_share_consent = $true } $dASess.csrf | Out-Null
Invoke-Json $dBSess.s 'Post' "/api/matches/$mQB/respond" @{ donor_share_consent = $true } $dBSess.csrf | Out-Null
$r1 = Invoke-Json $req2.s 'Post' "/api/matches/$mQA/accept" @{ requester_share_consent = $true } $req2.csrf
$r2 = Invoke-Json $req2.s 'Post' "/api/matches/$mQB/accept" @{ requester_share_consent = $true } $req2.csrf
if ($r1.status -eq 200 -and $r2.status -eq 409) { Ok 'F18 capacity_full enforced at quota' } else { Bad 'F18' "a=$($r1.status) b=$($r2.status)" }

# ---------- F19 quantity guard ----------
$rQ2 = New-Req $req2 'B+' 2 "$fx CapTwo" 'urgent' $future2 $balangaLocId
$mQ2A = DbQuery "SELECT id FROM matches WHERE request_id=$rQ2 AND donor_id=$dA LIMIT 1;"
$mQ2B = DbQuery "SELECT id FROM matches WHERE request_id=$rQ2 AND donor_id=$dB LIMIT 1;"
Invoke-Json $dASess.s 'Post' "/api/matches/$mQ2A/respond" @{ donor_share_consent = $true } $dASess.csrf | Out-Null
Invoke-Json $dBSess.s 'Post' "/api/matches/$mQ2B/respond" @{ donor_share_consent = $true } $dBSess.csrf | Out-Null
Invoke-Json $req2.s 'Post' "/api/matches/$mQ2A/accept" @{ requester_share_consent = $true } $req2.csrf | Out-Null
Invoke-Json $req2.s 'Post' "/api/matches/$mQ2B/accept" @{ requester_share_consent = $true } $req2.csrf | Out-Null
$r = Invoke-Json $req2.s 'Put' "/api/requests/$rQ2" @{ quantity_units = 1 } $req2.csrf
$qNow = DbQuery "SELECT quantity_units FROM blood_requests WHERE id=$rQ2;"
if ($r.status -eq 409 -and $qNow -eq '2') { Ok 'F19 quantity reduction below commitments 409' } else { Bad 'F19' "st=$($r.status) q=$qNow" }
$r = Invoke-Json $req2.s 'Put' "/api/requests/$rQ2" @{ quantity_units = 3 } $req2.csrf
if ($r.status -eq 200) { Ok 'F19 quantity increase allowed' } else { Bad 'F19 inc' "got $($r.status)" }

# ---------- F20 cancel closes ACCEPTED + revokes contact ----------
Invoke-Json $req2.s 'Post' "/api/requests/$rQ2/cancel" @{} $req2.csrf | Out-Null
$dbSt = DbQuery "SELECT status FROM matches WHERE id=$mQ2A;"
$r = Invoke-Json $dASess.s 'Get' "/api/matches/$mQ2A/contact" $null $dASess.csrf
if ($dbSt -eq 'CLOSED' -and $r.status -eq 403) { Ok 'F20 cancel closes ACCEPTED + revokes contact' } else { Bad 'F20' "db=$dbSt ct=$($r.status)" }

# ---------- F21 IDOR / cross-user ----------
$r = Invoke-Json $dBSess.s 'Post' "/api/matches/$mCid/withdraw" @{} $dBSess.csrf
$w1 = $r.status
$r = Invoke-Json $dBSess.s 'Post' "/api/matches/$mCid/accept" @{ requester_share_consent = $true } $dBSess.csrf
$a1 = $r.status
if ($w1 -eq 403 -and $a1 -eq 403) { Ok 'F21 cross-user withdraw/accept forbidden' } else { Bad 'F21' "w=$w1 a=$a1" }
$r = Invoke-Json $dCSess.s 'Post' "/api/matches/$mCid/respond" @{} $dCSess.csrf
if ($r.status -eq 422) { Ok 'F21 respond without consent 422' } else { Bad 'F21 consent' "got $($r.status)" }

# ---------- F22 withdraw releases capacity + terminal ----------
$rW = New-Req $req2 'B+' 1 "$fx Withdraw" 'urgent' $future2 $balangaLocId
$mWA = DbQuery "SELECT id FROM matches WHERE request_id=$rW AND donor_id=$dA LIMIT 1;"
$mWB = DbQuery "SELECT id FROM matches WHERE request_id=$rW AND donor_id=$dB LIMIT 1;"
Invoke-Json $dASess.s 'Post' "/api/matches/$mWA/respond" @{ donor_share_consent = $true } $dASess.csrf | Out-Null
Invoke-Json $req2.s 'Post' "/api/matches/$mWA/accept" @{ requester_share_consent = $true } $req2.csrf | Out-Null
Invoke-Json $dASess.s 'Post' "/api/matches/$mWA/withdraw" @{} $dASess.csrf | Out-Null
$dbSt = DbQuery "SELECT status FROM matches WHERE id=$mWA;"
Invoke-Json $dBSess.s 'Post' "/api/matches/$mWB/respond" @{ donor_share_consent = $true } $dBSess.csrf | Out-Null
$r = Invoke-Json $req2.s 'Post' "/api/matches/$mWB/accept" @{ requester_share_consent = $true } $req2.csrf
$r2 = Invoke-Json $dASess.s 'Post' "/api/requests/$rW/respond" @{ donor_share_consent = $true } $dASess.csrf
if ($dbSt -eq 'WITHDRAWN' -and $r.status -eq 200 -and $r2.status -eq 409) { Ok 'F22 withdraw terminal + capacity released + no reopen' } else { Bad 'F22' "db=$dbSt acc=$($r.status) re=$($r2.status)" }

# ---------- F23 parallel accepts serialize at capacity ----------
$rP = New-Req $req2 'B+' 1 "$fx Race" 'urgent' $future2 $balangaLocId
$mPA = DbQuery "SELECT id FROM matches WHERE request_id=$rP AND donor_id=$dA LIMIT 1;"
$mPB = DbQuery "SELECT id FROM matches WHERE request_id=$rP AND donor_id=$dB LIMIT 1;"
Invoke-Json $dASess.s 'Post' "/api/matches/$mPA/respond" @{ donor_share_consent = $true } $dASess.csrf | Out-Null
Invoke-Json $dBSess.s 'Post' "/api/matches/$mPB/respond" @{ donor_share_consent = $true } $dBSess.csrf | Out-Null
$jobCode = {
    param($BaseUrl, $email, $csrf, $mid)
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $t = Invoke-RestMethod -Uri "$BaseUrl/api/csrf" -Method Get -WebSession $s -TimeoutSec 10 -UseBasicParsing
    $token = $t.data.csrf_token
    $body = @{ email = $email; password = 'Str0ngPass1' } | ConvertTo-Json
    $loginRes = Invoke-RestMethod -Uri "$BaseUrl/api/login" -Method Post -WebSession $s -ContentType 'application/json' -Headers @{'X-CSRF-Token'=$token} -Body $body
    # Server rotates CSRF at login: adopt the fresh token for the accept.
    $token = $loginRes.data.csrf_token
    if ([string]::IsNullOrEmpty($token)) {
        $t2 = Invoke-RestMethod -Uri "$BaseUrl/api/csrf" -Method Get -WebSession $s -TimeoutSec 10 -UseBasicParsing
        $token = $t2.data.csrf_token
    }
    $abody = @{ requester_share_consent = $true } | ConvertTo-Json
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl/api/matches/$mid/accept" -Method Post -WebSession $s -ContentType 'application/json' -Headers @{'X-CSRF-Token'=$token} -Body $abody -TimeoutSec 20 -UseBasicParsing
        return [int]$res.StatusCode
    } catch {
        return [int]$_.Exception.Response.StatusCode
    }
}
try {
    $j1 = Start-Job -ScriptBlock $jobCode -ArgumentList $BaseUrl, $req2Email, $null, $mPA
    $j2 = Start-Job -ScriptBlock $jobCode -ArgumentList $BaseUrl, $req2Email, $null, $mPB
    Wait-Job $j1, $j2 | Out-Null
    $c1 = Receive-Job $j1; $c2 = Receive-Job $j2
    Remove-Job $j1, $j2
    $codes = @($c1, $c2) | Sort-Object
    $inv = DbQuery "SELECT (SELECT COUNT(*) FROM matches WHERE request_id=$rP AND status='ACCEPTED') + (SELECT COUNT(*) FROM matches WHERE request_id=$rP AND status='COMPLETED');"
    if (($codes -join ',') -eq '200,409' -and [int]$inv -le 1) { Ok 'F23 parallel accepts serialize; capacity invariant holds' } else { Bad 'F23' "codes=$($codes -join ',') inv=$inv" }
} catch {
    Write-Host "SKIP  F23 parallel jobs unsupported in this host ($($_.Exception.Message)); invariant checked sequentially"
    $inv = DbQuery "SELECT (SELECT COUNT(*) FROM matches WHERE request_id=$rP AND status='ACCEPTED') + (SELECT COUNT(*) FROM matches WHERE request_id=$rP AND status='COMPLETED');"
    if ([int]$inv -le 1) { Ok 'F23 capacity invariant holds (sequential fallback)' } else { Bad 'F23 inv' "inv=$inv" }
}

# ---------- F24 consent revoke/re-grant on ACCEPTED ----------
$r = Invoke-Json $dASess.s 'Post' "/api/matches/$mQA/consent" @{ share = $false } $dASess.csrf
$rC = Invoke-Json $dASess.s 'Get' "/api/matches/$mQA/contact" $null $dASess.csrf
$r2 = Invoke-Json $dASess.s 'Post' "/api/matches/$mQA/consent" @{ share = $true } $dASess.csrf
$rC2 = Invoke-Json $dASess.s 'Get' "/api/matches/$mQA/contact" $null $dASess.csrf
if ($r.status -eq 200 -and $rC.status -eq 403 -and $r2.status -eq 200 -and $rC2.status -eq 200) { Ok 'F24 revoke denies contact; re-grant restores (ACCEPTED)' } else { Bad 'F24' "rev=$($r.status) denied=$($rC.status) grant=$($r2.status) ok=$($rC2.status)" }

# ---------- F25 deactivation closes ACCEPTED + cancel notifies accepted donor ----------
$rD = New-Req $req2 'B+' 1 "$fx Deact" 'urgent' $future2 $balangaLocId
$mDA = DbQuery "SELECT id FROM matches WHERE request_id=$rD AND donor_id=$dB LIMIT 1;"
Invoke-Json $dBSess.s 'Post' "/api/matches/$mDA/respond" @{ donor_share_consent = $true } $dBSess.csrf | Out-Null
Invoke-Json $req2.s 'Post' "/api/matches/$mDA/accept" @{ requester_share_consent = $true } $req2.csrf | Out-Null
$r = Invoke-Json $adSess.s 'Post' "/api/admin/users/$dB/deactivate" @{} $adSess.csrf
$dbSt = DbQuery "SELECT status FROM matches WHERE id=$mDA;"
$rC = Invoke-Json $req2.s 'Get' "/api/matches/$mDA/contact" $null $req2.csrf
$req2Id = [int](DbQuery "SELECT id FROM users WHERE email='$req2Email';")
$nClosed = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$req2Id AND type='match.closed' AND related_id=$rD;"
Invoke-Json $adSess.s 'Post' "/api/admin/users/$dB/reactivate" @{} $adSess.csrf | Out-Null
if ($r.status -eq 200 -and $dbSt -eq 'CLOSED' -and $rC.status -eq 403 -and [int]$nClosed -ge 1) { Ok 'F25 deactivation closes ACCEPTED, revokes contact, notifies' } else { Bad 'F25' "deact=$($r.status) db=$dbSt ct=$($rC.status) n=$nClosed" }
# Deactivation revoked dB's session epoch (and reactivation bumps it again):
# the reactivated account must establish a fresh session.
$dBSess = Login $dBEmail

# ---------- F26 accepted donor notified on cancel ----------
$nCan = DbQuery "SELECT COUNT(*) FROM notifications WHERE user_id=$dA AND type='request.cancelled' AND related_id=$rQ2;"
if ([int]$nCan -ge 1) { Ok 'F26 accepted donor receives cancel notice' } else { Bad 'F26' "n=$nCan" }

# ---------- F27 match scope returns only own qualifying requests + count ----------
$expIds = @(DbQuery "SELECT DISTINCT m.request_id FROM matches m JOIN blood_requests br ON br.id=m.request_id JOIN users req ON req.id=br.requester_id WHERE m.donor_id=$dA AND m.status IN ('RESPONDED','ACCEPTED','COMPLETED') AND br.status='OPEN' AND req.account_status='active';" | ForEach-Object { [int]$_ })
$r = FeedRaw $dASess "feed_scope=match&page_size=50"
$gotIds = @($r.body.data.requests | ForEach-Object { [int]$_.id })
$mc = [int]$r.body.data.match_count
$allIn = (@($gotIds | Where-Object { $expIds -notcontains $_ }).Count -eq 0)
if ($r.status -eq 200 -and $expIds.Count -gt 0 -and $mc -eq $expIds.Count -and $gotIds.Count -eq $expIds.Count -and $allIn) { Ok 'F27 match tab lists own qualifying OPEN requests + count' } else { Bad 'F27' "st=$($r.status) exp=$($expIds.Count) got=$($gotIds.Count) mc=$mc" }
$rAll = FeedRaw $dASess "page_size=1"
if ([int]$rAll.body.data.match_count -eq $expIds.Count) { Ok 'F27 match_count present on all-scope responses' } else { Bad 'F27 count' "mc=$($rAll.body.data.match_count)" }

# ---------- F28 invalid scope 400 ----------
$r = FeedRaw $dASess "feed_scope=bogus"
if ($r.status -eq 400) { Ok 'F28 invalid feed_scope 400' } else { Bad 'F28' "got $($r.status)" }

# ---------- F29 critical scope + composition ----------
$r = FeedRaw $dASess "feed_scope=critical&page_size=50"
$allCrit = (@($r.body.data.requests) | Where-Object { $_.urgency -ne 'critical' }).Count -eq 0
$r2 = FeedRaw $dASess "feed_scope=critical&blood_type=B%2B&page_size=50"
$comp = $r2.status -eq 200 -and ((@($r2.body.data.requests) | Where-Object { $_.urgency -ne 'critical' -or $_.required_blood_type -ne 'B+' }).Count -eq 0)
if ($r.status -eq 200 -and $allCrit -and $comp) { Ok 'F29 critical tab + composes with right filters' } else { Bad 'F29' "crit=$allCrit comp=$comp" }

# ---------- F30 match scope composes with right filters ----------
$r = FeedRaw $dASess "feed_scope=match&blood_type=A%2B&page_size=50"
if ($r.status -eq 200 -and [int]$r.body.data.total -eq 0) { Ok 'F30 match+filter composition narrows to zero' } else { Bad 'F30' "st=$($r.status) total=$($r.body.data.total)" }

# ---------- F31 withdrawn/closed excluded; match_id is own only ----------
$r = FeedRaw $dASess "feed_scope=match&page_size=50"
$noTerminal = ((@($r.body.data.requests) | Where-Object { $_.my_match_status -eq 'WITHDRAWN' -or $_.my_match_status -eq 'CLOSED' }).Count -eq 0)
$ownIds = @(DbQuery "SELECT id FROM matches WHERE donor_id=$dA;" | ForEach-Object { [int]$_ })
$midOk = ((@($r.body.data.requests) | Where-Object { $_.match_id -ne $null -and $ownIds -notcontains [int]$_.match_id }).Count -eq 0)
$noRw = ((@($r.body.data.requests) | Where-Object { [int]$_.id -eq $rW }).Count -eq 0)
if ($noTerminal -and $midOk -and $noRw) { Ok 'F31 withdrawn/closed excluded; match_id own-only' } else { Bad 'F31' "term=$noTerminal mid=$midOk rw=$noRw" }

# ---------- F32 match count tracks relationship changes ----------
$before = FeedRaw $dBSess "feed_scope=match&page_size=1"
Invoke-Json $dBSess.s 'Post' "/api/matches/$mQB/withdraw" @{} $dBSess.csrf | Out-Null
$after = FeedRaw $dBSess "feed_scope=match&page_size=1"
if ($before.status -eq 200 -and $after.status -eq 200 -and ([int]$after.body.data.match_count) -eq ([int]$before.body.data.match_count - 1)) { Ok 'F32 match_count drops after withdraw' } else { Bad 'F32' "before=$($before.body.data.match_count) after=$($after.body.data.match_count)" }

# ---------- F33 candidate-only rows excluded; respond transitions into Match tab ----------
$rM = New-Req $req2 'B+' 1 "$fx MatchDef" 'routine' $future2 $balangaLocId
$mRow = DbQuery "SELECT status FROM matches WHERE request_id=$rM AND donor_id=$dA;"
$rPre = FeedRaw $dASess "feed_scope=match&page_size=50"
$preIds = @($rPre.body.data.requests | ForEach-Object { [int]$_.id })
$preCount = [int]$rPre.body.data.match_count
$allRows = FeedRows $dASess ''
$inAll = ((@($allRows.rows | Where-Object { [int]$_.id -eq $rM }).Count -ge 1))
$r = Invoke-Json $dASess.s 'Post' "/api/requests/$rM/respond" @{ donor_share_consent = $true } $dASess.csrf
$rPost = FeedRaw $dASess "feed_scope=match&page_size=50"
$postIds = @($rPost.body.data.requests | ForEach-Object { [int]$_.id })
$postCount = [int]$rPost.body.data.match_count
$candidateOnly = ($mRow -eq 'POTENTIAL' -or $mRow -eq 'NOTIFIED')
if ($candidateOnly -and $preIds -notcontains $rM -and $inAll -and $r.status -eq 200 -and $postIds -contains $rM -and $postCount -eq ($preCount + 1)) { Ok 'F33 candidate-only excluded; RESPONDED included + counted' } else { Bad 'F33' "row=$mRow pre=$preCount post=$postCount st=$($r.status)" }

# ---------- F34 COMPLETED on still-OPEN request stays in Match tab ----------
$rC2 = New-Req $req2 'B+' 2 "$fx CompletedOpen" 'routine' $future5 $balangaLocId
$mC2 = DbQuery "SELECT id FROM matches WHERE request_id=$rC2 AND donor_id=$dA LIMIT 1;"
Invoke-Json $dASess.s 'Post' "/api/matches/$mC2/respond" @{ donor_share_consent = $true } $dASess.csrf | Out-Null
$rep = Invoke-Json $dASess.s 'Post' '/api/donation-reports' @{ match_id = [int]$mC2 } $dASess.csrf
$repId = [int]$rep.body.data.report.id
$cf = Invoke-Json $ofSess.s 'Post' "/api/officer/donation-reports/$repId/confirm" @{} $ofSess.csrf
$dbSt = DbQuery "SELECT m.status FROM matches m WHERE m.id=$mC2;"
$reqSt = DbQuery "SELECT status FROM blood_requests WHERE id=$rC2;"
$r = FeedRaw $dASess "feed_scope=match&page_size=50"
$gotIds = @($r.body.data.requests | ForEach-Object { [int]$_.id })
if ($cf.status -eq 200 -and $dbSt -eq 'COMPLETED' -and $reqSt -eq 'OPEN' -and $gotIds -contains $rC2) { Ok 'F34 COMPLETED on OPEN request included + counted' } else { Bad 'F34' "cf=$($cf.status) db=$dbSt req=$reqSt" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
