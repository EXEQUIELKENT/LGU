<?php
/**
 * base_url.php — the URL path this installation is served from.
 *
 * WHY THIS EXISTS
 * Ten pages used to carry their own copy of this:
 *
 *     if ($_SERVER['HTTP_HOST'] === 'localhost') {
 *         $BASE_URL = '/LGU/lgu-portal/public/';
 *     } else {
 *         $BASE_URL = '/lgu-portal/public/';
 *     }
 *
 * That test is only ever true for the developer's own browser. A phone on
 * the same Wi-Fi has to reach the machine by its LAN address
 * (http://192.168.x.x/LGU/...), so HTTP_HOST is an IP, the else branch runs,
 * and every URL built from $BASE_URL loses the "/LGU/" segment — the global
 * stylesheet, translations.json, the PWA manifest, the map and tracking APIs
 * and the footer navigation all resolve to paths that do not exist, which is
 * the 404-on-mobile everyone hits. "localhost:8080", a machine hostname, a
 * staging domain and a deployment whose document root is public/ itself all
 * break the same way.
 *
 * HOW IT IS FIXED
 * The path is derived from the request instead of guessed from the host
 * name. Every page that needs $BASE_URL lives exactly one directory below
 * public/ (public/citizen/*, public/functionality/*), so the public root is
 * the grandparent of the running script — true no matter which host was used
 * to reach it or where the project is deployed.
 */

if (!function_exists('cimm_base_url')) {
    /**
     * Absolute URL path of public/, with a trailing slash.
     * e.g. "/LGU/lgu-portal/public/", "/lgu-portal/public/", or "/" when
     * public/ is itself the document root.
     */
    function cimm_base_url(): string {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return '/';                       // CLI or a very odd SAPI
        }
        // dirname twice: .../public/<dir>/<page>.php -> .../public
        // Normalise again afterwards: on Windows dirname() treats "\" as a
        // separator and returns "\" (not "/") once it reaches the root, which
        // is what a deployment with public/ as the document root hits.
        $dir = str_replace('\\', '/', dirname(dirname($script)));
        return rtrim($dir, '/') . '/';
    }
}

if (!function_exists('cimm_asset_url')) {
    /** cimm_base_url() + a path below public/, e.g. 'assets/img/logo.png'. */
    function cimm_asset_url(string $relative): string {
        return cimm_base_url() . ltrim($relative, '/');
    }
}
