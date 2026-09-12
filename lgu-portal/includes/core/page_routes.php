<?php
/**
 * page_routes.php — opaque ("hashed") URLs for user-facing pages.
 *
 * WHAT THIS DOES
 * Every navigable page under public/admin/ and public/citizen/ gets a second,
 * opaque URL: the real filename is replaced by a 16-hex token, in the SAME
 * directory. So instead of
 *     /lgu-portal/public/admin/current_reports.php
 * the browser shows
 *     /lgu-portal/public/admin/4f9c1a77b30e52d8
 *
 * WHY SAME-DIRECTORY (this is the important design point)
 * The token replaces only the FILENAME, never the directory. Every page in
 * this codebase links its assets relatively ("../assets/css/emp-global.css",
 * "../../includes/..."), and the browser resolves those against the current
 * URL's directory. Keeping the directory identical means every relative
 * asset, form action, AJAX path and include continues to resolve exactly as
 * before — which is what lets this be added to a live system without
 * touching a single asset path.
 *
 * HOW A REQUEST IS SERVED
 *   .htaccess (per directory)  ^([A-Fa-f0-9]{16})$  ->  _r.php?__h=$1
 *   _r.php  resolves the token back to the real file and include()s it.
 * _r.php lives in the SAME directory as the page it serves, so __DIR__,
 * relative require_once and getcwd() all behave as if the page were hit
 * directly.
 *
 * WHAT IS DELIBERATELY *NOT* HASHED
 *   public/api/*            — called by OTHER systems (RGMAP webhooks, IPMS,
 *                             Energy, CPRF). Their URLs are hardcoded on the
 *                             far side; changing them breaks the integration.
 *   admin/sso_consume.php   — registered in Main LGU's connected_systems table
 *                             (sso_consume_path). Main LGU redirects straight
 *                             to it on launch.
 *   public/functionality/*  — same-origin endpoints; left alone so in-flight
 *                             AJAX keeps working. Safe to add later.
 * cimm_url() returns anything it cannot hash UNCHANGED, so a link that points
 * at one of the above (or at a file that does not exist) still works. That
 * fail-safe is deliberate: a missed conversion degrades to today's behaviour
 * rather than to a 404.
 *
 * REAL .php URLs KEEP WORKING
 * Hashing is additive. The original .php paths still serve the page, which is
 * what keeps these working with no migration:
 *   - notifications.url rows already stored in the DB ("current_reports.php?
 *     highlight_rep=12") — these are passed through cimm_url() at render time,
 *     so new clicks get a token while old stored values stay valid.
 *   - links in emails that have already been sent
 *   - existing bookmarks
 * Set CIMM_HASHED_PAGES_ENFORCE (see cimm_enforce_hashed_page()) to also
 * redirect a direct .php page hit to its token URL.
 *
 * SCOPE NOTE (worth being straight about): this hides URL structure, it is not
 * an access control. It stops casual discovery/enumeration of page names; it
 * does not authorise anything. The role guards (session_guard.php / roles.php)
 * remain the actual security boundary and are untouched by this file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_credentials.php'; // cimm_is_localhost()

/**
 * Directories whose pages get tokenised, and the files inside them that must
 * keep their real name. Keyed by the directory name under public/.
 */
if (!function_exists('cimm_route_dirs')) {
    function cimm_route_dirs(): array {
        return [
            'admin' => [
                // Main LGU redirects straight here using the path stored in its
                // connected_systems.sso_consume_path column.
                'sso_consume.php',
                '_r.php',
            ],
            'citizen' => [
                '_r.php',
            ],
        ];
    }
}

/**
 * Secret the tokens are derived from. Changing it changes every token at once
 * (old ones stop resolving — real .php URLs still work, so that is a rotation,
 * not an outage).
 *
 * Override per-host by creating includes/config/route.local.php returning a
 * string, the same gitignored-local-override pattern db_credentials.php uses.
 */
if (!function_exists('cimm_route_secret')) {
    function cimm_route_secret(): string {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $env = getenv('CIMM_ROUTE_SECRET');
        if (is_string($env) && $env !== '') {
            return $secret = $env;
        }

        $localOverride = __DIR__ . '/../config/route.local.php';
        if (is_file($localOverride)) {
            $fromFile = require $localOverride;
            if (is_string($fromFile) && $fromFile !== '') {
                return $secret = $fromFile;
            }
        }

        // Shipped default so tokens work out of the box on a fresh checkout.
        return $secret = 'CIMM_PAGE_ROUTE_SECRET_2026';
    }
}

/**
 * How (and whether) this directory can serve token URLs right now.
 *
 * This exists because a PARTIAL DEPLOY must never take the site down. Tokenised
 * links are only useful if the pieces that resolve them actually reached the
 * server, and the two pieces involved — "_r.php" and ".htaccess" — are exactly
 * the kind of files deployment tooling silently skips (dotfiles are hidden by
 * default in most FTP clients, and underscore-prefixed files are a common
 * exclude pattern). That happened on the live domain: the pages were uploaded
 * and started emitting tokens while _r.php had not been, so every link 404'd.
 *
 * Returns one of:
 *   'pretty'  — _r.php and .htaccess both present: /citizen/<token>
 *   'query'   — _r.php present, .htaccess missing: /citizen/_r.php?__h=<token>
 *               (hides the page name just as well, and needs no mod_rewrite)
 *   'off'     — _r.php missing: fall back to the real .php URL, i.e. exactly
 *               how the site behaved before any of this existed
 */
if (!function_exists('cimm_route_mode')) {
    function cimm_route_mode(string $dir): string {
        static $cache = [];
        if (isset($cache[$dir])) {
            return $cache[$dir];
        }
        $base = cimm_public_dir() . '/' . $dir;
        if (!is_file($base . '/_r.php')) {
            return $cache[$dir] = 'off';
        }
        // The rewrite lives in this directory's .htaccess. Without it a bare
        // token has nothing routing it, so use the query form instead.
        if (!is_file($base . '/.htaccess')) {
            return $cache[$dir] = 'query';
        }
        return $cache[$dir] = 'pretty';
    }
}

/** Token for a page, derived from "<dir>/<file>" so the same filename in two directories differs. */
if (!function_exists('cimm_page_token')) {
    function cimm_page_token(string $dir, string $file): string {
        return substr(hash_hmac('sha256', $dir . '/' . $file, cimm_route_secret()), 0, 16);
    }
}

/** Absolute path of public/. */
if (!function_exists('cimm_public_dir')) {
    function cimm_public_dir(): string {
        return dirname(__DIR__, 2) . '/public';
    }
}

/** Is this a page we are allowed to tokenise? */
if (!function_exists('cimm_is_hashable_page')) {
    function cimm_is_hashable_page(string $dir, string $file): bool {
        $dirs = cimm_route_dirs();
        if (!isset($dirs[$dir])) {
            return false;
        }
        if (substr($file, -4) !== '.php' || in_array($file, $dirs[$dir], true)) {
            return false;
        }
        // Reject anything with path separators — a token only ever stands in
        // for a plain filename inside one directory.
        if ($file !== basename($file)) {
            return false;
        }
        return is_file(cimm_public_dir() . '/' . $dir . '/' . $file);
    }
}

/**
 * Token -> real filename, by hashing each candidate in the directory and
 * comparing. The directory holds a couple of dozen files, so this is cheap,
 * and it means there is no map file to keep in sync when a page is added or
 * renamed. Returns null when nothing matches.
 */
if (!function_exists('cimm_resolve_page_token')) {
    function cimm_resolve_page_token(string $dir, string $token): ?string {
        if (!preg_match('/^[A-Fa-f0-9]{16}$/', $token) || !isset(cimm_route_dirs()[$dir])) {
            return null;
        }
        $token = strtolower($token);
        foreach (glob(cimm_public_dir() . '/' . $dir . '/*.php') ?: [] as $path) {
            $file = basename($path);
            if (!cimm_is_hashable_page($dir, $file)) {
                continue;
            }
            if (hash_equals(cimm_page_token($dir, $file), $token)) {
                return $file;
            }
        }
        return null;
    }
}

/** Directory name (under public/) the current request is executing in. */
if (!function_exists('cimm_current_route_dir')) {
    function cimm_current_route_dir(): string {
        // The page being served, not _r.php — the router rewrites these before
        // including, so this is correct under both direct and tokenised hits.
        $script = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? '');
        return basename(dirname(str_replace('\\', '/', (string)$script)));
    }
}

/**
 * The one helper the pages call. Give it a link exactly as it is written today
 * and it returns the tokenised equivalent.
 *
 *   cimm_url('current_reports.php')                  -> 4f9c1a77b30e52d8
 *   cimm_url('road_monitoring.php?highlight_id=3')   -> 8b1e...?highlight_id=3
 *   cimm_url('profile.php#aeDistrictSection')        -> 22c7...#aeDistrictSection
 *   cimm_url('../citizen/citizencimm.php')           -> ../citizen/9d0a...
 *   cimm_url('api/ipms-requests.php')                -> api/ipms-requests.php  (unchanged)
 *
 * Anything it cannot safely tokenise is returned byte-for-byte unchanged.
 */
if (!function_exists('cimm_url')) {
    function cimm_url(string $target, ?string $dirOverride = null): string {
        $trimmed = trim($target);
        if ($trimmed === '') {
            return $target;
        }
        // Leave absolute URLs and non-page schemes alone.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $trimmed)) {
            return $target;
        }

        // Split off ?query and #fragment, which ride along untouched.
        $suffix = '';
        $path = $trimmed;
        $cut = strcspn($path, '?#');
        if ($cut < strlen($path)) {
            $suffix = substr($path, $cut);
            $path   = substr($path, 0, $cut);
        }
        if ($path === '') {
            return $target; // bare "?x=1" / "#anchor" — self link, nothing to hash
        }

        // Keep any leading ../ or ./ so the link's depth is preserved exactly.
        $prefix = '';
        while (preg_match('#^(\.\./|\./)#', $path, $m)) {
            $prefix .= $m[1];
            $path = substr($path, strlen($m[1]));
        }

        $file = basename($path);
        $sub  = trim(dirname($path), './');          // '' or e.g. 'admin'
        $dir  = $sub !== '' ? $sub : ($dirOverride ?? cimm_current_route_dir());

        if (!cimm_is_hashable_page($dir, $file)) {
            return $target;                          // fail-safe: leave as-is
        }

        $mode = cimm_route_mode($dir);
        if ($mode === 'off') {
            return $target;                          // router not deployed here
        }

        $token = cimm_page_token($dir, $file);
        $base  = $prefix . ($sub !== '' ? $sub . '/' : '');

        if ($mode === 'query') {
            // ?__h=<token> first, then any query the caller already had.
            $extra = '';
            if ($suffix !== '' && $suffix[0] === '?') {
                $extra = '&' . substr($suffix, 1);
            } elseif ($suffix !== '') {
                $extra = $suffix;                    // bare #fragment
            }
            if ($extra !== '' && $extra[0] === '&') {
                $hash = '';
                if (($hp = strpos($extra, '#')) !== false) {
                    $hash  = substr($extra, $hp);
                    $extra = substr($extra, 0, $hp);
                }
                return $base . '_r.php?__h=' . $token . $extra . $hash;
            }
            return $base . '_r.php?__h=' . $token . $extra;
        }

        return $base . $token . $suffix;
    }
}

/** cimm_url() + htmlspecialchars, for dropping straight into an href. */
if (!function_exists('cimm_url_attr')) {
    function cimm_url_attr(string $target, ?string $dirOverride = null): string {
        return htmlspecialchars(cimm_url($target, $dirOverride), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * OPTIONAL, OFF BY DEFAULT — send a direct .php page hit to its token URL so
 * the real filename never survives in the address bar.
 *
 * Enable by defining CIMM_HASHED_PAGES_ENFORCE = true (e.g. in
 * includes/config/route.local.php's caller, or an auto_prepend file).
 *
 * Only ever redirects idempotent GET/HEAD requests: redirecting a POST would
 * drop the request body (302 turns it into a GET), which would silently break
 * every form that posts to its own .php URL.
 */
if (!function_exists('cimm_enforce_hashed_page')) {
    function cimm_enforce_hashed_page(): void {
        if (!defined('CIMM_HASHED_PAGES_ENFORCE') || CIMM_HASHED_PAGES_ENFORCE !== true) {
            return;
        }
        if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $file   = basename($script);
        $dir    = basename(dirname($script));
        if ($file === '_r.php' || !cimm_is_hashable_page($dir, $file)) {
            return;
        }
        $qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: ' . cimm_page_token($dir, $file) . $qs, true, 302);
        exit;
    }
}
