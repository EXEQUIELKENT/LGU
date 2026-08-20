-- IPMS → CIMMS integration: track inbound maintenance feedback on the requests queue.
-- Safe to run multiple times (MySQL 8+ IF NOT EXISTS). On older MySQL, schema is also
-- ensured at runtime by public/api/ipms-requests.php.

ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS barangay VARCHAR(120) NULL DEFAULT NULL AFTER district,
    ADD COLUMN IF NOT EXISTS source VARCHAR(32) NOT NULL DEFAULT 'citizen' AFTER cprf_facility_name,
    ADD COLUMN IF NOT EXISTS source_feedback_id VARCHAR(64) NULL DEFAULT NULL AFTER source;

CREATE INDEX IF NOT EXISTS idx_requests_ipms_source ON requests (source, source_feedback_id);
