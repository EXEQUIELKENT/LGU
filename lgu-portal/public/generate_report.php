<?php
// Buffer output so stray PHP warnings never corrupt the binary XLSX stream
ob_start();
session_start();
date_default_timezone_set('Asia/Manila');

// ── Security: Admin only ──────────────────────────────────────────────────────
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    http_response_code(403); die('Unauthorized');
}
$role = strtolower(trim($_SESSION['employee_role'] ?? ''));
if (!in_array($role, ['admin', 'super admin', 'office staff'])) {
    http_response_code(403); die('Admin access required');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('Method not allowed');
}

// ══════════════════════════════════════════════════════════════════════════════
//  🔐 ONE-TIME PASSWORD TOKEN VALIDATION
//  The token is issued by verify_password.php after confirming the admin's
//  password. It is single-use and expires after 60 seconds.
// ══════════════════════════════════════════════════════════════════════════════
$TOKEN_TTL = 60; // seconds

$submittedToken = $_POST['report_token'] ?? '';
$sessionToken   = $_SESSION['report_token'] ?? '';
$tokenTime      = (int)($_SESSION['report_token_time'] ?? 0);

$tokenValid =
    !empty($submittedToken) &&
    !empty($sessionToken) &&
    hash_equals($sessionToken, $submittedToken) &&   // timing-safe comparison
    (time() - $tokenTime) <= $TOKEN_TTL;             // not expired

// Consume immediately — token is single-use regardless of outcome
unset($_SESSION['report_token'], $_SESSION['report_token_time']);

if (!$tokenValid) {
    ob_end_clean();
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Access Denied — CIMM</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;
       display:flex;align-items:center;justify-content:center;min-height:100vh}
  .box{background:#fff;border-radius:16px;padding:48px 52px;max-width:440px;width:92%;
       box-shadow:0 8px 32px rgba(0,0,0,.12);text-align:center;border-top:5px solid #ef4444}
  .icon{font-size:52px;margin-bottom:16px}
  h2{color:#dc2626;font-size:22px;margin-bottom:10px}
  p{color:#64748b;font-size:14px;line-height:1.6;margin-bottom:22px}
  a{display:inline-block;background:#1e3a5f;color:#fff;text-decoration:none;
    padding:11px 26px;border-radius:9px;font-size:14px;font-weight:600;
    transition:.2s}
  a:hover{background:#2d5fa3}
</style></head><body>
<div class="box">
  <div class="icon">⛔</div>
  <h2>Access Denied</h2>
  <p>Your security token is invalid or has expired.<br>
     Please go back and verify your password again before generating a report.</p>
  <a href="employee.php">← Back to Dashboard</a>
</div></body></html>';
    exit;
}
// ── End token validation ──────────────────────────────────────────────────────

require __DIR__ . '/db.php';
require __DIR__ . '/notif_helper.php';

// ── Input validation ──────────────────────────────────────────────────────────
$format     = in_array($_POST['format'] ?? '', ['excel','pdf']) ? $_POST['format'] : 'excel';
$reportType = in_array($_POST['report_type'] ?? '', ['requests','schedules','summary','current_reports','pending_reports','archive_reports'])
              ? $_POST['report_type'] : 'requests';
$dateFrom   = date('Y-m-d', strtotime($_POST['date_from'] ?? date('Y-m-01')));
$dateTo     = date('Y-m-d', strtotime($_POST['date_to']   ?? date('Y-m-d')));
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$reportTitles = [
    'requests'        => 'Infrastructure Repair Requests Report',
    'schedules'       => 'Maintenance Schedule Report',
    'summary'         => 'Executive Summary Report',
    'current_reports' => 'Current Reports',
    'pending_reports' => 'Pending Reports',
    'archive_reports' => 'Archive Reports',
];
$reportTitle = $reportTitles[$reportType];
$generatedBy = trim(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')) ?: 'Admin';
$generatedAt = date('d/m/Y H:i');
$periodStr   = date('F d, Y', strtotime($dateFrom)) . ' – ' . date('F d, Y', strtotime($dateTo));

// ── Notify Admins / Super Admins that a report was exported ────────────────────
// Fired right before the file is streamed to the browser (i.e. only once the
// export has actually been built), from whichever branch (XLSX or PDF) runs.
function notifyReportExported(mysqli $conn, string $reportType, string $format, string $reportTitle, string $periodStr, string $generatedBy, int $actorId): void {
    $formatLabel = ($format === 'pdf') ? 'PDF' : 'Excel';
    $icon        = ($format === 'pdf') ? '📄' : '📊';

    $title = "{$icon} Report Exported: {$reportTitle}";
    $description = "{$generatedBy} downloaded the \"{$reportTitle}\" report as {$formatLabel} for {$periodStr}.";

    notifyAdminsOnly(
        $conn,
        $title,
        $description,
        'employee.php',
        'Report Export',
        $actorId
    );
}

// ── Data fetchers ─────────────────────────────────────────────────────────────
function fetchRequests($conn, $from, $to) {
    $stmt = $conn->prepare("
        SELECT
            CONCAT('REQ-', LPAD(req_id, 4, '0'))          AS req_id_fmt,
            infrastructure, location, issue,
            approval_status,
            DATE_FORMAT(created_at,'%d-%b-%Y %H:%i')  AS created_fmt
        FROM requests
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetchSchedules($conn, $from, $to) {
    $rows = [];

    // ── Source 1: maintenance_schedule table ─────────────────────────────────
    $stmt = $conn->prepare("
        SELECT
            ms.task,
            ms.location,
            ms.starting_date,
            DATE_FORMAT(ms.starting_date,'%d-%b-%Y')             AS start_fmt,
            DATE_FORMAT(ms.estimated_completion_date,'%d-%b-%Y') AS end_fmt,
            ms.status,
            ms.priority,
            IFNULL(ms.category,'General Maintenance')              AS category,
            IFNULL(ms.assigned_team,'Unassigned')                  AS assigned_team,
            TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,''))) AS engineer_name,
            FORMAT(ms.budget,2)                                    AS budget_fmt,
            'Maintenance Task'                                     AS source_type
        FROM maintenance_schedule ms
        LEFT JOIN employees e ON ms.engineer_id = e.user_id
        WHERE DATE(ms.starting_date) BETWEEN ? AND ?
        ORDER BY FIELD(ms.priority,'Critical','High','Medium','Low'), ms.starting_date ASC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $today = new DateTime('today');
        $statusLabel = $r['status'];
        try {
            $dueDate = new DateTime($r['starting_date']);
            $diffDays = (int)$today->diff($dueDate)->format('%r%a');
            if ($r['status'] !== 'Completed' && $r['status'] !== 'In Progress') {
                if ($diffDays < 0) { $statusLabel = 'Delayed'; $r['priority'] = 'Critical'; }
                elseif ($diffDays === 0) { $statusLabel = 'In Progress'; $r['priority'] = 'High'; }
            }
        } catch (Exception $e) {}
        $r['status'] = $statusLabel;
        $rows[] = $r;
    }
    $stmt->close();

    // ── Source 2: reports table (infrastructure reports shown on the calendar) ─
    $stmt2 = $conn->prepare("
        SELECT
            IFNULL(req.infrastructure,'Infrastructure Report')    AS task,
            IFNULL(req.location,'—')                              AS location,
            r.starting_date,
            r.estimated_end_date                                  AS raw_end_date,
            DATE_FORMAT(r.starting_date,'%d-%b-%Y')             AS start_fmt,
            DATE_FORMAT(r.estimated_end_date,'%d-%b-%Y')        AS end_fmt,
            res.status                                            AS status,
            IFNULL(r.priority_lvl,'Low')                         AS priority,
            'Infrastructure Report'                               AS category,
            '—'                                                   AS assigned_team,
            TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,''))) AS engineer_name,
            FORMAT(r.budget,2)                                    AS budget_fmt,
            'Infrastructure Report'                               AS source_type
        FROM reports r
        LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
        LEFT JOIN requests             req ON res.req_id = req.req_id
        LEFT JOIN employees            e   ON r.engineer_id = e.user_id
        WHERE res.status IN ('Scheduled','Pending','In Progress','Completed','Pending Completion','')
          AND r.starting_date IS NOT NULL
          AND DATE(r.starting_date) BETWEEN ? AND ?
        ORDER BY r.starting_date ASC
    ");
    $stmt2->bind_param('ss', $from, $to);
    $stmt2->execute();
    $todayGen = new DateTime('today', new DateTimeZone('Asia/Manila'));
    foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rawSt   = $r['status'];
        $rawEnd  = $r['raw_end_date'] ?? '';
        if ($rawSt === 'Completed') {
            $r['status'] = 'Completed';
        } elseif (in_array($rawSt, ['In Progress', 'Pending Completion'])) {
            $r['status'] = 'In Progress';
        } else {
            $r['status'] = 'Scheduled';
            if (!empty($rawEnd)) {
                try {
                    $endDtGen = new DateTime($rawEnd, new DateTimeZone('Asia/Manila'));
                    if ($todayGen > $endDtGen) $r['status'] = 'Delayed';
                } catch (Exception $e) {}
            }
        }
        $rows[] = $r;
    }
    $stmt2->close();

    usort($rows, fn($a, $b) => strcmp($a['starting_date'] ?? '', $b['starting_date'] ?? ''));
    return $rows;
}

function fetchCurrentReports($conn, $from, $to) {
    // Reports currently assigned (Approved status = awaiting/accepted by engineer)
    $stmt = $conn->prepare("
        SELECT
            CONCAT('RPT-', LPAD(r.rep_id, 4, '0'))                       AS rep_id_fmt,
            IFNULL(req.infrastructure,'—')                                AS infrastructure,
            IFNULL(req.location,'—')                                      AS location,
            IFNULL(req.issue,'—')                                         AS issue,
            IFNULL(r.priority_lvl,'—')                                    AS priority_lvl,
            FORMAT(r.budget,2)                                            AS budget_fmt,
            DATE_FORMAT(r.starting_date,'%d-%b-%Y')                      AS start_fmt,
            DATE_FORMAT(r.estimated_end_date,'%d-%b-%Y')                 AS end_fmt,
            res.status                                                    AS resolution_status,
            TRIM(CONCAT(IFNULL(e1.first_name,''),' ',IFNULL(e1.last_name,''))) AS engineer_name,
            IF(r.engineer_accepted=1,'Accepted','Pending Acceptance')     AS acceptance,
            DATE_FORMAT(r.created_at,'%d-%b-%Y %H:%i')                   AS created_fmt
        FROM reports r
        LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
        LEFT JOIN requests             req ON res.req_id = req.req_id
        LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
        WHERE res.status IN ('Approved','Pending Admin Approval')
          AND DATE(r.created_at) BETWEEN ? AND ?
        ORDER BY r.rep_id DESC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetchPendingReports($conn, $from, $to) {
    // Reports in active workflow (Scheduled, In Progress, Pending Completion)
    $stmt = $conn->prepare("
        SELECT
            CONCAT('RPT-', LPAD(r.rep_id, 4, '0'))                       AS rep_id_fmt,
            IFNULL(req.infrastructure,'—')                                AS infrastructure,
            IFNULL(req.location,'—')                                      AS location,
            IFNULL(req.issue,'—')                                         AS issue,
            IFNULL(r.priority_lvl,'—')                                    AS priority_lvl,
            FORMAT(r.budget,2)                                            AS budget_fmt,
            r.estimated_end_date                                          AS raw_end_date,
            DATE_FORMAT(r.starting_date,'%d-%b-%Y')                     AS start_fmt,
            DATE_FORMAT(r.estimated_end_date,'%d-%b-%Y')                AS end_fmt,
            res.status                                                    AS resolution_status,
            TRIM(CONCAT(IFNULL(e1.first_name,''),' ',IFNULL(e1.last_name,''))) AS engineer_name,
            DATE_FORMAT(r.created_at,'%d-%b-%Y %H:%i')               AS created_fmt
        FROM reports r
        LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
        LEFT JOIN requests             req ON res.req_id = req.req_id
        LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
        WHERE res.status IN ('Scheduled','Pending','In Progress','Pending Completion','')
          AND DATE(r.starting_date) BETWEEN ? AND ?
        ORDER BY r.starting_date ASC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // Compute Delayed override where today is past estimated_end_date
    $todayPend = new DateTime('today', new DateTimeZone('Asia/Manila'));
    foreach ($rows as &$r) {
        $st     = $r['resolution_status'] ?? '';
        $rawEnd = $r['raw_end_date'] ?? '';
        if (!in_array($st, ['In Progress','Pending Completion','Completed','Cancelled']) && !empty($rawEnd)) {
            try {
                $endDtP = new DateTime($rawEnd, new DateTimeZone('Asia/Manila'));
                if ($todayPend > $endDtP) $r['resolution_status'] = 'Delayed';
            } catch (Exception $e) {}
        }
    }
    unset($r);
    return $rows;
}

function fetchArchiveReports($conn, $from, $to) {
    // Completed or Cancelled reports
    $stmt = $conn->prepare("
        SELECT
            CONCAT('RPT-', LPAD(r.rep_id, 4, '0'))                       AS rep_id_fmt,
            IFNULL(req.infrastructure,'—')                                AS infrastructure,
            IFNULL(req.location,'—')                                      AS location,
            IFNULL(req.issue,'—')                                         AS issue,
            IFNULL(r.priority_lvl,'—')                                    AS priority_lvl,
            FORMAT(r.budget,2)                                            AS budget_fmt,
            DATE_FORMAT(r.starting_date,'%d-%b-%Y')                     AS start_fmt,
            DATE_FORMAT(r.estimated_end_date,'%d-%b-%Y')                AS end_fmt,
            res.status                                                    AS resolution_status,
            TRIM(CONCAT(IFNULL(e1.first_name,''),' ',IFNULL(e1.last_name,''))) AS engineer_name,
            DATE_FORMAT(r.created_at,'%d-%b-%Y %H:%i')               AS created_fmt
        FROM reports r
        LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
        LEFT JOIN requests             req ON res.req_id = req.req_id
        LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
        WHERE res.status IN ('Completed','Cancelled')
          AND DATE(r.created_at) BETWEEN ? AND ?
        ORDER BY r.rep_id DESC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


function fetchSummary($conn, $from, $to) {
    $n = function($sql) use ($conn, $from, $to) {
        $s = $conn->prepare($sql); $s->bind_param('ss', $from, $to);
        $s->execute(); return (int)$s->get_result()->fetch_row()[0];
    };
    $all = function($sql) use ($conn, $from, $to) {
        $s = $conn->prepare($sql); $s->bind_param('ss', $from, $to);
        $s->execute(); return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    };
    return [
        'total_requests'   => $n("SELECT COUNT(*) FROM requests WHERE DATE(created_at) BETWEEN ? AND ?"),
        'pending'          => $n("SELECT COUNT(*) FROM requests WHERE approval_status='Pending'  AND DATE(created_at) BETWEEN ? AND ?"),
        'approved'         => $n("SELECT COUNT(*) FROM requests WHERE approval_status='Approved' AND DATE(created_at) BETWEEN ? AND ?"),
        'rejected'         => $n("SELECT COUNT(*) FROM requests WHERE approval_status='Rejected' AND DATE(created_at) BETWEEN ? AND ?"),
        'total_schedules'  => $n("SELECT COUNT(*) FROM maintenance_schedule WHERE DATE(starting_date) BETWEEN ? AND ?"),
        'completed_tasks'  => $n("SELECT COUNT(*) FROM maintenance_schedule WHERE status='Completed'  AND DATE(starting_date) BETWEEN ? AND ?"),
        'in_progress'      => $n("SELECT COUNT(*) FROM maintenance_schedule WHERE status='In Progress' AND DATE(starting_date) BETWEEN ? AND ?"),
        'delayed'          => $n("SELECT COUNT(*) FROM maintenance_schedule WHERE status='Delayed'    AND DATE(starting_date) BETWEEN ? AND ?"),
        'top_infra'        => $all("SELECT infrastructure AS lbl, COUNT(*) AS cnt FROM requests WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY infrastructure ORDER BY cnt DESC LIMIT 5"),
        'top_locations'    => $all("SELECT location AS lbl, COUNT(*) AS cnt FROM requests WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY location ORDER BY cnt DESC LIMIT 5"),
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  EXCEL (pure PHP / ZipArchive)
// ══════════════════════════════════════════════════════════════════════════════
function numToCol(int $n): string {
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}

function xmlSafe(string $v): string {
    return preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/u', '', $v);
}

function buildStylesXml(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="11">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="13"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="22"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>
    <font><sz val="11"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/><b/></font>
    <font><sz val="28"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>
    <font><sz val="11"/><b/><name val="Arial"/></font>
    <font><sz val="11"/><color rgb="FF374151"/><name val="Arial"/></font>
  </fonts>
  <fills count="18">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F4FA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF9EE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3E2"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFDECEA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF22C55E"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEF4444"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF6366F1"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF97316"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFAFBFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE4ECF7"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2D5FA3"/></patternFill></fill>
  </fills>
  <borders count="5">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD1DAE8"/></left>
      <right style="thin"><color rgb="FFD1DAE8"/></right>
      <top style="thin"><color rgb="FFD1DAE8"/></top>
      <bottom style="thin"><color rgb="FFD1DAE8"/></bottom>
      <diagonal/>
    </border>
    <border>
      <left style="medium"><color rgb="FF1E3A5F"/></left>
      <right style="medium"><color rgb="FF1E3A5F"/></right>
      <top style="medium"><color rgb="FF1E3A5F"/></top>
      <bottom style="medium"><color rgb="FF1E3A5F"/></bottom>
      <diagonal/>
    </border>
    <border><bottom style="medium"><color rgb="FF1E3A5F"/></bottom><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFB0C4DE"/></left>
      <right style="thin"><color rgb="FFB0C4DE"/></right>
      <top style="thin"><color rgb="FFB0C4DE"/></top>
      <bottom style="medium"><color rgb="FF2D5FA3"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="28">
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="0" borderId="3" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="8" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="9" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="11" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="13" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="12" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="7" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="8" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="15" borderId="0" xfId="0" applyFill="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="17" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>
  </cellXfs>
</styleSheet>
XML;
}

function statusStyleXls(string $s): int {
    return match(strtolower($s)) {
        'approved','completed'                                              => 6,
        'pending','scheduled','pending acceptance','accepted'              => 7,
        'rejected','delayed','cancelled'                                   => 8,
        'in progress','pending completion','pending admin approval',
        'pending approval'                                                 => 9,
        default                                                            => 7,
    };
}
function priorityStyleXls(string $p): int {
    return match(strtolower($p)) {
        'critical' => 10,
        'high'     => 11,
        'medium'   => 7,
        'low'      => 12,
        default    => 12,
    };
}

function makeSharedStrings(array &$pool, string $v): int {
    if (!array_key_exists($v, $pool)) $pool[$v] = count($pool);
    return $pool[$v];
}

function sc(string $ref, int $si, int $style): string {
    return "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$si}</v></c>";
}
function nc(string $ref, $val, int $style): string {
    return "<c r=\"{$ref}\" s=\"{$style}\"><v>{$val}</v></c>";
}

function buildSheetXml(array $def, array &$pool): string {
    $headers   = $def['headers'];
    $rows      = $def['rows'];
    $colCount  = count($headers);
    $lastCol   = numToCol($colCount);
    $rowXmls   = [];
    $mergeRefs = [];
    $rn = 1;

    $ti = makeSharedStrings($pool, $def['title']);
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"42\" customHeight=\"1\">" . sc("A{$rn}", $ti, 3) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $org = makeSharedStrings($pool, "LGU \u{2013} CIMM  |  Community Infrastructure Monitoring & Management");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">" . sc("A{$rn}", $org, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $meta2 = makeSharedStrings($pool, "Period: {$def['meta_period']}   |   Generated by: {$def['meta_by']}   |   {$def['meta_date']}");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"16\" customHeight=\"1\">" . sc("A{$rn}", $meta2, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"5\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"17\"/></row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $rowXmls[] = "<row r=\"{$rn}\" ht=\"6\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"18\"/></row>"; $rn++;

    $headerRow = $rn;
    $hCells = '';
    foreach ($headers as $ci => $h) {
        $col  = numToCol($ci + 1);
        $hi   = makeSharedStrings($pool, strtoupper($h));
        $hCells .= sc("{$col}{$rn}", $hi, ($def['centerCols'][$ci] ?? false) ? 1 : 20);
    }
    $rowXmls[] = "<row r=\"{$rn}\" ht=\"22\" customHeight=\"1\">{$hCells}</row>"; $rn++;

    $dataCount = count($rows);
    foreach ($rows as $ri => $rowData) {
        $isEven  = $ri % 2 === 1;
        $baseS   = $isEven ? 2 : 0;
        $ctrBase = $isEven ? 22 : 21;
        $cells   = '';
        foreach (array_values($rowData) as $ci => $val) {
            $col    = numToCol($ci + 1);
            $ref    = "{$col}{$rn}";
            $center = $def['centerCols'][$ci] ?? false;
            $badge  = $def['badgeCols'][$ci] ?? null;
            if ($badge === 'status') {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), statusStyleXls((string)$val));
            } elseif ($badge === 'priority') {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), priorityStyleXls((string)$val));
            } elseif (is_numeric($val) && ($def['numericCols'][$ci] ?? false)) {
                $cells .= nc($ref, $val, $center ? $ctrBase : $baseS);
            } else {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), $center ? $ctrBase : $baseS);
            }
        }
        $isLast = ($ri === $dataCount - 1) && ($def['totalRow'] ?? false);
        if ($isLast) {
            $cells = '';
            foreach (array_values($rowData) as $ci => $val) {
                $col = numToCol($ci + 1); $ref = "{$col}{$rn}";
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), $ci === 0 ? 23 : 24);
            }
        }
        $rowXmls[] = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">{$cells}</row>"; $rn++;
    }

    if ($dataCount === 0) {
        $ei = makeSharedStrings($pool, 'No records found for the selected date range.');
        $rowXmls[] = "<row r=\"{$rn}\" ht=\"24\" customHeight=\"1\">" . sc("A{$rn}", $ei, 4) . "</row>"; $rn++;
    }

    $colWidths = '';
    foreach ($headers as $ci => $h) {
        $col = $ci + 1;
        $w   = $def['colWidths'][$ci] ?? min(max(mb_strlen($h) * 1.5 + 6, 12), 60);
        $colWidths .= "<col min=\"{$col}\" max=\"{$col}\" width=\"{$w}\" customWidth=\"1\"/>";
    }

    $freezeRow = $headerRow + 1;
    $freezeXml = "<sheetViews><sheetView workbookViewId=\"0\" tabSelected=\"1\">"
               . "<pane ySplit=\"{$headerRow}\" topLeftCell=\"A{$freezeRow}\" activePane=\"bottomLeft\" state=\"frozen\"/>"
               . "<selection pane=\"bottomLeft\" activeCell=\"A{$freezeRow}\"/>"
               . "</sheetView></sheetViews>";

    $mergeCellXml = implode('', array_map(fn($r) => "<mergeCell ref=\"{$r}\"/>", $mergeRefs));
    $merges = "<mergeCells count=\"" . count($mergeRefs) . "\">{$mergeCellXml}</mergeCells>";

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . $freezeXml
         . '<sheetFormatPr defaultRowHeight="18"/>'
         . "<cols>{$colWidths}</cols>"
         . '<sheetData>' . implode('', $rowXmls) . '</sheetData>'
         . $merges
         . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
         . '<pageSetup orientation="landscape" fitToPage="1" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
         . '<headerFooter>'
         . '<oddHeader>&amp;L&amp;B&amp;14 CIMM Portal&amp;R&amp;8Generated: ' . date('M d, Y h:i A') . '</oddHeader>'
         . '<oddFooter>&amp;LConfidential — Internal Use Only&amp;CPage &amp;P of &amp;N&amp;R' . htmlspecialchars($def['title'], ENT_XML1) . '</oddFooter>'
         . '</headerFooter>'
         . '</worksheet>';
}

function buildXLSX(array $sheetDefs, string $reportTitle): string {
    $pool = []; $sheetXmls = []; $sheetList = ''; $sheetRels = ''; $overrides = '';
    foreach ($sheetDefs as $si => $def) {
        $num  = $si + 1; $rId = "rId{$num}"; $name = htmlspecialchars($def['name'], ENT_XML1);
        $sheetList .= "<sheet name=\"{$name}\" sheetId=\"{$num}\" r:id=\"{$rId}\"/>";
        $sheetRels .= "<Relationship Id=\"{$rId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$num}.xml\"/>";
        $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$num}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $sheetXmls[$num] = buildSheetXml($def, $pool);
    }
    $total = count($sheetDefs); $rIdSS = 'rId' . ($total + 1); $rIdST = 'rId' . ($total + 2);
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
           . ' count="' . count($pool) . '" uniqueCount="' . count($pool) . '">';
    foreach (array_keys($pool) as $v) {
        $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars(xmlSafe($v), ENT_XML1, 'UTF-8') . '</t></si>';
    }
    $ssXml .= '</sst>';
    $tmp = tempnam(sys_get_temp_dir(), 'cimm_') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $overrides . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr date1904="0"/><sheets>' . $sheetList . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRels
        . "<Relationship Id=\"{$rIdSS}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Target=\"sharedStrings.xml\"/>"
        . "<Relationship Id=\"{$rIdST}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
        . '</Relationships>');
    foreach ($sheetXmls as $num => $xml) { $zip->addFromString("xl/worksheets/sheet{$num}.xml", $xml); }
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    $zip->addFromString('xl/styles.xml', buildStylesXml());
    $zip->close();
    return $tmp;
}

// ══════════════════════════════════════════════════════════════════════════════
//  PDF (HTML → browser print)
// ══════════════════════════════════════════════════════════════════════════════
function buildPDFHtml(string $reportTitle,string $reportType,string $dateFrom,string $dateTo,array $data,string $generatedBy,string $generatedAt,string $periodStr): string {
    $statusCss = function(string $s): array {
        return match(strtolower($s)) {
            'approved','completed'                                    => ['#dcfce7','#166534','#16a34a'],
            'pending','scheduled','pending acceptance','accepted'     => ['#fef9c3','#713f12','#ca8a04'],
            'rejected','delayed','cancelled'                         => ['#fee2e2','#7f1d1d','#dc2626'],
            'in progress','pending completion','pending approval',
            'pending admin approval'                                 => ['#ede9fe','#4c1d95','#7c3aed'],
            default                                                  => ['#f1f5f9','#334155','#64748b'],
        };
    };
    $priorityCss = function(string $p): array {
        return match(strtolower($p)) {
            'critical' => ['#fee2e2','#7f1d1d','#dc2626'],
            'high'     => ['#ffedd5','#7c2d12','#ea580c'],
            'medium'   => ['#fef9c3','#713f12','#ca8a04'],
            'low'      => ['#dbeafe','#1e3a5f','#2563eb'],
            default    => ['#f1f5f9','#334155','#64748b'],
        };
    };
    $badge = function(string $v, array $css): string {
        [$bg, $txt, $border] = $css;
        return "<span class='badge' style='background:{$bg};color:{$txt};border:1px solid {$border}40'>{$v}</span>";
    };
    $bodyHtml = ''; $countNote = '';
    if ($reportType === 'requests') {
        $countNote = count($data) . ' record' . (count($data) !== 1 ? 's' : '');
        $heads = ['Request ID','Infrastructure','Location','Issue','Status','Date Submitted'];
        $rows  = '';
        foreach ($data as $i => $r) {
            $sc = $statusCss($r['approval_status']);
            $rows .= '<tr class="' . ($i%2?'even':'') . '">'
                . "<td class='mono'>{$r['req_id_fmt']}</td>"
                . '<td>'.htmlspecialchars($r['infrastructure']).'</td>'
                . '<td class="small">'.htmlspecialchars($r['location']).'</td>'
                . '<td class="small">'.htmlspecialchars($r['issue']).'</td>'
                . '<td>'.$badge($r['approval_status'],$sc).'</td>'
                . "<td class='nowrap small'>{$r['created_fmt']}</td></tr>";
        }
        if (!$rows) $rows = '<tr><td colspan="6" class="empty-row">No records found for this period.</td></tr>';
        $th = implode('',array_map(fn($h)=>"<th>{$h}</th>",$heads));
        $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";
    } elseif ($reportType === 'schedules') {
        $countNote = count($data) . ' record' . (count($data) !== 1 ? 's' : '');
        $heads = ['Task / Infrastructure','Location','Start Date','Est. Completion','Status','Priority','Category','Type','Engineer','Budget'];
        $rows  = '';
        foreach ($data as $i => $r) {
            $sc = $statusCss($r['status']); $pc = $priorityCss($r['priority']);
            $endFmt = $r['end_fmt'] ?? '—';
            $rows .= '<tr class="'.($i%2?'even':'').'">'
                . '<td><strong>'.htmlspecialchars($r['task']).'</strong></td>'
                . '<td class="small">'.htmlspecialchars($r['location']).'</td>'
                . "<td class='nowrap small'>{$r['start_fmt']}</td>"
                . "<td class='nowrap small'>{$endFmt}</td>"
                . '<td>'.$badge($r['status'],$sc).'</td>'
                . '<td>'.$badge($r['priority'],$pc).'</td>'
                . '<td class="small">'.htmlspecialchars($r['category']).'</td>'
                . '<td class="small" style="font-size:9px;color:#6366f1">'.htmlspecialchars($r['source_type']).'</td>'
                . '<td class="small">'.htmlspecialchars($r['engineer_name'] ?: 'Unassigned').'</td>'
                . '<td class="small nowrap">'.htmlspecialchars('PHP ' . ($r['budget_fmt'] ?? '0.00')).'</td>'
                . '</tr>';
        }
        if (!$rows) $rows = '<tr><td colspan="10" class="empty-row">No records found for this period.</td></tr>';
        $th = implode('',array_map(fn($h)=>"<th>{$h}</th>",$heads));
        $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";

    } elseif (in_array($reportType, ['current_reports','pending_reports','archive_reports'])) {
        $countNote = count($data) . ' record' . (count($data) !== 1 ? 's' : '');
        $isCurrent = $reportType === 'current_reports';
        $isPending = $reportType === 'pending_reports';

        if ($isCurrent) {
            // Match admin view: ID, Infrastructure, Location, Issue, Priority, Budget, Start, End, Status, Engineer, Created At
            $heads = ['Report ID','Infrastructure','Location','Issue','Priority','Budget','Start Date','Est. End','Status','Engineer','Created At'];
            $rows  = '';
            foreach ($data as $i => $r) {
                $rawStatus   = $r['resolution_status'] ?? 'Approved';
                $displayStatus = ($rawStatus === 'Pending Admin Approval') ? 'Pending Approval' : $rawStatus;
                $sc  = $statusCss($displayStatus);
                $pc  = $priorityCss($r['priority_lvl'] ?? '—');
                $rows .= '<tr class="'.($i%2?'even':'').'">'
                    . "<td class='mono'>{$r['rep_id_fmt']}</td>"
                    . '<td>'.htmlspecialchars($r['infrastructure']).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['location']).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['issue']).'</td>'
                    . '<td style="text-align:center">'.$badge($r['priority_lvl'] ?? '—', $pc).'</td>'
                    . '<td class="small nowrap">PHP '.htmlspecialchars($r['budget_fmt']).'</td>'
                    . "<td class='nowrap small'>{$r['start_fmt']}</td>"
                    . "<td class='nowrap small'>{$r['end_fmt']}</td>"
                    . '<td style="text-align:center">'.$badge($displayStatus, $sc).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['engineer_name'] ?: 'Unassigned').'</td>'
                    . "<td class='small nowrap'>{$r['created_fmt']}</td>"
                    . '</tr>';
            }
            if (!$rows) $rows = '<tr><td colspan="11" class="empty-row">No records found for this period.</td></tr>';
            $th = implode('',array_map(fn($h)=>"<th>{$h}</th>",$heads));
            $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";

        } else {
            // Pending and Archive: ID, Infrastructure, Location, Issue, Priority, Budget, Start, End, Status, Engineer, Created At
            $heads = ['Report ID','Infrastructure','Location','Issue','Priority','Budget','Start Date','Est. End','Status','Engineer','Created At'];
            $rows  = '';
            foreach ($data as $i => $r) {
                $rawStatus     = $r['resolution_status'] ?? '';
                $displayStatus = ($rawStatus === 'Pending Admin Approval') ? 'Pending Approval' : $rawStatus;
                $sc  = $statusCss($displayStatus);
                $pc  = $priorityCss($r['priority_lvl'] ?? '—');
                $rows .= '<tr class="'.($i%2?'even':'').'">'
                    . "<td class='mono'>{$r['rep_id_fmt']}</td>"
                    . '<td>'.htmlspecialchars($r['infrastructure']).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['location']).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['issue']).'</td>'
                    . '<td style="text-align:center">'.$badge($r['priority_lvl'] ?? '—', $pc).'</td>'
                    . '<td class="small nowrap">PHP '.htmlspecialchars($r['budget_fmt']).'</td>'
                    . "<td class='nowrap small'>{$r['start_fmt']}</td>"
                    . "<td class='nowrap small'>{$r['end_fmt']}</td>"
                    . '<td style="text-align:center">'.$badge($displayStatus, $sc).'</td>'
                    . '<td class="small">'.htmlspecialchars($r['engineer_name'] ?: 'Unassigned').'</td>'
                    . "<td class='small nowrap'>{$r['created_fmt']}</td>"
                    . '</tr>';
            }
            if (!$rows) $rows = '<tr><td colspan="11" class="empty-row">No records found for this period.</td></tr>';
            $th = implode('',array_map(fn($h)=>"<th>{$h}</th>",$heads));
            $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";
        }

    } else {
        $d = $data;
        $pendPct = $d['total_requests']>0  ? round($d['pending']/$d['total_requests']*100) : 0;
        $donePct = $d['total_schedules']>0 ? round($d['completed_tasks']/$d['total_schedules']*100) : 0;
        $metrics = [
            ['Total Requests',$d['total_requests'],'#1e3a5f','📋'],
            ['Pending',$d['pending'],'#ca8a04','⏳'],
            ['Approved',$d['approved'],'#16a34a','✅'],
            ['Rejected',$d['rejected'],'#dc2626','❌'],
            ['Total Schedules',$d['total_schedules'],'#7c3aed','📅'],
            ['Completed Tasks',$d['completed_tasks'],'#0891b2','🏁'],
            ['In Progress',$d['in_progress'],'#2563eb','🔧'],
            ['Delayed',$d['delayed'],'#ea580c','⚠️'],
        ];
        $cards = '<div class="metric-grid">';
        foreach ($metrics as [$lbl,$val,$color,$ico]) {
            $cards .= "<div class='metric-card' style='border-top:4px solid {$color}'>"
                . "<div class='metric-icon'>{$ico}</div>"
                . "<div class='metric-val' style='color:{$color}'>{$val}</div>"
                . "<div class='metric-lbl'>{$lbl}</div></div>";
        }
        $cards .= '</div>';
        $progress = "<div class='progress-section'>"
            . "<div class='progress-item'><span class='pl'>Request Pending Rate</span><span class='pv'>{$pendPct}%</span>"
            . "<div class='pbar'><div class='pfill' style='width:{$pendPct}%;background:#ca8a04'></div></div></div>"
            . "<div class='progress-item'><span class='pl'>Schedule Completion Rate</span><span class='pv'>{$donePct}%</span>"
            . "<div class='pbar'><div class='pfill' style='width:{$donePct}%;background:#16a34a'></div></div></div>"
            . "</div>";
        $makeTopTable = function(array $rows, string $col1Label): string {
            $out = '<table style="font-size:11px"><thead><tr><th style="width:36px">#</th>'
                 . "<th style='text-align:left'>{$col1Label}</th><th style='width:70px'>Count</th></tr></thead><tbody>";
            foreach ($rows as $i => $r) {
                $cls = $i%2?'even':'';
                $pct = $rows[0]['cnt']>0 ? round($r['cnt']/$rows[0]['cnt']*100) : 0;
                $out .= "<tr class='{$cls}'><td style='text-align:center;font-weight:700;color:#1e3a5f'>".($i+1)."</td>"
                      . "<td>".htmlspecialchars($r['lbl'])
                      . "<div style='margin-top:3px;height:4px;background:#e2e8f0;border-radius:2px'>"
                      . "<div style='width:{$pct}%;height:4px;background:#2d5fa3;border-radius:2px'></div></div></td>"
                      . "<td style='text-align:center;font-weight:700'>{$r['cnt']}</td></tr>";
            }
            if (!$rows) $out .= '<tr><td colspan="3" class="empty-row">No data</td></tr>';
            return $out . '</tbody></table>';
        };
        $bodyHtml = $cards . $progress
            . '<div class="two-col">'
            . '<div><h3 class="sub-title">🏗️ Top Infrastructure Types</h3>'.$makeTopTable($d['top_infra'],'Infrastructure').'</div>'
            . '<div><h3 class="sub-title">📍 Top Locations</h3>'.$makeTopTable($d['top_locations'],'Location').'</div>'
            . '</div>';
    }
    $countBadgeHtml = $countNote ? "<span class='count-badge'>{$countNote}</span>" : '';
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$reportTitle}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1e293b;background:#fff}
.print-bar{position:fixed;top:0;left:0;right:0;height:48px;background:#1e3a5f;display:flex;align-items:center;justify-content:space-between;padding:0 20px;z-index:9999;box-shadow:0 2px 12px rgba(0,0,0,.25)}
.print-bar-title{color:#fff;font-size:13px;font-weight:600}
.print-bar-btns{display:flex;gap:8px}
.btn-print{background:#fff;color:#1e3a5f;border:none;padding:7px 16px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer}
.btn-close{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}
.page{padding:58px 24px 24px}
.report-header{display:flex;align-items:stretch;background:#1e3a5f;border-radius:12px;overflow:hidden;margin-bottom:20px}
.header-accent{width:8px;background:linear-gradient(180deg,#5b9bd5,#2d5fa3)}
.header-body{flex:1;padding:18px 22px}
.header-org{font-size:10px;color:#93c5fd;letter-spacing:.08em;text-transform:uppercase;font-weight:600;margin-bottom:4px}
.header-title{font-size:20px;font-weight:700;color:#fff;line-height:1.2;margin-bottom:6px}
.header-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:10px;color:#93c5fd}
.header-meta span{display:flex;align-items:center;gap:4px}
.header-right{background:#2d5fa3;padding:18px 22px;min-width:160px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end}
.header-right .label{font-size:9px;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em}
.header-right .value{font-size:11px;color:#fff;font-weight:600;text-align:right;margin-top:2px}
.section-header{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #e2e8f0}
.section-title-text{font-size:14px;font-weight:700;color:#1e3a5f}
.count-badge{background:#dbeafe;color:#1e3a5f;border:1px solid #bfdbfe;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700}
table{width:100%;border-collapse:collapse;font-size:11px}
thead tr{background:#1e3a5f}
thead th{color:#fff;font-weight:600;padding:9px 10px;text-align:left;font-size:10px;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}
tbody td{padding:8px 10px;border-bottom:1px solid #e8edf5;vertical-align:top}
tbody tr.even td{background:#f8fafc}
.empty-row{text-align:center;color:#94a3b8;padding:28px;font-size:13px}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:600;white-space:nowrap}
.mono{font-family:monospace;font-size:10px;color:#475569}
.small{font-size:10px;color:#475569}
.nowrap{white-space:nowrap}
.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.metric-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.metric-icon{font-size:20px;margin-bottom:6px}
.metric-val{font-size:26px;font-weight:700;line-height:1;margin-bottom:4px}
.metric-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;font-weight:600}
.progress-section{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}
.progress-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px}
.pl{font-size:10px;font-weight:600;color:#475569}
.pv{font-size:13px;font-weight:700;color:#1e3a5f;float:right}
.pbar{margin-top:8px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden}
.pfill{height:6px;border-radius:3px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:4px}
.sub-title{font-size:12px;font-weight:700;color:#1e3a5f;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #e2e8f0}
.report-footer{margin-top:22px;padding-top:12px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:9px;color:#94a3b8}
.footer-conf{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:2px 8px;border-radius:4px;font-weight:600;font-size:9px}
@media print{.print-bar{display:none!important}.page{padding:0}thead{-webkit-print-color-adjust:exact;print-color-adjust:exact}.badge,.metric-card,.pfill,.report-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}tbody tr.even td{background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@page{size:A4 landscape;margin:12mm 10mm}
</style></head><body>
<div class="print-bar">
  <span class="print-bar-title">📄 {$reportTitle}</span>
  <div class="print-bar-btns">
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="page">
  <div class="report-header">
    <div class="header-accent"></div>
    <div class="header-body">
      <div class="header-org">🏛️ LGU — Community Infrastructure Monitoring &amp; Management (CIMM)</div>
      <div class="header-title">{$reportTitle}</div>
      <div class="header-meta">
        <span>📅 Period: <strong style='color:#fff'>{$periodStr}</strong></span>
        <span>👤 Generated by: <strong style='color:#fff'>{$generatedBy}</strong></span>
        <span>🕐 {$generatedAt}</span>
      </div>
    </div>
    <div class="header-right">
      <div class="label">Report Type</div><div class="value">{$reportTitle}</div>
      <div class="label" style="margin-top:10px">Document Status</div>
      <div class="value" style="color:#86efac">● Official</div>
    </div>
  </div>
  <div class="section-header">
    <div class="section-title-text">Report Data</div>{$countBadgeHtml}
  </div>
  {$bodyHtml}
  <div class="report-footer">
    <span>CIMM Infrastructure Monitoring Portal &nbsp;|&nbsp; LGU</span>
    <span class="footer-conf">CONFIDENTIAL — Internal Use Only</span>
    <span>{$reportTitle} &nbsp;|&nbsp; {$periodStr}</span>
  </div>
</div></body></html>
HTML;
}

// ══════════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════════
//  PDF HELPERS  (status/priority badge CSS)
// ══════════════════════════════════════════════════════════════════════════════
function statusStyle(string $s): array {
    return match(strtolower($s)) {
        'approved','completed'                        => ['#dcfce7','#166534','#16a34a'],
        'pending','scheduled','pending acceptance'    => ['#fef9c3','#713f12','#ca8a04'],
        'rejected','delayed','cancelled'              => ['#fee2e2','#7f1d1d','#dc2626'],
        'in progress','pending completion'            => ['#ede9fe','#4c1d95','#7c3aed'],
        default                                       => ['#f1f5f9','#334155','#64748b'],
    };
}
function priorityStyle(string $p): array {
    return match(strtolower($p)) {
        'critical' => ['#fee2e2','#7f1d1d','#dc2626'],
        'high'     => ['#ffedd5','#7c2d12','#ea580c'],
        'medium'   => ['#fef9c3','#713f12','#ca8a04'],
        'low'      => ['#dbeafe','#1e3a5f','#2563eb'],
        default    => ['#f1f5f9','#334155','#64748b'],
    };
}
function htmlBadge(string $v, array $css): string {
    [$bg,$txt,$bdr] = $css;
    return "<span class='badge' style='background:{$bg};color:{$txt};border:1px solid {$bdr}40'>" . htmlspecialchars($v) . "</span>";
}

// ══════════════════════════════════════════════════════════════════════════════
//  ROUTING
// ══════════════════════════════════════════════════════════════════════════════
if ($format === 'excel') {
    // ── CSV output — dates prefixed with \t so Excel never auto-converts them ──
    // Helper: wrap a date/datetime string so Excel treats it as text
    $d = fn(string $v): string => "\t" . $v;   // tab prefix = force text in Excel

    $sections = [];

    if ($reportType === 'requests') {
        $rows     = fetchRequests($conn, $dateFrom, $dateTo);
        $pending  = count(array_filter($rows, fn($r) => $r['approval_status'] === 'Pending'));
        $approved = count(array_filter($rows, fn($r) => $r['approval_status'] === 'Approved'));
        $rejected = count(array_filter($rows, fn($r) => $r['approval_status'] === 'Rejected'));
        $sections[] = [
            'title'   => 'Infrastructure Repair Requests',
            'headers' => ['Request ID','Infrastructure','Location','Issue / Description','Status','Date Submitted'],
            'rows'    => array_map(fn($r) => [
                $r['req_id_fmt'], $r['infrastructure'], $r['location'],
                $r['issue'],      $r['approval_status'], $d($r['created_fmt']),
            ], $rows),
            'summary' => [
                ['Total Requests', count($rows)],
                ['Pending',  $pending],
                ['Approved', $approved],
                ['Rejected', $rejected],
            ],
        ];

    } elseif ($reportType === 'schedules') {
        $rows     = fetchSchedules($conn, $dateFrom, $dateTo);
        $byStatus = [];
        foreach ($rows as $r) { $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1; }
        $sections[] = [
            'title'   => 'Maintenance Schedule (Tasks & Infrastructure Reports)',
            'headers' => ['Task / Infrastructure','Location','Start Date','Est. Completion','Status','Priority','Category','Type','Engineer','Team','Budget (PHP)'],
            'rows'    => array_map(fn($r) => [
                $r['task'],           $r['location'],          $d($r['start_fmt']),
                $d($r['end_fmt'] ?? '—'), $r['status'],        $r['priority'],
                $r['category'],       $r['source_type'],       $r['engineer_name'] ?: 'Unassigned',
                $r['assigned_team'],  $r['budget_fmt'],
            ], $rows),
            'summary' => array_merge(
                [['Total Items', count($rows)]],
                array_map(fn($s, $c) => [$s, $c], array_keys($byStatus), array_values($byStatus))
            ),
        ];

    } elseif ($reportType === 'summary') {
        $sum     = fetchSummary($conn, $dateFrom, $dateTo);
        $pendPct = $sum['total_requests']>0  ? round($sum['pending']/$sum['total_requests']*100,1) : 0;
        $donePct = $sum['total_schedules']>0 ? round($sum['completed_tasks']/$sum['total_schedules']*100,1) : 0;
        $sections[] = [
            'title'   => 'Citizen Requests Overview',
            'headers' => ['Metric','Value','Rate / Note'],
            'rows'    => [
                ['Total Requests Submitted', $sum['total_requests'], ''],
                ['Pending Approval',         $sum['pending'],        "{$pendPct}% of total"],
                ['Approved',                 $sum['approved'],       ''],
                ['Rejected',                 $sum['rejected'],       ''],
            ],
        ];
        $sections[] = [
            'title'   => 'Maintenance Schedule Overview',
            'headers' => ['Metric','Value','Rate / Note'],
            'rows'    => [
                ['Total Maintenance Tasks', $sum['total_schedules'],  ''],
                ['Completed',              $sum['completed_tasks'],  "{$donePct}% completion rate"],
                ['In Progress',            $sum['in_progress'],      ''],
                ['Delayed',                $sum['delayed'],          'Requires attention'],
            ],
        ];
        $infra = array_map(fn($r,$i) => [($i+1),$r['lbl'],$r['cnt']], $sum['top_infra'], array_keys($sum['top_infra']));
        $sections[] = [
            'title'   => 'Top 5 Infrastructure Types',
            'headers' => ['Rank','Infrastructure Type','Request Count'],
            'rows'    => $infra ?: [['-','No data available',0]],
        ];
        $locs = array_map(fn($r,$i) => [($i+1),$r['lbl'],$r['cnt']], $sum['top_locations'], array_keys($sum['top_locations']));
        $sections[] = [
            'title'   => 'Top 5 Locations by Request Volume',
            'headers' => ['Rank','Location','Request Count'],
            'rows'    => $locs ?: [['-','No data available',0]],
        ];

    } elseif ($reportType === 'current_reports') {
        $rows     = fetchCurrentReports($conn, $dateFrom, $dateTo);
        $byStatus = [];
        foreach ($rows as $r) {
            $ds = ($r['resolution_status'] === 'Pending Admin Approval') ? 'Pending Approval' : ($r['resolution_status'] ?? 'Approved');
            $byStatus[$ds] = ($byStatus[$ds] ?? 0) + 1;
        }
        $sections[] = [
            'title'   => 'Current Reports — Assigned to Engineers',
            'headers' => ['Report ID','Infrastructure','Location','Issue','Priority','Budget (PHP)','Start Date','Est. End Date','Status','Engineer','Date Created'],
            'rows'    => array_map(fn($r) => [
                $r['rep_id_fmt'],  $r['infrastructure'],  $r['location'],
                $r['issue'],       $r['priority_lvl'],    $r['budget_fmt'],
                $d($r['start_fmt']), $d($r['end_fmt']),
                ($r['resolution_status'] === 'Pending Admin Approval') ? 'Pending Approval' : ($r['resolution_status'] ?? 'Approved'),
                $r['engineer_name'] ?: 'Unassigned', $d($r['created_fmt']),
            ], $rows),
            'summary' => array_merge(
                [['Total Current Reports', count($rows)]],
                array_map(fn($s,$c) => [$s,$c], array_keys($byStatus), array_values($byStatus))
            ),
        ];

    } elseif ($reportType === 'pending_reports') {
        $rows     = fetchPendingReports($conn, $dateFrom, $dateTo);
        $byStatus = [];
        foreach ($rows as $r) {
            $ds = ($r['resolution_status'] === 'Pending Admin Approval') ? 'Pending Approval' : $r['resolution_status'];
            $byStatus[$ds] = ($byStatus[$ds] ?? 0) + 1;
        }
        $sections[] = [
            'title'   => 'Pending Reports — Scheduled / In Progress / Pending Completion / Pending Approval',
            'headers' => ['Report ID','Infrastructure','Location','Issue','Priority','Budget (PHP)','Start Date','Est. End Date','Status','Engineer','Date Created'],
            'rows'    => array_map(fn($r) => [
                $r['rep_id_fmt'],  $r['infrastructure'],  $r['location'],
                $r['issue'],       $r['priority_lvl'],    $r['budget_fmt'],
                $d($r['start_fmt']), $d($r['end_fmt']),
                ($r['resolution_status'] === 'Pending Admin Approval') ? 'Pending Approval' : $r['resolution_status'],
                $r['engineer_name'] ?: 'Unassigned', $d($r['created_fmt']),
            ], $rows),
            'summary' => array_merge(
                [['Total Pending Reports', count($rows)]],
                array_map(fn($s,$c) => [$s,$c], array_keys($byStatus), array_values($byStatus))
            ),
        ];

    } elseif ($reportType === 'archive_reports') {
        $rows      = fetchArchiveReports($conn, $dateFrom, $dateTo);
        $completed = count(array_filter($rows, fn($r) => $r['resolution_status'] === 'Completed'));
        $cancelled = count(array_filter($rows, fn($r) => $r['resolution_status'] === 'Cancelled'));
        $sections[] = [
            'title'   => 'Archive Reports — Completed & Cancelled',
            'headers' => ['Report ID','Infrastructure','Location','Issue','Priority','Budget (PHP)','Start Date','Est. End Date','Status','Engineer','Date Created'],
            'rows'    => array_map(fn($r) => [
                $r['rep_id_fmt'],  $r['infrastructure'],  $r['location'],
                $r['issue'],       $r['priority_lvl'],    $r['budget_fmt'],
                $d($r['start_fmt']), $d($r['end_fmt']),   $r['resolution_status'],
                $r['engineer_name'] ?: 'Unassigned', $d($r['created_fmt']),
            ], $rows),
            'summary' => [
                ['Total Archived Reports', count($rows)],
                ['Completed',  $completed],
                ['Cancelled',  $cancelled],
            ],
        ];
    }

    // ── Auto-compute column widths from actual data for every section ──────────
    // Excel col width unit ≈ (max_chars * 1.15) + 2, capped at 60, min 10.
    foreach ($sections as &$sec) {
        $headers = $sec['headers'];
        $colCount = count($headers);
        $maxLen = [];
        // Seed with header lengths — headers render as BOLD UPPERCASE so multiply by 1.35
        // to guarantee the header text itself is never clipped.
        foreach ($headers as $ci => $h) {
            $maxLen[$ci] = (int)(mb_strlen(strtoupper($h)) * 1.35);
        }
        // Walk data rows and strip \t prefix before measuring
        foreach (($sec['rows'] ?? []) as $row) {
            foreach (array_values($row) as $ci => $val) {
                $clean = ltrim((string)$val, "\t");
                // For long strings measure only the longest line (newline or natural wrap at ~80)
                $len = 0;
                foreach (explode("\n", $clean) as $line) {
                    $len = max($len, mb_strlen(trim($line)));
                }
                $maxLen[$ci] = max($maxLen[$ci] ?? 0, min($len, 80));
            }
        }
        $widths = [];
        for ($i = 0; $i < $colCount; $i++) {
            // Excel col width unit ≈ chars * 1.2 + 2, min 12 so short labels show fully
            $widths[$i] = max(12, min(65, (int)(($maxLen[$i] ?? 12) * 1.2) + 2));
        }
        $sec['colWidths']  = $widths;
        // Remove \t prefix from all data values before passing to XLSX builder
        $sec['rows'] = array_map(function($row) {
            return array_map(fn($v) => ltrim((string)$v, "\t"), array_values($row));
        }, $sec['rows'] ?? []);
    }
    unset($sec);

    // Convert sections to sheetDefs format expected by buildXLSX
    $sheetDefs = [];
    foreach ($sections as $sec) {
        $sheetDefs[] = [
            'name'        => mb_substr($sec['title'] ?? 'Data', 0, 31), // Excel tab max 31 chars
            'title'       => $sec['title'] ?? 'Data',
            'headers'     => $sec['headers'],
            'rows'        => $sec['rows'] ?? [],
            'colWidths'   => $sec['colWidths'],
            'centerCols'  => $sec['centerCols'] ?? [],
            'badgeCols'   => $sec['badgeCols']  ?? [],
            'meta_period' => $periodStr,
            'meta_by'     => $generatedBy,
            'meta_date'   => $generatedAt,
        ];
    }

    $xlsxFile = buildXLSX($sheetDefs, $reportTitle);
    $xlsxData = file_get_contents($xlsxFile);
    @unlink($xlsxFile);
    $filename = 'CIMM_' . str_replace(['_',' '], '-', ucwords($reportType, '_')) . '_' . date('Ymd') . '.xlsx';

    // Export succeeded — let Admins / Super Admins know.
    notifyReportExported($conn, $reportType, 'excel', $reportTitle, $periodStr, $generatedBy, (int)($_SESSION['employee_id'] ?? 0));

    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsxData));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo $xlsxData;
    exit;

} else {
    // ── PDF ───────────────────────────────────────────────────────────────────
    $data = match($reportType) {
        'requests'        => fetchRequests($conn, $dateFrom, $dateTo),
        'schedules'       => fetchSchedules($conn, $dateFrom, $dateTo),
        'summary'         => fetchSummary($conn, $dateFrom, $dateTo),
        'current_reports' => fetchCurrentReports($conn, $dateFrom, $dateTo),
        'pending_reports' => fetchPendingReports($conn, $dateFrom, $dateTo),
        'archive_reports' => fetchArchiveReports($conn, $dateFrom, $dateTo),
        default           => fetchRequests($conn, $dateFrom, $dateTo),
    };

    // Export succeeded — let Admins / Super Admins know.
    notifyReportExported($conn, $reportType, 'pdf', $reportTitle, $periodStr, $generatedBy, (int)($_SESSION['employee_id'] ?? 0));

    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo buildPDFHtml($reportTitle, $reportType, $dateFrom, $dateTo, $data, $generatedBy, $generatedAt, $periodStr);
    exit;
}