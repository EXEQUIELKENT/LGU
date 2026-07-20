<?php
/**
 * export_report_docx.php
 * ─────────────────────────────────────────────────────────────────
 * Shared endpoint used by the "Create Report" button that appears
 * at the bottom of the request/report detail modals (Office Staff
 * only). Takes the fields currently shown in the modal (posted as
 * JSON from the page's JS) and returns a Word (.docx) file built
 * with docx_lib.php — no external library required.
 *
 * Expected POST body (application/json):
 * {
 *   "filename": "REQ-024",                 // used to name the download, sanitized below
 *   "title":    "Road Damage Report",
 *   "subtitle": "Generated Jul 8, 2026 by Kent Tarroza (Office Staff)",
 *   "color":    "2563EB",                  // optional accent color (hex, no '#'), per-page theme
 *   "sections": [
 *     { "heading": "Request Details", "rows": [ {"label":"Location","value":"..."}, ... ] },
 *     { "heading": "Evidence", "rows": [ {"label":"Evidence Images","images":["uploads/evidence/a.jpg", ...]} ] },
 *     ...
 *   ],
 *   "footerNote": "optional"
 * }
 *
 * "images" entries are relative paths (as used in the page's <img src>)
 * — they are resolved against this file's directory, verified to stay
 * inside it, loaded from disk, normalized to JPEG via GD, and embedded
 * directly into the generated .docx as real pictures.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../../includes/core/session_guard.php';
require_once __DIR__ . '/../../includes/core/docx_lib.php';

header('X-Content-Type-Options: nosniff');

function export_docx_fail(int $httpCode, string $message): void {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// ── Authorization: Office Staff only (server-side, independent of the UI) ──
$employeeRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
if ($employeeRole !== 'office staff') {
    export_docx_fail(403, 'Only Office Staff accounts can generate this document.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    export_docx_fail(405, 'This endpoint only accepts POST requests.');
}

// ── Parse & validate the posted JSON payload ────────────────────────────────
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    export_docx_fail(400, 'Invalid or missing JSON body.');
}

$title      = trim((string)($payload['title']      ?? 'Report'));
$subtitle   = trim((string)($payload['subtitle']    ?? ''));
$footerNote = trim((string)($payload['footerNote']  ?? ''));
$rawColor   = isset($payload['color']) ? (string)$payload['color'] : '';
$rawSections = $payload['sections'] ?? [];
$rawFilename = trim((string)($payload['filename']   ?? 'Report'));

if (!is_array($rawSections) || empty($rawSections)) {
    export_docx_fail(400, 'No report data was provided.');
}

// Accent color: only accept a valid 6-digit hex, otherwise fall back to the
// library default inside docx_lib.php (green).
$accentColor = null;
if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $rawColor)) {
    $accentColor = ltrim($rawColor, '#');
}

// ── Local image resolution (safe against path traversal / SSRF) ────────────
/**
 * Resolve a browser-supplied <img src> (a relative path within this app)
 * to a real, readable file on disk. Rejects absolute URLs, protocol-relative
 * URLs, and any path that escapes the app directory.
 */
function export_docx_resolve_local_path(string $relPath): ?string {
    $relPath = trim($relPath);
    if ($relPath === '') return null;

    // Reject absolute / external URLs (http:, https:, //host, data:, etc.)
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $relPath)) return null;
    if (strpos($relPath, '//') === 0) return null;

    // Strip query string / fragment, then normalize
    $relPath = strtok($relPath, '?#');
    $relPath = ltrim($relPath, '/');
    if ($relPath === '' || strpos($relPath, '..') !== false) return null;

    $baseDir = realpath(__DIR__ . '/..');
    $full    = realpath(__DIR__ . '/../' . $relPath);
    if ($baseDir === false || $full === false) return null;
    if (strpos($full, $baseDir . DIRECTORY_SEPARATOR) !== 0) return null;
    if (!is_file($full)) return null;

    return $full;
}

/**
 * Load an image from disk and normalize it to a JPEG buffer suitable for
 * embedding in the .docx (flattens transparency, caps dimensions).
 * Returns ['data' => jpegBinary, 'width' => int, 'height' => int] or null.
 */
function export_docx_load_image(string $absPath, int $maxDim = 1000, int $quality = 78): ?array {
    if (!function_exists('imagecreatefromstring')) {
        // GD not available — fall back to embedding small raw JPEGs directly.
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg'], true)) return null;
        $data = @file_get_contents($absPath);
        if ($data === false) return null;
        $size = @getimagesizefromstring($data);
        if (!$size) return null;
        return ['data' => $data, 'width' => $size[0], 'height' => $size[1]];
    }

    $raw = @file_get_contents($absPath);
    if ($raw === false) return null;

    $im = @imagecreatefromstring($raw);
    if ($im === false) return null;

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0) { imagedestroy($im); return null; }

    if ($w > $maxDim || $h > $maxDim) {
        $scale = min($maxDim / $w, $maxDim / $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $resized;
        $w = $nw;
        $h = $nh;
    } else {
        // Flatten any transparency (e.g. PNG alpha) onto a white background.
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $flat;
    }

    ob_start();
    imagejpeg($im, null, $quality);
    $data = ob_get_clean();
    imagedestroy($im);

    if ($data === false || $data === '') return null;
    return ['data' => $data, 'width' => $w, 'height' => $h];
}

// Cap the total number of images embedded per document to keep generation
// time and file size reasonable.
$imagesEmbeddedCount = 0;
const EXPORT_DOCX_MAX_IMAGES = 24;

/**
 * Given a list of <img src> strings, resolve + load + normalize each one,
 * respecting the global embed cap. Returns a list of normalized image
 * buffers (possibly empty if none were readable).
 */
function export_docx_build_image_list(array $srcList): array {
    global $imagesEmbeddedCount;
    $out = [];
    foreach ($srcList as $src) {
        if ($imagesEmbeddedCount >= EXPORT_DOCX_MAX_IMAGES) break;
        if (!is_string($src) || trim($src) === '') continue;
        $absPath = export_docx_resolve_local_path($src);
        if ($absPath === null) continue;
        $img = export_docx_load_image($absPath);
        if ($img === null) continue;
        $out[] = $img;
        $imagesEmbeddedCount++;
    }
    return $out;
}

// Rebuild sections defensively — only pull the keys we expect, as strings —
// and resolve any 'images' arrays into real embedded image buffers.
$sections = [];
foreach ($rawSections as $section) {
    if (!is_array($section)) continue;
    $heading = isset($section['heading']) && $section['heading'] !== '' ? (string)$section['heading'] : null;
    $rows    = [];
    foreach (($section['rows'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $label = (string)($row['label'] ?? '');
        if ($label === '') continue;

        if (isset($row['images']) && is_array($row['images']) && !empty($row['images'])) {
            $images = export_docx_build_image_list($row['images']);
            if (!empty($images)) {
                $rows[] = ['label' => $label, 'images' => $images];
                continue;
            }
            // No images could be resolved/loaded — fall back to a text note.
            $rows[] = ['label' => $label, 'value' => 'No evidence images'];
            continue;
        }

        $value = (string)($row['value'] ?? '');
        $rows[] = ['label' => $label, 'value' => $value];
    }
    if (!empty($rows)) {
        $sections[] = ['heading' => $heading, 'rows' => $rows];
    }
}

if (empty($sections)) {
    export_docx_fail(400, 'No report fields to include.');
}

// Sanitize the filename: letters, numbers, dash, underscore, space only
$safeFilename = preg_replace('/[^A-Za-z0-9 _-]/', '', $rawFilename);
$safeFilename = trim($safeFilename) !== '' ? trim($safeFilename) : 'Report';
$safeFilename = str_replace(' ', '_', $safeFilename);

try {
    $docxBinary = $accentColor !== null
        ? generate_simple_docx($title, $subtitle, $sections, $footerNote, $accentColor)
        : generate_simple_docx($title, $subtitle, $sections, $footerNote);
} catch (Throwable $e) {
    export_docx_fail(500, 'Could not generate the document: ' . $e->getMessage());
}

// ── Notify all Admins & Super Admins that a Word document was exported ─────
// Wrapped defensively so any notification error never blocks the download.
try {
    require_once __DIR__ . '/../../includes/config/db.php';
    require_once __DIR__ . '/../../includes/core/notif_helper.php';

    $actorName = function_exists('getActorName')
        ? getActorName()
        : (trim(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')) ?: 'Office Staff');

    // Figure out what this document represents (a request or a report) from
    // its title, so admins can click through to the right record — for
    // reports, notifications.php's relocation logic will auto-correct the
    // link to whichever report page currently holds that status.
    $notifUrl = null;
    if (preg_match('/#?REP-?(\d+)/i', $title, $rm)) {
        $notifUrl = 'pending_reports.php?highlight_rep=' . $rm[1];
    } elseif (preg_match('/#?REQ-?(\d+)/i', $title, $rm)) {
        $notifUrl = 'requests.php?highlight=' . $rm[1];
    }

    // Title is built client-side as "{ID} — {Infrastructure}" — reuse the
    // infrastructure part (if present) as the notification's request_type
    // so it groups sensibly alongside other notifications.
    $notifReqType = '';
    if (strpos($title, ' — ') !== false) {
        [, $afterDash] = explode(' — ', $title, 2);
        $notifReqType = trim($afterDash);
    }

    notifyAdminsOnly(
        $conn,
        'Document Exported',
        "{$actorName} exported \"{$title}\" as a Word document.",
        $notifUrl,
        $notifReqType,
        (int)($_SESSION['employee_id'] ?? 0)
    );
} catch (Throwable $notifErr) {
    error_log('[export_report_docx] Notification error: ' . $notifErr->getMessage());
}

// ── Stream the file back ─────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $safeFilename . '.docx"');
header('Content-Length: ' . strlen($docxBinary));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $docxBinary;
exit;