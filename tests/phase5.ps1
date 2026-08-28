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

function Upload($session, $uri, $fieldName, $fileName, $bytes, $mime, $csrf, $fields) {
    $b = "----bm$(Get-Random)"
    $ms = New-Object System.IO.MemoryStream
    $enc = [Text.Encoding]::ASCII
    $w = { param($text) $d = $enc.GetBytes($text); $ms.Write($d, 0, $d.Length) }
    foreach ($k in $fields.Keys) {
        & $w "--$b`r`n"
        & $w "Content-Disposition: form-data; name=`"$k`"`r`n`r`n$($fields[$k])`r`n"
    }
    & $w "--$b`r`nContent-Disposition: form-data; name=`"$fieldName`"; filename=`"$fileName`"`r`nContent-Type: $mime`r`n`r`n"
    $ms.Write($bytes, 0, $bytes.Length)
    & $w "`r`n--$b--`r`n"
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Post -Body $ms.ToArray() `
            -ContentType "multipart/form-data; boundary=$b" -Headers @{'X-CSRF-Token'=$csrf} `
            -WebSession $session -TimeoutSec 20 -UseBasicParsing
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $status = [int]$resp.StatusCode
        try {
            $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
            $raw = $reader.ReadToEnd()
        } catch { $raw = '' }
        try { $parsed = $raw | ConvertFrom-Json } catch { $parsed = $null }
        return @{ status = $status; body = $parsed }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

function New-FixtureUser($email, $name, $role, $chapterId, $dob, $vs) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'; if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    $dobSql = 'NULL'; if ($null -ne $dob) { $dobSql = "'$dob'" }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status, date_of_birth) VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$vs', 'active', $dobSql);"
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
$pdfBytes = [Text.Encoding]::ASCII.GetBytes("%PDF-1.4`n% fake pdf for test`n%%EOF`n")
$pngBytes = [byte[]](0x89,0x50,0x4E,0x47,0x0D,0x0A,0x1A,0x0A) + [Text.Encoding]::ASCII.GetBytes("restofpng")
$txtBytes  = [Text.Encoding]::ASCII.GetBytes("just plain text pretending to be png")
$exeBytes  = [byte[]](0x4D,0x5A) + [Text.Encoding]::ASCII.GetBytes("fakeexecutable")

Write-Host "== Phase 5 profiles, documents & verification audit =="

# --- fixtures ---
$officerEmail = "p5off$suffix@test.local";  $officerId   = New-FixtureUser $officerEmail 'Officer Five' 'officer' 1 $null 'verified'
$adminEmail   = "p5adm$suffix@test.local";  $adminId     = New-FixtureUser $adminEmail 'Admin Five' 'admin' $null $null 'active'
$memPEmail    = "p5pend$suffix@test.local"; $memPId      = New-FixtureUser $memPEmail 'Pending Pete' 'member' 1 (Get-Date).AddYears(-20).ToString('yyyy-MM-dd') 'pending'
$memREmail    = "p5rej$suffix@test.local";  $memRId      = New-FixtureUser $memREmail 'Rejected Rita' 'member' 1 (Get-Date).AddYears(-25).ToString('yyyy-MM-dd') 'rejected'
$memOEmail    = "p5other$suffix@test.local"; $memOId     = New-FixtureUser $memOEmail 'Other Chapter' 'member' 2 (Get-Date).AddYears(-30).ToString('yyyy-MM-dd') 'pending'
$provEmail    = "p5prov$suffix@test.local"; $provId      = New-FixtureUser $provEmail 'Provenance Pat' 'member' 1 (Get-Date).AddYears(-40).ToString('yyyy-MM-dd') 'pending'
DbQuery "UPDATE users SET blood_type='A+', blood_type_source='self_reported' WHERE id=$provId;"

$off = Login $officerEmail
$adm = Login $adminEmail

# ===== A. PROFILE =====
$mP = Login $memPEmail
$r = Invoke-Json $mP.s 'Get' '/api/profile' $null $mP.csrf
$pjson = $r.raw
if ($r.status -eq 200 -and $r.body.data.profile.capabilities -ne $null -and $pjson -notmatch 'password_hash') { Ok 'A1 GET profile safe+complete' } else { Bad 'A1' "status=$($r.status)" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ full_name = 'Pending Peter Updated'; phone = '+63 917 000 1111' } $mP.csrf
$dbName = DbQuery "SELECT full_name FROM users WHERE id=$memPId;"
if ($r.status -eq 200 -and $dbName -match 'Updated') { Ok 'A2 PUT permitted fields persisted' } else { Bad 'A2' "status=$($r.status) db=$dbName" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ role = 'admin' } $mP.csrf
if ($r.status -eq 400 -and $r.body.error.details.role) { Ok 'A3 role modification rejected' } else { Bad 'A3' "got $($r.status)" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ verification_status = 'verified' } $mP.csrf
if ($r.status -eq 400) { Ok 'A4 verification_status modification rejected' } else { Bad 'A4' "got $($r.status)" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ latitude = 14.5 } $mP.csrf
if ($r.status -eq 400 -and $r.body.error.details.location) { Ok 'A5 single coordinate rejected' } else { Bad 'A5' "got $($r.status)" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ latitude = 200; longitude = 120 } $mP.csrf
if ($r.status -eq 400 -and $r.body.error.details.latitude) { Ok 'A6 out-of-range latitude rejected' } else { Bad 'A6' "got $($r.status)" }

$r = Invoke-Json $mP.s 'Put' '/api/profile' @{ latitude = 14.65; longitude = 120.53 } $mP.csrf
$dbGeo = DbQuery "SELECT CONCAT(latitude,'|',longitude) FROM users WHERE id=$memPId;"
if ($r.status -eq 200 -and $dbGeo -like '14.65*|120.53*') { Ok 'A7 coordinate pair saved' } else { Bad 'A7' "db=$dbGeo" }

# ===== B. DOCUMENTS =====
$r = Upload $mP.s '/api/profile/documents' 'file' 'id-card.pdf' $pdfBytes 'application/pdf' $mP.csrf @{ doc_type = 'national_id' }
if ($r.status -eq 201) { Ok 'B1 valid PDF accepted' } else { Bad 'B1' "got $($r.status): $($r.body.error.message)" }
$docId = $r.body.data.document.id
$row = DbQuery "SELECT stored_name FROM member_documents WHERE id=$docId;"
$storedPath = "backend/storage/documents/$row"
if ($row -match '^[0-9a-f]{64}$' -and (Test-Path $storedPath)) { Ok 'B2 random name stored outside webroot' } else { Bad 'B2' "stored=$row" }

$r = Upload $mP.s '/api/profile/documents' 'file' 'malware.exe' $exeBytes 'application/octet-stream' $mP.csrf @{ doc_type = 'national_id' }
if ($r.status -eq 400) { Ok 'B3 executable content rejected' } else { Bad 'B3' "got $($r.status)" }

$r = Upload $mP.s '/api/profile/documents' 'file' 'fake.png' $txtBytes 'image/png' $mP.csrf @{ doc_type = 'donor_card' }
if ($r.status -eq 400) { Ok 'B4 text masquerading as png rejected (server-side MIME)' } else { Bad 'B4' "got $($r.status)" }

$bigBytes = New-Object byte[] (5 * 1024 * 1024 + 100)
$r = Upload $mP.s '/api/profile/documents' 'file' 'huge.png' $bigBytes 'image/png' $mP.csrf @{ doc_type = 'national_id' }
if ($r.status -eq 413) { Ok 'B5 oversized file rejected 413' } else { Bad 'B5' "got $($r.status)" }

$r = Upload $mP.s '/api/profile/documents' 'file' 't.pdf' $pdfBytes 'application/pdf' $mP.csrf @{ doc_type = '../evil' }
if ($r.status -eq 400) { Ok 'B6 doc_type traversal rejected' } else { Bad 'B6' "got $($r.status)" }

$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' "/api/profile/documents/$docId/file" $null $null
if ($r.status -eq 401) { Ok 'B7 anonymous document download 401' } else { Bad 'B7' "got $($r.status)" }

$mR = Login $memREmail
$r = Invoke-Json $mR.s 'Get' "/api/profile/documents/$docId/file" $null $mR.csrf
if ($r.status -eq 404) { Ok 'B8 foreign user cannot fetch others document' } else { Bad 'B8' "got $($r.status)" }

$r = Invoke-WebRequest -Uri "$BaseUrl/api/profile/documents/$docId/file" -WebSession $mP.s -UseBasicParsing
if ([int]$r.StatusCode -eq 200 -and $r.Content.Length -gt 0 -and $r.Headers['Content-Disposition'] -notmatch '[/\\]') { Ok 'B9 owner download works, no path leak' } else { Bad 'B9' "code=$($r.StatusCode)" }

# officer cross-chapter document access blocked
$mO = Login $memOEmail
$off2Email = "p5off2$suffix@test.local"
$off2Id = New-FixtureUser $off2Email 'Officer Two' 'officer' 2 $null 'verified'
$off2 = Login $off2Email
$r = Invoke-Json $off2.s 'Get' "/api/officer/documents/$docId/file" $null $off2.csrf
if ($r.status -eq 403) { Ok 'B10 officer other-chapter document access 403' } else { Bad 'B10' "got $($r.status)" }
$r = Invoke-Json $off.s 'Get' "/api/officer/documents/$docId/file" $null $off.csrf
if ($r.status -eq 200) { Ok 'B11 same-chapter officer document access 200' } else { Bad 'B11' "got $($r.status)" }

# ===== C. VERIFICATION WORKFLOW =====
$r = Invoke-Json $off.s 'Get' '/api/officer/verifications' $null $off.csrf
$inQueue = @($r.body.data.queue | Where-Object { $_.id -eq [int]$memPId }).Count
if ($r.status -eq 200 -and $inQueue -eq 1) { Ok 'C1 pending member appears in own-chapter queue' } else { Bad 'C1' "inQueue=$inQueue" }

$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$officerId/decision" @{ decision = 'verified' } $off.csrf
if ($r.status -eq 403) { Ok 'C2 self-verification blocked' } else { Bad 'C2' "got $($r.status)" }

$offBEmail = "p5offb$suffix@test.local"
$offBId = New-FixtureUser $offBEmail 'Officer Target B' 'officer' 1 $null 'verified'
$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$offBId/decision" @{ decision = 'rejected' } $off.csrf
if ($r.status -eq 403) { Ok 'C3 officer-target verification blocked' } else { Bad 'C3' "got $($r.status)" }

$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$adminId/decision" @{ decision = 'rejected' } $off.csrf
if ($r.status -eq 403) { Ok 'C4 admin-target verification blocked' } else { Bad 'C4' "got $($r.status)" }

$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$memOId/decision" @{ decision = 'verified' } $off.csrf
if ($r.status -eq 403) { Ok 'C5 cross-chapter decision blocked' } else { Bad 'C5' "got $($r.status)" }

# provenance: approve with donor card
$provSess = Login $provEmail
Upload $provSess.s '/api/profile/documents' 'file' 'card.pdf' $pdfBytes 'application/pdf' $provSess.csrf @{ doc_type = 'donor_card' } | Out-Null
$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$provId/decision" @{ decision = 'verified'; accept_donor_card = $true } $off.csrf
$dbProv = DbQuery "SELECT CONCAT(blood_type_source,'|',blood_type_verified) FROM users WHERE id=$provId;"
if ($r.status -eq 200 -and $dbProv -eq 'donor_card|1') { Ok 'C6 donor-card provenance accepted on approval' } else { Bad 'C6' "db=$dbProv" }
$r = Invoke-Json $provSess.s 'Get' '/api/profile' $null $provSess.csrf
if ($r.raw -match 'not a substitute for medical confirmation') { Ok 'C7 medical disclaimer present' } else { Bad 'C7' 'disclaimer missing' }

# reject + resubmission
$rejEmail = "p5rejflow$suffix@test.local"
$rejFlowId = New-FixtureUser $rejEmail 'Reject Flow' 'member' 1 $null 'pending'
$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$rejFlowId/decision" @{ decision = 'rejected'; reason = 'unreadable ID' } $off.csrf
$dbVs = DbQuery "SELECT verification_status FROM users WHERE id=$rejFlowId;"
if ($r.status -eq 200 -and $dbVs -eq 'rejected') { Ok 'C8 pending->rejected with reason' } else { Bad 'C8' "db=$dbVs" }

$rejSess = Login $rejEmail
$r = Invoke-Json $rejSess.s 'Post' '/api/profile/resubmit' @{} $rejSess.csrf
if ($r.status -eq 400) { Ok 'C9 resubmit without documents blocked' } else { Bad 'C9' "got $($r.status)" }
Upload $rejSess.s '/api/profile/documents' 'file' 'better-id.pdf' $pdfBytes 'application/pdf' $rejSess.csrf @{ doc_type = 'national_id' } | Out-Null
$r = Invoke-Json $rejSess.s 'Post' '/api/profile/resubmit' @{} $rejSess.csrf
$dbVs = DbQuery "SELECT verification_status FROM users WHERE id=$rejFlowId;"
if ($r.status -eq 200 -and $dbVs -eq 'pending') { Ok 'C10 rejected->pending after resubmission' } else { Bad 'C10' "db=$dbVs" }

$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$memPId/decision" @{ decision = 'verified' } $off.csrf
$dbVs = DbQuery "SELECT verification_status FROM users WHERE id=$memPId;"
if ($r.status -eq 200 -and $dbVs -eq 'verified') { Ok 'C11 pending->verified approved' } else { Bad 'C11' "db=$dbVs" }
$r = Invoke-Json $off.s 'Post' "/api/officer/verifications/$memPId/decision" @{ decision = 'rejected' } $off.csrf
if ($r.status -eq 409) { Ok 'C12 non-pending target 409' } else { Bad 'C12' "got $($r.status)" }

$r = Invoke-Json $adm.s 'Post' "/api/officer/verifications/$memOId/decision" @{ decision = 'verified' } $adm.csrf
if ($r.status -eq 200) { Ok 'C13 admin override on any chapter allowed' } else { Bad 'C13' "got $($r.status)" }

# ===== D. AGE ELIGIBILITY =====
function AgeCase($years, $withConsent, $expectAllowed, $label) {
    $dob = (Get-Date).AddYears(-$years).AddDays(-5).ToString('yyyy-MM-dd')
    $e = "p5age$years$withConsent$suffix@test.local"
    $id = New-FixtureUser $e "Age Test $years" 'member' 1 $dob 'pending'
    $sess = Login $e
    if ($withConsent) {
        Upload $sess.s '/api/profile/documents' 'file' 'consent.pdf' $pdfBytes 'application/pdf' $sess.csrf @{ doc_type = 'parental_consent' } | Out-Null
    }
    $offLocal = $script:off
    $r = Invoke-Json $offLocal.s 'Get' "/api/officer/verifications/$id" $null $offLocal.csrf
    $el = $r.body.data.verification.age_eligibility
    if ($r.status -eq 200 -and $el.donor_path_allowed -eq $expectAllowed -and $el.age -ge ($years - 1) -and $el.age -le $years) {
        Ok $label
    } else { Bad $label "allowed=$($el.donor_path_allowed) age=$($el.age)" }
}
AgeCase 15 $false $false 'D1 15-year-old not eligible'
AgeCase 16 $false $false 'D2 16 without consent not eligible'
AgeCase 16 $true  $true  'D3 16 with consent eligible'
AgeCase 17 $false $false 'D4 17 without consent not eligible'
AgeCase 17 $true  $true  'D5 17 with consent eligible'
AgeCase 18 $false $true  'D6 18-year-old processed normally'

# ===== E. CAPABILITY MATRIX (exposed via /api/profile) =====
$vSess = Login $memPEmail
$r = Invoke-Json $vSess.s 'Get' '/api/profile' $null $vSess.csrf
$caps = $r.body.data.profile.capabilities
if ($caps.appear_as_donor -eq $true -and $caps.create_request -eq $true) { Ok 'E1 verified member matrix flags' } else { Bad 'E1' "caps=$(($caps | ConvertTo-Json -Compress))" }
$r = Invoke-Json $mR.s 'Get' '/api/profile' $null $mR.csrf
$caps = $r.body.data.profile.capabilities
if ($caps.create_request -eq $false -and $caps.resubmit_verification -eq $true) { Ok 'E2 rejected member locked from requests, can resubmit' } else { Bad 'E2' "caps=$(($caps | ConvertTo-Json -Compress))" }

# ===== F. AUDIT =====
$acts = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('profile.updated','document.uploaded','document.accessed','verification.approved','verification.rejected','verification.resubmitted','verification.provenance_accepted','authz.denied');"
if ([int]$acts -ge 7) { Ok "F1 audit events recorded ($acts distinct)" } else { Bad 'F1' "distinct=$acts" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
