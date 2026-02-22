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
if (!in_array($role, ['admin', 'super admin'])) {
    http_response_code(403); die('Admin access required');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('Method not allowed');
}

require __DIR__ . '/db.php';

// ── Input validation ──────────────────────────────────────────────────────────
$format     = in_array($_POST['format'] ?? '', ['excel','pdf']) ? $_POST['format'] : 'excel';
$reportType = in_array($_POST['report_type'] ?? '', ['requests','schedules','summary'])
              ? $_POST['report_type'] : 'requests';
$dateFrom   = date('Y-m-d', strtotime($_POST['date_from'] ?? date('Y-m-01')));
$dateTo     = date('Y-m-d', strtotime($_POST['date_to']   ?? date('Y-m-d')));
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$reportTitles = [
    'requests'  => 'Infrastructure Repair Requests Report',
    'schedules' => 'Maintenance Schedule Report',
    'summary'   => 'Executive Summary Report',
];
$reportTitle = $reportTitles[$reportType];
$generatedBy = trim(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')) ?: 'Admin';
$generatedAt = date('F d, Y  h:i A');
$periodStr   = date('F d, Y', strtotime($dateFrom)) . ' – ' . date('F d, Y', strtotime($dateTo));

// ── Data fetchers ─────────────────────────────────────────────────────────────
function fetchRequests($conn, $from, $to) {
    $stmt = $conn->prepare("
        SELECT
            CONCAT('REQ-', LPAD(req_id, 4, '0'))          AS req_id_fmt,
            infrastructure, location, issue,
            approval_status,
            DATE_FORMAT(created_at,'%b %d, %Y %h:%i %p')  AS created_fmt
        FROM requests
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetchSchedules($conn, $from, $to) {
    $stmt = $conn->prepare("
        SELECT
            task, location,
            DATE_FORMAT(starting_date,'%b %d, %Y')         AS start_fmt,
            status, priority,
            IFNULL(category,'—')                            AS category,
            IFNULL(assigned_team,'Unassigned')              AS assigned_team
        FROM maintenance_schedule
        WHERE DATE(starting_date) BETWEEN ? AND ?
        ORDER BY
            FIELD(priority,'Critical','High','Medium','Low'),
            starting_date ASC
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

/* Strip characters that are illegal in XML 1.0 (control chars except \t \n \r).
   htmlspecialchars alone does NOT remove these, leaving the XML unparseable. */
function xmlSafe(string $v): string {
    return preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/u', '', $v);
}

/* ─── Centralised style sheet ─────────────────────────────────────────────── */
function buildStylesXml(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">

  <!-- ── fonts ── -->
  <fonts count="11">
    <font><sz val="11"/><name val="Arial"/></font>                           <!-- 0 default -->
    <font><sz val="13"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font> <!-- 1 col-header -->
    <font><sz val="11"/><name val="Arial"/></font>                           <!-- 2 data odd -->
    <font><sz val="22"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font> <!-- 3 title -->
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>    <!-- 4 meta -->
    <font><sz val="11"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font><!-- 5 section header -->
    <font><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/><b/></font><!-- 6 badge white -->
    <font><sz val="28"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font><!-- 7 metric big -->
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>    <!-- 8 metric label -->
    <font><sz val="11"/><b/><name val="Arial"/></font>                       <!-- 9 data even bold -->
    <font><sz val="11"/><color rgb="FF374151"/><name val="Arial"/></font>    <!-- 10 data even -->
  </fonts>

  <!-- ── fills ── -->
  <fills count="18">
    <fill><patternFill patternType="none"/></fill>                         <!-- 0 -->
    <fill><patternFill patternType="gray125"/></fill>                      <!-- 1 -->
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill> <!-- 2 navy header -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F4FA"/></patternFill></fill> <!-- 3 alt row -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF9EE"/></patternFill></fill> <!-- 4 green light -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3E2"/></patternFill></fill> <!-- 5 orange light -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFFDECEA"/></patternFill></fill> <!-- 6 red light -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/></patternFill></fill> <!-- 7 blue light -->
    <fill><patternFill patternType="solid"><fgColor rgb="FF22C55E"/></patternFill></fill> <!-- 8 green badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/></patternFill></fill> <!-- 9 amber badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFEF4444"/></patternFill></fill> <!-- 10 red badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FF6366F1"/></patternFill></fill> <!-- 11 indigo badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/></patternFill></fill> <!-- 12 blue badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFF97316"/></patternFill></fill> <!-- 13 orange badge -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFFAFBFF"/></patternFill></fill> <!-- 14 near white -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFE4ECF7"/></patternFill></fill> <!-- 15 title bar bg -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill> <!-- 16 pure white -->
    <fill><patternFill patternType="solid"><fgColor rgb="FF2D5FA3"/></patternFill></fill> <!-- 17 mid-blue -->
  </fills>

  <!-- ── borders ── -->
  <borders count="5">
    <border><left/><right/><top/><bottom/><diagonal/></border>             <!-- 0 none -->
    <border>
      <left style="thin"><color rgb="FFD1DAE8"/></left>
      <right style="thin"><color rgb="FFD1DAE8"/></right>
      <top style="thin"><color rgb="FFD1DAE8"/></top>
      <bottom style="thin"><color rgb="FFD1DAE8"/></bottom>
      <diagonal/>
    </border>                                                               <!-- 1 light -->
    <border>
      <left style="medium"><color rgb="FF1E3A5F"/></left>
      <right style="medium"><color rgb="FF1E3A5F"/></right>
      <top style="medium"><color rgb="FF1E3A5F"/></top>
      <bottom style="medium"><color rgb="FF1E3A5F"/></bottom>
      <diagonal/>
    </border>                                                               <!-- 2 navy medium -->
    <border>
      <bottom style="medium"><color rgb="FF1E3A5F"/></bottom>
      <diagonal/>
    </border>                                                               <!-- 3 bottom only -->
    <border>
      <left style="thin"><color rgb="FFB0C4DE"/></left>
      <right style="thin"><color rgb="FFB0C4DE"/></right>
      <top style="thin"><color rgb="FFB0C4DE"/></top>
      <bottom style="medium"><color rgb="FF2D5FA3"/></bottom>
      <diagonal/>
    </border>                                                               <!-- 4 col header bottom accent -->
  </borders>

  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>

  <!-- ── cell xfs (style index used in s="N") ── -->
  <cellXfs count="28">
    <!-- 0  default data odd -->
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1">
      <alignment horizontal="left" vertical="center" wrapText="1"/>
    </xf>
    <!-- 1  column header -->
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 2  data even -->
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" wrapText="1"/>
    </xf>
    <!-- 3  report title -->
    <xf numFmtId="0" fontId="3" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" indent="1"/>
    </xf>
    <!-- 4  meta line -->
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" indent="1"/>
    </xf>
    <!-- 5  section header -->
    <xf numFmtId="0" fontId="5" fillId="0" borderId="3" xfId="0" applyFont="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center"/>
    </xf>
    <!-- 6  badge: Approved / Completed / green -->
    <xf numFmtId="0" fontId="6" fillId="8" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 7  badge: Pending / Scheduled / amber -->
    <xf numFmtId="0" fontId="6" fillId="9" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 8  badge: Rejected / Delayed / red -->
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 9  badge: In Progress / indigo -->
    <xf numFmtId="0" fontId="6" fillId="11" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 10 badge: Critical / red -->
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 11 badge: High / orange -->
    <xf numFmtId="0" fontId="6" fillId="13" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 12 badge: Low / blue -->
    <xf numFmtId="0" fontId="6" fillId="12" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 13 metric value (big number) -->
    <xf numFmtId="0" fontId="7" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 14 metric label -->
    <xf numFmtId="0" fontId="8" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 15 rank / center data odd -->
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 16 rank / center data even -->
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 17 title bar spacer row -->
    <xf numFmtId="0" fontId="0" fillId="15" borderId="0" xfId="0" applyFill="1"/>
    <!-- 18 blank spacer -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 19 metric header label (col-header style but smaller) -->
    <xf numFmtId="0" fontId="1" fillId="17" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 20 col header left-aligned (for wide text cols) -->
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" indent="1"/>
    </xf>
    <!-- 21 data odd center -->
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 22 data even center -->
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 23 total row label -->
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" indent="1"/>
    </xf>
    <!-- 24 total row value -->
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 25 data odd left no wrap -->
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1">
      <alignment horizontal="left" vertical="center"/>
    </xf>
    <!-- 26 data even left no wrap -->
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center"/>
    </xf>
    <!-- 27 title org sub-line -->
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
      <alignment horizontal="right" vertical="center" indent="1"/>
    </xf>
  </cellXfs>
</styleSheet>
XML;
}

/* ─── Status / priority → style index ────────────────────────────────────── */
function statusStyle(string $s): int {
    return match(strtolower($s)) {
        'approved','completed'  => 6,
        'pending','scheduled'   => 7,
        'rejected','delayed'    => 8,
        'in progress'           => 9,
        default                 => 7,
    };
}
function priorityStyle(string $p): int {
    return match(strtolower($p)) {
        'critical' => 10,
        'high'     => 11,
        'medium'   => 7,
        'low'      => 12,
        default    => 12,
    };
}

/* ─── Shared-string helper ───────────────────────────────────────────────── */
function makeSharedStrings(array &$pool, string $v): int {
    if (!array_key_exists($v, $pool)) $pool[$v] = count($pool);
    return $pool[$v];
}

/* ─── Cell XML helpers ───────────────────────────────────────────────────── */
function sc(string $ref, int $si, int $style): string {
    return "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$si}</v></c>";
}
function nc(string $ref, $val, int $style): string {    // numeric cell
    return "<c r=\"{$ref}\" s=\"{$style}\"><v>{$val}</v></c>";
}

/* ─── Build a single worksheet ───────────────────────────────────────────── */
function buildSheetXml(array $def, array &$pool): string {
    $headers   = $def['headers'];
    $rows      = $def['rows'];
    $colCount  = count($headers);
    $lastCol   = numToCol($colCount);

    $rowXmls   = [];
    $mergeRefs = [];   // collected here, emitted after sheetData
    $rn = 1;           // current Excel row number

    /* ── Row 1: Report title ── */
    $ti = makeSharedStrings($pool, $def['title']);
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"42\" customHeight=\"1\">" . sc("A{$rn}", $ti, 3) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}";
    $rn++;

    /* ── Row 2: Org name — own merged row so it always gets full table width ── */
    $org = makeSharedStrings($pool, "LGU \u{2013} CIMM  |  Community Infrastructure Monitoring & Management");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">" . sc("A{$rn}", $org, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}";
    $rn++;

    /* ── Row 3: Meta line (Period · Generated by · Date) ── */
    $meta2 = makeSharedStrings($pool, "Period: {$def['meta_period']}   |   Generated by: {$def['meta_by']}   |   {$def['meta_date']}");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"16\" customHeight=\"1\">" . sc("A{$rn}", $meta2, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}";
    $rn++;

    /* ── Row 4: thin colour-bar spacer ── */
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"5\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"17\"/></row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}";
    $rn++;

    /* ── Row 5: blank breathing gap ── */
    $rowXmls[] = "<row r=\"{$rn}\" ht=\"6\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"18\"/></row>";
    $rn++;

    $headerRow = $rn;   // remember for freeze pane

    /* Row 5: column headers */
    $hCells = '';
    foreach ($headers as $ci => $h) {
        $col  = numToCol($ci + 1);
        $hi   = makeSharedStrings($pool, strtoupper($h));
        $hCells .= sc("{$col}{$rn}", $hi, ($def['centerCols'][$ci] ?? false) ? 1 : 20);
    }
    $rowXmls[] = "<row r=\"{$rn}\" ht=\"22\" customHeight=\"1\">{$hCells}</row>";
    $rn++;

    /* Data rows */
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
                $sIdx = statusStyle((string)$val);
                $vi   = makeSharedStrings($pool, (string)$val);
                $cells .= sc($ref, $vi, $sIdx);
            } elseif ($badge === 'priority') {
                $sIdx = priorityStyle((string)$val);
                $vi   = makeSharedStrings($pool, (string)$val);
                $cells .= sc($ref, $vi, $sIdx);
            } elseif (is_numeric($val) && ($def['numericCols'][$ci] ?? false)) {
                $cells .= nc($ref, $val, $center ? $ctrBase : $baseS);
            } else {
                $vi = makeSharedStrings($pool, (string)$val);
                $cells .= sc($ref, $vi, $center ? $ctrBase : $baseS);
            }
        }

        /* last row: slightly different bottom border via total row if summary */
        $isLast = ($ri === $dataCount - 1) && ($def['totalRow'] ?? false);
        if ($isLast) {
            // Rebuild as total row
            $cells = '';
            foreach (array_values($rowData) as $ci => $val) {
                $col = numToCol($ci + 1); $ref = "{$col}{$rn}";
                $vi  = makeSharedStrings($pool, (string)$val);
                $cells .= sc($ref, $vi, $ci === 0 ? 23 : 24);
            }
        }

        $rowXmls[] = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">{$cells}</row>";
        $rn++;
    }

    /* Empty state */
    if ($dataCount === 0) {
        $ei = makeSharedStrings($pool, 'No records found for the selected date range.');
        $rowXmls[] = "<row r=\"{$rn}\" ht=\"24\" customHeight=\"1\">" . sc("A{$rn}", $ei, 4) . "</row>";
        $rn++;
    }

    /* Column widths */
    $colWidths = '';
    foreach ($headers as $ci => $h) {
        $col   = $ci + 1;
        $w     = $def['colWidths'][$ci] ?? min(max(mb_strlen($h) * 1.5 + 6, 12), 60);
        $colWidths .= "<col min=\"{$col}\" max=\"{$col}\" width=\"{$w}\" customWidth=\"1\"/>";
    }

    /* Freeze pane below header */
    $freezeRow = $headerRow + 1;
    $freezeXml = "<sheetViews><sheetView workbookViewId=\"0\" tabSelected=\"1\">"
               . "<pane ySplit=\"{$headerRow}\" topLeftCell=\"A{$freezeRow}\" activePane=\"bottomLeft\" state=\"frozen\"/>"
               . "<selection pane=\"bottomLeft\" activeCell=\"A{$freezeRow}\"/>"
               . "</sheetView></sheetViews>";

    /* Merge cells — built dynamically during row construction above */
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

/* ─── Build complete XLSX binary ─────────────────────────────────────────── */
function buildXLSX(array $sheetDefs, string $reportTitle): string {
    $pool        = [];
    $sheetXmls   = [];
    $sheetList   = '';
    $sheetRels   = '';
    $overrides   = '';

    foreach ($sheetDefs as $si => $def) {
        $num  = $si + 1;
        $rId  = "rId{$num}";
        $name = htmlspecialchars($def['name'], ENT_XML1);
        $sheetList .= "<sheet name=\"{$name}\" sheetId=\"{$num}\" r:id=\"{$rId}\"/>";
        $sheetRels .= "<Relationship Id=\"{$rId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$num}.xml\"/>";
        $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$num}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $sheetXmls[$num] = buildSheetXml($def, $pool);
    }

    $total = count($sheetDefs);
    $rIdSS = 'rId' . ($total + 1);
    $rIdST = 'rId' . ($total + 2);

    /* Shared strings */
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
        . $overrides . '</Types>'
    );
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>'
    );
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr date1904="0"/>'
        . '<sheets>' . $sheetList . '</sheets>'
        . '</workbook>'
    );
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRels
        . "<Relationship Id=\"{$rIdSS}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Target=\"sharedStrings.xml\"/>"
        . "<Relationship Id=\"{$rIdST}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
        . '</Relationships>'
    );
    foreach ($sheetXmls as $num => $xml) {
        $zip->addFromString("xl/worksheets/sheet{$num}.xml", $xml);
    }
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    $zip->addFromString('xl/styles.xml', buildStylesXml());
    $zip->close();

    return $tmp;
}

// ══════════════════════════════════════════════════════════════════════════════
//  PDF (HTML → browser print)
// ══════════════════════════════════════════════════════════════════════════════
function buildPDFHtml(
    string $reportTitle, string $reportType,
    string $dateFrom, string $dateTo,
    array  $data,
    string $generatedBy, string $generatedAt,
    string $periodStr
): string {

    /* ── colour helpers ── */
    $statusCss = function(string $s): array {
        return match(strtolower($s)) {
            'approved','completed' => ['#dcfce7','#166534','#16a34a'],
            'pending','scheduled'  => ['#fef9c3','#713f12','#ca8a04'],
            'rejected','delayed'   => ['#fee2e2','#7f1d1d','#dc2626'],
            'in progress'          => ['#ede9fe','#4c1d95','#7c3aed'],
            default                => ['#f1f5f9','#334155','#64748b'],
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

    /* ── build table HTML ── */
    $bodyHtml  = '';
    $countNote = '';

    if ($reportType === 'requests') {
        $countNote = count($data) . ' record' . (count($data) !== 1 ? 's' : '');
        $heads = ['Request ID','Infrastructure','Location','Issue','Status','Date Submitted'];
        $rows  = '';
        foreach ($data as $i => $r) {
            $sc = $statusCss($r['approval_status']);
            $rows .= '<tr class="' . ($i % 2 ? 'even' : '') . '">'
                . "<td class='mono'>{$r['req_id_fmt']}</td>"
                . '<td>' . htmlspecialchars($r['infrastructure']) . '</td>'
                . '<td class="small">' . htmlspecialchars($r['location']) . '</td>'
                . '<td class="small">' . htmlspecialchars($r['issue']) . '</td>'
                . '<td>' . $badge($r['approval_status'], $sc) . '</td>'
                . "<td class='nowrap small'>{$r['created_fmt']}</td>"
                . '</tr>';
        }
        if (!$rows) $rows = '<tr><td colspan="6" class="empty-row">No records found for this period.</td></tr>';
        $th = implode('', array_map(fn($h) => "<th>{$h}</th>", $heads));
        $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";

    } elseif ($reportType === 'schedules') {
        $countNote = count($data) . ' record' . (count($data) !== 1 ? 's' : '');
        $heads = ['Task','Location','Start Date','Status','Priority','Category','Team'];
        $rows  = '';
        foreach ($data as $i => $r) {
            $sc = $statusCss($r['status']);
            $pc = $priorityCss($r['priority']);
            $rows .= '<tr class="' . ($i % 2 ? 'even' : '') . '">'
                . '<td><strong>' . htmlspecialchars($r['task']) . '</strong></td>'
                . '<td class="small">' . htmlspecialchars($r['location']) . '</td>'
                . "<td class='nowrap'>{$r['start_fmt']}</td>"
                . '<td>' . $badge($r['status'], $sc) . '</td>'
                . '<td>' . $badge($r['priority'], $pc) . '</td>'
                . '<td class="small">' . htmlspecialchars($r['category']) . '</td>'
                . '<td class="small">' . htmlspecialchars($r['assigned_team']) . '</td>'
                . '</tr>';
        }
        if (!$rows) $rows = '<tr><td colspan="7" class="empty-row">No records found for this period.</td></tr>';
        $th = implode('', array_map(fn($h) => "<th>{$h}</th>", $heads));
        $bodyHtml = "<table><thead><tr>{$th}</tr></thead><tbody>{$rows}</tbody></table>";

    } else { /* summary */
        $d = $data;
        $pendPct  = $d['total_requests']  > 0 ? round($d['pending']        / $d['total_requests']  * 100) : 0;
        $donePct  = $d['total_schedules'] > 0 ? round($d['completed_tasks'] / $d['total_schedules'] * 100) : 0;

        $metrics = [
            ['Total Requests',   $d['total_requests'],  '#1e3a5f','📋'],
            ['Pending',          $d['pending'],          '#ca8a04','⏳'],
            ['Approved',         $d['approved'],         '#16a34a','✅'],
            ['Rejected',         $d['rejected'],         '#dc2626','❌'],
            ['Total Schedules',  $d['total_schedules'],  '#7c3aed','📅'],
            ['Completed Tasks',  $d['completed_tasks'],  '#0891b2','🏁'],
            ['In Progress',      $d['in_progress'],      '#2563eb','🔧'],
            ['Delayed',          $d['delayed'],          '#ea580c','⚠️'],
        ];
        $cards = '<div class="metric-grid">';
        foreach ($metrics as [$lbl, $val, $color, $ico]) {
            $cards .= "<div class='metric-card' style='border-top:4px solid {$color}'>"
                . "<div class='metric-icon'>{$ico}</div>"
                . "<div class='metric-val' style='color:{$color}'>{$val}</div>"
                . "<div class='metric-lbl'>{$lbl}</div>"
                . "</div>";
        }
        $cards .= '</div>';

        /* Progress bars */
        $progress = "<div class='progress-section'>"
            . "<div class='progress-item'><span class='pl'>Request Pending Rate</span><span class='pv'>{$pendPct}%</span>"
            . "<div class='pbar'><div class='pfill' style='width:{$pendPct}%;background:#ca8a04'></div></div></div>"
            . "<div class='progress-item'><span class='pl'>Schedule Completion Rate</span><span class='pv'>{$donePct}%</span>"
            . "<div class='pbar'><div class='pfill' style='width:{$donePct}%;background:#16a34a'></div></div></div>"
            . "</div>";

        /* Top tables */
        $makeTopTable = function(array $rows, string $col1Label) use ($badge, $priorityCss): string {
            $out = '<table style="font-size:11px"><thead><tr><th style="width:36px">#</th>'
                 . "<th style='text-align:left'>{$col1Label}</th><th style='width:70px'>Count</th></tr></thead><tbody>";
            foreach ($rows as $i => $r) {
                $cls = $i % 2 ? 'even' : '';
                $pct = $rows[0]['cnt'] > 0 ? round($r['cnt'] / $rows[0]['cnt'] * 100) : 0;
                $out .= "<tr class='{$cls}'><td style='text-align:center;font-weight:700;color:#1e3a5f'>" . ($i + 1) . "</td>"
                      . "<td>" . htmlspecialchars($r['lbl'])
                      . "<div style='margin-top:3px;height:4px;background:#e2e8f0;border-radius:2px'>"
                      . "<div style='width:{$pct}%;height:4px;background:#2d5fa3;border-radius:2px'></div></div></td>"
                      . "<td style='text-align:center;font-weight:700'>{$r['cnt']}</td></tr>";
            }
            if (!$rows) $out .= '<tr><td colspan="3" class="empty-row">No data</td></tr>';
            $out .= '</tbody></table>';
            return $out;
        };

        $bodyHtml = $cards . $progress
            . '<div class="two-col">'
            . '<div><h3 class="sub-title">🏗️ Top Infrastructure Types</h3>' . $makeTopTable($d['top_infra'], 'Infrastructure') . '</div>'
            . '<div><h3 class="sub-title">📍 Top Locations</h3>' . $makeTopTable($d['top_locations'], 'Location') . '</div>'
            . '</div>';
    }

    /* ── full HTML page ── */
    $countBadgeHtml = $countNote
        ? "<span class='count-badge'>{$countNote}</span>" : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$reportTitle}</title>
<style>
/* ── Reset ── */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1e293b;background:#fff}

/* ── Print bar ── */
.print-bar{position:fixed;top:0;left:0;right:0;height:48px;background:#1e3a5f;
  display:flex;align-items:center;justify-content:space-between;padding:0 20px;
  z-index:9999;box-shadow:0 2px 12px rgba(0,0,0,.25)}
.print-bar-title{color:#fff;font-size:13px;font-weight:600}
.print-bar-btns{display:flex;gap:8px}
.btn-print{background:#fff;color:#1e3a5f;border:none;padding:7px 16px;border-radius:6px;
  font-size:12px;font-weight:700;cursor:pointer;transition:.2s}
.btn-print:hover{background:#e0e8f5}
.btn-close{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);
  padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}

/* ── Page wrapper ── */
.page{padding:58px 24px 24px}

/* ── Header banner ── */
.report-header{display:flex;align-items:stretch;background:#1e3a5f;border-radius:12px;
  overflow:hidden;margin-bottom:20px}
.header-accent{width:8px;background:linear-gradient(180deg,#5b9bd5,#2d5fa3)}
.header-body{flex:1;padding:18px 22px}
.header-org{font-size:10px;color:#93c5fd;letter-spacing:.08em;text-transform:uppercase;
  font-weight:600;margin-bottom:4px}
.header-title{font-size:20px;font-weight:700;color:#fff;line-height:1.2;margin-bottom:6px}
.header-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:10px;color:#93c5fd}
.header-meta span{display:flex;align-items:center;gap:4px}
.header-right{background:#2d5fa3;padding:18px 22px;min-width:160px;display:flex;
  flex-direction:column;justify-content:center;align-items:flex-end}
.header-right .label{font-size:9px;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em}
.header-right .value{font-size:11px;color:#fff;font-weight:600;text-align:right;margin-top:2px}

/* ── Section header ── */
.section-header{display:flex;align-items:center;gap:10px;margin-bottom:14px;
  padding-bottom:8px;border-bottom:2px solid #e2e8f0}
.section-title-text{font-size:14px;font-weight:700;color:#1e3a5f}
.count-badge{background:#dbeafe;color:#1e3a5f;border:1px solid #bfdbfe;
  padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700}

/* ── Data table ── */
table{width:100%;border-collapse:collapse;font-size:11px}
thead tr{background:#1e3a5f}
thead th{color:#fff;font-weight:600;padding:9px 10px;text-align:left;
  font-size:10px;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}
tbody tr{transition:.1s}
tbody tr:hover td{background:#f0f6ff!important}
tbody td{padding:8px 10px;border-bottom:1px solid #e8edf5;vertical-align:top}
tbody tr.even td{background:#f8fafc}
.empty-row{text-align:center;color:#94a3b8;padding:28px;font-size:13px}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;
  font-weight:600;white-space:nowrap}
.mono{font-family:monospace;font-size:10px;color:#475569}
.small{font-size:10px;color:#475569}
.nowrap{white-space:nowrap}

/* ── Summary metric grid ── */
.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.metric-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;
  padding:14px 16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.metric-icon{font-size:20px;margin-bottom:6px}
.metric-val{font-size:26px;font-weight:700;line-height:1;margin-bottom:4px}
.metric-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;font-weight:600}

/* ── Progress bars ── */
.progress-section{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}
.progress-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px}
.pl{font-size:10px;font-weight:600;color:#475569}
.pv{font-size:13px;font-weight:700;color:#1e3a5f;float:right}
.pbar{margin-top:8px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden}
.pfill{height:6px;border-radius:3px;transition:width .4s}

/* ── Two-col layout ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:4px}
.sub-title{font-size:12px;font-weight:700;color:#1e3a5f;margin-bottom:10px;
  padding-bottom:6px;border-bottom:1px solid #e2e8f0}

/* ── Footer ── */
.report-footer{margin-top:22px;padding-top:12px;border-top:1px solid #e2e8f0;
  display:flex;justify-content:space-between;align-items:center;
  font-size:9px;color:#94a3b8}
.footer-conf{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;
  padding:2px 8px;border-radius:4px;font-weight:600;font-size:9px}

/* ── Print ── */
@media print{
  .print-bar{display:none!important}
  .page{padding:0}
  .report-header{border-radius:0;margin-bottom:14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  table{font-size:10px}
  thead{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .metric-card{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .metric-grid{grid-template-columns:repeat(4,1fr)}
  .badge{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody tr.even td{background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pfill{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
@page{size:A4 landscape;margin:12mm 10mm}
</style>
</head>
<body>

<!-- ── Print toolbar ── -->
<div class="print-bar no-print">
  <span class="print-bar-title">📄 {$reportTitle}</span>
  <div class="print-bar-btns">
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
  </div>
</div>

<div class="page">

  <!-- ── Report header banner ── -->
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
      <div class="label">Report Type</div>
      <div class="value">{$reportTitle}</div>
      <div class="label" style="margin-top:10px">Document Status</div>
      <div class="value" style="color:#86efac">● Official</div>
    </div>
  </div>

  <!-- ── Section heading ── -->
  <div class="section-header">
    <div class="section-title-text">Report Data</div>
    {$countBadgeHtml}
  </div>

  <!-- ── Main content ── -->
  {$bodyHtml}

  <!-- ── Footer ── -->
  <div class="report-footer">
    <span>CIMM Infrastructure Monitoring Portal &nbsp;|&nbsp; LGU</span>
    <span class="footer-conf">CONFIDENTIAL — Internal Use Only</span>
    <span>{$reportTitle} &nbsp;|&nbsp; {$periodStr}</span>
  </div>

</div>
</body>
</html>
HTML;
}

// ══════════════════════════════════════════════════════════════════════════════
//  ROUTING
// ══════════════════════════════════════════════════════════════════════════════
$meta = [
    'meta_by'     => $generatedBy,
    'meta_date'   => $generatedAt,
    'meta_period' => $periodStr,
];

if ($format === 'excel') {

    $sheetDefs = [];

    if ($reportType === 'requests') {
        $rows = fetchRequests($conn, $dateFrom, $dateTo);
        // Map to column array
        $mapped = array_map(fn($r) => [
            $r['req_id_fmt'], $r['infrastructure'], $r['location'],
            $r['issue'], $r['approval_status'], $r['created_fmt']
        ], $rows);
        $sheetDefs[] = $meta + [
            'name'       => 'Requests',
            'title'      => 'Infrastructure Repair Requests Report',
            'headers'    => ['Request ID','Infrastructure','Location','Issue / Description','Status','Date Submitted'],
            'colWidths'  => [14, 22, 28, 36, 14, 20],
            'centerCols' => [0=>true, 4=>true, 5=>true],
            'badgeCols'  => [4=>'status'],
            'numericCols'=> [],
            'totalRow'   => false,
            'rows'       => $mapped,
        ];
    } elseif ($reportType === 'schedules') {
        $rows = fetchSchedules($conn, $dateFrom, $dateTo);
        $mapped = array_map(fn($r) => [
            $r['task'], $r['location'], $r['start_fmt'],
            $r['status'], $r['priority'], $r['category'], $r['assigned_team']
        ], $rows);
        $sheetDefs[] = $meta + [
            'name'       => 'Schedules',
            'title'      => 'Maintenance Schedule Report',
            'headers'    => ['Task','Location','Start Date','Status','Priority','Category','Assigned Team'],
            'colWidths'  => [30, 26, 14, 14, 12, 16, 20],
            'centerCols' => [2=>true, 3=>true, 4=>true],
            'badgeCols'  => [3=>'status', 4=>'priority'],
            'numericCols'=> [],
            'totalRow'   => false,
            'rows'       => $mapped,
        ];
    } elseif ($reportType === 'summary') {
        $sum = fetchSummary($conn, $dateFrom, $dateTo);

        /* Sheet 1 – Overview metrics */
        $pendPct = $sum['total_requests']  > 0 ? round($sum['pending']        / $sum['total_requests']  * 100, 1) : 0;
        $donePct = $sum['total_schedules'] > 0 ? round($sum['completed_tasks']/ $sum['total_schedules'] * 100, 1) : 0;
        $sheetDefs[] = $meta + [
            'name'       => 'Overview',
            'title'      => 'Executive Summary Report',
            'headers'    => ['Category','Metric','Value','Notes'],
            'colWidths'  => [22, 28, 14, 32],
            'centerCols' => [2=>true],
            'badgeCols'  => [],
            'numericCols'=> [2=>true],
            'totalRow'   => false,
            'rows'       => [
                ['Requests', 'Total Requests',          $sum['total_requests'],   ''],
                ['Requests', 'Pending',                 $sum['pending'],          "{$pendPct}% of total"],
                ['Requests', 'Approved',                $sum['approved'],         ''],
                ['Requests', 'Rejected',                $sum['rejected'],         ''],
                ['Schedules','Total Maintenance Tasks', $sum['total_schedules'],  ''],
                ['Schedules','Completed',               $sum['completed_tasks'],  "{$donePct}% completion rate"],
                ['Schedules','In Progress',             $sum['in_progress'],      ''],
                ['Schedules','Delayed',                 $sum['delayed'],          'Requires attention'],
            ],
        ];

        /* Sheet 2 – Top Infrastructure */
        $infra = array_map(fn($r, $i) => [($i+1), $r['lbl'], $r['cnt']], $sum['top_infra'], array_keys($sum['top_infra']));
        $sheetDefs[] = $meta + [
            'name'       => 'Top Infrastructure',
            'title'      => 'Top Infrastructure Types by Request Volume',
            'headers'    => ['Rank','Infrastructure Type','Request Count'],
            'colWidths'  => [10, 36, 18],
            'centerCols' => [0=>true, 2=>true],
            'badgeCols'  => [],
            'numericCols'=> [2=>true],
            'totalRow'   => false,
            'rows'       => $infra ?: [['-','No data available',0]],
        ];

        /* Sheet 3 – Top Locations */
        $locs = array_map(fn($r, $i) => [($i+1), $r['lbl'], $r['cnt']], $sum['top_locations'], array_keys($sum['top_locations']));
        $sheetDefs[] = $meta + [
            'name'       => 'Top Locations',
            'title'      => 'Top Locations by Request Volume',
            'headers'    => ['Rank','Location','Request Count'],
            'colWidths'  => [10, 46, 18],
            'centerCols' => [0=>true, 2=>true],
            'badgeCols'  => [],
            'numericCols'=> [2=>true],
            'totalRow'   => false,
            'rows'       => $locs ?: [['-','No data available',0]],
        ];
    }

    $tmpFile  = buildXLSX($sheetDefs, $reportTitle);
    $filename = 'CIMM_' . ucfirst($reportType) . '_' . date('Ymd') . '.xlsx';

    // Discard any buffered output (PHP notices/warnings) before sending binary
    ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;

} else { /* PDF */

    if ($reportType === 'requests') {
        $data = fetchRequests($conn, $dateFrom, $dateTo);
    } elseif ($reportType === 'schedules') {
        $data = fetchSchedules($conn, $dateFrom, $dateTo);
    } else {
        $data = fetchSummary($conn, $dateFrom, $dateTo);
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo buildPDFHtml($reportTitle, $reportType, $dateFrom, $dateTo, $data, $generatedBy, $generatedAt, $periodStr);
    exit;
}