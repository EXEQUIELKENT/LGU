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
    '0e8e894b3f5e3d02' => 'citizenrepform.php',
    '40c762c996b27e67' => 'citizenreports.php',
    '817b4727e07e3e16' => 'about.php',
    '81a0c38ecbe1edf4' => 'privacy.php',
    'affadba9c7d82f13' => 'termcon.php',
    'b8e5c6d5090e33c4' => 'citizen_feedback.php',
    'ba71ff4b2fa2d7fe' => 'track_report.php',
    'c78600d9ef644c06' => 'login.php',
    'de28512328815531' => 'citizencimm.php',
];

/** Where to send a token this table doesn't know. */
const CIMM_LEGACY_FALLBACK = 'citizencimm.php';

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
