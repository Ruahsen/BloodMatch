<?php

declare(strict_types=1);

// Delivery-failure harness for the registration-time email OTP flow.
//
// Simulates "account creation succeeds but the email cannot be delivered"
// by pointing the mail capture transport at an impossible path (a routine
// that can never succeed because a path component is an existing FILE).
// Pre-setting $_ENV wins over the .env file (Env::load only fills missing
// keys), so the sabotage is confined to this process.
//
// Usage:
//   D:\xampp\php\php.exe tests/email_otp_delivery_check.php
//
// Exit 0 + PASS lines on success, exit 1 + FAIL lines otherwise. Leaves one
// fixture user (deliveryfail*@test.local) behind, like the .ps1 suites do.

$_ENV['MAIL_CAPTURE_DIR'] = dirname(__DIR__) . '/backend/src/autoload.php/mail-capture';

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;
use BloodMatch\Services\AuthService;

Env::load(BASE_PATH . '/.env');

$pass = 0;
$fail = 0;
$ok = static function (string $name) use (&$pass): void {
    $pass++;
    echo "PASS  $name\n";
};
$bad = static function (string $name, string $why) use (&$fail): void {
    $fail++;
    echo "FAIL  $name -> $why\n";
};

$email = 'deliveryfail' . random_int(100000, 999999) . '@test.local';

try {
    $result = (new AuthService())->register([
        'full_name' => 'Delivery Failure Fixture',
        'email' => $email,
        'password' => 'Str0ngPass1',
        'chapter_id' => 1,
        'date_of_birth' => '2000-05-10',
        'blood_type' => 'O+',
        'phone' => null,
        'privacy_acknowledged' => true,
    ]);
} catch (Throwable $e) {
    $bad('registration survives mail outage', get_class($e) . ': ' . $e->getMessage());
    echo "\n== RESULT: $pass passed, $fail failed ==\n";
    exit(1);
}

$userId = (int) ($result['user']['id'] ?? 0);
$otp = $result['email_otp'] ?? [];

// 1. Account creation stands.
if ($userId > 0 && ($result['user']['verification_status'] ?? '') === 'pending') {
    $ok('account created (pending) despite mail outage');
} else {
    $bad('account created (pending) despite mail outage', 'no usable user payload');
}

// 2. The response honestly reports non-delivery, still hands over the
//    claim token (the retry path) and never exposes secrets.
$tokenOk = is_string($otp['verification_token'] ?? null) && (bool) preg_match('/^[0-9a-f]{64}$/', $otp['verification_token']);
$maskedOk = ($otp['masked_email'] ?? '') === 'd***@test.local';
if (($otp['required'] ?? false) === true && ($otp['delivered'] ?? true) === false && $tokenOk && $maskedOk) {
    $ok('201 reports delivered=false with claim token + masked email');
} else {
    $bad('201 reports delivered=false with claim token + masked email', json_encode($otp));
}

// 3. Fresh reads: unverified, no usable OTP row survived.
$pdo = Database::pdo();
$verifiedAt = $pdo->query("SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id = $userId")->fetchColumn();
$usableOtps = (int) $pdo->query("SELECT COUNT(*) FROM email_verification_otps WHERE user_id = $userId AND used_at IS NULL")->fetchColumn();
$liveClaims = (int) $pdo->query("SELECT COUNT(*) FROM email_otp_claim_tokens WHERE user_id = $userId AND used_at IS NULL")->fetchColumn();
if ($verifiedAt === 'NULL' && $usableOtps === 0 && $liveClaims === 1) {
    $ok('email stays unverified; undelivered OTP removed; retry token live');
} else {
    $bad('email stays unverified; undelivered OTP removed; retry token live', "verified=$verifiedAt otps=$usableOtps claims=$liveClaims");
}

// 4. Audit shows both sides of the story, with no OTP material.
$deliveryFailed = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.email_otp.delivery_failed' AND target_id = '$userId'")->fetchColumn();
$regCtx = (string) $pdo->query("SELECT context FROM audit_log WHERE action = 'user.registered' AND target_id = '$userId' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($deliveryFailed >= 1 && str_contains($regCtx, 'email_otp_delivered')) {
    $ok('delivery_failed audited; register context carries the flag');
} else {
    $bad('delivery_failed audited; register context carries the flag', "failed=$deliveryFailed ctx=$regCtx");
}

// 5. The retry path works once mail recovers: Env::get consults $_ENV
//    on every call, so restoring the real capture dir mid-process flips
//    delivery back on. Resend through the SAME claim token, then verify
//    the delivered code — the full register -> fail -> retry -> verified
//    journey in one process.
$captureDir = dirname(__DIR__) . '/logs/mail-capture';
$_ENV['MAIL_CAPTURE_DIR'] = $captureDir;
$svc = new BloodMatch\Services\EmailOtpService();
$claimToken = (string) $otp['verification_token'];
try {
    $resent = $svc->sendWithToken($claimToken);
    if (($resent['already_verified'] ?? true) === false) {
        $ok('retry resend accepted through the original claim token');
    } else {
        $bad('retry resend accepted through the original claim token', 'already_verified shape');
    }
} catch (Throwable $e) {
    $bad('retry resend accepted through the original claim token', get_class($e) . ': ' . $e->getMessage());
}

$eml = null;
foreach (glob($captureDir . '/*.eml') ?: [] as $file) {
    if (str_contains(basename((string) $file), $email) && ($eml === null || filemtime((string) $file) > filemtime($eml))) {
        $eml = (string) $file;
    }
}
$code = null;
if ($eml !== null && preg_match('/^(\d{6})$/m', (string) file_get_contents($eml), $m)) {
    $code = $m[1];
}
if ($code !== null) {
    $ok('recovered email captured with a 6-digit code');
} else {
    $bad('recovered email captured with a 6-digit code', 'no .eml/code for ' . $email);
}

if ($code !== null) {
    try {
        $done = $svc->verifyWithToken($claimToken, $code);
        $verifiedAt2 = $pdo->query("SELECT IFNULL(email_verified_at,'NULL') FROM users WHERE id = $userId")->fetchColumn();
        if (($done['verified'] ?? false) === true && $verifiedAt2 !== 'NULL') {
            $ok('retry code verified; email_verified_at populated');
        } else {
            $bad('retry code verified; email_verified_at populated', json_encode($done));
        }
    } catch (Throwable $e) {
        $bad('retry code verified; email_verified_at populated', get_class($e) . ': ' . $e->getMessage());
    }
}

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
