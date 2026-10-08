<?php

// BloodMatch notification-email unit tests (no DB, no SMTP required).
// Run: D:\xampp\php\php.exe tests/email_template.php
// Exit 0 = all pass, 1 = any failure.

declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/autoload.php';

use BloodMatch\Services\Mailer;
use BloodMatch\Services\NotificationEmailTemplate;

$pass = 0;
$fail = 0;

function ok(string $name): void
{
    global $pass;
    $pass++;
    echo "PASS  $name\n";
}

function bad(string $name, string $why): void
{
    global $fail;
    $fail++;
    echo "FAIL  $name -> $why\n";
}

function setMailEnv(array $vars): void
{
    foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USER', 'MAIL_PASS', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_FROM', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_ENCRYPTION', 'MAIL_TIMEOUT',
        'MAIL_CAPTURE_DIR', 'FRONTEND_URL', 'APP_CORS_ORIGINS'] as $k) {
        unset($_ENV[$k]);
        putenv($k);
    }
    foreach ($vars as $k => $v) {
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}

// Representative (type, title, body) triples mirroring the real call sites.
$cases = [
    ['match.new', 'New compatible donation opportunity (#12)', 'An urgent request for blood type O- near Phase 10 Hospital needs donors like you. Open BloodMatch for details.', 'blood_request', 12],
    ['match.responded', 'A donor responded to your request (#12)', 'A compatible donor responded to your O- blood request at Phase 10 Hospital. Review your matches to accept a donor.', 'blood_request', 12],
    ['match.accepted', 'You were accepted for request (#12)', 'The requester accepted your response. You can now view contact details to coordinate directly.', 'blood_request', 12],
    ['match.unaccepted', 'A requester withdrew an acceptance', 'The requester is no longer holding your response as accepted. Your willingness to donate is still recorded.', 'blood_request', 12],
    ['match.withdrawn', 'A donor withdrew a response', 'A donor withdrew their response to your blood request. Your accepted capacity was released if they were accepted.', 'blood_request', 12],
    ['match.consent_revoked', 'Contact sharing was revoked', 'The other party revoked email sharing for your match. Contact details are no longer available.', 'blood_request', 12],
    ['match.closed', 'An accepted match was closed', 'An accepted donor relationship on your blood request was closed for an administrative or safety reason. Contact details are no longer available.', 'blood_request', 12],
    ['verification.decision', 'Your membership has been verified', 'An officer verified your membership. You can now participate as a donor.', 'verification', 7],
    ['account.status_changed', 'Your account has been reactivated', 'Your account was reactivated. Welcome back!', 'account', 7],
    ['donation.confirmed', 'Donation confirmed - thank you!', 'Your donation was confirmed. Thank you for saving a life!', 'donation_report', 3],
    ['donation.rejected', 'Donation report rejected', 'Your donation report could not be confirmed.', 'donation_report', 3],
    ['request.fulfilled', 'Blood request fulfilled', 'Your blood request #12 has received all required units and is now fulfilled.', 'blood_request', 12],
    ['request.cancelled', 'Blood request cancelled', 'Your blood request #12 has been cancelled.', 'blood_request', 12],
    ['request.expired', 'Blood request expired', 'Your blood request #12 passed its needed date and has expired.', 'blood_request', 12],
];

setMailEnv(['APP_CORS_ORIGINS' => 'http://localhost:5173']);

// U01: every known type renders subject/body/branding/footer/links.
foreach ($cases as [$type, $title, $body, $relType, $relId]) {
    $e = NotificationEmailTemplate::render($type, $title, $body, $relType, $relId, '2026-10-06 12:00:00');
    $okName = "U01 render $type";
    if ($e['subject'] !== '[BloodMatch] ' . $title) {
        bad($okName, 'subject mismatch');
        continue;
    }
    if (strpos($e['html'], 'BloodMatch') === false || strpos($e['html'], 'will never ask for your password') === false) {
        bad($okName, 'html missing branding/footer');
        continue;
    }
    if (strpos($e['html'], htmlspecialchars($title, ENT_QUOTES, 'UTF-8')) === false
        || strpos($e['html'], htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) === false) {
        bad($okName, 'html missing title/body');
        continue;
    }
    if (strpos($e['html'], '2026-10-06 12:00:00 UTC') === false) {
        bad($okName, 'html missing timestamp');
        continue;
    }
    if ($e['text'] === '' || strpos($e['text'], $e['actionUrl']) === false || strpos($e['text'], $title) === false) {
        bad($okName, 'text fallback incomplete');
        continue;
    }
    if (strpos($e['actionUrl'], 'http://localhost:5173') !== 0) {
        bad($okName, 'action URL not absolute: ' . $e['actionUrl']);
        continue;
    }
    ok($okName);
}

// U02: action paths are role-safe deep links.
$paths = [
    ['match.new', 'blood_request', 12, '/feed'],
    ['match.responded', 'blood_request', 12, '/requests/12/matches'],
    ['match.withdrawn', 'blood_request', 12, '/requests/12/matches'],
    ['match.accepted', 'blood_request', 12, '/notifications'],
    ['verification.decision', 'verification', 7, '/profile'],
    ['account.status_changed', 'account', 7, '/profile'],
    ['request.fulfilled', 'blood_request', 12, '/requests/mine'],
    ['request.cancelled', 'blood_request', 12, '/notifications'],
    ['request.expired', 'blood_request', 12, '/notifications'],
    ['donation.confirmed', 'donation_report', 3, '/notifications'],
    ['future.unknown_type', null, null, '/notifications'],
];
foreach ($paths as [$type, $relType, $relId, $expected]) {
    $got = NotificationEmailTemplate::actionPathFor($type, $relType, $relId);
    if ($got === $expected) {
        ok("U02 path $type -> $expected");
    } else {
        bad("U02 path $type", "got $got expected $expected");
    }
}

// U03: hostile title/body is escaped (no raw HTML passthrough).
$xss = NotificationEmailTemplate::render(
    'match.new',
    '"><script>alert(1)</script>',
    'Body <img src=x onerror=alert(2)> and <a href="http://evil.test">link</a>',
    'blood_request',
    12,
    '2026-10-06 12:00:00'
);
if (strpos($xss['html'], '<script>') === false && strpos($xss['html'], '<img') === false
    && strpos($xss['html'], '&lt;script&gt;') !== false) {
    ok('U03 hostile content escaped');
} else {
    bad('U03 hostile content escaped', 'raw HTML leaked into email body');
}

// U04: template adds no sensitive material beyond the notification itself.
// Strip the (escaped) title/body, then scan the chrome for secret-disclosure
// patterns (not bare words: the footer legitimately says "never ask for your
// password", which mentions the word but discloses nothing).
$forbiddenPatterns = [
    'password_hash' => '/password_hash/i',
    'stored token hash' => '/token_hash/i',
    'disclosed reset token' => '/reset.{0,10}token\s*[:=]/i',
    'disclosed password value' => '/password\s*[:=]\s*\S/i',
    'csrf token value' => '/csrf.{0,20}[a-f0-9]{16,}/i',
    'raw coordinates' => '/-?\d{1,3}\.\d{4,}/',
    'email address' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
    'phone number' => '/\+?63\d{10}|\b09\d{9}\b/',
];
$chromeClean = true;
foreach ($cases as [$type, $title, $body, $relType, $relId]) {
    $e = NotificationEmailTemplate::render($type, $title, $body, $relType, $relId, '2026-10-06 12:00:00');
    $chrome = str_replace(
        [htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), htmlspecialchars($body, ENT_QUOTES, 'UTF-8'), $title, $body],
        '',
        $e['html'] . "\n" . $e['text']
    );
    foreach ($forbiddenPatterns as $label => $rx) {
        if (preg_match($rx, $chrome) === 1) {
            bad('U04 no sensitive leakage', "$type chrome shows $label");
            $chromeClean = false;
            break;
        }
    }
}
if ($chromeClean) {
    ok('U04 template chrome discloses no secrets');
}

// U05: another user's email address is never injected by the template.
$e = NotificationEmailTemplate::render('match.accepted', 'Accepted', 'Coordination unlocked.', 'blood_request', 12, '2026-10-06 12:00:00');
if (strpos($e['html'] . $e['text'], 'stranger@test.local') === false
    && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', str_replace('bloodmatch@localhost', '', $e['html'] . $e['text'])) === 0) {
    ok('U05 no foreign email address in template output');
} else {
    bad('U05 no foreign email address', 'unexpected email-like string found');
}

// U06: unconfigured mailer is a graceful skip.
setMailEnv([]);
if (!Mailer::isConfigured() && Mailer::send('a@test.local', 's', '<p>b</p>') === false) {
    ok('U06 unconfigured mailer skips gracefully');
} else {
    bad('U06 unconfigured mailer', 'expected isConfigured=false and send=false');
}

// U07: capture transport writes a verifiable file and counts as sent.
$capDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bm_mailcap_' . bin2hex(random_bytes(4));
setMailEnv(['MAIL_CAPTURE_DIR' => $capDir]);
$e = NotificationEmailTemplate::render('request.cancelled', 'Blood request cancelled', 'Your blood request #99 has been cancelled.', 'blood_request', 99, '2026-10-06 12:00:00');
if (Mailer::isConfigured() && Mailer::send('donor@test.local', $e['subject'], $e['html'], $e['text']) === true) {
    $files = glob($capDir . DIRECTORY_SEPARATOR . '*.eml');
    $content = $files !== false && $files !== [] ? (string) file_get_contents($files[0]) : '';
    if ($content !== '' && strpos($content, 'To: donor@test.local') !== false
        && strpos($content, 'Blood request cancelled') !== false
        && strpos($content, 'BloodMatch') !== false
        && strpos($content, '/notifications') !== false) {
        ok('U07 capture transport writes verifiable email file');
    } else {
        bad('U07 capture transport', 'file missing expected headers/content');
    }
} else {
    bad('U07 capture transport', 'send did not return true');
}
array_map('unlink', glob($capDir . DIRECTORY_SEPARATOR . '*.eml') ?: []);
@rmdir($capDir);

// U08: SMTP failure returns false (never throws, never blocks the caller).
// A deliverable From address isolates the connection-failure path from
// sender-validation failure (both must return false, never throw).
setMailEnv([
    'MAIL_HOST' => '127.0.0.1',
    'MAIL_PORT' => '9',
    'MAIL_TIMEOUT' => '3',
    'MAIL_FROM_ADDRESS' => 'noreply@test.local',
]);
$start = microtime(true);
$result = Mailer::send('a@test.local', 's', '<p>b</p>', 'b');
$elapsed = microtime(true) - $start;
if ($result === false && $elapsed < 30) {
    ok(sprintf('U08 SMTP failure returns false (%.1fs)', $elapsed));
} else {
    bad('U08 SMTP failure', 'expected false, got ' . var_export($result, true));
}

// U09: canonical env aliases work (MAIL_USERNAME/MAIL_PASSWORD/MAIL_FROM_ADDRESS,
// MAIL_ENCRYPTION, MAIL_FROM_NAME accepted; legacy names still read).
setMailEnv([
    'MAIL_HOST' => 'smtp.test.local',
    'MAIL_USERNAME' => 'user@test.local',
    'MAIL_PASSWORD' => 'secret',
    'MAIL_FROM_ADDRESS' => 'noreply@test.local',
    'MAIL_FROM_NAME' => 'BloodMatch Test',
    'MAIL_ENCRYPTION' => 'tls',
]);
$ref = new ReflectionClass(Mailer::class);
foreach (['smtpUser' => 'user@test.local', 'smtpPass' => 'secret', 'fromAddress' => 'noreply@test.local'] as $m => $want) {
    $rm = $ref->getMethod($m);
    $rm->setAccessible(true);
    $got = $rm->invoke(null);
    if ($got === $want) {
        ok("U09 alias $m");
    } else {
        bad("U09 alias $m", "got '$got' expected '$want'");
    }
}
// Legacy fallbacks still honored.
setMailEnv(['MAIL_HOST' => 'smtp.test.local', 'MAIL_USER' => 'legacy', 'MAIL_PASS' => 'legacypass', 'MAIL_FROM' => 'legacy@test.local']);
foreach (['smtpUser' => 'legacy', 'smtpPass' => 'legacypass', 'fromAddress' => 'legacy@test.local'] as $m => $want) {
    $rm = $ref->getMethod($m);
    $rm->setAccessible(true);
    $got = $rm->invoke(null);
    if ($got === $want) {
        ok("U09 legacy $m");
    } else {
        bad("U09 legacy $m", "got '$got' expected '$want'");
    }
}

echo "\n== email_template: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
