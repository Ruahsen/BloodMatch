<?php

declare(strict_types=1);

// Pure service checks: no Env::load, PDO connection, HTTP request, or email send.
require dirname(__DIR__) . '/backend/src/autoload.php';

use BloodMatch\Services\AgeEligibilityService;
use BloodMatch\Services\BloodCompatibilityService;
use BloodMatch\Services\DonorEligibilityService;

$matrix = [];
$seed = file_get_contents(dirname(__DIR__) . '/database/seeds/002_compatibility_matrix.sql');
preg_match_all("/\('([^']+)',\s*'([^']+)'\)/", $seed, $rows, PREG_SET_ORDER);
foreach ($rows as $row) {
    $matrix[$row[1]] = explode(',', $row[2]);
}
if (count($matrix) !== 8) {
    throw new RuntimeException('Offline matrix fixture must contain all eight recipient groups.');
}
$prop = new ReflectionProperty(BloodCompatibilityService::class, 'cache');
$prop->setAccessible(true);
$prop->setValue(null, $matrix);

$passed = 0;
function check(string $name, bool $condition): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}

$today = new DateTimeImmutable('today');
$nowUtc = gmdate('Y-m-d H:i:s');
$donor = [
    'id' => 2, 'role' => 'member', 'account_status' => 'active',
    'verification_status' => 'verified', 'email_verified_at' => $nowUtc,
    'donor_enrolled_at' => $nowUtc, 'donor_availability' => 'available',
    'last_verified_donation_at' => null, 'blood_type' => 'O-',
    'date_of_birth' => $today->modify('-25 years')->format('Y-m-d'),
    'has_parental_consent' => 0, 'chapter_id' => 3,
];
$request = [
    'requester_id' => 1, 'status' => 'OPEN', 'required_blood_type' => 'AB+',
    'needed_datetime' => gmdate('Y-m-d H:i:s', time() + 3600), 'request_chapter_id' => 1,
];
$evaluate = static fn (array $changes = [], array $requestChanges = []): array =>
    DonorEligibilityService::evaluateForRequest(array_replace($donor, $changes), array_replace($request, $requestChanges), $nowUtc);

check('Adult enrolled donor remains eligible across chapters with compatible substitution', $evaluate()['eligible']);
check('Missing birth date fails closed after enrollment', $evaluate(['date_of_birth' => null])['code'] === 'age_ineligible');
check('Invalid legacy date fails closed', $evaluate(['date_of_birth' => '2001-02-30'])['code'] === 'age_ineligible');
check('Future birth date fails closed', $evaluate(['date_of_birth' => $today->modify('+25 years')->format('Y-m-d')])['code'] === 'age_ineligible');
check('Under 16 remains blocked even with consent/enrollment', $evaluate(['date_of_birth' => $today->modify('-15 years')->format('Y-m-d'), 'has_parental_consent' => 1])['code'] === 'age_ineligible');
check('Day before sixteenth birthday blocked', $evaluate(['date_of_birth' => $today->modify('-16 years')->modify('+1 day')->format('Y-m-d'), 'has_parental_consent' => 1])['code'] === 'age_ineligible');
$sixteen = $today->modify('-16 years')->format('Y-m-d');
check('Sixteenth birthday requires consent', $evaluate(['date_of_birth' => $sixteen])['code'] === 'parental_consent_required');
check('Sixteenth birthday with stored consent allowed', $evaluate(['date_of_birth' => $sixteen, 'has_parental_consent' => 1])['eligible']);
check('Missing projected consent never grants minor eligibility', $evaluate(['date_of_birth' => $sixteen, 'has_parental_consent' => null])['code'] === 'parental_consent_required');
check('Seventeen-year-old with consent allowed', $evaluate(['date_of_birth' => $today->modify('-17 years')->format('Y-m-d'), 'has_parental_consent' => 1])['eligible']);
check('Day before eighteenth birthday still requires consent', $evaluate(['date_of_birth' => $today->modify('-18 years')->modify('+1 day')->format('Y-m-d')])['code'] === 'parental_consent_required');
check('Eighteenth birthday does not require consent', $evaluate(['date_of_birth' => $today->modify('-18 years')->format('Y-m-d')])['eligible']);
check('DOB correction restores live eligibility without removing enrollment', $evaluate(['date_of_birth' => $donor['date_of_birth']])['eligible']);
check('Unavailable adult stays blocked', $evaluate(['donor_availability' => 'unavailable'])['code'] === 'unavailable');
check('Staff read-only rule still wins', $evaluate(['role' => 'officer'])['code'] === 'staff_account');
check('Inactive member remains blocked', $evaluate(['account_status' => 'deactivated'])['code'] === 'inactive_account');
check('Unverified email remains blocked', $evaluate(['email_verified_at' => null])['code'] === 'email_unverified');
check('Not enrolled remains blocked', $evaluate(['donor_enrolled_at' => null])['code'] === 'not_enrolled');
check('Incompatible donor remains blocked', $evaluate(['blood_type' => 'AB+'], ['required_blood_type' => 'O-'])['code'] === 'blood_incompatible');
check('Own request remains blocked', $evaluate([], ['requester_id' => 2])['code'] === 'own_request');
check('Expired request remains blocked', $evaluate([], ['needed_datetime' => gmdate('Y-m-d H:i:s', time() - 10)])['code'] === 'request_expired');
check('Existing cooldown cannot be overridden by age/consent', DonorEligibilityService::evaluateForRequest(
    $donor, $request, $nowUtc,
    ['blocked' => true, 'which' => 'cooldown', 'eligible_again_at_utc' => '2099-01-01 00:00:00']
)['code'] === 'cooldown');
check('Enrollment age policy agrees with live minor eligibility', AgeEligibilityService::evaluate($sixteen, false)['donor_path_allowed'] === false);

echo "{$passed} offline donor age/consent checks passed. Database projections, HTTP persistence and races not exercised." . PHP_EOL;
