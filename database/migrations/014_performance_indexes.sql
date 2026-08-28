-- Migration 014: Performance indexes for high-frequency dashboard, demand map, and audit queries
CREATE INDEX idx_breq_chapter_status_created ON blood_requests (request_chapter_id, status, created_at);
CREATE INDEX idx_audit_target_created ON audit_log (target_type, target_id, created_at);
