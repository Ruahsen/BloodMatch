<?php

declare(strict_types=1);

// Standard development/test accounts for BloodMatch.
//
// Creates (idempotently) the seven documented test logins so the normal
// POST /api/login flow works with password "Str0ngPass1":
//   admin@test.local            -> admin (no chapter; scope-exempt)
//   samatofficer@test.local     -> officer, Mt. Samat Chapter
//   tarakofficer@test.local     -> officer, Mt. Tarak Chapter
//   meridianofficer@test.local  -> officer, Meridian Heights Chapter
//   verified@test.local         -> verified member + enrolled available donor
//   pending@test.local          -> pending member (login works; donor-gated)
//   rejected@test.local         -> rejected member (login works; capability-gated)
//
// Fixture-layer only: this script touches ONLY these seven emails plus their
// login-throttle rows. It does not modify AuthService, middleware, RBAC,
// CSRF, production auth behavior, real-user accounts, or randomized
// per-test fixtures (p*@test.local with random suffixes).
//
// Usage (from repository root):
//   D:\xampp\php\php.exe database/seed_standard_test_accounts.php
//
// Safe to re-run: existing rows are reconciled to the spec (password is
// only re-hashed when password_verify fails) and login-throttle rows for
// these seven emails are cleared so prior failed probes do not lock them.

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;

Env::load(BASE_PATH . '/.env');

const STANDARD_TEST_PASSWORD = 'Str0ngPass1';

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, '[std-accounts] cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Resolve chapter IDs by code (never hardcode auto-increment IDs).
$chapterIds = [];
$stmt = $pdo->query('SELECT id, code FROM chapters');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $chapterIds[(string) $row['code']] = (int) $row['id'];
}

foreach (['mt_samat', 'mt_tarak', 'meridian_heights'] as $code) {
    if (!isset($chapterIds[$code])) {
        fwrite(STDERR, "[std-accounts] missing chapter '{$code}' (run seeds 001 first)" . PHP_EOL);
        exit(1);
    }
}

$specs = [
    [
        'email' => 'admin@test.local',
        'full_name' => 'Test Admin',
        'role' => 'admin',
        'chapter_id' => null,
        'verification_status' => 'verified',
        'date_of_birth' => '1990-01-01',
        'blood_type' => null,
        'blood_type_source' => null,
        'donor_enrolled' => false,
    ],
    [
        'email' => 'samatofficer@test.local',
        'full_name' => 'Mt Samat Officer',
        'role' => 'officer',
        'chapter_id' => $chapterIds['mt_samat'],
        'verification_status' => 'verified',
        'date_of_birth' => '1990-02-01',
        'blood_type' => null,
        'blood_type_source' => null,
        'donor_enrolled' => false,
    ],
    [
        'email' => 'tarakofficer@test.local',
        'full_name' => 'Mt Tarak Officer',
        'role' => 'officer',
        'chapter_id' => $chapterIds['mt_tarak'],
        'verification_status' => 'verified',
        'date_of_birth' => '1990-03-01',
        'blood_type' => null,
        'blood_type_source' => null,
        'donor_enrolled' => false,
    ],
    [
        'email' => 'meridianofficer@test.local',
        'full_name' => 'Meridian Heights Officer',
        'role' => 'officer',
        'chapter_id' => $chapterIds['meridian_heights'],
        'verification_status' => 'verified',
        'date_of_birth' => '1990-04-01',
        'blood_type' => null,
        'blood_type_source' => null,
        'donor_enrolled' => false,
    ],
    [
        'email' => 'verified@test.local',
        'full_name' => 'Verified Donor',
        'role' => 'member',
        'chapter_id' => $chapterIds['mt_samat'],
        'verification_status' => 'verified',
        'date_of_birth' => '1995-06-15',
        'blood_type' => 'O+',
        'blood_type_source' => 'self_reported',
        'donor_enrolled' => true,
    ],
    [
        'email' => 'pending@test.local',
        'full_name' => 'Pending Member',
        'role' => 'member',
        'chapter_id' => $chapterIds['mt_samat'],
        'verification_status' => 'pending',
        'date_of_birth' => '2000-05-10',
        'blood_type' => 'O+',
        'blood_type_source' => 'self_reported',
        'donor_enrolled' => false,
    ],
    [
        'email' => 'rejected@test.local',
        'full_name' => 'Rejected Member',
        'role' => 'member',
        'chapter_id' => $chapterIds['mt_samat'],
        'verification_status' => 'rejected',
        'date_of_birth' => '1999-07-20',
        'blood_type' => 'O+',
        'blood_type_source' => 'self_reported',
        'donor_enrolled' => false,
    ],
];

$findStmt = $pdo->prepare(
    'SELECT id, password_hash, role, chapter_id, verification_status, account_status,
            blood_type, blood_type_source, donor_enrolled_at, donor_availability,
            last_verified_donation_at, date_of_birth, email_verified_at
     FROM users WHERE email = ? LIMIT 1'
);
$clearThrottleStmt = $pdo->prepare('DELETE FROM auth_throttle WHERE identifier = ?');

foreach ($specs as $spec) {
    $email = (string) $spec['email'];
    $pdo->beginTransaction();
    try {
        $findStmt->execute([$email]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

        $hash = password_hash(STANDARD_TEST_PASSWORD, PASSWORD_BCRYPT);
        if ($hash === false) {
            throw new RuntimeException('password_hash failed for ' . $email);
        }

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO users
                    (email, password_hash, full_name, role, chapter_id,
                     verification_status, account_status, deactivated_at,
                     date_of_birth, blood_type, blood_type_source, blood_type_verified,
                     donor_enrolled_at, donor_availability, last_verified_donation_at,
                     latitude, longitude, email_verified_at)
                 VALUES
                    (?, ?, ?, ?, ?,
                     ?, \'active\', NULL,
                     ?, ?, ?, 0,
                     ?, ?, NULL,
                     ?, ?, UTC_TIMESTAMP())'
            );
            $enrolledAt = $spec['donor_enrolled'] ? gmdate('Y-m-d H:i:s') : null;
            $availability = $spec['donor_enrolled'] ? 'available' : null;
            // Verified donor gets a Balanga City reference point so proximity
            // ranking has a real distance; everyone else stays unlocated.
            $lat = $spec['donor_enrolled'] ? 14.676500 : null;
            $lng = $spec['donor_enrolled'] ? 120.536100 : null;
            $insert->execute([
                $email,
                $hash,
                $spec['full_name'],
                $spec['role'],
                $spec['chapter_id'],
                $spec['verification_status'],
                $spec['date_of_birth'],
                $spec['blood_type'],
                $spec['blood_type_source'],
                $enrolledAt,
                $availability,
                $lat,
                $lng,
            ]);
            echo "[std-accounts] created {$email} ({$spec['role']}/{$spec['verification_status']})" . PHP_EOL;
        } else {
            $updates = [];
            $params = [];

            $storedHash = (string) ($existing['password_hash'] ?? '');
            $needsRehash = $storedHash === '' || !password_verify(STANDARD_TEST_PASSWORD, $storedHash);
            if ($needsRehash) {
                $updates[] = 'password_hash = ?';
                $params[] = $hash;
                // Revoke sessions issued against the old credential, mirroring
                // the password-reset revocation semantic (session_version bump).
                $updates[] = 'session_version = session_version + 1';
            }

            $wantChapter = $spec['chapter_id'] === null ? null : (int) $spec['chapter_id'];
            $haveChapter = $existing['chapter_id'] === null ? null : (int) $existing['chapter_id'];
            if ((string) $existing['role'] !== (string) $spec['role']) {
                $updates[] = 'role = ?';
                $params[] = $spec['role'];
            }
            if ($haveChapter !== $wantChapter) {
                $updates[] = 'chapter_id = ?';
                $params[] = $wantChapter;
            }
            if ((string) $existing['verification_status'] !== (string) $spec['verification_status']) {
                $updates[] = 'verification_status = ?';
                $params[] = $spec['verification_status'];
            }
            if ((string) $existing['account_status'] !== 'active') {
                $updates[] = "account_status = 'active'";
                $updates[] = 'deactivated_at = NULL';
            }
            // Grandfather rule for the login gate: pre-existing fixture
            // accounts keep working. Fill only when NULL — a real OTP
            // verification timestamp is never overwritten.
            if (($existing['email_verified_at'] ?? null) === null) {
                $updates[] = 'email_verified_at = UTC_TIMESTAMP()';
            }
            if ((string) ($existing['date_of_birth'] ?? '') !== (string) $spec['date_of_birth']) {
                $updates[] = 'date_of_birth = ?';
                $params[] = $spec['date_of_birth'];
            }
            if ((string) ($existing['blood_type'] ?? '') !== (string) ($spec['blood_type'] ?? '')) {
                $updates[] = 'blood_type = ?';
                $params[] = $spec['blood_type'];
            }
            $wantSource = $spec['blood_type_source'];
            $haveSource = $existing['blood_type_source'];
            if ((string) ($haveSource ?? '') !== (string) ($wantSource ?? '')) {
                $updates[] = 'blood_type_source = ?';
                $params[] = $wantSource;
            }

            $isEnrolled = $existing['donor_enrolled_at'] !== null;
            if ($spec['donor_enrolled'] && !$isEnrolled) {
                $updates[] = 'donor_enrolled_at = UTC_TIMESTAMP()';
                $updates[] = "donor_availability = 'available'";
                $updates[] = 'last_verified_donation_at = NULL';
                $updates[] = 'latitude = ?';
                $params[] = 14.676500;
                $updates[] = 'longitude = ?';
                $params[] = 120.536100;
            } elseif (!$spec['donor_enrolled'] && ($isEnrolled || $existing['donor_availability'] !== null)) {
                $updates[] = 'donor_enrolled_at = NULL';
                $updates[] = 'donor_availability = NULL';
                $updates[] = 'last_verified_donation_at = NULL';
            }

            if ($updates !== []) {
                $params[] = (int) $existing['id'];
                $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
                echo "[std-accounts] reconciled {$email} (" . implode(',', array_slice($updates, 0, 3)) . (count($updates) > 3 ? ',...' : '') . ')' . PHP_EOL;
            } else {
                echo "[std-accounts] ok {$email} (already in spec)" . PHP_EOL;
            }
        }

        // Fixture-scoped throttle hygiene only: clear stale lock rows for
        // these seven emails so earlier failed probes do not block the
        // documented logins. Production throttle logic is untouched.
        $clearThrottleStmt->execute(['login:' . $email]);
        $clearThrottleStmt->execute(['reset:' . $email]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '[std-accounts] FAILED ' . $email . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo '[std-accounts] done (7 accounts ensured)' . PHP_EOL;
