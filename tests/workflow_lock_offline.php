<?php

declare(strict_types=1);

// Pure transaction-contract/policy checks with a connectionless PDO double.
require dirname(__DIR__) . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Services\WorkflowLockService;

final class OfflineWorkflowPDO extends PDO
{
    public bool $active = false;
    public array $events = [];
    public function __construct() {}
    public function beginTransaction(): bool { $this->events[] = 'begin'; return $this->active = true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->events[] = 'commit'; $this->active = false; return true; }
    public function rollBack(): bool { $this->events[] = 'rollback'; $this->active = false; return true; }
}
$pdo = new OfflineWorkflowPDO();
$prop = new ReflectionProperty(Database::class, 'pdo');
$prop->setAccessible(true);
$prop->setValue(null, $pdo);
$passed = 0;
function check(string $label, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $passed++;
    echo 'PASS ' . $label . PHP_EOL;
}
function expectStatus(callable $work, int $status): bool {
    try { $work(); } catch (RuntimeException $e) { return $e->getCode() === $status; }
    return false;
}

check('Successful owner commits and returns result', WorkflowLockService::transaction(fn () => 'saved') === 'saved'
    && $pdo->events === ['begin', 'commit']);
$pdo->events = [];
check('State conflict rolls back before propagating', expectStatus(fn () => WorkflowLockService::transaction(function () {
    throw new RuntimeException('Already closed.', 409);
}), 409) && $pdo->events === ['begin', 'rollback']);
$pdo->events = [];
check('Nested success commits only outer transaction', WorkflowLockService::transaction(fn () =>
    WorkflowLockService::transaction(fn () => 'nested')) === 'nested' && $pdo->events === ['begin', 'commit']);
$pdo->events = [];
check('Nested failure rolls back only outer transaction', expectStatus(fn () => WorkflowLockService::transaction(fn () =>
    WorkflowLockService::transaction(function () { throw new RuntimeException('Conflict.', 409); })), 409)
    && $pdo->events === ['begin', 'rollback']);
foreach ([1205, 1213] as $dbCode) {
    $pdo->events = [];
    $calls = 0;
    $ok = expectStatus(function () use ($dbCode, &$calls) {
        return WorkflowLockService::transaction(function () use ($dbCode, &$calls) {
            $calls++;
            $e = new PDOException('Offline conflict.');
            $e->errorInfo = ['HY000', $dbCode, 'Offline conflict.'];
            throw $e;
        });
    }, 409);
    check('Database conflict ' . $dbCode . ' rolls back without retrying side effects', $ok && $calls === 1
        && $pdo->events === ['begin', 'rollback']);
}
$request = ['status' => 'OPEN', 'request_chapter_id' => 1];
$officer = ['id' => 3, 'account_status' => 'active', 'role' => 'officer', 'chapter_id' => 1];
WorkflowLockService::assertReportReviewer($officer, $request, 2);
check('Assigned active officer may review another donor', true);
check('Officer cannot review own report', expectStatus(fn () => WorkflowLockService::assertReportReviewer($officer, $request, 3), 403));
check('Cross-chapter officer denied', expectStatus(fn () => WorkflowLockService::assertReportReviewer(array_replace($officer, ['chapter_id' => 2]), $request, 2), 403));
check('Officer without chapter denied', expectStatus(fn () => WorkflowLockService::assertReportReviewer(array_replace($officer, ['chapter_id' => null]), $request, 2), 403));
check('Changed member role denied at save', expectStatus(fn () => WorkflowLockService::assertReportReviewer(array_replace($officer, ['role' => 'member']), $request, 2), 403));
check('Deactivated reviewer denied at save', expectStatus(fn () => WorkflowLockService::assertReportReviewer(array_replace($officer, ['account_status' => 'deactivated']), $request, 2), 403));
WorkflowLockService::assertReportReviewer(array_replace($officer, ['role' => 'admin', 'chapter_id' => null]), $request, 2);
check('Admin retains global review scope', true);
foreach (['CANCELLED', 'EXPIRED', 'FULFILLED'] as $status) {
    check($status . ' is blocked for active actions', expectStatus(fn () => WorkflowLockService::assertOpen(['status' => $status]), 409));
}
echo "{$passed} offline workflow contract checks passed. Real SQL locking/concurrency not exercised." . PHP_EOL;
