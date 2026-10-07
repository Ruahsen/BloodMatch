param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$MysqlPath = 'D:\xampp\mysql\bin\mysql.exe'
)

# Meridian Heights officer fixture audit (standalone; not part of run_all.ps1).
#
# Verifies the third canonical Bataan chapter behaves as a normal chapter
# under the existing generic chapter/officer system:
#   M1 chapter exists exactly once (id 3, meridian_heights)
#   M2 exactly one meridianofficer@test.local fixture
#   M3 role = officer, M4 chapter_id = Meridian Heights, active + verified
#   M5 real login flow (200 + session + /auth/me)
#   M6 chapter isolation (own allowed, Mt. Samat / Mt. Tarak denied)
#   M7 Mt. Samat + Mt. Tarak officers still work
#   M8 admin remains global
#   M9 member authentication unaffected
#
# Fixture source: database/seed_standard_test_accounts.php (idempotent).
# No special-case Meridian logic exists or is asserted anywhere here.

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
        $args = @{ Uri = "$BaseUrl$uri"; Method = $method; WebSession = $session; TimeoutSec = 10; UseBasicParsing = $true }
        if ($null -ne $body) { $args['Body'] = ($body | ConvertTo-Json -Depth 5); $args['ContentType'] = 'application/json' }
        if ($csrf) { $args['Headers'] = $headers }
        $res = Invoke-WebRequest @args
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

function Login($email, $password) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = $password } $csrf
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    $csrf = $r.body.data.csrf_token
    if ([string]::IsNullOrEmpty($csrf)) { $csrf = Get-Csrf $s }
    return @{ s = $s; csrf = $csrf; user = $r.body.data.user }
}

Write-Host "== Meridian Heights officer audit =="

# --- M1 canonical chapter exists exactly once ---
$row = DbQuery "SELECT CONCAT(id,'|',code,'|',name) FROM chapters WHERE code='meridian_heights';"
if ($row -eq '3|meridian_heights|Meridian Heights Chapter') { Ok 'M1 meridian_heights chapter exists exactly once (id 3)' } else { Bad 'M1 chapter' "got '$row'" }
$count = DbQuery "SELECT COUNT(*) FROM chapters WHERE code='meridian_heights';"
if ($count -eq '1') { Ok 'M1b no duplicate Meridian chapter' } else { Bad 'M1b duplicates' "count=$count" }

# --- M2 exactly one fixture account ---
$mc = DbQuery "SELECT COUNT(*) FROM users WHERE email='meridianofficer@test.local';"
if ($mc -eq '1') { Ok 'M2 exactly one meridianofficer@test.local' } else { Bad 'M2 count' "count=$mc" }

# --- M3/M4 role + chapter + states from DB (mirrors samat/tarak fixtures) ---
$db = DbQuery "SELECT CONCAT(role,'|',chapter_id,'|',verification_status,'|',account_status) FROM users WHERE email='meridianofficer@test.local';"
if ($db -eq 'officer|3|verified|active') { Ok 'M3/M4 officer|3|verified|active' } else { Bad 'M3/M4' "got '$db'" }
$ref1 = DbQuery "SELECT CONCAT(role,'|',chapter_id,'|',verification_status,'|',account_status) FROM users WHERE email='samatofficer@test.local';"
$ref2 = DbQuery "SELECT CONCAT(role,'|',chapter_id,'|',verification_status,'|',account_status) FROM users WHERE email='tarakofficer@test.local';"
if ($ref1 -eq 'officer|1|verified|active' -and $ref2 -eq 'officer|2|verified|active') { Ok 'M3b samat/tarak fixtures unchanged (officer|1, officer|2)' } else { Bad 'M3b refs' "samat='$ref1' tarak='$ref2'" }
$hashFmt = DbQuery "SELECT password_hash FROM users WHERE email='meridianofficer@test.local';"
if ($hashFmt -like '$2y$*') { Ok 'M3c bcrypt hash stored (no plaintext)' } else { Bad 'M3c hash format' 'not bcrypt' }

# --- M5 real login flow ---
try {
    $m = Login 'meridianofficer@test.local' 'Str0ngPass1'
    if ($m.user.role -eq 'officer' -and $m.user.verification_status -eq 'verified') { Ok 'M5 login 200 officer/verified' } else { Bad 'M5 login payload' 'wrong role/status' }
    $me = Invoke-Json $m.s 'Get' '/api/auth/me' $null $null
    if ($me.status -eq 200 -and $me.body.data.user.email -eq 'meridianofficer@test.local' -and $me.body.data.user.role -eq 'officer') { Ok 'M5b session created (/auth/me officer)' } else { Bad 'M5b session' "got $($me.status)" }
} catch {
    Bad 'M5 login' $_
    $m = $null
}

# --- M6 chapter isolation on a real scoped endpoint ---
if ($null -ne $m) {
    $r = Invoke-Json $m.s 'Get' '/api/officer/users' $null $null
    $foreign = @($r.body.data.users | Where-Object { $_.chapter_id -ne 3 }).Count
    if ($r.status -eq 200 -and $foreign -eq 0) { Ok 'M6 own chapter allowed, zero foreign rows' } else { Bad 'M6 own scope' "status=$($r.status) foreign=$foreign" }
    $r1 = Invoke-Json $m.s 'Get' '/api/officer/users?chapter_id=1' $null $null
    if ($r1.status -eq 403) { Ok 'M6b Mt. Samat denied (403)' } else { Bad 'M6b samat' "got $($r1.status)" }
    $r2 = Invoke-Json $m.s 'Get' '/api/officer/users?chapter_id=2' $null $null
    if ($r2.status -eq 403) { Ok 'M6c Mt. Tarak denied (403)' } else { Bad 'M6c tarak' "got $($r2.status)" }
}

# --- M7 existing officers unaffected ---
try {
    $sa = Login 'samatofficer@test.local' 'Str0ngPass1'
    $rs = Invoke-Json $sa.s 'Get' '/api/officer/users' $null $null
    if ($rs.status -eq 200) { Ok 'M7a Mt. Samat officer still works' } else { Bad 'M7a samat' "got $($rs.status)" }
} catch { Bad 'M7a samat' $_ }
try {
    $ta = Login 'tarakofficer@test.local' 'Str0ngPass1'
    $rt = Invoke-Json $ta.s 'Get' '/api/officer/users' $null $null
    if ($rt.status -eq 200) { Ok 'M7b Mt. Tarak officer still works' } else { Bad 'M7b tarak' "got $($rt.status)" }
} catch { Bad 'M7b tarak' $_ }

# --- M8 admin remains global ---
try {
    $ad = Login 'admin@test.local' 'Str0ngPass1'
    $ra = Invoke-Json $ad.s 'Get' '/api/admin/users?page=1&page_size=5' $null $null
    if ($ra.status -eq 200 -and [int]$ra.body.data.total -ge 7) { Ok "M8 admin global list (total=$($ra.body.data.total))" } else { Bad 'M8 admin' "got $($ra.status)" }
} catch { Bad 'M8 admin' $_ }

# --- M9 member authentication unaffected ---
try {
    $mm = Login 'verified@test.local' 'Str0ngPass1'
    $rm = Invoke-Json $mm.s 'Get' '/api/auth/me' $null $null
    if ($rm.status -eq 200 -and $rm.body.data.user.role -eq 'member') { Ok 'M9 member login unaffected' } else { Bad 'M9 member' "got $($rm.status)" }
} catch { Bad 'M9 member' $_ }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
