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

function New-FixtureUser($email, $name, $role, $chapterId, $password) {
    $hash = & $PhpPath -r "echo password_hash('$password', PASSWORD_BCRYPT);"
    $chapSql = 'NULL'
    if ($null -ne $chapterId) { $chapSql = "$chapterId" }
    DbQuery "INSERT INTO users (email, password_hash, full_name, role, chapter_id, verification_status, account_status)
             VALUES ('$email', '$hash', '$name', '$role', $chapSql, '$(if ($role -eq 'officer') {'verified'} else {'pending'})', 'active');"
    return (DbQuery "SELECT id FROM users WHERE email='$email';")
}

$suffix = "$(Get-Random)"
$pw = 'Str0ngPass1'

Write-Host "== Phase 4 RBAC & chapter scoping audit =="

# --- fixtures ---
$adminEmail  = "p4admin$suffix@test.local"
$officerAEmail = "p4offa$suffix@test.local"
$officerBEmail = "p4offb$suffix@test.local"
$memberEmail = "p4member$suffix@test.local"
$adminId  = New-FixtureUser $adminEmail 'Phase Four Admin' 'admin' $null $pw
$officerAId = New-FixtureUser $officerAEmail 'Officer Alpha' 'officer' 1 $pw
$officerBId = New-FixtureUser $officerBEmail 'Officer Bravo' 'officer' 2 $pw
$memberId = New-FixtureUser $memberEmail 'Member Mike' 'member' 1 $pw

function Login($email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s
    $r = Invoke-Json $s 'Post' '/api/login' @{ email = $email; password = $pw } $csrf
    if ($r.status -ne 200) { throw "fixture login failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $csrf }
}

$baselineDenials = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='authz.denied';")

# --- T01 unauthenticated -> 401 ---
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' '/api/admin/users' $null $null
if ($r.status -eq 401) { Ok 'T01 unauthenticated admin access 401' } else { Bad 'T01' "got $($r.status)" }

# --- T02 member -> admin endpoint 403 ---
$m = Login $memberEmail
$r = Invoke-Json $m.s 'Get' '/api/admin/users' $null $m.csrf
if ($r.status -eq 403) { Ok 'T02 member denied admin list 403' } else { Bad 'T02' "got $($r.status)" }

# --- T03 officer -> admin-only endpoint 403 ---
$oa = Login $officerAEmail
$r = Invoke-Json $oa.s 'Get' '/api/admin/users' $null $oa.csrf
if ($r.status -eq 403) { Ok 'T03 officer denied admin-only endpoint 403' } else { Bad 'T03' "got $($r.status)" }

# --- T04 admin lists system-wide ---
$a = Login $adminEmail
$r = Invoke-Json $a.s 'Get' '/api/admin/users?page=1&page_size=100' $null $a.csrf
if ($r.status -eq 200 -and $r.body.data.total -ge 4) { Ok "T04 admin system-wide list (total=$($r.body.data.total))" } else { Bad 'T04' "got $($r.status)" }

# --- T05 officer sees own chapter only ---
$r = Invoke-Json $oa.s 'Get' '/api/officer/users' $null $oa.csrf
$foreign = @($r.body.data.users | Where-Object { $_.chapter_id -ne 1 }).Count
if ($r.status -eq 200 -and $foreign -eq 0) { Ok 'T05 officer list restricted to own chapter' } else { Bad 'T05' "status=$($r.status) foreign=$foreign" }

# --- T06 officer requesting other chapter explicitly -> 403 ---
$r = Invoke-Json $oa.s 'Get' '/api/officer/users?chapter_id=2' $null $oa.csrf
if ($r.status -eq 403) { Ok 'T06 officer cross-chapter request 403' } else { Bad 'T06' "got $($r.status)" }

# --- T07 officer without chapter rejected ---
DbQuery "INSERT INTO users (email, password_hash, full_name, role, verification_status, account_status) VALUES ('p4nochapter$suffix@test.local', '$(& $PhpPath -r ""echo password_hash('$pw', PASSWORD_BCRYPT);"")', 'No Chapter Officer', 'officer', 'verified', 'active');"
$ncId = DbQuery "SELECT id FROM users WHERE email='p4nochapter$suffix@test.local';"
$nc = Login "p4nochapter$suffix@test.local"
$r = Invoke-Json $nc.s 'Get' '/api/officer/users' $null $nc.csrf
if ($r.status -eq 403) { Ok 'T07 null-chapter officer blocked from scoped list' } else { Bad 'T07' "got $($r.status)" }

# --- T08 invalid role value rejected ---
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$memberId/role" @{ role = 'superadmin' } $a.csrf
if ($r.status -eq 400) { Ok 'T08 invalid role value 400' } else { Bad 'T08' "got $($r.status)" }

# --- T09 officer requires exactly one chapter ---
$freshMemberEmail = "p4prom$suffix@test.local"
$freshId = New-FixtureUser $freshMemberEmail 'Promotee' 'member' $null $pw
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$freshId/role" @{ role = 'officer' } $a.csrf
if ($r.status -eq 400) { Ok 'T09 officer without chapter rejected 400' } else { Bad 'T09' "got $($r.status)" }

# --- T10 admin promotes member to officer with chapter ---
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$freshId/role" @{ role = 'officer'; chapter_id = 3 } $a.csrf
$dbRole = DbQuery "SELECT CONCAT(role,'|',chapter_id) FROM users WHERE id=$freshId;"
if ($r.status -eq 200 -and $dbRole -eq 'officer|3') { Ok 'T10 promote to officer+chapter3 persisted' } else { Bad 'T10' "status=$($r.status) db=$dbRole" }

# --- T11 self-role change forbidden (even for admin) ---
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$adminId/role" @{ role = 'member' } $a.csrf
if ($r.status -eq 403) { Ok 'T11 admin self-role-change 403' } else { Bad 'T11' "got $($r.status)" }

# --- T12 officers cannot reach role/chapter endpoints (incl. on self) ---
$r = Invoke-Json $oa.s 'Post' "/api/admin/users/$officerAId/role" @{ role = 'admin' } $oa.csrf
if ($r.status -eq 403) { Ok 'T12 officer self-role-change via admin route 403' } else { Bad 'T12' "got $($r.status)" }
$r = Invoke-Json $oa.s 'Post' "/api/admin/users/$officerAId/chapter" @{ chapter_id = 2 } $oa.csrf
if ($r.status -eq 403) { Ok 'T13 officer self-chapter-change via admin route 403' } else { Bad 'T13' "got $($r.status)" }

# --- T14 admin cross-chapter reassignment allowed ---
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$officerBId/chapter" @{ chapter_id = 3 } $a.csrf
$dbChap = DbQuery "SELECT chapter_id FROM users WHERE id=$officerBId;"
if ($r.status -eq 200 -and $dbChap -eq '3') { Ok 'T14 admin exempt from chapter scope (reassign ok)' } else { Bad 'T14' "status=$($r.status) db=$dbChap" }

# --- T15 officer with null chapter cannot be created via API (already T09); verify DB invariant path: setChapter clear on officer -> 422 ---
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$officerBId/chapter" @{ chapter_id = $null } $a.csrf
if ($r.status -eq 422) { Ok 'T15 clearing officer chapter rejected 422' } else { Bad 'T15' "got $($r.status)" }

# --- T16 deactivate/reactivate lifecycle ---
$verBefore = DbQuery "SELECT verification_status FROM users WHERE id=$memberId;"
$r = Invoke-Json $a.s 'Post' "/api/admin/users/$memberId/deactivate" @{} $a.csrf
$row = DbQuery "SELECT CONCAT(account_status,'|',IF(deactivated_at IS NULL,'NULL','SET'),'|',verification_status) FROM users WHERE id=$memberId;"
if ($r.status -eq 200 -and $row -like 'deactivated|SET|*') { Ok 'T16 deactivate sets status+timestamp' } else { Bad 'T16' "row=$row" }
if (($row -split '\|')[2] -eq $verBefore) { Ok 'T17 verification_status untouched by deactivation' } else { Bad 'T17' "ver changed: $verBefore -> $row" }

$r = Invoke-Json $m.s 'Get' '/api/auth/me' $null $m.csrf
if ($r.status -eq 403) { Ok 'T18 deactivated live session denied protected access' } else { Bad 'T18' "got $($r.status)" }

$r = Invoke-Json $a.s 'Post' "/api/admin/users/$memberId/reactivate" @{} $a.csrf
$row = DbQuery "SELECT CONCAT(account_status,'|',IF(deactivated_at IS NULL,'NULL','SET')) FROM users WHERE id=$memberId;"
if ($r.status -eq 200 -and $row -eq 'active|NULL') { Ok 'T19 reactivate restores active + clears timestamp' } else { Bad 'T19' "row=$row" }

# --- T20 deactivated admin mid-session denied ---
DbQuery "UPDATE users SET account_status='deactivated', deactivated_at=NOW() WHERE id=$adminId;"
$r = Invoke-Json $a.s 'Get' '/api/admin/users' $null $a.csrf
if ($r.status -eq 403) { Ok 'T20 deactivated admin session denied 403' } else { Bad 'T20' "got $($r.status)" }
DbQuery "UPDATE users SET account_status='active', deactivated_at=NULL WHERE id=$adminId;"

# --- T21 URL guessing still backend-denied ---
$r = Invoke-Json (New-Object Microsoft.PowerShell.Commands.WebRequestSession) 'Get' '/api/admin/nonexistent' $null $null
if ($r.status -eq 404) { Ok 'T21 unknown admin path 404 JSON (not exposed)' } else { Bad 'T21' "got $($r.status)" }

# --- T22 audit denials recorded ---
$newDenials = [int](DbQuery "SELECT COUNT(*) FROM audit_log WHERE action='authz.denied';")
if ($newDenials -gt $baselineDenials) { Ok "T22 authz.denial audits written (+$($newDenials - $baselineDenials))" } else { Bad 'T22' "baseline=$baselineDenials now=$newDenials" }

# --- T23 admin actions audited ---
$acts = DbQuery "SELECT COUNT(DISTINCT action) FROM audit_log WHERE action IN ('admin.user.role_changed','admin.user.chapter_assigned','admin.user.deactivated','admin.user.reactivated');"
if ([int]$acts -ge 4) { Ok 'T23 admin action events audited' } else { Bad 'T23' "distinct=$acts" }

Write-Host ''
Write-Host "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
