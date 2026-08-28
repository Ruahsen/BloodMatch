<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use BloodMatch\Services\DonorEligibilityService;
use PDO;

final class AnalyticsRepository
{
    private const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    private const URGENCIES = ['routine', 'urgent', 'critical'];

    /**
     * Regional Blood Demand Map: chapter-level aggregated active demand.
     */
    public function getDemandMap(array $filters = [], ?int $scopedChapterId = null): array
    {
        $pdo = Database::pdo();

        // 1. Fetch target chapters
        if ($scopedChapterId !== null) {
            $stmt = $pdo->prepare('SELECT id, code, name, municipality, latitude, longitude FROM chapters WHERE id = ? ORDER BY id ASC');
            $stmt->execute([$scopedChapterId]);
        } elseif (isset($filters['chapter_id']) && is_numeric($filters['chapter_id'])) {
            $stmt = $pdo->prepare('SELECT id, code, name, municipality, latitude, longitude FROM chapters WHERE id = ? ORDER BY id ASC');
            $stmt->execute([(int) $filters['chapter_id']]);
        } else {
            $stmt = $pdo->query('SELECT id, code, name, municipality, latitude, longitude FROM chapters ORDER BY id ASC');
        }
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch OPEN blood requests aggregated by chapter, blood type, and urgency
        $where = ["br.status = 'OPEN'"];
        $params = [];

        if ($scopedChapterId !== null) {
            $where[] = 'br.request_chapter_id = ?';
            $params[] = $scopedChapterId;
        } elseif (isset($filters['chapter_id']) && is_numeric($filters['chapter_id'])) {
            $where[] = 'br.request_chapter_id = ?';
            $params[] = (int) $filters['chapter_id'];
        }

        if (isset($filters['blood_type']) && is_string($filters['blood_type']) && in_array(trim($filters['blood_type']), self::BLOOD_TYPES, true)) {
            $where[] = 'br.required_blood_type = ?';
            $params[] = trim($filters['blood_type']);
        }

        if (isset($filters['urgency']) && is_string($filters['urgency']) && in_array(trim($filters['urgency']), self::URGENCIES, true)) {
            $where[] = 'br.urgency = ?';
            $params[] = trim($filters['urgency']);
        }

        if (isset($filters['days']) && is_numeric($filters['days']) && (int) $filters['days'] > 0) {
            $where[] = 'br.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)';
            $params[] = (int) $filters['days'];
        }

        $sql = 'SELECT br.request_chapter_id, br.required_blood_type, br.urgency,
                       COUNT(br.id) AS req_count,
                       COALESCE(SUM(br.quantity_units), 0) AS units_count
                FROM blood_requests br
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY br.request_chapter_id, br.required_blood_type, br.urgency';

        $reqStmt = $pdo->prepare($sql);
        $reqStmt->execute($params);
        $rows = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group results per chapter
        $chapterAggregates = [];
        foreach ($chapters as $ch) {
            $chId = (int) $ch['id'];
            $btCounts = [];
            foreach (self::BLOOD_TYPES as $bt) {
                $btCounts[$bt] = 0;
            }
            $urgCounts = [
                'routine' => 0,
                'urgent' => 0,
                'critical' => 0,
            ];

            $chapterAggregates[$chId] = [
                'chapter_id' => $chId,
                'chapter_code' => (string) $ch['code'],
                'chapter_name' => (string) $ch['name'],
                'municipality' => (string) $ch['municipality'],
                'latitude' => $ch['latitude'] !== null ? (float) $ch['latitude'] : null,
                'longitude' => $ch['longitude'] !== null ? (float) $ch['longitude'] : null,
                'open_requests_count' => 0,
                'total_units_needed' => 0,
                'blood_type_counts' => $btCounts,
                'urgency_counts' => $urgCounts,
            ];
        }

        foreach ($rows as $r) {
            $chId = (int) $r['request_chapter_id'];
            if (!isset($chapterAggregates[$chId])) {
                continue;
            }
            $bt = (string) $r['required_blood_type'];
            $urg = (string) $r['urgency'];
            $cnt = (int) $r['req_count'];
            $units = (int) $r['units_count'];

            $chapterAggregates[$chId]['open_requests_count'] += $cnt;
            $chapterAggregates[$chId]['total_units_needed'] += $units;
            if (isset($chapterAggregates[$chId]['blood_type_counts'][$bt])) {
                $chapterAggregates[$chId]['blood_type_counts'][$bt] += $units;
            }
            if (isset($chapterAggregates[$chId]['urgency_counts'][$urg])) {
                $chapterAggregates[$chId]['urgency_counts'][$urg] += $cnt;
            }
        }

        return array_values($chapterAggregates);
    }

    /**
     * Chapter Officer Dashboard summary.
     */
    public function getOfficerDashboard(int $chapterId): array
    {
        $pdo = Database::pdo();

        // 1. Chapter info
        $stmt = $pdo->prepare('SELECT id, code, name, municipality FROM chapters WHERE id = ? LIMIT 1');
        $stmt->execute([$chapterId]);
        $chapter = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($chapter === false) {
            $chapter = [
                'id' => $chapterId,
                'code' => '',
                'name' => 'Unknown Chapter',
                'municipality' => '',
            ];
        }

        // 2. Pending verifications in chapter
        $pvStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE role = \'member\' AND chapter_id = ? AND verification_status = \'pending\' AND account_status = \'active\''
        );
        $pvStmt->execute([$chapterId]);
        $pendingVerifications = (int) $pvStmt->fetchColumn();

        // 3. Pending donation reports for requests in chapter
        $pdrStmt = $pdo->prepare(
            'SELECT COUNT(dr.id)
             FROM donation_reports dr
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE br.request_chapter_id = ? AND dr.status = \'PENDING\''
        );
        $pdrStmt->execute([$chapterId]);
        $pendingDonationReports = (int) $pdrStmt->fetchColumn();

        // 4. Active OPEN requests in chapter
        $reqStmt = $pdo->prepare(
            'SELECT COUNT(id) AS open_count,
                    COALESCE(SUM(quantity_units), 0) AS units_needed,
                    COALESCE(SUM(CASE WHEN urgency IN (\'urgent\', \'critical\') THEN 1 ELSE 0 END), 0) AS urgent_count
             FROM blood_requests
             WHERE request_chapter_id = ? AND status = \'OPEN\''
        );
        $reqStmt->execute([$chapterId]);
        $reqSummary = $reqStmt->fetch(PDO::FETCH_ASSOC);

        $activeOpenRequests = (int) ($reqSummary['open_count'] ?? 0);
        $totalUnitsNeeded = (int) ($reqSummary['units_needed'] ?? 0);
        $urgentOpenRequests = (int) ($reqSummary['urgent_count'] ?? 0);

        // 5. Demand by blood type in chapter
        $btStmt = $pdo->prepare(
            'SELECT required_blood_type, COALESCE(SUM(quantity_units), 0) AS units
             FROM blood_requests
             WHERE request_chapter_id = ? AND status = \'OPEN\'
             GROUP BY required_blood_type'
        );
        $btStmt->execute([$chapterId]);
        $btRows = $btStmt->fetchAll(PDO::FETCH_ASSOC);

        $demandByBloodType = [];
        foreach (self::BLOOD_TYPES as $bt) {
            $demandByBloodType[$bt] = 0;
        }
        foreach ($btRows as $r) {
            $bt = (string) $r['required_blood_type'];
            if (isset($demandByBloodType[$bt])) {
                $demandByBloodType[$bt] = (int) $r['units'];
            }
        }

        // 6. Donor pool availability evaluated via DonorEligibilityService
        $donorStmt = $pdo->prepare(
            'SELECT id, blood_type, donor_availability, last_verified_donation_at
             FROM users
             WHERE chapter_id = ? AND role = \'member\' AND verification_status = \'verified\'
               AND account_status = \'active\' AND donor_enrolled_at IS NOT NULL'
        );
        $donorStmt->execute([$chapterId]);
        $donors = $donorStmt->fetchAll(PDO::FETCH_ASSOC);

        $donorPool = $this->evaluateDonorPoolStatus($donors);

        // 7. Recent chapter activity via AuditLogRepository
        $auditRepo = new AuditLogRepository();
        $recentAudit = $auditRepo->listForChapter($chapterId, [], 1, 5);

        return [
            'chapter' => [
                'id' => (int) $chapter['id'],
                'code' => (string) $chapter['code'],
                'name' => (string) $chapter['name'],
                'municipality' => (string) $chapter['municipality'],
            ],
            'metrics' => [
                'pending_verifications' => $pendingVerifications,
                'pending_donation_reports' => $pendingDonationReports,
                'active_open_requests' => $activeOpenRequests,
                'total_units_needed' => $totalUnitsNeeded,
                'urgent_open_requests' => $urgentOpenRequests,
                'enrolled_donors' => $donorPool['total_enrolled'],
                'available_donors' => $donorPool['available'],
                'standby_donors' => $donorPool['standby'],
                'cooldown_donors' => $donorPool['cooldown'],
                'unavailable_donors' => $donorPool['unavailable'],
            ],
            'demand_by_blood_type' => $demandByBloodType,
            'donor_pool' => $donorPool,
            'recent_activity' => $recentAudit['logs'] ?? [],
        ];
    }

    /**
     * Admin Dashboard summary.
     */
    public function getAdminDashboard(?int $filterChapterId = null): array
    {
        $pdo = Database::pdo();

        // 1. Total users
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $totalMembers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = \'member\'')->fetchColumn();
        $totalOfficers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = \'officer\'')->fetchColumn();

        // 2. Request lifecycle aggregates
        $reqWhere = [];
        $reqParams = [];
        if ($filterChapterId !== null) {
            $reqWhere[] = 'request_chapter_id = ?';
            $reqParams[] = $filterChapterId;
        }
        $whereSql = count($reqWhere) > 0 ? 'WHERE ' . implode(' AND ', $reqWhere) : '';

        $reqStmt = $pdo->prepare(
            "SELECT COUNT(id) AS total_requests,
                    COALESCE(SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END), 0) AS open_count,
                    COALESCE(SUM(CASE WHEN status = 'FULFILLED' THEN 1 ELSE 0 END), 0) AS fulfilled_count,
                    COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END), 0) AS cancelled_count,
                    COALESCE(SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END), 0) AS expired_count,
                    COALESCE(SUM(CASE WHEN status = 'OPEN' THEN quantity_units ELSE 0 END), 0) AS open_units
             FROM blood_requests {$whereSql}"
        );
        $reqStmt->execute($reqParams);
        $reqAgg = $reqStmt->fetch(PDO::FETCH_ASSOC);

        $totalReqs = (int) ($reqAgg['total_requests'] ?? 0);
        $openCount = (int) ($reqAgg['open_count'] ?? 0);
        $fulfilledCount = (int) ($reqAgg['fulfilled_count'] ?? 0);
        $cancelledCount = (int) ($reqAgg['cancelled_count'] ?? 0);
        $expiredCount = (int) ($reqAgg['expired_count'] ?? 0);
        $openUnits = (int) ($reqAgg['open_units'] ?? 0);

        // Resolved formula: FULFILLED + CANCELLED + EXPIRED
        $resolvedCount = $fulfilledCount + $cancelledCount + $expiredCount;
        $fulfillmentRate = $resolvedCount > 0 ? round(($fulfilledCount / $resolvedCount) * 100, 1) : 0.0;
        $cancellationRate = $resolvedCount > 0 ? round(($cancelledCount / $resolvedCount) * 100, 1) : 0.0;
        $expirationRate = $resolvedCount > 0 ? round(($expiredCount / $resolvedCount) * 100, 1) : 0.0;

        // 3. System-wide pending queues
        $pvWhere = ['role = \'member\'', 'verification_status = \'pending\'', 'account_status = \'active\''];
        $pvParams = [];
        if ($filterChapterId !== null) {
            $pvWhere[] = 'chapter_id = ?';
            $pvParams[] = $filterChapterId;
        }
        $pvStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $pvWhere));
        $pvStmt->execute($pvParams);
        $pendingVerifications = (int) $pvStmt->fetchColumn();

        $pdrWhere = ['dr.status = \'PENDING\''];
        $pdrParams = [];
        if ($filterChapterId !== null) {
            $pdrWhere[] = 'br.request_chapter_id = ?';
            $pdrParams[] = $filterChapterId;
        }
        $pdrStmt = $pdo->prepare(
            'SELECT COUNT(dr.id)
             FROM donation_reports dr
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE ' . implode(' AND ', $pdrWhere)
        );
        $pdrStmt->execute($pdrParams);
        $pendingDonationReports = (int) $pdrStmt->fetchColumn();

        // 4. Donor pool evaluation
        $donorWhere = [
            'role = \'member\'',
            'verification_status = \'verified\'',
            'account_status = \'active\'',
            'donor_enrolled_at IS NOT NULL',
        ];
        $donorParams = [];
        if ($filterChapterId !== null) {
            $donorWhere[] = 'chapter_id = ?';
            $donorParams[] = $filterChapterId;
        }
        $donorStmt = $pdo->prepare(
            'SELECT id, blood_type, donor_availability, last_verified_donation_at
             FROM users WHERE ' . implode(' AND ', $donorWhere)
        );
        $donorStmt->execute($donorParams);
        $donors = $donorStmt->fetchAll(PDO::FETCH_ASSOC);

        $donorPool = $this->evaluateDonorPoolStatus($donors);

        // 5. Chapters comparison
        $chapStmt = $pdo->query('SELECT id, code, name, municipality FROM chapters ORDER BY id ASC');
        $chapters = $chapStmt->fetchAll(PDO::FETCH_ASSOC);

        $chaptersSummary = [];
        foreach ($chapters as $ch) {
            $chId = (int) $ch['id'];

            // Open requests
            $chReqStmt = $pdo->prepare('SELECT COUNT(id), COALESCE(SUM(quantity_units), 0) FROM blood_requests WHERE request_chapter_id = ? AND status = \'OPEN\'');
            $chReqStmt->execute([$chId]);
            [$chOpenReqs, $chOpenUnits] = $chReqStmt->fetch(PDO::FETCH_NUM);

            // Enrolled donors
            $chDonStmt = $pdo->prepare('SELECT COUNT(id) FROM users WHERE chapter_id = ? AND role = \'member\' AND verification_status = \'verified\' AND account_status = \'active\' AND donor_enrolled_at IS NOT NULL');
            $chDonStmt->execute([$chId]);
            $chDonors = (int) $chDonStmt->fetchColumn();

            $chaptersSummary[] = [
                'id' => $chId,
                'code' => (string) $ch['code'],
                'name' => (string) $ch['name'],
                'municipality' => (string) $ch['municipality'],
                'open_requests' => (int) $chOpenReqs,
                'total_units_needed' => (int) $chOpenUnits,
                'enrolled_donors' => $chDonors,
            ];
        }

        // 6. Recent system-wide audit activity
        $auditRepo = new AuditLogRepository();
        $recentAudit = $auditRepo->listAll([], 1, 5);

        return [
            'overview' => [
                'total_users' => $totalUsers,
                'total_members' => $totalMembers,
                'total_officers' => $totalOfficers,
                'total_requests_all_time' => $totalReqs,
                'active_open_requests' => $openCount,
                'open_units_needed' => $openUnits,
                'fulfilled_requests' => $fulfilledCount,
                'cancelled_requests' => $cancelledCount,
                'expired_requests' => $expiredCount,
                'resolved_requests' => $resolvedCount,
                'fulfillment_rate_percent' => $fulfillmentRate,
                'cancellation_rate_percent' => $cancellationRate,
                'expiration_rate_percent' => $expirationRate,
                'pending_verifications' => $pendingVerifications,
                'pending_donation_reports' => $pendingDonationReports,
                'enrolled_donors' => $donorPool['total_enrolled'],
                'available_donors' => $donorPool['available'],
                'standby_donors' => $donorPool['standby'],
                'cooldown_donors' => $donorPool['cooldown'],
                'unavailable_donors' => $donorPool['unavailable'],
            ],
            'donor_pool' => $donorPool,
            'chapters_summary' => $chaptersSummary,
            'recent_system_activity' => $recentAudit['logs'] ?? [],
        ];
    }

    /**
     * Analytics Summary: detailed time-series, categorical metrics, and rates.
     */
    public function getAnalyticsSummary(array $filters, ?int $scopedChapterId = null): array
    {
        $pdo = Database::pdo();

        // 1. Determine effective chapter filter
        $targetChapterId = $scopedChapterId ?? (isset($filters['chapter_id']) && is_numeric($filters['chapter_id']) ? (int) $filters['chapter_id'] : null);

        // 2. Date ranges (default: last 30 days)
        $dateTo = isset($filters['date_to']) && is_string($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($filters['date_to']))
            ? trim($filters['date_to']) . ' 23:59:59'
            : gmdate('Y-m-d 23:59:59');

        $dateFrom = isset($filters['date_from']) && is_string($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($filters['date_from']))
            ? trim($filters['date_from']) . ' 00:00:00'
            : gmdate('Y-m-d 00:00:00', strtotime('-30 days'));

        // 3. Request metrics within window
        $reqWhere = ['created_at BETWEEN ? AND ?'];
        $reqParams = [$dateFrom, $dateTo];

        if ($targetChapterId !== null) {
            $reqWhere[] = 'request_chapter_id = ?';
            $reqParams[] = $targetChapterId;
        }

        $whereSql = implode(' AND ', $reqWhere);

        $reqStmt = $pdo->prepare(
            "SELECT COUNT(id) AS total_requests,
                    COALESCE(SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END), 0) AS open_count,
                    COALESCE(SUM(CASE WHEN status = 'FULFILLED' THEN 1 ELSE 0 END), 0) AS fulfilled_count,
                    COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END), 0) AS cancelled_count,
                    COALESCE(SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END), 0) AS expired_count,
                    COALESCE(SUM(quantity_units), 0) AS total_units
             FROM blood_requests WHERE {$whereSql}"
        );
        $reqStmt->execute($reqParams);
        $reqAgg = $reqStmt->fetch(PDO::FETCH_ASSOC);

        $totalReqs = (int) ($reqAgg['total_requests'] ?? 0);
        $openCount = (int) ($reqAgg['open_count'] ?? 0);
        $fulfilledCount = (int) ($reqAgg['fulfilled_count'] ?? 0);
        $cancelledCount = (int) ($reqAgg['cancelled_count'] ?? 0);
        $expiredCount = (int) ($reqAgg['expired_count'] ?? 0);
        $totalUnits = (int) ($reqAgg['total_units'] ?? 0);

        $resolvedCount = $fulfilledCount + $cancelledCount + $expiredCount;
        $fulfillmentRate = $resolvedCount > 0 ? round(($fulfilledCount / $resolvedCount) * 100, 1) : 0.0;
        $cancellationRate = $resolvedCount > 0 ? round(($cancelledCount / $resolvedCount) * 100, 1) : 0.0;
        $expirationRate = $resolvedCount > 0 ? round(($expiredCount / $resolvedCount) * 100, 1) : 0.0;

        // 4. Request demand by blood type within window
        $btStmt = $pdo->prepare(
            "SELECT required_blood_type, COUNT(id) AS req_count, COALESCE(SUM(quantity_units), 0) AS units
             FROM blood_requests WHERE {$whereSql}
             GROUP BY required_blood_type"
        );
        $btStmt->execute($reqParams);
        $btRows = $btStmt->fetchAll(PDO::FETCH_ASSOC);

        $demandByBloodType = [];
        foreach (self::BLOOD_TYPES as $bt) {
            $demandByBloodType[$bt] = ['requests' => 0, 'units' => 0];
        }
        foreach ($btRows as $r) {
            $bt = (string) $r['required_blood_type'];
            if (isset($demandByBloodType[$bt])) {
                $demandByBloodType[$bt] = [
                    'requests' => (int) $r['req_count'],
                    'units' => (int) $r['units'],
                ];
            }
        }

        // 5. Demand by urgency within window
        $urgStmt = $pdo->prepare(
            "SELECT urgency, COUNT(id) AS req_count, COALESCE(SUM(quantity_units), 0) AS units
             FROM blood_requests WHERE {$whereSql}
             GROUP BY urgency"
        );
        $urgStmt->execute($reqParams);
        $urgRows = $urgStmt->fetchAll(PDO::FETCH_ASSOC);

        $demandByUrgency = [
            'routine' => ['requests' => 0, 'units' => 0],
            'urgent' => ['requests' => 0, 'units' => 0],
            'critical' => ['requests' => 0, 'units' => 0],
        ];
        foreach ($urgRows as $r) {
            $urg = (string) $r['urgency'];
            if (isset($demandByUrgency[$urg])) {
                $demandByUrgency[$urg] = [
                    'requests' => (int) $r['req_count'],
                    'units' => (int) $r['units'],
                ];
            }
        }

        // 6. Daily request trend
        $trendStmt = $pdo->prepare(
            "SELECT DATE(created_at) AS req_date, COUNT(id) AS req_count
             FROM blood_requests WHERE {$whereSql}
             GROUP BY DATE(created_at) ORDER BY req_date ASC"
        );
        $trendStmt->execute($reqParams);
        $dailyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

        // 7. Donor pool live eligibility breakdown in scope
        $donorWhere = [
            'role = \'member\'',
            'verification_status = \'verified\'',
            'account_status = \'active\'',
            'donor_enrolled_at IS NOT NULL',
        ];
        $donorParams = [];
        if ($targetChapterId !== null) {
            $donorWhere[] = 'chapter_id = ?';
            $donorParams[] = $targetChapterId;
        }
        $donorStmt = $pdo->prepare(
            'SELECT id, blood_type, donor_availability, last_verified_donation_at
             FROM users WHERE ' . implode(' AND ', $donorWhere)
        );
        $donorStmt->execute($donorParams);
        $donors = $donorStmt->fetchAll(PDO::FETCH_ASSOC);

        $donorPool = $this->evaluateDonorPoolStatus($donors);

        // 8. Verification metrics
        $auditWhere = ['created_at BETWEEN ? AND ?'];
        $auditParams = [$dateFrom, $dateTo];

        if ($targetChapterId !== null) {
            $auditWhere[] = '(target_type = \'user\' AND target_id REGEXP \'^[0-9]+$\' AND CAST(target_id AS UNSIGNED) IN (SELECT id FROM users WHERE chapter_id = ?))';
            $auditParams[] = $targetChapterId;
        }

        $auditStmt = $pdo->prepare(
            'SELECT action, COUNT(id) AS act_count
             FROM audit_log
             WHERE ' . implode(' AND ', $auditWhere) . ' AND action IN (\'verification.approved\', \'verification.rejected\')
             GROUP BY action'
        );
        $auditStmt->execute($auditParams);
        $verifRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        $approvedVerifs = 0;
        $rejectedVerifs = 0;
        foreach ($verifRows as $vr) {
            if ($vr['action'] === 'verification.approved') {
                $approvedVerifs = (int) $vr['act_count'];
            } elseif ($vr['action'] === 'verification.rejected') {
                $rejectedVerifs = (int) $vr['act_count'];
            }
        }

        // 9. Donation Engagement metrics
        $donWhere = ['dr.reported_at BETWEEN ? AND ?'];
        $donParams = [$dateFrom, $dateTo];
        if ($targetChapterId !== null) {
            $donWhere[] = 'br.request_chapter_id = ?';
            $donParams[] = $targetChapterId;
        }
        $donStmt = $pdo->prepare(
            'SELECT dr.status, COUNT(dr.id) AS report_count
             FROM donation_reports dr
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE ' . implode(' AND ', $donWhere) . '
             GROUP BY dr.status'
        );
        $donStmt->execute($donParams);
        $donRows = $donStmt->fetchAll(PDO::FETCH_ASSOC);

        $pendingDonations = 0;
        $confirmedDonations = 0;
        $rejectedDonations = 0;
        foreach ($donRows as $dr) {
            if ($dr['status'] === 'PENDING') {
                $pendingDonations = (int) $dr['report_count'];
            } elseif ($dr['status'] === 'CONFIRMED') {
                $confirmedDonations = (int) $dr['report_count'];
            } elseif ($dr['status'] === 'REJECTED') {
                $rejectedDonations = (int) $dr['report_count'];
            }
        }

        // Completed matches in window
        $matchWhere = ['m.status = \'COMPLETED\''];
        $matchParams = [];
        if ($targetChapterId !== null) {
            $matchWhere[] = 'br.request_chapter_id = ?';
            $matchParams[] = $targetChapterId;
        }
        $matchStmt = $pdo->prepare(
            'SELECT COUNT(m.id)
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE ' . implode(' AND ', $matchWhere)
        );
        $matchStmt->execute($matchParams);
        $completedMatches = (int) $matchStmt->fetchColumn();

        return [
            'date_from' => substr($dateFrom, 0, 10),
            'date_to' => substr($dateTo, 0, 10),
            'chapter_id' => $targetChapterId,
            'request_volume' => [
                'total_requests' => $totalReqs,
                'open_requests' => $openCount,
                'fulfilled_requests' => $fulfilledCount,
                'cancelled_requests' => $cancelledCount,
                'expired_requests' => $expiredCount,
                'resolved_requests' => $resolvedCount,
                'total_units_requested' => $totalUnits,
                'fulfillment_rate_percent' => $fulfillmentRate,
                'cancellation_rate_percent' => $cancellationRate,
                'expiration_rate_percent' => $expirationRate,
            ],
            'demand_by_blood_type' => $demandByBloodType,
            'demand_by_urgency' => $demandByUrgency,
            'daily_request_trend' => $dailyTrend,
            'donor_pool' => $donorPool,
            'verification_activity' => [
                'approved' => $approvedVerifs,
                'rejected' => $rejectedVerifs,
            ],
            'donation_engagement' => [
                'pending_reports' => $pendingDonations,
                'confirmed_donations' => $confirmedDonations,
                'rejected_reports' => $rejectedDonations,
                'completed_matches' => $completedMatches,
            ],
        ];
    }

    /**
     * Evaluates donor pool counts using authoritative DonorEligibilityService standby/cooldown rules.
     */
    private function evaluateDonorPoolStatus(array $donors): array
    {
        $available = 0;
        $standby = 0;
        $cooldown = 0;
        $unavailable = 0;
        $byBloodType = [];

        foreach (self::BLOOD_TYPES as $bt) {
            $byBloodType[$bt] = 0;
        }

        foreach ($donors as $d) {
            $bt = (string) ($d['blood_type'] ?? '');
            if (isset($byBloodType[$bt])) {
                $byBloodType[$bt]++;
            }

            $avail = (string) ($d['donor_availability'] ?? '');
            if ($avail === 'unavailable') {
                $unavailable++;
                continue;
            }

            // Available or standby -> evaluate windows against last_verified_donation_at
            $window = DonorEligibilityService::evaluateWindows(
                $d['last_verified_donation_at'] !== null ? (string) $d['last_verified_donation_at'] : null
            );

            if ($window['blocked']) {
                if ($window['which'] === 'standby') {
                    $standby++;
                } elseif ($window['which'] === 'cooldown') {
                    $cooldown++;
                } else {
                    $unavailable++;
                }
            } else {
                $available++;
            }
        }

        return [
            'total_enrolled' => count($donors),
            'available' => $available,
            'standby' => $standby,
            'cooldown' => $cooldown,
            'unavailable' => $unavailable,
            'by_blood_type' => $byBloodType,
        ];
    }
}
