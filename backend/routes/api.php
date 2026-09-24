<?php

declare(strict_types=1);

use BloodMatch\Controllers\Admin\AdminDashboardController;
use BloodMatch\Controllers\Admin\AuditLogAdminController;
use BloodMatch\Controllers\Admin\UserAdminController;
use BloodMatch\Controllers\Analytics\AnalyticsController;
use BloodMatch\Controllers\Analytics\DemandMapController;
use BloodMatch\Controllers\Auth\CsrfController;
use BloodMatch\Controllers\CompatibilityController;
use BloodMatch\Controllers\DocumentController;
use BloodMatch\Controllers\DonationReportController;
use BloodMatch\Controllers\ProfileController;
use BloodMatch\Controllers\RequestsController;
use BloodMatch\Controllers\MatchesController;
use BloodMatch\Controllers\NotificationsController;
use BloodMatch\Controllers\Auth\LoginController;
use BloodMatch\Controllers\Auth\LogoutController;
use BloodMatch\Controllers\Auth\MeController;
use BloodMatch\Controllers\Auth\PasswordResetController;
use BloodMatch\Controllers\Auth\RegisterController;
use BloodMatch\Controllers\ChaptersController;
use BloodMatch\Controllers\HealthController;
use BloodMatch\Controllers\Officer\AuditLogOfficerController;
use BloodMatch\Controllers\Officer\ChapterUsersController;
use BloodMatch\Controllers\Officer\OfficerDashboardController;
use BloodMatch\Controllers\Officer\OfficerVerificationController;
use BloodMatch\Routing\Router;

return static function (Router $router): void {
    $router->add('GET', '/api/health', [new HealthController(), 'check']);
    $router->add('GET', '/api/csrf', [new CsrfController(), 'token']);
    $router->add('GET', '/api/chapters', [new ChaptersController(), 'index']);

    $router->add('POST', '/api/register', [new RegisterController(), 'register']);
    $router->add('POST', '/api/login', [new LoginController(), 'login']);
    $router->add('POST', '/api/logout', [new LogoutController(), 'logout']);
    $router->add('GET', '/api/auth/me', [new MeController(), 'me']);

    $router->add('POST', '/api/password-reset/request', [new PasswordResetController(), 'request']);
    $router->add('POST', '/api/password-reset/confirm', [new PasswordResetController(), 'confirm']);

    $router->add('GET', '/api/admin/users', [new UserAdminController(), 'index']);
    $router->add('POST', '/api/admin/users/{id}/role', [new UserAdminController(), 'setRole']);
    $router->add('POST', '/api/admin/users/{id}/chapter', [new UserAdminController(), 'setChapter']);
    $router->add('POST', '/api/admin/users/{id}/deactivate', [new UserAdminController(), 'deactivate']);
    $router->add('POST', '/api/admin/users/{id}/reactivate', [new UserAdminController(), 'reactivate']);

    $router->add('GET', '/api/officer/users', [new ChapterUsersController(), 'index']);

    $router->add('GET', '/api/profile', [new ProfileController(), 'get']);
    $router->add('PUT', '/api/profile', [new ProfileController(), 'update']);
    $router->add('POST', '/api/profile/resubmit', [new ProfileController(), 'resubmit']);
    $router->add('POST', '/api/profile/enroll-donor', [new ProfileController(), 'enrollDonor']);

    $router->add('POST', '/api/profile/documents', [new DocumentController(), 'upload']);
    $router->add('GET', '/api/profile/documents', [new DocumentController(), 'listOwn']);
    $router->add('GET', '/api/profile/documents/{id}/file', [new DocumentController(), 'fileOwn']);

    $router->add('GET', '/api/officer/verifications', [new OfficerVerificationController(), 'queue']);
    $router->add('GET', '/api/officer/verifications/{userId}', [new OfficerVerificationController(), 'detail']);
    $router->add('POST', '/api/officer/verifications/{userId}/decision', [new OfficerVerificationController(), 'decide']);
    $router->add('GET', '/api/officer/documents/{documentId}/file', [new DocumentController(), 'fileOfficer']);

    $router->add('POST', '/api/requests', [new RequestsController(), 'create']);
    $router->add('GET', '/api/my/requests', [new RequestsController(), 'mine']);
    $router->add('GET', '/api/requests/{id}', [new RequestsController(), 'show']);
    $router->add('PUT', '/api/requests/{id}', [new RequestsController(), 'update']);
    $router->add('POST', '/api/requests/{id}/cancel', [new RequestsController(), 'cancel']);
    $router->add('GET', '/api/requests/{id}/matches', [new RequestsController(), 'matches']);
    $router->add('POST', '/api/officer/requests/{id}/re-match', [new RequestsController(), 'rematch']);
    $router->add('GET', '/api/compatibility-matrix', [new CompatibilityController(), 'show']);

    $router->add('POST', '/api/profile/donor-availability', [new ProfileController(), 'setDonorAvailability']);
    $router->add('POST', '/api/matches/{matchId}/respond', [new MatchesController(), 'respond']);
    $router->add('POST', '/api/donation-reports', [new DonationReportController(), 'submit']);
    $router->add('GET', '/api/my/donation-reports', [new DonationReportController(), 'myReports']);
    $router->add('GET', '/api/officer/donation-reports', [new DonationReportController(), 'officerQueue']);
    $router->add('POST', '/api/officer/donation-reports/{id}/confirm', [new DonationReportController(), 'confirm']);
    $router->add('POST', '/api/officer/donation-reports/{id}/reject', [new DonationReportController(), 'reject']);

    $router->add('GET', '/api/admin/audit-logs', [new AuditLogAdminController(), 'index']);
    $router->add('GET', '/api/officer/audit-logs', [new AuditLogOfficerController(), 'index']);

    $router->add('GET', '/api/officer/dashboard', [new OfficerDashboardController(), 'index']);
    $router->add('GET', '/api/admin/dashboard', [new AdminDashboardController(), 'index']);
    $router->add('GET', '/api/demand-map', [new DemandMapController(), 'index']);
    $router->add('GET', '/api/analytics/summary', [new AnalyticsController(), 'summary']);

    $router->add('GET', '/api/notifications', [new NotificationsController(), 'index']);
    $router->add('GET', '/api/notifications/unread-count', [new NotificationsController(), 'unreadCount']);
    $router->add('POST', '/api/notifications/{id}/read', [new NotificationsController(), 'markRead']);
    $router->add('POST', '/api/notifications/read-all', [new NotificationsController(), 'markAllRead']);
};
