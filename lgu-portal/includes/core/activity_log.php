<?php
/**
 * activity_log.php
 * ─────────────────────────────────────────────────────────────────────────
 * Shared "History / Activity" helper used by requests.php, current_reports.php,
 * pending_reports.php and archive_reports.php.
 *
 * Include AFTER db.php (needs $conn) and after session_guard.php (needs $_SESSION).
 *
 *   require_once __DIR__ . '/../../includes/core/activity_log.php';
 *
 * Public functions:
 *   ensure_activity_log_table($conn)                                   — idempotent migration
 *   log_activity($conn,$page,$refType,$refId,$action,$message,$actor)  — write one entry
 *   log_report_activity($conn,$page,$repId,$action,$message)           — shortcut for ref_type='report'
 *   log_request_activity($conn,$page,$reqId,$action,$message)          — shortcut for ref_type='request'
 *   fetch_activity_log($conn, $refFilters, $limit)                     — read entries for given ref ids
 *   activity_log_items_html($entries)                                  — render <div class="activity-log-item">…
 *   activity_actor_name()                                              — "First Name (Role)" for the current user
 *
 * Design note on scope: entries are tied to ref_type + ref_id (a request or a
 * report), not strictly to the page an action happened on. That way a report's
 * full trail (assigned → accepted → completed → archived) follows it from
 * Current → Pending → Archive, and a request's trail shows up on requests.php
 * once it has a linked report. Every page still only ever queries the ref ids
 * that are actually visible on that page, so what's shown always matches
 * "what activity is happening in that page".
 */

// ── Table migration (idempotent, safe to call every request) ──────────────
function ensure_activity_log_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) return;
    $conn->query("
        CREATE TABLE IF NOT EXISTS activity_log (
            log_id      INT AUTO_INCREMENT PRIMARY KEY,
            page        VARCHAR(40)  NOT NULL,
            ref_type    VARCHAR(20)  NOT NULL,
            ref_id      INT          NOT NULL,
            action      VARCHAR(60)  NOT NULL,
            message     VARCHAR(500) NOT NULL,
            actor_id    INT          DEFAULT NULL,
            actor_name  VARCHAR(150) DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_al_page (page, created_at),
            INDEX idx_al_ref  (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ensured = true;
}

// ── Who is performing the action right now ──────────────────────────────────
function activity_actor_name(): string {
    // Prefer the app's own helper if notif_helper.php (or similar) already defined one.
    if (function_exists('getActorName')) {
        try {
            $n = getActorName();
            if (!empty($n)) return $n;
        } catch (Throwable $e) { /* fall through */ }
    }
    $name = trim($_SESSION['employee_first_name'] ?? '');
    $role = trim($_SESSION['employee_role'] ?? '');
    if ($name === '') $name = 'Someone';
    return $role !== '' ? "{$name} ({$role})" : $name;
}

// ── Write one entry. Never throws — a logging failure must never break the
//    action that triggered it. ───────────────────────────────────────────────
function log_activity(mysqli $conn, string $page, string $refType, int $refId, string $action, string $message, ?string $actorName = null): void {
    try {
        if ($refId <= 0 || trim($message) === '') return;
        ensure_activity_log_table($conn);

        // Keep the message comfortably inside the column limit even if it was
        // built with free-text (decline reasons, admin notes, etc.) appended.
        if (function_exists('mb_strimwidth')) {
            $message = mb_strimwidth($message, 0, 480, '…');
        } elseif (strlen($message) > 480) {
            $message = substr($message, 0, 480) . '…';
        }

        $actorName = $actorName ?? activity_actor_name();
        $actorIdRaw = (int)($_SESSION['employee_id'] ?? 0);
        $actorId    = $actorIdRaw > 0 ? $actorIdRaw : null;

        $stmt = $conn->prepare(
            "INSERT INTO activity_log (page, ref_type, ref_id, action, message, actor_id, actor_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param('ssissis', $page, $refType, $refId, $action, $message, $actorId, $actorName);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[log_activity] ' . $e->getMessage());
    }
}

function log_report_activity(mysqli $conn, string $page, int $repId, string $action, string $message): void {
    log_activity($conn, $page, 'report', $repId, $action, $message);
}

function log_request_activity(mysqli $conn, string $page, int $reqId, string $action, string $message): void {
    log_activity($conn, $page, 'request', $reqId, $action, $message);
}

// ── Read entries for a set of ref ids ───────────────────────────────────────
// $refFilters = ['report' => [1,2,3], 'request' => [4,5]]  — either key optional/empty.
// $page: when provided, this is the *sole* scope — every entry logged with
// page = $page is returned, regardless of whether the underlying report/request
// is still visible on that page right now (a report that moved from Pending to
// Current still keeps its Pending-page history on the Pending page). $refFilters
// is ignored in this mode; pass an empty array. Pass $page = null to fall back
// to the old cross-page behavior, which requires $refFilters and returns a
// ref id's full trail across every page it ever touched.
function fetch_activity_log(mysqli $conn, array $refFilters, int $limit = 40, ?string $page = null): array {
    try {
        ensure_activity_log_table($conn);

        if ($page !== null && trim($page) !== '') {
            $pageSafe = $conn->real_escape_string(trim($page));
            $where = "page = '{$pageSafe}'";
        } else {
            $clauses = [];
            foreach ($refFilters as $type => $ids) {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0)));
                if (empty($ids)) continue;
                $typeSafe  = $conn->real_escape_string((string)$type);
                $clauses[] = "(ref_type = '{$typeSafe}' AND ref_id IN (" . implode(',', $ids) . "))";
            }
            if (empty($clauses)) return [];
            $where = '(' . implode(' OR ', $clauses) . ')';
        }
        $limit = max(1, min(300, $limit));
        $sql   = "SELECT log_id, page, ref_type, ref_id, action, message, actor_name, created_at
                   FROM activity_log
                   WHERE {$where}
                   ORDER BY created_at DESC, log_id DESC
                   LIMIT {$limit}";
        $res = $conn->query($sql);
        $out = [];
        if ($res) { while ($row = $res->fetch_assoc()) $out[] = $row; }
        return $out;
    } catch (Throwable $e) {
        error_log('[fetch_activity_log] ' . $e->getMessage());
        return [];
    }
}

// ── Icon + accent color per action keyword ──────────────────────────────────
function activity_icon_for(string $action): array {
    static $map = [
        'submitted_for_approval' => ['fa-paper-plane',   'info'],
        'accepted'               => ['fa-thumbs-up',     'success'],
        'declined'               => ['fa-thumbs-down',   'warning'],
        'decline_reviewed'       => ['fa-gavel',          'info'],
        'updated'                => ['fa-pen',            'info'],
        'approved_schedule'      => ['fa-calendar-check', 'success'],
        'returned'               => ['fa-rotate-left',    'warning'],
        'progress_logged'        => ['fa-clipboard-list', 'info'],
        'completion_requested'   => ['fa-flag-checkered', 'info'],
        'completed'              => ['fa-circle-check',   'success'],
        'not_completed'          => ['fa-rotate-left',    'warning'],
        'validated'              => ['fa-check',          'success'],
        'rejected'               => ['fa-xmark',          'danger'],
        'assigned'               => ['fa-user-gear',      'info'],
        'submitted'               => ['fa-inbox',          'info'],
        'viewed'                  => ['fa-eye',             'info'],
        'images_viewed'           => ['fa-images',          'info'],
        'downloaded'              => ['fa-file-word',       'info'],
    ];
    return $map[$action] ?? ['fa-clock-rotate-left', 'info'];
}

// ── Relative + absolute time formatting ─────────────────────────────────────
function activity_time_ago($datetime): string {
    if (!$datetime) return '';
    $ts = strtotime((string)$datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   { $m = (int)floor($diff / 60);   return $m . ($m === 1 ? ' minute ago' : ' minutes ago'); }
    if ($diff < 86400)  { $h = (int)floor($diff / 3600);  return $h . ($h === 1 ? ' hour ago'   : ' hours ago'); }
    if ($diff < 604800) { $d = (int)floor($diff / 86400); return $d . ($d === 1 ? ' day ago'    : ' days ago'); }
    return date('M j, Y', $ts);
}

function activity_full_datetime($datetime): string {
    if (!$datetime) return '';
    $ts = strtotime((string)$datetime);
    if ($ts === false) return '';
    return date('F j, Y \a\t h:i A', $ts);
}

// ── Render the list-item markup (or the empty state) ────────────────────────
function activity_log_items_html(array $entries): string {
    if (empty($entries)) {
        return '<div class="activity-log-empty" id="activityLogEmpty"><i class="fas fa-inbox"></i>No activity recorded yet for the items on this page.</div>';
    }
    $html = '';
    foreach ($entries as $e) {
        [$icon, $mod] = activity_icon_for($e['action'] ?? '');
        $msg  = htmlspecialchars($e['message'] ?? '');
        $when = htmlspecialchars(activity_time_ago($e['created_at'] ?? null));
        $full = htmlspecialchars(activity_full_datetime($e['created_at'] ?? null));
        $html .= '<div class="activity-log-item" data-log-id="' . (int)($e['log_id'] ?? 0) . '">'
               .   '<div class="act-log-icon act-log-icon-' . $mod . '"><i class="fas ' . $icon . '"></i></div>'
               .   '<div class="act-log-body">'
               .     '<div class="act-log-message">' . $msg . '</div>'
               .     '<div class="act-log-meta"><span class="act-log-time" title="' . $full . '">' . $when . '</span></div>'
               .   '</div>'
               . '</div>';
    }
    return $html;
}