<?php
/**
 * _r.php — token router for the pages in THIS directory.
 *
 * .htaccess rewrites  /admin/4f9c1a77b30e52d8  ->  /admin/_r.php?__h=4f9c...
 * and this resolves the token back to the real page and include()s it.
 *
 * It deliberately lives in the same directory as the pages it serves: the
 * included page's __DIR__, its relative require_once calls, getcwd() and every
 * relative asset URL it emits then behave exactly as they do on a direct hit.
 * See includes/core/page_routes.php for the full design note.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/core/page_routes.php';

$__dir   = basename(__DIR__);
$__token = (string)($_GET['__h'] ?? '');
$__file  = cimm_resolve_page_token($__dir, $__token);

if ($__file === null) {
    http_response_code(404);
    exit('Not found.');
}

// Remove the routing parameter so the page sees exactly the query string the
// caller sent (a page reading $_GET must not find a stray __h), then make the
// script identity look like a direct hit. Several pages derive the current
// page for nav highlighting from basename($_SERVER['PHP_SELF']), and
// cimm_current_route_dir() reads SCRIPT_FILENAME to resolve same-directory
// links — both have to point at the real page, not at _r.php.
unset($_GET['__h'], $_REQUEST['__h']);
$__qs = $_GET ? http_build_query($_GET) : '';
$__self = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/' . $__file;

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . $__file;
$_SERVER['SCRIPT_NAME']     = $__self;
$_SERVER['PHP_SELF']        = $__self;
$_SERVER['QUERY_STRING']    = $__qs;
$_SERVER['REQUEST_URI']     = $__self . ($__qs !== '' ? '?' . $__qs : '');

require __DIR__ . '/' . $__file;
