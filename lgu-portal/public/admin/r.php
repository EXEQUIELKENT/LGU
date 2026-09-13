<?php
/**
 * r.php — legacy redirect shim. NOT the old router.
 *
 * The opaque page routing was removed (see the "Remove opaque page routing"
 * commit): pages are served under their real .php names again and nothing in
 * the codebase emits a token any more. But phones that loaded a page while
 * routing was still live are still holding that HTML, and every nav link in
 * it points at "r.php?__h=<token>". With r.php deleted those taps 404, and
 * the only way out was asking each person to clear their browser data.
 *
 * So this file exists purely to heal those stale links: it looks the token up
 * in a frozen table and redirects to the real page. It generates nothing,
 * hashes nothing, and holds no secret — the table below was computed once
 * from the old algorithm and will never change.
 *
 * 308 rather than 301: a stale page's AJAX calls are POSTs
 * ("r.php?__h=<token>&ajax=update"), and 301/302 would turn those into GETs
 * and silently drop the body. 308 preserves method and body, and is
 * cacheable, so a phone stops re-requesting the dead URL entirely.
 *
 * SAFE TO DELETE once no client can still be holding pre-removal HTML.
 */

declare(strict_types=1);

/** token => real filename, in this directory. Frozen; do not extend. */
const CIMM_LEGACY_TOKENS = [
    '04b0c1de4574e5a7' => 'pending_reports.php',
    '0d394751ded9bffe' => 'case_management.php',
    '28e805012d02dbbb' => 'profile.php',
    '2bd039070dd3ab1f' => 'road_monitoring.php',
    '77e9971e45739c12' => 'requests.php',
    'a0a0e9e91dc03fab' => 'current_reports.php',
    'ae43195d727e5a51' => 'admin_create.php',
    'b3ec67614681fb4f' => 'sched.php',
    'cf40ec4c2dc91811' => 'user_management.php',
    'dc9799b90a8c7069' => 'archive_reports.php',
    'e8d51e014718b96e' => 'employee.php',
    'f62f8a7b74c59238' => 'emp_feedback.php',
];

/** Where to send a token this table doesn't know. */
const CIMM_LEGACY_FALLBACK = 'employee.php';

$token  = strtolower(trim((string)($_GET['__h'] ?? '')));
$target = CIMM_LEGACY_TOKENS[$token] ?? null;

// Carry every other parameter across untouched — stale links include things
// like "&highlight_rep=12" and "&ajax=update" that the page still reads.
$params = $_GET;
unset($params['__h']);
$qs = $params ? '?' . http_build_query($params) : '';

if ($target !== null && is_file(__DIR__ . '/' . $target)) {
    header('Location: ' . $target . $qs, true, 308);
    exit;
}

// Unknown token, or the page it named is gone. Send them somewhere real
// rather than a dead end — but temporarily, so nothing caches the guess.
header('Location: ' . CIMM_LEGACY_FALLBACK . $qs, true, 302);
exit;
