<?php
/**
 * assets.php — city infrastructure asset registry (Asset Inventory module).
 *
 * No such table existed anywhere in the schema before this — this is the
 * missing "list of city assets with condition tracking" half of Maintenance
 * Management, kept separate from maintenance_schedule/sched.php on purpose.
 *
 * cimm_ensure_assets_schema() follows the same
 * CREATE-TABLE-IF-NOT-EXISTS-then-SHOW-COLUMNS-then-conditional-ALTER pattern
 * as cimm_ensure_maintenance_schedule_schema() in
 * includes/api/cimm_cprf_facilities.php, so it self-migrates on first load
 * without a separate manual SQL step.
 */

if (!function_exists('cimm_ensure_assets_schema')) {
    function cimm_ensure_assets_schema(mysqli $conn): void
    {
        $create = "CREATE TABLE IF NOT EXISTS assets (
            asset_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            asset_type VARCHAR(100) NOT NULL,
            location VARCHAR(255) NOT NULL,
            district VARCHAR(50) NULL DEFAULT NULL,
            lat DECIMAL(10,7) NULL DEFAULT NULL,
            lng DECIMAL(10,7) NULL DEFAULT NULL,
            `condition` ENUM('Good','Fair','Poor','Critical') NOT NULL DEFAULT 'Good',
            install_date DATE NULL DEFAULT NULL,
            notes TEXT NULL DEFAULT NULL,
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_asset_type (asset_type),
            INDEX idx_asset_condition (`condition`),
            INDEX idx_asset_district (district)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        $conn->query($create);

        $result = $conn->query('SHOW COLUMNS FROM assets');
        $colInfo = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $colInfo[strtolower((string)$row['Field'])] = $row;
            }
            $result->free();
        }

        // Forward-compat columns, added defensively in case an older assets
        // table already existed without them.
        if (!isset($colInfo['district'])) {
            $conn->query('ALTER TABLE assets ADD COLUMN district VARCHAR(50) NULL DEFAULT NULL AFTER location');
            $conn->query('ALTER TABLE assets ADD INDEX idx_asset_district (district)');
        }
        if (!isset($colInfo['lat'])) {
            $conn->query('ALTER TABLE assets ADD COLUMN lat DECIMAL(10,7) NULL DEFAULT NULL AFTER district');
        }
        if (!isset($colInfo['lng'])) {
            $conn->query('ALTER TABLE assets ADD COLUMN lng DECIMAL(10,7) NULL DEFAULT NULL AFTER lat');
        }

        // Optional, forward-compatible link from a maintenance task back to
        // the asset it concerns. Nullable/default NULL and never referenced
        // by schedule-crud.php's named-field INSERT/UPDATE statements, so
        // adding it cannot change sched.php's existing behavior at all —
        // it's inert until a future iteration adds an asset picker there.
        $schedResult = $conn->query("SHOW TABLES LIKE 'maintenance_schedule'");
        if ($schedResult && $schedResult->num_rows > 0) {
            $schedCols = $conn->query('SHOW COLUMNS FROM maintenance_schedule');
            $hasAssetId = false;
            if ($schedCols) {
                while ($row = $schedCols->fetch_assoc()) {
                    if (strtolower((string)$row['Field']) === 'asset_id') { $hasAssetId = true; break; }
                }
                $schedCols->free();
            }
            if (!$hasAssetId) {
                $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN asset_id INT UNSIGNED NULL DEFAULT NULL');
                $conn->query('ALTER TABLE maintenance_schedule ADD INDEX idx_ms_asset_id (asset_id)');
            }
        }
    }
}

if (!function_exists('conditionBadge')) {
    function conditionBadge(?string $condition): string {
        $styles = [
            'Good'     => ['bg' => '#d1fae5', 'fg' => '#065f46', 'bd' => '#34d399', 'dot' => '#059669'],
            'Fair'     => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fbbf24', 'dot' => '#d97706'],
            'Poor'     => ['bg' => '#fde8e8', 'fg' => '#9b1c1c', 'bd' => '#f87171', 'dot' => '#dc2626'],
            'Critical' => ['bg' => '#fce7f3', 'fg' => '#831843', 'bd' => '#f472b6', 'dot' => '#db2777'],
        ];
        $condition = $condition ?? 'Good';
        $s = $styles[$condition] ?? ['bg' => '#e5e7eb', 'fg' => '#374151', 'bd' => '#9ca3af', 'dot' => '#6b7280'];
        return "<span style=\"display:inline-flex;align-items:center;gap:5px;background:{$s['bg']};color:{$s['fg']};"
             . "border:1px solid {$s['bd']};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;"
             . "font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;\">"
             . "<span style=\"width:6px;height:6px;border-radius:50%;background:{$s['dot']};display:inline-block;flex-shrink:0;\"></span>{$condition}</span>";
    }
}
