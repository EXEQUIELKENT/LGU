-- Link CIMM maintenance schedules to CPRF facilities by exact facility_id (no GPS dependency)
-- Run once on the CIMM (lgu-portal) database.

ALTER TABLE maintenance_schedule
    ADD COLUMN IF NOT EXISTS cprf_facility_id INT UNSIGNED NULL DEFAULT NULL AFTER location,
    ADD COLUMN IF NOT EXISTS cprf_facility_name VARCHAR(150) NULL DEFAULT NULL AFTER cprf_facility_id;

-- MySQL < 8.0 does not support IF NOT EXISTS on ADD COLUMN; use the PHP auto-migration in
-- api/cimm_cprf_facilities.php (cimm_ensure_cprf_facility_columns) if this fails.

CREATE INDEX IF NOT EXISTS idx_cprf_facility_id ON maintenance_schedule (cprf_facility_id);
