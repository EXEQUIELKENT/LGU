<?php
/**
 * db_credentials.php — single source of truth for "which DB credentials
 * should this request use?".
 *
 * Used by db.php (main app connection), session_guard.php (heartbeat
 * connection) and logout.php (logout-stamp connection) so the localhost
 * vs. domain switch only has to be defined once. Before this, each of those
 * three files hardcoded its own mysqli(...) call — they'd drifted out of
 * sync (session_guard.php had the domain credentials, logout.php still had
 * the localhost ones), so logging out silently failed to stamp last_activity
 * in production while the heartbeat silently failed to do the same on
 * localhost.
 *
 * db.local.php (gitignored, server-only) still wins over both branches below
 * if it exists, for a staging box or any host that needs its own values.
 */

if (!function_exists('cimm_is_localhost')) {
    function cimm_is_localhost(): bool {
        $host = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? '');
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}

if (!function_exists('cimm_db_credentials')) {
    function cimm_db_credentials(): array {
        if (cimm_is_localhost()) {
            // Local XAMPP defaults.
            $creds = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'cimm_lgu'];
        } else {
            // Live domain defaults.
            $creds = ['host' => 'localhost', 'user' => 'cimm_root', 'pass' => '12345678', 'name' => 'cimm_lgu'];
        }

        $localOverride = __DIR__ . '/db.local.php';
        if (is_file($localOverride)) {
            // db.local.php sets $DB_HOST/$DB_USER/$DB_PASS/$DB_NAME directly —
            // load it in an isolated scope and let it override the defaults above.
            $DB_HOST = $creds['host']; $DB_USER = $creds['user']; $DB_PASS = $creds['pass']; $DB_NAME = $creds['name'];
            require $localOverride;
            $creds = ['host' => $DB_HOST, 'user' => $DB_USER, 'pass' => $DB_PASS, 'name' => $DB_NAME];
        }

        return $creds;
    }
}
