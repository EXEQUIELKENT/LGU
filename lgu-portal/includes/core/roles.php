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
