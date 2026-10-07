param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe',
    [string]$PhpPath = 'D:\xampp\php\php.exe'
)

# Meridian Heights verification-file routing audit (standalone; not in run_all.ps1).
#
# Proves chapter-scoped verification routing for the third canonical chapter
# through the REAL user flow (register-model fixture -> login -> multipart
# upload -> officer queue/detail/file), with a full 3x3 officer isolation
# matrix. No decisions are made, so submissions stay pending and the test is
# re-runnable without cleanup.
#
# Expected data flow under test:
#   users.chapter_id (member) -> member_documents.user_id -> queue filtered by
#   officer chapter (pendingMembersByChapter) -> detail/file gated by
#   requireChapterScope. Documents carry NO chapter of their own.

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

function Get-Status($session, $uri) {
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Get -WebSession $session -TimeoutSec 15 -UseBasicParsing
        return [int]$res.StatusCode
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        return [int]$resp.StatusCode
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

function New-FixtureMember($email, $name, $chapterId) {
    $hash = & $PhpPath -r "echo password_hash('Str0ngPass1', PASSWORD_BCRYPT);"
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status, date_of_birth, email_verified_at) VALUES ('$email', '$hash', '$name', 'member', $chapterId, 'pending', 'active', '1998-04-12', UTC_TIMESTAMP());"
    # Fixtures bypass /api/register by construction: grandfather-equivalent (migration 021).
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

Write-Host "== Meridian Heights verification routing audit =="

$suffix = "$(Get-Random)"
$pdfBytes = [Text.Encoding]::ASCII.GetBytes("%PDF-1.4`n% routing probe`n%%EOF`n")

# --- fixtures: one pending member per chapter (random suffix = isolated, re-runnable) ---
$merEmail = "mvroutemer$suffix@test.local"; $merId = New-FixtureMember $merEmail 'Routing Meridian' 3
$samEmail = "mvroutesam$suffix@test.local"; $samId = New-FixtureMember $samEmail 'Routing Samat' 1
$tarEmail = "mvroutetar$suffix@test.local"; $tarId = New-FixtureMember $tarEmail 'Routing Tarak' 2

$mer = Login $merEmail
$samM = Login $samEmail
$tarM = Login $tarEmail

# --- V1-V3 real uploads (201) ---
$r = Upload $mer.s '/api/profile/documents' 'file' 'meridian-id.pdf' $pdfBytes 'application/pdf' $mer.csrf @{ doc_type = 'national_id'; privacy_acknowledged = '1' }
if ($r.status -eq 201) { Ok 'V1 meridian member upload 201' } else { Bad 'V1 meridian upload' "got $($r.status)" }
$merDoc = $r.body.data.document.id
$r = Upload $samM.s '/api/profile/documents' 'file' 'samat-id.pdf' $pdfBytes 'application/pdf' $samM.csrf @{ doc_type = 'national_id'; privacy_acknowledged = '1' }
if ($r.status -eq 201) { Ok 'V2 samat member upload 201' } else { Bad 'V2 samat upload' "got $($r.status)" }
$samDoc = $r.body.data.document.id
$r = Upload $tarM.s '/api/profile/documents' 'file' 'tarak-id.pdf' $pdfBytes 'application/pdf' $tarM.csrf @{ doc_type = 'national_id'; privacy_acknowledged = '1' }
if ($r.status -eq 201) { Ok 'V3 tarak member upload 201' } else { Bad 'V3 tarak upload' "got $($r.status)" }
$tarDoc = $r.body.data.document.id

# --- V4 DB: chapter follows the member; member state untouched; file outside webroot ---
$row = DbQuery "SELECT CONCAT(u.chapter_id,'|',u.verification_status,'|',u.account_status,'|',d.user_id) FROM member_documents d JOIN users u ON u.id=d.user_id WHERE d.id=$merDoc;"
if ($row -eq "3|pending|active|$merId") { Ok 'V4 meridian doc owned by pending/active ch.3 member' } else { Bad 'V4 meridian row' "got '$row'" }
$rowS = DbQuery "SELECT CONCAT(u.chapter_id,'|',d.user_id) FROM member_documents d JOIN users u ON u.id=d.user_id WHERE d.id=$samDoc;"
$rowT = DbQuery "SELECT CONCAT(u.chapter_id,'|',d.user_id) FROM member_documents d JOIN users u ON u.id=d.user_id WHERE d.id=$tarDoc;"
if ($rowS -eq "1|$samId" -and $rowT -eq "2|$tarId") { Ok 'V4b samat/tarak docs owned by ch.1/ch.2 members' } else { Bad 'V4b rows' "samat='$rowS' tarak='$rowT'" }
$stored = DbQuery "SELECT stored_name FROM member_documents WHERE id=$merDoc;"
if ($stored -match '^[0-9a-f]{64}$' -and (Test-Path "backend/storage/documents/$stored")) { Ok 'V4c stored file is 64-hex outside webroot' } else { Bad 'V4c storage' 'bad reference or missing file' }

# --- officer sessions (stable fixtures) ---
$offMer = Login 'meridianofficer@test.local'
$offSam = Login 'samatofficer@test.local'
$offTar = Login 'tarakofficer@test.local'

function QueueIds($sess) {
    $q = Invoke-Json $sess.s 'Get' '/api/officer/verifications' $null $null
    if ($q.status -ne 200) { return @{ status = $q.status; ids = @(); chapter = $null } }
    return @{ status = 200; ids = @($q.body.data.queue | ForEach-Object { [int]$_.id }); chapter = $q.body.data.chapter_id }
}

# --- V5-V7 queue isolation matrix ---
$qm = QueueIds $offMer
if ($qm.status -eq 200 -and [int]$qm.chapter -eq 3 -and $qm.ids -contains [int]$merId -and $qm.ids -notcontains [int]$samId -and $qm.ids -notcontains [int]$tarId) { Ok 'V5 meridian queue: own visible, others absent' } else { Bad 'V5 meridian queue' "st=$($qm.status) ch=$($qm.chapter)" }
$qs = QueueIds $offSam
if ($qs.status -eq 200 -and $qs.ids -contains [int]$samId -and $qs.ids -notcontains [int]$merId -and $qs.ids -notcontains [int]$tarId) { Ok 'V6 samat queue: own visible, meridian+tarak absent' } else { Bad 'V6 samat queue' "st=$($qs.status)" }
$qt = QueueIds $offTar
if ($qt.status -eq 200 -and $qt.ids -contains [int]$tarId -and $qt.ids -notcontains [int]$merId -and $qt.ids -notcontains [int]$samId) { Ok 'V7 tarak queue: own visible, meridian+samat absent' } else { Bad 'V7 tarak queue' "st=$($qt.status)" }

# --- V8 individual detail: meridian sees own; cross-chapter denied ---
$d = Invoke-Json $offMer.s 'Get' "/api/officer/verifications/$merId" $null $null
$docSeen = @($d.body.data.verification.documents | Where-Object { [int]$_.id -eq [int]$merDoc }).Count
if ($d.status -eq 200 -and $docSeen -eq 1) { Ok 'V8 meridian detail 200 + own document listed' } else { Bad 'V8 meridian detail' "st=$($d.status) docSeen=$docSeen" }
$dS = Invoke-Json $offSam.s 'Get' "/api/officer/verifications/$merId" $null $null
if ($dS.status -eq 403) { Ok 'V8b samat detail on meridian member denied (403)' } else { Bad 'V8b samat detail' "got $($dS.status)" }
$dT = Invoke-Json $offTar.s 'Get' "/api/officer/verifications/$merId" $null $null
if ($dT.status -eq 403) { Ok 'V8c tarak detail on meridian member denied (403)' } else { Bad 'V8c tarak detail' "got $($dT.status)" }
$dS2 = Invoke-Json $offSam.s 'Get' "/api/officer/verifications/$samId" $null $null
$dT2 = Invoke-Json $offTar.s 'Get' "/api/officer/verifications/$tarId" $null $null
if ($dS2.status -eq 200 -and $dT2.status -eq 200) { Ok 'V8d samat/tarak detail on own members allowed' } else { Bad 'V8d own detail' "samat=$($dS2.status) tarak=$($dT2.status)" }

# --- V9 actual file bytes: status only, contents never printed ---
$fM = Get-Status $offMer.s "/api/officer/documents/$merDoc/file"
if ($fM -eq 200) { Ok 'V9 meridian file access allowed (200)' } else { Bad 'V9 meridian file' "got $fM" }
$fS = Get-Status $offSam.s "/api/officer/documents/$merDoc/file"
if ($fS -eq 403) { Ok 'V9b samat file access denied (403)' } else { Bad 'V9b samat file' "got $fS" }
$fT = Get-Status $offTar.s "/api/officer/documents/$merDoc/file"
if ($fT -eq 403) { Ok 'V9c tarak file access denied (403)' } else { Bad 'V9c tarak file' "got $fT" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
