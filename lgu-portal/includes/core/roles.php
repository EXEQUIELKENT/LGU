<?php
/**
 * roles.php — single source of truth for "what role is the current employee,
 * and does that role have access?"
 *
 * Every admin page previously redefined its own $isAdmin / $isEngineer /
 * $isAreaEngineer / $isOfficeStaff / $isSuperAdmin by independently
 * re-reading and re-normalizing $_SESSION['employee_role']. The underlying
 * check was consistent everywhere it was audited (strtolower(trim(...))
 * against the same literal role names) — but that consistency was
 * accidental, enforced by nothing. Any new page could typo a role string or
 * forget to normalize case and silently grant or deny access incorrectly.
 * Centralizing it here means a role's definition only has to be right once.
 *
 * The 5 valid roles are fixed by the DB schema itself:
 *   employees.role enum('Area Engineer','Engineer','Office Staff','Admin','Super Admin')
 *   — see sql/cimm_LGU.sql.
 *
 * Include this anywhere after session_start() (session_guard.php already
 * does that on every protected page).
 */

// The authoritative list of valid role values — matches the DB column
// exactly (employees.role enum(...) in sql/cimm_LGU.sql). Before this,
// user_management.php's own local UM_VALID_ROLES constant was the only
// place in the codebase with a correct whitelist; nothing else referenced
// it, and several files elsewhere check literal 'Manager'/'Head Engineer'
// strings that were never valid enum values (dead comparisons — see the
// role-check inventory this file's centralization was based on).
if (!defined('CIMM_VALID_ROLES')) {
    define('CIMM_VALID_ROLES', ['Area Engineer', 'Engineer', 'Office Staff', 'Admin', 'Super Admin']);
}

if (!function_exists('cimm_current_role')) {
    function cimm_current_role(): string {
        return strtolower(trim($_SESSION['employee_role'] ?? ''));
    }
}

if (!function_exists('cimm_is_super_admin')) {
    function cimm_is_super_admin(): bool {
        return cimm_current_role() === 'super admin';
    }
}

if (!function_exists('cimm_is_admin')) {
    // "Admin" here means Admin OR Super Admin — matches every existing
    // $isAdmin check in the codebase, which always treated the two as
    // equally privileged for the pages/actions that used it.
    function cimm_is_admin(): bool {
        return in_array(cimm_current_role(), ['admin', 'super admin'], true);
    }
}

if (!function_exists('cimm_is_engineer')) {
    function cimm_is_engineer(): bool {
        return cimm_current_role() === 'engineer';
    }
}

if (!function_exists('cimm_is_area_engineer')) {
    function cimm_is_area_engineer(): bool {
        return cimm_current_role() === 'area engineer';
    }
}

if (!function_exists('cimm_is_office_staff')) {
    function cimm_is_office_staff(): bool {
        return cimm_current_role() === 'office staff';
    }
}

/**
 * "All Districts" sentinel — an engineer_profiles.district value that means
 * "not scoped to a single district" instead of the usual literal district
 * name. Lets specific Engineer / Area Engineer accounts (e.g. senior staff
 * who cover the whole city) see and be assigned reports from every district,
 * without adding a second access-control system alongside the existing
 * single-district column.
 *
 * Set an account's engineer_profiles.district to this value (see
 * sql/grant_all_districts.sql) and every place that reads that column
 * — current_reports.php, pending_reports.php, archive_reports.php,
 * employee.php, get_engineers.php, assign_engineer.php — treats them as
 * unrestricted rather than matching a single literal district.
 */
if (!defined('CIMM_ALL_DISTRICTS_LABEL')) {
    define('CIMM_ALL_DISTRICTS_LABEL', 'All Districts');
}

if (!function_exists('cimm_district_is_all')) {
    function cimm_district_is_all(?string $district): bool {
        return strcasecmp(trim((string)$district), CIMM_ALL_DISTRICTS_LABEL) === 0;
    }
}
