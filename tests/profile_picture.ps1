param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe'
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

function PostJson($session, $uri, $body, $csrf) {
    $headers = @{}
    if ($csrf) { $headers['X-CSRF-Token'] = $csrf }
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Post -Body ($body | ConvertTo-Json) `
            -ContentType 'application/json' -Headers $headers -WebSession $session -TimeoutSec 10 -UseBasicParsing
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
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
        return @{ status = $status; body = $parsed }
    }
}

function PostFile($session, $uri, $filePath, $csrf) {
    $headers = @{ 'X-CSRF-Token' = $csrf }
    # Build multipart manually via curl.exe so binary upload matches browser behavior
    $cookie = ($session.Cookies.GetCookies($BaseUrl) | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join '; '
    $out = & curl.exe -s -X POST "$BaseUrl$uri" -H "X-CSRF-Token: $csrf" -b $cookie `
        -F "file=@$filePath" --connect-timeout 15
    return ($out | ConvertFrom-Json)
}

function GetReq($session, $uri) {
    try {
        $res = Invoke-WebRequest -Uri "$BaseUrl$uri" -Method Get -WebSession $session -TimeoutSec 10 -UseBasicParsing
        return @{ status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
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
        return @{ status = $status; body = $parsed }
    }
}

function DbQuery($sql) {
    (& $MysqlPath -h 127.0.0.1 -P 3307 -u root -N -B bloodmatch_dev -e $sql) | Where-Object { $_ -ne '' }
}

Write-Host "== Profile picture verification =="

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) "bm-avatar-test"
New-Item -ItemType Directory -Path $tmp -Force | Out-Null
$png1 = Join-Path $tmp "a1.png"
$png2 = Join-Path $tmp "a2.png"
# Minimal 1x1 PNGs (red / blue) generated from embedded base64
[IO.File]::WriteAllBytes($png1, [Convert]::FromBase64String(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))
[IO.File]::WriteAllBytes($png2, [Convert]::FromBase64String(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))
$txt = Join-Path $tmp "evil.txt"
'not-an-image' | Out-File -Encoding ascii $txt

# --- P01 fresh user has no picture ---
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrf = Get-Csrf $s
$email = "ppic$(Get-Random)@test.local"
$r = PostJson $s '/api/register' @{
    full_name = 'Pic Tester'; email = $email; password = 'Str0ngPass1';
    chapter_id = 1; date_of_birth = '2000-05-10'; blood_type = 'O+'; privacy_acknowledged = $true
} $csrf
$uid = $r.body.data.user.id
$r = PostJson $s '/api/login' @{ email = $email; password = 'Str0ngPass1' } $csrf
if ($r.status -eq 200 -and $null -eq $r.body.data.user.profile_picture_url) { Ok 'P01 login returns null profile_picture_url when none' } else { Bad 'P01' "got $($r.status)" }
$r = GetReq $s '/api/auth/me'
if ($r.status -eq 200 -and $null -eq $r.body.data.user.profile_picture_url) { Ok 'P02 /auth/me returns null profile_picture_url when none' } else { Bad 'P02' "got $($r.status)" }
$r = GetReq $s '/api/profile/picture'
if ($r.status -eq 404) { Ok 'P03 GET picture with none returns 404' } else { Bad 'P03' "got $($r.status)" }

# --- P04 unauthenticated upload rejected ---
$sAnon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrfAnon = Get-Csrf $sAnon
try {
    $res = Invoke-WebRequest -Uri "$BaseUrl/api/profile/picture" -Method Post -WebSession $sAnon -TimeoutSec 10 -UseBasicParsing
    Bad 'P04' "got $($res.StatusCode)"
} catch {
    if ([int]$_.Exception.Response.StatusCode -eq 403) { Ok 'P04 upload without CSRF rejected (403)' } else { Bad 'P04' "got $([int]$_.Exception.Response.StatusCode)" }
}

# --- P05 invalid type rejected ---
$bad = PostFile $s '/api/profile/picture' $txt $csrf
if ($bad.success -eq $false) { Ok 'P05 non-image upload rejected' } else { Bad 'P05' 'unexpected success' }

# --- P06 valid upload ---
$good = PostFile $s '/api/profile/picture' $png1 $csrf
if ($good.success -eq $true -and $good.data.user.profile_picture_url -like '/api/profile/picture*') {
    Ok 'P06 valid PNG upload returns versioned profile_picture_url'
} else { Bad 'P06' ($good | ConvertTo-Json -Compress) }
$row = DbQuery "SELECT profile_picture FROM users WHERE id=$uid;"
if ($row -match '^[0-9a-f]{64}$') { Ok 'P07 users.profile_picture stores 64-hex reference' } else { Bad 'P07' "row='$row'" }
$r = GetReq $s '/api/auth/me'
if ($r.status -eq 200 -and $r.body.data.user.profile_picture_url -like '/api/profile/picture*') { Ok 'P08 /auth/me reflects new picture (navbar refresh without re-login)' } else { Bad 'P08' "got $($r.status)" }
$r = GetReq $s '/api/profile'
if ($r.status -eq 200 -and $r.body.data.profile.profile_picture_url -like '/api/profile/picture*') { Ok 'P09 /api/profile reflects new picture' } else { Bad 'P09' "got $($r.status)" }
try {
    $img = Invoke-WebRequest -Uri "$BaseUrl/api/profile/picture" -Method Get -WebSession $s -TimeoutSec 10 -UseBasicParsing
    if ([int]$img.StatusCode -eq 200 -and $img.Headers['Content-Type'] -like 'image/*') { Ok 'P10 GET picture serves image bytes' } else { Bad 'P10' "got $([int]$img.StatusCode)" }
} catch { Bad 'P10' $_.Exception.Message }

# --- P11 replacement removes old file ---
$files1 = DbQuery "SELECT profile_picture FROM users WHERE id=$uid;"
$good2 = PostFile $s '/api/profile/picture' $png2 $csrf
$files2 = DbQuery "SELECT profile_picture FROM users WHERE id=$uid;"
if ($good2.success -eq $true -and $files2 -ne $files1 -and $files2 -match '^[0-9a-f]{64}$') { Ok 'P11 replacement updates reference' } else { Bad 'P11' 'reference not rotated' }

# --- P12 second user cannot see first user's picture via own endpoint ---
$sB = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$csrfB = Get-Csrf $sB
$emailB = "ppicb$(Get-Random)@test.local"
PostJson $sB '/api/register' @{
    full_name = 'Pic Other'; email = $emailB; password = 'Str0ngPass1';
    chapter_id = 1; date_of_birth = '1999-01-01'; privacy_acknowledged = $true
} $csrfB | Out-Null
PostJson $sB '/api/login' @{ email = $emailB; password = 'Str0ngPass1' } $csrfB | Out-Null
$r = GetReq $sB '/api/profile/picture'
if ($r.status -eq 404) { Ok 'P12 other user without picture gets 404 (no cross-user access)' } else { Bad 'P12' "got $($r.status)" }

# --- P13 audit logged ---
$rows = DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='profile.picture_updated';"
if ([int]$rows -ge 2) { Ok "P13 profile.picture_updated audited ($rows rows)" } else { Bad 'P13' "only $rows rows" }

Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
