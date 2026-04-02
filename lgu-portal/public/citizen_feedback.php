<?php
/**
 * citizen_feedback.php
 * Citizen-facing feedback form — mirrors citizencimm.php/citizenrepform.php style.
 */
session_start();
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once 'auth_config.php';
require_once 'db.php';

if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL     = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
} else {
    $BASE_URL     = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
}

// Notification helpers
function setNotification($type, $message) {
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
}
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type    = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon    = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif(){var n=document.getElementById('notifPopup');if(n)n.style.opacity='0';setTimeout(()=>{if(n)n.remove();},400);}
            setTimeout(closeNotif,4000);
        </script>";
    }
}

// Auto-create tables if missing
$conn->query("
    CREATE TABLE IF NOT EXISTS `citizen_feedback` (
      `feedback_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `full_name`      VARCHAR(120) NOT NULL DEFAULT 'Citizen',
      `contact_number` VARCHAR(20)  DEFAULT NULL,
      `email`          VARCHAR(120) DEFAULT NULL,
      `feedback_type`  ENUM('Concern','Acknowledgement','Improvement','Complaint','Suggestion') NOT NULL DEFAULT 'Concern',
      `title`          VARCHAR(200) NOT NULL,
      `description`    TEXT         NOT NULL,
      `rating`         DECIMAL(3,1) NOT NULL DEFAULT 3.0,
      `infrastructure` VARCHAR(200) DEFAULT NULL,
      `address`        TEXT         DEFAULT NULL,
      `coordinates`    VARCHAR(60)  DEFAULT NULL,
      `rep_id`         INT          DEFAULT NULL,
      `status`         ENUM('New','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'New',
      `employee_notes` TEXT         DEFAULT NULL,
      `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
// Migrate existing tables: promote rating from TINYINT to DECIMAL(3,1) for half-star support
@$conn->query("ALTER TABLE `citizen_feedback` MODIFY COLUMN `rating` DECIMAL(3,1) NOT NULL DEFAULT 3.0");
$conn->query("
    CREATE TABLE IF NOT EXISTS `feedback_images` (
      `img_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `feedback_id` INT UNSIGNED NOT NULL,
      `img_path`    VARCHAR(300) NOT NULL,
      `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`img_id`),
      KEY `idx_fbk_id` (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Fetch completed archive reports for the reference dropdown
$archiveReports = [];
$archiveSQL = "
    SELECT r.rep_id, r.starting_date, r.estimated_end_date AS end_date,
           r.priority_lvl, r.budget,
           res.status,
           req.infrastructure, req.location, req.issue,
           GROUP_CONCAT(ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN evidence_images      ev  ON res.req_id = ev.req_id
    WHERE res.status IN ('Completed','Cancelled')
    GROUP BY r.rep_id
    ORDER BY r.rep_id DESC
    LIMIT 100
";
$archRes = $conn->query($archiveSQL);
if ($archRes) {
    while ($row = $archRes->fetch_assoc()) {
        $evImgs = [];
        if (!empty($row['evidence_images'])) {
            $evImgs = array_values(array_filter(explode(',', $row['evidence_images'])));
        }
        $archiveReports[] = [
            'rep_id'          => (int)$row['rep_id'],
            'infrastructure'  => $row['infrastructure'] ?? '—',
            'location'        => $row['location'] ?? '—',
            'issue'           => $row['issue'] ?? '',
            'status'          => $row['status'] ?? 'Completed',
            'priority'        => $row['priority_lvl'] ?? '',
            'budget'          => (float)($row['budget'] ?? 0),
            'start'           => !empty($row['starting_date'])
                                    ? date('M d, Y', strtotime($row['starting_date'])) : '—',
            'end'             => !empty($row['end_date'])
                                    ? date('M d, Y', strtotime($row['end_date'])) : '—',
            'evidence_images' => $evImgs,
        ];
    }
}

// Infrastructure options (mirror citizenrepform.php list)
$infraOptions = [
    'Road','Bridge','Drainage System','Flood Control','Street Lighting',
    'Public Building','School','Health Center','Water System',
    'Irrigation','Sports Facility','Public Market','Park/Plaza','Others'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
<title>Submit Feedback — CIMM LGU</title>
<link rel="stylesheet" href="<?= $BASE_URL ?>citizen_global.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script>
(function(){
    const t=localStorage.getItem('theme');
    if(t==='dark') document.documentElement.setAttribute('data-theme','dark');
    else document.documentElement.removeAttribute('data-theme');
})();
</script>
<style>
/* ══════════════════════════════════════════════
   ROOT VARIABLES — match citizencimm.php
══════════════════════════════════════════════ */
:root {
    --bg-primary:   #ffffff;
    --bg-secondary: rgba(255,255,255,.95);
    --bg-tertiary:  rgba(255,255,255,.9);
    --text-primary: #000000;
    --text-secondary:#333333;
    --border-color: rgba(0,0,0,.1);
    --shadow-color: rgba(0,0,0,.2);
    --card-bg:      #ffffff;
    --nav-bg:       rgba(255,255,255,.87);
    --accent-primary:   #2b6cb0;
    --accent-secondary: #3762c8;
    --accent-light:     #e6f0ff;
    --card-border:  1.5px solid rgb(47,99,156);
    --card-shadow:  0 4px 20px rgba(0,0,0,.45);
    --modal-bg:           #ffffff;
    --input-bg:           #fff;
    --input-border:       #c0c9d1;
    --input-focus-border: #2b6cb0;
    --input-focus-shadow: rgba(43,108,176,.15);
    --input-placeholder:  #666666;
}
[data-theme="dark"] {
    --bg-primary:   #1a1a1a;
    --bg-secondary: rgba(26,26,26,.95);
    --bg-tertiary:  rgba(30,30,30,.9);
    --text-primary: #ffffff;
    --text-secondary:#e0e0e0;
    --border-color: rgba(255,255,255,.1);
    --shadow-color: rgba(0,0,0,.5);
    --card-bg:      rgba(30,30,30,.95);
    --nav-bg:       rgba(26,26,26,.87);
    --accent-primary:   #4a8fd8;
    --accent-secondary: #5a9fe8;
    --accent-light:     #1e3a5f;
    --card-border:  1px solid rgba(255,255,255,.08);
    --card-shadow:  0 4px 20px rgba(0,0,0,.45);
    --modal-bg:           rgba(24,24,30,.98);
    --input-bg:           rgba(40,40,40,.9);
    --input-border:       rgba(255,255,255,.2);
    --input-focus-border: #4a8fd8;
    --input-focus-shadow: rgba(74,143,216,.25);
    --input-placeholder:  #888888;
}

body {
    background: url("<?= $BASE_URL ?>cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── Page wrapper ── */
.feedback-page {
    flex: 1;
    padding: 100px 20px 60px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
}

/* ── Hero banner ── */
.fbk-hero {
    text-align: center;
    color: #fff;
    margin-bottom: 8px;
}
.fbk-hero h1 {
    font-size: 2.6rem;
    font-weight: 900;
    text-shadow: 2px 2px 10px #000;
    margin-bottom: 10px;
}
.fbk-hero p {
    font-size: 1.1rem;
    text-shadow: 1px 1px 5px #000;
    opacity: .92;
}

/* ── Main card ── */
.fbk-card {
    background: var(--bg-secondary);
    backdrop-filter: blur(14px);
    border: var(--card-border);
    border-radius: 22px;
    box-shadow: var(--card-shadow);
    padding: 36px 40px;
    width: 100%;
    max-width: 820px;
}

.fbk-card-title {
    font-size: 2.0rem;
    font-weight: 800;
    color: var(--accent-primary);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 14px;
}
.fbk-card-title i { font-size: 1.5rem; }

/* ── Form grid ── */
.fbk-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px 24px;
}
.fbk-grid .full { grid-column: 1 / -1; }

.fbk-group { display: flex; flex-direction: column; gap: 6px; }
.fbk-group label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.fbk-group label .optional {
    font-size: 10px;
    font-weight: 500;
    color: #94a3b8;
    text-transform: none;
    letter-spacing: 0;
    margin-left: 5px;
}
.fbk-group input,
.fbk-group select,
.fbk-group textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    background: var(--bg-tertiary);
    color: var(--text-primary);
    outline: none;
    transition: border .2s, box-shadow .2s;
    box-sizing: border-box;
}
.fbk-group input:focus,
.fbk-group select:focus,
.fbk-group textarea:focus {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
}
.fbk-group textarea { resize: vertical; min-height: 100px; }

/* ── Half-star rating ── */
.star-rating-outer {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.hsr-wrap {
    display: flex;
    flex-direction: row;
    gap: 2px;
    align-items: center;
}
.hsr-star {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    font-size: 38px;
    line-height: 1;
    cursor: pointer;
    transition: transform .2s cubic-bezier(0.34,1.56,0.64,1);
    user-select: none;
    overflow: hidden;
}
.hsr-star:hover { transform: scale(1.28); }
/* Base: empty star */
.hsr-star::before {
    content: '☆';
    color: #d1d5db;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: color .18s;
}
/* Full star */
.hsr-star[data-fill="full"]::before {
    content: '★';
    color: #f59e0b;
    filter: drop-shadow(0 2px 6px rgba(245,158,11,.45));
    background: none;
    -webkit-text-fill-color: #f59e0b;
}
/* Half star — gray ★ behind via ::before, gold ★ clipped to 50% via ::after */
.hsr-star[data-fill="half"]::before {
    content: '★';
    color: #d1d5db;
    -webkit-text-fill-color: #d1d5db;
    background: none;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: none;
}
.hsr-star[data-fill="half"]::after {
    content: '★';
    position: absolute;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    color: #f59e0b;
    -webkit-text-fill-color: #f59e0b;
    font-size: 38px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
    pointer-events: none;
    clip-path: inset(0 50% 0 0);
}
.star-rating-bar {
    display: flex;
    gap: 6px;
    align-items: center;
}
.star-rating-bar-track {
    flex: 1;
    height: 6px;
    background: var(--border-color);
    border-radius: 4px;
    overflow: hidden;
}
.star-rating-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #f59e0b, #fbbf24);
    transition: width .3s ease;
    width: 60%; /* default 3/5 */
}
.star-hint {
    font-size: 12.5px;
    color: var(--text-secondary);
    font-weight: 600;
    white-space: nowrap;
    min-width: 130px;
}

/* ── Feedback type cards ── */
.fbk-type-wrap {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
}
@media (max-width: 680px) {
    .fbk-type-wrap { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 480px) {
    .fbk-type-wrap { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .fbk-type-wrap label { padding: 10px 6px; font-size: 11px; gap: 4px; border-radius: 10px; }
    .fbk-type-wrap label:last-of-type { grid-column: 1 / -1; max-width: calc(50% - 4px); margin: 0 auto; width: 100%; }
    .fbk-type-icon { font-size: 1.3rem; }
}
@media (max-width: 380px) {
    .fbk-type-wrap { grid-template-columns: repeat(2, 1fr); }
    .fbk-type-wrap label { padding: 10px 6px; font-size: 11px; }
    .fbk-type-wrap label:last-of-type { grid-column: 1 / -1; max-width: calc(50% - 4px); margin: 0 auto; width: 100%; }
    .fbk-type-icon { font-size: 1.3rem; }
}
.fbk-type-wrap input[type="radio"] { display: none; }
.fbk-type-wrap label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 14px 8px;
    border-radius: 14px;
    border: 2px solid var(--border-color);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all .22s cubic-bezier(0.34,1.56,0.64,1);
    color: var(--text-secondary);
    background: var(--bg-tertiary);
    text-transform: none;
    letter-spacing: 0;
    text-align: center;
    position: relative;
    overflow: hidden;
    user-select: none;
}
.fbk-type-icon {
    font-size: 1.6rem;
    line-height: 1;
    transition: transform .22s cubic-bezier(0.34,1.56,0.64,1);
}
.fbk-type-wrap label:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
.fbk-type-wrap label:hover .fbk-type-icon { transform: scale(1.2); }
.fbk-type-wrap input[type="radio"]:checked + label {
    border-color: transparent;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,.22);
}
.fbk-type-wrap input[type="radio"]:checked + label .fbk-type-icon { transform: scale(1.15); }

/* Per-type colors when selected */
#ftype_concern:checked + label     { background: linear-gradient(135deg,#f59e0b,#d97706); }
#ftype_acknowledgement:checked + label { background: linear-gradient(135deg,#22c55e,#16a34a); }
#ftype_improvement:checked + label { background: linear-gradient(135deg,#3b82f6,#2563eb); }
#ftype_complaint:checked + label   { background: linear-gradient(135deg,#ef4444,#dc2626); }
#ftype_suggestion:checked + label  { background: linear-gradient(135deg,#8b5cf6,#7c3aed); }

/* Per-type accent colors on hover — keep text dark/white so it stays readable */
#ftype_concern + label:hover     { border-color: #f59e0b; color: #92400e; background: rgba(245,158,11,.10); }
#ftype_acknowledgement + label:hover { border-color: #22c55e; color: #14532d; background: rgba(34,197,94,.10); }
#ftype_improvement + label:hover { border-color: #3b82f6; color: #1e3a8a; background: rgba(59,130,246,.10); }
#ftype_complaint + label:hover   { border-color: #ef4444; color: #7f1d1d; background: rgba(239,68,68,.10); }
#ftype_suggestion + label:hover  { border-color: #8b5cf6; color: #4c1d95; background: rgba(139,92,246,.10); }
[data-theme="dark"] #ftype_concern + label:hover     { color: #fde68a; background: rgba(245,158,11,.15); }
[data-theme="dark"] #ftype_acknowledgement + label:hover { color: #bbf7d0; background: rgba(34,197,94,.15); }
[data-theme="dark"] #ftype_improvement + label:hover { color: #bfdbfe; background: rgba(59,130,246,.15); }
[data-theme="dark"] #ftype_complaint + label:hover   { color: #fecaca; background: rgba(239,68,68,.15); }
[data-theme="dark"] #ftype_suggestion + label:hover  { color: #ede9fe; background: rgba(139,92,246,.15); }

/* Hide chatbot FAB while map modal is open so it doesn't overlap */
.chatbot-fab-hidden {
    opacity: 0 !important;
    pointer-events: none !important;
    transform: scale(0.7) !important;
    transition: opacity 0.18s ease, transform 0.18s ease !important;
}

/* ── Address / map ── */
.map-section {
    grid-column: 1 / -1;
}
.map-section .map-trigger-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 8px;
    flex-wrap: wrap;
}
.btn-pick-map {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all .25s;
    box-shadow: 0 3px 12px rgba(55,98,200,.3);
    white-space: nowrap;
}
.btn-pick-map:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(55,98,200,.45); }
.map-coords-badge {
    font-size: 12px;
    color: #16a34a;
    font-weight: 600;
    background: #f0fdf4;
    border: 1px solid #86efac;
    padding: 5px 12px;
    border-radius: 8px;
    display: none;
    align-self: flex-start;
    width: fit-content;
}
[data-theme="dark"] .map-coords-badge { background: #14532d; border-color: #4ade80; color: #86efac; }

/* ── Archive report reference ── */
.ref-select-wrap { position: relative; }
.ref-select-wrap .ref-clear {
    position: absolute;
    right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
    display: none;
    line-height: 1;
}
.ref-select-wrap .ref-clear.show { display: block; }

/* ── Photo upload ── */
.photo-drop-zone {
    border: 2px dashed var(--accent-secondary);
    border-radius: 14px;
    padding: 28px;
    text-align: center;
    background: var(--accent-light);
    cursor: pointer;
    transition: background .2s;
    position: relative;
    overflow: hidden;
}
.photo-drop-zone:hover { background: rgba(55,98,200,.08); }
[data-theme="dark"] .photo-drop-zone { background: rgba(55,98,200,.12); }
.photo-drop-icon { font-size: 2.5rem; color: var(--accent-secondary); margin-bottom: 8px; }
.photo-drop-text { font-size: 14px; font-weight: 600; color: var(--text-secondary); }
.photo-drop-hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
#photoInput { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.photo-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 10px;
    margin-top: 14px;
}
.photo-thumb-wrap {
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    aspect-ratio: 1;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.photo-thumb-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.photo-thumb-remove {
    position: absolute;
    top: 4px; right: 4px;
    width: 22px; height: 22px;
    background: rgba(239,68,68,.9);
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    line-height: 1;
}

/* ── Submit button ── */
.fbk-submit-row {
    grid-column: 1 / -1;
    display: flex;
    margin-top: 8px;
    justify-content: center;
}
.btn-fbk-submit {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 13px 34px;
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: all .25s;
    box-shadow: 0 4px 16px rgba(43,108,176,.35);
}
.btn-fbk-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(43,108,176,.5); }
.btn-fbk-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }

/* ── Map Modal — full-screen profile.php style ── */
#fbkMapBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    display: flex; align-items: stretch; justify-content: stretch;
    z-index: 9000;
    visibility: hidden; opacity: 0; pointer-events: none;
    transition: opacity .18s ease, visibility .18s ease;
}
#fbkMapBackdrop.show {
    visibility: visible; opacity: 1; pointer-events: auto;
}
#fbkMapModal {
    background: var(--modal-bg);
    width: 100%; height: 100%;
    border-radius: 0; overflow: hidden;
    display: flex; flex-direction: column; flex: 1;
}
.fbk-map-header {
    padding: 14px 18px; font-weight: 600;
    border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: center; align-items: center;
    position: relative; flex-shrink: 0;
    color: var(--text-primary);
}
.fbk-map-header h3 { flex: 1; text-align: center; margin: 0; font-size: 16px; }
#fbkMapGpsBtn {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    border: none; background: #eef2ff;
    border-radius: 10px; padding: 8px 12px; font-size: 18px; cursor: pointer; z-index: 10;
    transition: background .15s;
}
#fbkMapGpsBtn:hover { background: #e0e7ff; }
[data-theme="dark"] #fbkMapGpsBtn { background: rgba(55,98,200,.22); color: var(--text-primary); }
[data-theme="dark"] #fbkMapGpsBtn:hover { background: rgba(55,98,200,.35); }
#fbkMapLayerToggle {
    position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
    background: #2b6cb0; color: #fff; border: none;
    padding: 8px 14px; border-radius: 8px; font-size: 13px;
    cursor: pointer; font-weight: 600; transition: all .2s; z-index: 10;
}
#fbkMapLayerToggle:hover { background: #245a96; }
.fbk-map-address-input {
    display: flex; flex-direction: column; gap: 8px;
    padding: 10px 16px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
}
.fbk-map-search-wrap { position: relative; flex: 1; min-width: 0; }
#fbkMapSearchInput {
    width: 100%; box-sizing: border-box;
    padding: 10px 34px 10px 12px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
    transition: border-color .2s, box-shadow .2s; font-family: inherit;
}
#fbkMapSearchInput::placeholder { color: var(--input-placeholder); opacity: .7; }
#fbkMapSearchInput:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}
#fbkMapSearchClearBtn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 15px; line-height: 1;
    padding: 2px 4px; border-radius: 4px; display: none; transition: color .15s;
}
#fbkMapSearchClearBtn:hover { color: var(--text-primary); }
#fbkMapSearchClearBtn.visible { display: block; }
#fbkMapAddrField {
    width: 100%; box-sizing: border-box;
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
    transition: border-color .2s; font-family: inherit;
}
[data-theme="dark"] #fbkMapAddrField { color: var(--text-primary); }
#fbkMapSearchDropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--modal-bg); border: 1.5px solid var(--input-border);
    border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15);
    max-height: 200px; overflow-y: auto; z-index: 10200;
    display: none; overscroll-behavior: contain;
    scrollbar-width: thin; scrollbar-color: var(--border-color) transparent;
}
#fbkMapSearchDropdown::-webkit-scrollbar { width: 5px; }
#fbkMapSearchDropdown::-webkit-scrollbar-track { background: transparent; }
#fbkMapSearchDropdown::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
#fbkMapSearchDropdown.open { display: block; }
[data-theme="dark"] #fbkMapSearchDropdown { box-shadow: 0 8px 24px rgba(0,0,0,.45); }
.fbk-map-search-item {
    padding: 9px 13px; font-size: 13px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    display: flex; align-items: flex-start; gap: 8px; transition: background .12s;
}
.fbk-map-search-item:last-child { border-bottom: none; }
.fbk-map-search-item:hover { background: rgba(43,108,176,.09); }
[data-theme="dark"] .fbk-map-search-item:hover { background: rgba(74,143,216,.12); }
.fbk-map-search-item-icon { flex-shrink: 0; margin-top: 1px; opacity: .6; font-size: 14px; }
.fbk-map-search-item-text { flex: 1; min-width: 0; }
.fbk-map-search-item-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fbk-map-search-item-addr { font-size: 11px; color: var(--text-secondary); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fbk-map-search-spinner { display: none; padding: 10px 14px; font-size: 12px; color: var(--text-secondary); }
.fbk-map-search-spinner.visible { display: block; }
#fbkMapWrapper {
    position: relative; margin: 10px 12px 12px;
    border-radius: 12px; flex: 1; min-height: 0; overflow: hidden;
    display: flex; flex-direction: column;
}
#fbkLeafletMap {
    width: 100%; flex: 1; min-height: 300px;
    border-radius: 12px; touch-action: none; display: block;
}
.fbk-map-actions {
    display: flex; justify-content: center; align-items: center;
    padding: 12px 16px; border-top: 1px solid var(--border-color);
    gap: 12px; flex-shrink: 0;
}
.fbk-map-actions button {
    flex: 0 1 200px; min-width: 120px; max-width: 240px;
    padding: 11px 22px; border-radius: 10px;
    font-weight: 600; cursor: pointer; border: none;
    transition: all .2s ease; font-size: 15px; font-family: inherit;
}
.fbk-map-actions .btn-cancel { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.fbk-map-actions .btn-cancel:hover { background: #e5e7eb; }
[data-theme="dark"] .fbk-map-actions .btn-cancel {
    background: rgba(255,255,255,.1); color: var(--text-primary); border-color: var(--border-color);
}
[data-theme="dark"] .fbk-map-actions .btn-cancel:hover { background: rgba(255,255,255,.15); }
.fbk-map-actions .btn-save { background: #2b6cb0; color: #fff; }
.fbk-map-actions .btn-save:hover { background: #245a96; }
/* Zoom buttons */
#fbkLeafletMap .leaflet-control-zoom {
    border: none !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.18) !important;
    border-radius: 14px !important; overflow: hidden !important;
}
#fbkLeafletMap .leaflet-control-zoom-in,
#fbkLeafletMap .leaflet-control-zoom-out {
    width: 36px !important; height: 36px !important; line-height: 36px !important;
    font-size: 18px !important; font-weight: 400 !important; color: #2b6cb0 !important;
    background: rgba(255,255,255,.92) !important; border: none !important;
    display: flex !important; align-items: center !important; justify-content: center !important;
    transition: background .15s !important;
}
#fbkLeafletMap .leaflet-control-zoom-in { border-radius: 14px 14px 0 0 !important; }
#fbkLeafletMap .leaflet-control-zoom-out { border-radius: 0 0 14px 14px !important; border-top: 1px solid rgba(43,108,176,.12) !important; }
#fbkLeafletMap .leaflet-control-zoom-in:hover,
#fbkLeafletMap .leaflet-control-zoom-out:hover { background: #2b6cb0 !important; color: #fff !important; }
[data-theme="dark"] #fbkLeafletMap .leaflet-control-zoom-in,
[data-theme="dark"] #fbkLeafletMap .leaflet-control-zoom-out { background: rgba(26,26,26,.88) !important; color: #8ab4f8 !important; }
[data-theme="dark"] #fbkLeafletMap .leaflet-control-zoom-in:hover,
[data-theme="dark"] #fbkLeafletMap .leaflet-control-zoom-out:hover { background: #3762c8 !important; color: #fff !important; }
[data-theme="dark"] .leaflet-bar a { background-color: #2a2a35 !important; color: #e2e8f0 !important; border-color: rgba(255,255,255,.12) !important; }
[data-theme="dark"] .leaflet-control-attribution { background: rgba(28,28,35,.85) !important; color: #94a3b8 !important; }
@media (max-width: 768px) {
    #fbkMapWrapper { margin: 8px 10px 10px; border-radius: 10px; }
    #fbkLeafletMap { min-height: 250px; border-radius: 10px; }
    #fbkMapGpsBtn { left: 14px; padding: 6px 10px; font-size: 16px; }
    #fbkMapLayerToggle { right: 14px; padding: 6px 12px; font-size: 12px; }
    .fbk-map-actions button { flex: 1; padding: 12px 16px; font-size: 14px; max-width: 160px; }
}
@media (max-width: 480px) {
    #fbkMapWrapper { margin: 6px 8px 8px; border-radius: 8px; }
    #fbkLeafletMap { min-height: 200px; border-radius: 8px; }
    .fbk-map-actions button { flex: 1; padding: 10px 12px; font-size: 13px; max-width: none; }
}

/* ── Success banner ── */
.fbk-success-banner {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border: 1.5px solid #86efac;
    border-radius: 16px;
    padding: 22px 30px;
    display: flex;
    align-items: center;
    gap: 16px;
    width: 100%;
    max-width: 820px;
    display: none;
}
.fbk-success-banner.show { display: flex; }
.fbk-success-icon { font-size: 2.5rem; flex-shrink: 0; }
.fbk-success-text h3 { font-size: 1.1rem; font-weight: 800; color: #15803d; margin-bottom: 4px; }
.fbk-success-text p  { font-size: 14px; color: #166534; }

/* ── Section divider ── */
.fbk-section-label {
    grid-column: 1 / -1;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--accent-secondary);
    border-bottom: 1.5px solid var(--border-color);
    padding-bottom: 6px;
    margin-top: 6px;
}

/* ── Notification popup (citizenrepform style) ── */
.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: var(--card-bg, #fff);
    border-radius: 13px;
    box-shadow: 0 8px 38px rgba(34,53,126,0.23);
    z-index: 10000;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s;
    color: var(--text-primary);
}
.notif-popup .notif-icon { font-size: 23px; }
.notif-popup.notif-success { border-left: 5px solid #4fc97a; }
.notif-popup.notif-error   { border-left: 5px solid #d73f52; }
.notif-popup.notif-warning { border-left: 5px solid #dda203; }
.notif-popup.notif-info    { border-left: 5px solid #527cdf; }
.notif-popup .notif-close {
    background: none; border: none;
    font-size: 20px; margin-left: auto;
    color: #888; cursor: pointer; flex-shrink: 0;
}
@media (max-width: 768px) {
    .notif-popup {
        top: 40px; left: 12px; right: 12px;
        transform: none; min-width: unset; max-width: unset;
        width: calc(100vw - 24px);
        padding: 13px 14px; font-size: 14px; gap: 10px;
        align-items: flex-start; border-radius: 11px;
        flex-wrap: nowrap; box-sizing: border-box;
    }
    .notif-popup .notif-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .notif-popup .notif-message { flex: 1; word-break: break-word; line-height: 1.5; }
    .notif-popup .notif-close  { font-size: 18px; margin-left: 6px; margin-top: 1px; }
}

/* ── Submit Confirmation Modal ── */
#submitAlertBackdrop {
    position: fixed; z-index: 9500; inset: 0;
    background: rgba(15,23,42,0.45);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
#submitAlertBackdrop.active { display: flex; }
#submitAlertModal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,0.2), 0 0 0 1px rgba(0,0,0,0.05);
    padding: 32px 26px 22px;
    width: 320px; max-width: 92vw;
    animation: submitModalPop 0.28s cubic-bezier(0.34,1.56,0.64,1);
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
@keyframes submitModalPop {
    from { transform: translateY(24px) scale(0.93); opacity: 0; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}
[data-theme="dark"] #submitAlertModal {
    background: rgba(24,24,30,0.98);
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.08);
}
#submitAlertModal .icon-wrap {
    width: 60px; height: 60px;
    background: linear-gradient(135deg,rgba(79,201,122,0.12),rgba(79,201,122,0.08));
    border-radius: 50%; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(79,201,122,0.2);
}
[data-theme="dark"] #submitAlertModal .icon-wrap {
    background: linear-gradient(135deg,rgba(79,201,122,0.18),rgba(79,201,122,0.10));
}
#submitAlertModal .icon { font-size: 26px; line-height: 1; }
#submitAlertModal .alert-title {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary); margin-bottom: 8px;
}
#submitAlertModal .alert-desc {
    color: var(--text-secondary);
    font-size: 0.92rem; margin-bottom: 22px; line-height: 1.5;
}
#submitAlertModal .alert-btns { display: flex; gap: 10px; width: 100%; }
#submitAlertModal .alert-btn {
    flex: 1; padding: 10px 0; border-radius: 10px; border: none;
    font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.18s ease;
}
#submitAlertModal .alert-btn.cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}
#submitAlertModal .alert-btn.cancel:hover { background: var(--border-color); }
[data-theme="dark"] #submitAlertModal .alert-btn.cancel {
    background: rgba(255,255,255,0.06); color: #e2e8f0; border-color: rgba(255,255,255,0.1);
}
[data-theme="dark"] #submitAlertModal .alert-btn.cancel:hover { background: rgba(255,255,255,0.11); }
#submitAlertModal .alert-btn.confirm {
    background: linear-gradient(135deg,#4fc97a,#34a058);
    color: #fff; box-shadow: 0 4px 12px rgba(79,201,122,0.3);
}
#submitAlertModal .alert-btn.confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79,201,122,0.4); }

/* ── Prof-combobox (infrastructure / reference dropdowns) ── */
.prof-combobox { position: relative; width: 100%; }
.prof-combobox-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-tertiary); color: var(--text-primary);
    font-size: 14px; cursor: pointer; user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 42px; box-sizing: border-box; font-family: inherit;
}
.prof-combobox-display:hover { border-color: var(--accent-secondary); }
.prof-combobox-display.open {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
}
.prof-combobox-label {
    flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    color: #94a3b8; opacity: .85; transition: color .15s; font-size: 14px;
}
.prof-combobox-label.selected { color: var(--text-primary); opacity: 1; font-weight: 500; }
.prof-combobox-arrow { font-size: 11px; color: var(--text-secondary); margin-left: 8px; transition: transform .2s; flex-shrink: 0; }
.prof-combobox-display.open .prof-combobox-arrow { transform: rotate(180deg); }
.prof-combobox-dropdown {
    position: fixed; background: var(--bg-tertiary);
    border: 1.5px solid var(--accent-secondary);
    border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,.18);
    z-index: 99999; overflow: hidden; display: none;
}
.prof-combobox-dropdown.open { display: block; }
[data-theme="dark"] .prof-combobox-dropdown { background: rgba(40,40,40,0.98); box-shadow: 0 10px 28px rgba(0,0,0,.45); }
.prof-combobox-search {
    width: 100%; padding: 9px 13px; border: none;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-tertiary); color: var(--text-primary);
    font-size: 13px; outline: none; box-sizing: border-box; font-family: inherit;
}
.prof-combobox-search::placeholder { color: #94a3b8; opacity: .7; }
[data-theme="dark"] .prof-combobox-search { background: rgba(40,40,40,0.98); }
.prof-combobox-list { max-height: 220px; overflow-y: auto; overscroll-behavior: contain; }
.prof-combobox-list::-webkit-scrollbar { width: 5px; }
.prof-combobox-list::-webkit-scrollbar-track { background: transparent; }
.prof-combobox-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
.prof-combobox-option {
    padding: 10px 14px; font-size: 14px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background .12s; display: flex; align-items: center; gap: 8px;
}
.prof-combobox-option i { width: 16px; text-align: center; font-size: 13px; color: #3762c8; flex-shrink: 0; }
[data-theme="dark"] .prof-combobox-option i { color: #7aa3f5; }
.prof-combobox-option:last-child { border-bottom: none; }
.prof-combobox-option:hover, .prof-combobox-option.highlighted { background: rgba(43,108,176,.08); }
.prof-combobox-option.selected-opt { background: rgba(43,108,176,.13); font-weight: 600; color: var(--accent-secondary); }
[data-theme="dark"] .prof-combobox-option.selected-opt { color: #4a8fd8; }
.prof-combobox-no-results { padding: 13px 14px; text-align: center; font-size: 13px; color: var(--text-secondary); opacity: .7; }

/* ══ Reference dropdown: View pill button ══════════════════════════════════ */
.ref-view-btn {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px 4px 9px;
    border: 1.5px solid rgba(55,98,200,.35);
    border-radius: 20px;
    background: rgba(55,98,200,.07);
    color: var(--accent-secondary);
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    letter-spacing: .02em;
    transition: background .17s, border-color .17s, color .17s, box-shadow .17s;
}
.ref-view-btn i { font-size: 10px; }
.ref-view-btn:hover {
    background: var(--accent-secondary);
    border-color: var(--accent-secondary);
    color: #fff;
    box-shadow: 0 3px 10px rgba(55,98,200,.30);
}
[data-theme="dark"] .ref-view-btn {
    border-color: rgba(74,143,216,.4);
    background: rgba(74,143,216,.1);
    color: #7aa3f5;
}
[data-theme="dark"] .ref-view-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}

/* ══ Shared: priority badges + evidence strip (exact citizenreports copy) ══ */
.sched-evidence-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.sched-evidence-thumb {
    width: 80px; height: 80px; border-radius: 10px; object-fit: cover;
    border: 2px solid var(--border-color, rgba(0,0,0,.1));
    cursor: pointer; transition: transform .2s, box-shadow .2s;
}
.sched-evidence-thumb:hover { transform: scale(1.07); box-shadow: 0 4px 14px rgba(55,98,200,.3); }
.sched-priority-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 700;
}
.sched-priority-badge.p-low      { background:#d1fae5; color:#065f46; }
.sched-priority-badge.p-medium   { background:#fef3c7; color:#92400e; }
.sched-priority-badge.p-high     { background:#fde8e8; color:#9b1c1c; }
.sched-priority-badge.p-critical { background:#fce7f3; color:#831843; }
[data-theme="dark"] .sched-priority-badge.p-low      { background:rgba(6,95,70,.3);    color:#6ee7b7; }
[data-theme="dark"] .sched-priority-badge.p-medium   { background:rgba(146,64,14,.3);  color:#fcd34d; }
[data-theme="dark"] .sched-priority-badge.p-high     { background:rgba(155,28,28,.3);  color:#fca5a5; }
[data-theme="dark"] .sched-priority-badge.p-critical { background:rgba(131,24,67,.3);  color:#f9a8d4; }

/* ══ Report View Modal — exact citizenreports.php sched-detail-modal match ══ */
#refReportModalBackdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    z-index: 9800;
    display: none; align-items: center; justify-content: center;
}
#refReportModalBackdrop.active { display: flex; }
#refReportModal {
    background: var(--bg-primary, #fff);
    border-radius: 20px;
    box-shadow: 0 12px 50px var(--shadow-color, rgba(0,0,0,.2));
    width: 92%; max-width: 560px; max-height: 88vh;
    display: flex; flex-direction: column;
    animation: refModalIn .3s cubic-bezier(.34,1.56,.64,1);
    border: 1px solid var(--border-color, rgba(0,0,0,.08));
    overflow: hidden;
}
@keyframes refModalIn {
    from { opacity:0; transform: scale(.9) translateY(-20px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}
/* Band */
.ref-modal-band {
    height: 8px; border-radius: 20px 20px 0 0; width: 100%; flex-shrink: 0;
    background: linear-gradient(90deg,#2e7d32,#66bb6a);
}
.ref-modal-band.status-cancelled { background: linear-gradient(90deg,#c62828,#ef5350); }
/* Header */
.ref-modal-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 18px 24px 14px; gap: 12px;
    border-bottom: 1px solid var(--border-color, rgba(0,0,0,.08));
    background: var(--bg-tertiary, rgba(255,255,255,.9)); flex-shrink: 0;
}
.ref-modal-id {
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary, #555); text-transform: uppercase;
    letter-spacing: .09em; margin-bottom: 3px;
}
.ref-modal-title { font-size: 19px; font-weight: 700; color: var(--text-primary, #1a1a2e); line-height: 1.25; }
.ref-modal-close {
    background: none; border: none; font-size: 26px;
    color: var(--text-secondary, #555); cursor: pointer;
    width: 36px; height: 36px; display: flex; align-items: center;
    justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; margin-top: -2px;
}
.ref-modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
/* Body */
.ref-modal-body {
    padding: 0 24px 20px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07);
}
.ref-modal-body::-webkit-scrollbar { width: 5px; }
.ref-modal-body::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
.ref-modal-body::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }
/* Status pill */
.ref-status-row { padding-top: 16px; margin-bottom: 14px; }
.ref-status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700;
    background: #e8f5e9; color: #2e7d32;
}
.ref-status-pill.cancelled { background: #ffebee; color: #c62828; }
[data-theme="dark"] .ref-status-pill          { background: rgba(76,175,80,0.2);  color: #81c784; }
[data-theme="dark"] .ref-status-pill.cancelled { background: rgba(244,67,54,0.2); color: #e57373; }
/* Fields */
.ref-modal-field        { margin-bottom: 14px; }
.ref-modal-field-label  { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.ref-modal-field-value  { font-size: 14px; color: var(--text-primary, #1a1a2e); line-height: 1.55; }
.ref-modal-divider      { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 14px 0; border: none; }
.ref-modal-grid-2       { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
@media (max-width: 480px) {
    #refReportModal { width: 95%; max-height: 90vh; }
    .ref-modal-header, .ref-modal-body { padding-left: 18px; padding-right: 18px; }
    .ref-modal-grid-2 { grid-template-columns: 1fr; gap: 10px; }
}

/* ── Lightbox for photo previews ── */
#fbkLightboxBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.82);
    z-index: 10500;
    display: none;
    align-items: center;
    justify-content: center;
}
#fbkLightboxBackdrop.active { display: flex; }
#fbkLightboxImg {
    max-width: 92vw;
    max-height: 88vh;
    border-radius: 10px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
    cursor: zoom-out;
}
#fbkLightboxClose {
    position: fixed;
    top: 18px; right: 22px;
    background: rgba(255,255,255,.15);
    border: none;
    color: #fff;
    font-size: 28px;
    width: 42px; height: 42px;
    border-radius: 50%;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
    transition: background .15s;
}
#fbkLightboxClose:hover { background: rgba(255,255,255,.28); }

/* ── Responsive ── */
@media (max-width: 680px) {
    .fbk-card { padding: 22px 18px; }
    .fbk-grid { grid-template-columns: 1fr; }
    .fbk-grid .full { grid-column: 1; }
    .fbk-hero h1 { font-size: 2rem; }
    .map-section { grid-column: 1; }
}

/* ── Footer ── */
.footer {
    width: 100%; padding: 60px 20px 30px;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    border-top: 1px solid var(--border-color);
    box-shadow: 0 -2px 12px var(--shadow-color);
    margin-top: 0; flex-shrink: 0;
}
@media (max-width: 768px) {
    .footer { padding: 40px 20px 20px; }
    .footer-content { grid-template-columns: 1fr !important; gap: 30px !important; margin-bottom: 30px !important; }
    .footer-bottom { flex-direction: column !important; gap: 20px !important; padding-top: 20px !important; margin-top: 20px !important; }
}
</style>
</head>
<body>

<?php showNotification(); ?>

    <!-- DESKTOP NAVIGATION -->
    <header class="nav">
        <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span data-i18n="site_title">InfraGovServices</span>
        </a>
        <div class="nav-center">
            <div class="nav-links">
                <?php if ($show_login): ?>
                <a href="login.php" data-i18n="nav_login">Log in</a>
                <?php endif; ?>
                <a href="citizencimm.php" data-i18n="nav_home">Home</a>
                <a href="citizenreports.php" data-i18n="nav_reports">Reports</a>
                <a href="citizenrepform.php" data-i18n="nav_requests">Requests</a>
                <a href="citizen_feedback.php" class="active" data-i18n="nav_feedback">Feedback</a>
                <a href="about.php" data-i18n="nav_about">About</a>
            </div>
            <div class="nav-divider"></div>
            <div class="nav-actions">
                <div class="desktop-clock" id="desktopClock"></div>
                <button class="translate-btn" id="translateBtn" data-i18n-title="translate_btn_title" title="Translate to Filipino">
                    <span class="globe-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                    </span>
                    <span class="lang-label" id="langLabel" data-i18n="lang_label">EN</span>
                </button>
                <button class="nav-btn dark-mode-btn dark-toggle" id="darkModeBtn" title="Toggle Dark Mode">
                    <span class="dark-icon">🌙</span>
                    <span class="light-icon" style="display: none;">☀️</span>
                </button>
            </div>
        </div>
    </header>

    <!-- MOBILE SIDEBAR -->
    <div class="sidebar-nav" id="sidebarNav">
        <div class="sidebar-top">
            <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
                <img src="assets/img/officiallogo.png" alt="LGU Logo">
                <div class="sidebar-divider logo-divider"></div>
            </a>
            <div class="sidebar-logo-spacer"></div>
            <ul class="nav-list">
                <?php if ($show_login): ?>
                <li><a href="login.php" class="nav-link"><span><i class="fas fa-lock"></i></span><span data-i18n="nav_login">Log in</span></a></li>
                <?php endif; ?>
                <li><a href="citizencimm.php" class="nav-link"><span><i class="fas fa-home"></i></span><span data-i18n="nav_home">Home</span></a></li>
                <li><a href="citizenreports.php" class="nav-link"><span><i class="fas fa-file-alt"></i></span><span data-i18n="nav_reports">Reports</span></a></li>
                <li><a href="citizenrepform.php" class="nav-link"><span><i class="fas fa-clipboard-list"></i></span><span data-i18n="nav_requests">Requests</span></a></li>
                <li><a href="#" class="nav-link active"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
                <li><a href="about.php" class="nav-link"><span><i class="fas fa-info-circle"></i></span><span data-i18n="nav_about">About</span></a></li>
            </ul>
        </div>
    </div>

    <!-- MOBILE TOP NAV -->
    <div class="mobile-top-nav">
        <button class="mobile-toggle" id="mobileToggle">☰</button>
        <button class="mobile-translate-btn" id="mobileTranslateBtn" data-i18n-title="translate_btn_title" title="Translate">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            <span class="mobile-lang-label" id="mobileLangLabel">E</span>
        </button>
        <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
        </a>
        <div class="mobile-clock" id="mobileClock"></div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
    </div>

    <div class="lang-badge" id="langBadge">
        <span class="badge-flag" id="badgeFlag">🇺🇸</span>
        <span id="badgeText">Switched to English</span>
    </div>

<!-- ─── PAGE ─────────────────────────────────────────────────────────────── -->
<div class="feedback-page">

    <!-- Feedback Form Card -->
    <div class="fbk-card">
        <div class="fbk-card-title" data-i18n="fbk_card_title">
            Feedback Form
        </div>

        <form action="handle_feedback.php" method="POST" enctype="multipart/form-data" id="feedbackForm">

            <div class="fbk-grid">

                <!-- ── SECTION: Who are you? ── -->
                <div class="fbk-section-label"><span data-i18n="fbk_section_your_info">Your Information</span> <span style="font-size:10px;font-weight:400;color:#94a3b8;" data-i18n="fbk_name_optional_note">(Name is optional — defaults to "Citizen")</span></div>

                <div class="fbk-group">
                    <label><span data-i18n="fbk_label_fullname">Full Name</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <input type="text" name="full_name" placeholder="e.g. Juan Dela Cruz" data-i18n-placeholder="fbk_fullname_placeholder"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="fbk-group">
                    <label><span data-i18n="fbk_label_contact">Contact Number</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <input type="tel" id="fbkContactNumber" name="contact_number" placeholder="09XX-XXX-XXXX" maxlength="13"
                           value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
                </div>

                <div class="fbk-group full">
                    <label><span data-i18n="fbk_label_email">Email Address</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <input type="email" name="email" placeholder="your@email.com" data-i18n-placeholder="fbk_email_placeholder"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <small style="color:#94a3b8;font-size:11px;margin-top:5px;display:block;" data-i18n="fbk_email_hint">📧 If provided, we'll send you notification on your feedback.</small>
                </div>

                <!-- ── SECTION: Feedback Details ── -->
                <div class="fbk-section-label" data-i18n="fbk_section_details">Feedback Details</div>

                <div class="fbk-group full">
                    <label data-i18n="fbk_label_type">Type of Feedback</label>
                    <div class="fbk-type-wrap">
                        <?php
                        $fbkTypes = [
                            'Concern'         => ['icon' => '⚠️', 'label' => 'Concern',         'i18n' => 'fbk_type_concern'],
                            'Acknowledgement' => ['icon' => '👍', 'label' => 'Acknowledgement', 'i18n' => 'fbk_type_acknowledgement'],
                            'Improvement'     => ['icon' => '💡', 'label' => 'Improvement',     'i18n' => 'fbk_type_improvement'],
                            'Complaint'       => ['icon' => '📢', 'label' => 'Complaint',        'i18n' => 'fbk_type_complaint'],
                            'Suggestion'      => ['icon' => '✏️', 'label' => 'Suggestion',       'i18n' => 'fbk_type_suggestion'],
                        ];
                        $selectedType = $_POST['feedback_type'] ?? 'Concern';
                        foreach ($fbkTypes as $type => $meta): ?>
                        <input type="radio" name="feedback_type" id="ftype_<?= strtolower($type) ?>"
                               value="<?= $type ?>" <?= $selectedType === $type ? 'checked' : '' ?>>
                        <label for="ftype_<?= strtolower($type) ?>">
                            <span class="fbk-type-icon"><?= $meta['icon'] ?></span>
                            <span data-i18n="<?= $meta['i18n'] ?>"><?= $meta['label'] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fbk-group full">
                    <label><span data-i18n="fbk_label_title">Feedback Title</span> <span style="color:#ef4444">*</span></label>
                    <input type="text" name="title" placeholder="Brief title of your feedback" data-i18n-placeholder="fbk_title_placeholder"
                           required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                </div>

                <div class="fbk-group full">
                    <label><span data-i18n="fbk_label_description">Description</span> <span style="color:#ef4444">*</span></label>
                    <textarea name="description" placeholder="Please provide detailed feedback…" data-i18n-placeholder="fbk_description_placeholder" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="fbk-group">
                    <label><span data-i18n="fbk_label_rating">Star Rating</span> <span style="color:#ef4444">*</span> <span class="optional" data-i18n="fbk_rating_hint">hover left/right half of a star for .5 values</span></label>
                    <div class="star-rating-outer">
                        <input type="hidden" name="rating" id="ratingVal" value="<?= htmlspecialchars($_POST['rating'] ?? '3') ?>">
                        <div class="hsr-wrap" id="hsrWrap">
                            <span class="hsr-star" data-star="1" data-fill="full"></span>
                            <span class="hsr-star" data-star="2" data-fill="full"></span>
                            <span class="hsr-star" data-star="3" data-fill="full"></span>
                            <span class="hsr-star" data-star="4" data-fill="empty"></span>
                            <span class="hsr-star" data-star="5" data-fill="empty"></span>
                        </div>
                        <div class="star-rating-bar">
                            <div class="star-rating-bar-track">
                                <div class="star-rating-bar-fill" id="starBarFill"></div>
                            </div>
                            <span class="star-hint" id="starHint">3 / 5 — Average</span>
                        </div>
                    </div>
                </div>

                <!-- ── SECTION: Where / What ── -->
                <div class="fbk-section-label" data-i18n="fbk_section_infra_location">Infrastructure & Location</div>

                <div class="fbk-group">
                    <label><span data-i18n="fbk_label_infrastructure">Infrastructure / Facility</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <input type="hidden" id="infraVal" name="infrastructure" value="<?= htmlspecialchars($_POST['infrastructure'] ?? '') ?>">
                    <div class="prof-combobox" id="cbInfra">
                        <div class="prof-combobox-display" id="cbInfraDisplay">
                            <span class="prof-combobox-label <?= !empty($_POST['infrastructure']) ? 'selected' : '' ?>" id="cbInfraLabel">
                                <?= !empty($_POST['infrastructure']) ? htmlspecialchars($_POST['infrastructure']) : '— Select infrastructure —' ?>
                            </span>
                            <span class="prof-combobox-arrow">▾</span>
                        </div>
                        <div class="prof-combobox-dropdown" id="cbInfraDropdown">
                            <input class="prof-combobox-search" type="text" placeholder="🔍 Search…" data-i18n-placeholder="infra_search_placeholder" autocomplete="off">
                            <div class="prof-combobox-list">
                                <div class="prof-combobox-option" data-value="Roads"><i class="fas fa-road"></i> <span data-i18n="infra_roads">Roads</span></div>
                                <div class="prof-combobox-option" data-value="Street Lights"><i class="fas fa-lightbulb"></i> <span data-i18n="infra_street_lights">Street Lights</span></div>
                                <div class="prof-combobox-option" data-value="Drainage"><i class="fas fa-water"></i> <span data-i18n="infra_drainage">Drainage</span></div>
                                <div class="prof-combobox-option" data-value="Public Facilities"><i class="fas fa-landmark"></i> <span data-i18n="infra_public_facilities">Public Facilities</span></div>
                                <div class="prof-combobox-option" data-value="Water Supply"><i class="fas fa-faucet"></i> <span data-i18n="infra_water_supply">Water Supply</span></div>
                                <div class="prof-combobox-option" data-value="Electrical"><i class="fas fa-bolt"></i> <span data-i18n="infra_electrical">Electrical</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fbk-group">
                    <label><span data-i18n="fbk_label_ref_report">Reference Completed Report</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <input type="hidden" id="refRepIdVal" name="rep_id" value="">
                    <div class="prof-combobox" id="cbRef">
                        <div class="prof-combobox-display" id="cbRefDisplay">
                            <span class="prof-combobox-label" id="cbRefLabel" data-i18n="fbk_ref_none">— None —</span>
                            <span class="prof-combobox-arrow">▾</span>
                        </div>
                        <div class="prof-combobox-dropdown" id="cbRefDropdown">
                            <input class="prof-combobox-search" type="text" placeholder="🔍 Search report…" data-i18n-placeholder="fbk_ref_search_placeholder" autocomplete="off">
                            <div class="prof-combobox-list">
                                <div class="prof-combobox-option" data-value="" data-i18n="fbk_ref_none">— None —</div>
                                <?php foreach ($archiveReports as $ar): ?>
                                <div class="prof-combobox-option ref-report-option"
                                     data-value="<?= $ar['rep_id'] ?>"
                                     data-label="#REP-<?= str_pad($ar['rep_id'], 3, '0', STR_PAD_LEFT) ?>"
                                     style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                    <span style="display:flex;align-items:center;gap:7px;flex:1;min-width:0;">
                                        <i class="fas fa-file-alt"></i>
                                        <span>#REP-<?= str_pad($ar['rep_id'], 3, '0', STR_PAD_LEFT) ?></span>
                                    </span>
                                    <button type="button" class="ref-view-btn"
                                            onclick="event.stopPropagation();openRefReportModal(<?= $ar['rep_id'] ?>)"
                                            title="View this report">
                                        <i class="fas fa-eye"></i> <span data-i18n="fbk_ref_view_btn">View</span>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address with Leaflet map -->
                <div class="fbk-group full map-section">
                    <label><span data-i18n="fbk_label_address">Address / Location</span> <span class="optional" data-i18n="fbk_optional">optional</span></label>
                    <div style="position:relative;">
                        <input type="text" name="address" id="addressInput"
                               placeholder="Enter address or pick on map…"
                               data-i18n-placeholder="fbk_address_placeholder"
                               style="padding-right:46px;"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        <button type="button" onclick="openMapModal()" title="Pick on Map"
                            data-i18n-title="fbk_pick_on_map"
                            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                   background:#2b6cb0;border:none;color:#fff;width:32px;height:32px;
                                   border-radius:50%;cursor:pointer;font-size:15px;
                                   display:flex;align-items:center;justify-content:center;
                                   box-shadow:0 2px 8px rgba(0,0,0,.25);transition:background .2s;">
                            <i class="fas fa-map-marker-alt"></i>
                        </button>
                    </div>
                    <input type="hidden" name="coord_lat" id="coordLat">
                    <input type="hidden" name="coord_lng" id="coordLng">
                    <span class="map-coords-badge" id="coordsBadge" style="margin-top:6px;display:none;" data-i18n="fbk_coords_badge">📍 Location pinned</span>
                </div>

                <!-- ── SECTION: Photo Evidence ── -->
                <div class="fbk-section-label"><span data-i18n="fbk_section_photos">Photo Evidence</span> <span style="font-size:10px;font-weight:400;color:#94a3b8;" data-i18n="fbk_photos_note">(optional — max 5 photos)</span></div>

                <div class="fbk-group full">
                    <div class="photo-drop-zone" id="photoDropZone">
                        <input type="file" name="photos[]" id="photoInput"
                               accept="image/*" multiple>
                        <div class="photo-drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="photo-drop-text" data-i18n="fbk_photo_drop_text">Click or drag photos here</div>
                        <div class="photo-drop-hint" data-i18n="fbk_photo_drop_hint">JPG, PNG, WEBP — max 5 photos, 5 MB each</div>
                    </div>
                    <div class="photo-preview-grid" id="photoPreviewGrid"></div>
                </div>

                <!-- ── Submit ── -->
                <div class="fbk-submit-row">
                    <button type="button" class="btn-fbk-submit" id="submitBtn" onclick="openFbkSubmitModal()">
                        <i class="fas fa-paper-plane"></i> <span data-i18n="fbk_submit_btn">Submit Feedback</span>
                    </button>
                </div>

            </div><!-- /.fbk-grid -->
        </form>
    </div><!-- /.fbk-card -->

</div><!-- /.feedback-page -->

<!-- ─── SUBMIT CONFIRMATION MODAL ─────────────────────────────────────────── -->
<div id="submitAlertBackdrop">
    <div id="submitAlertModal">
        <div class="icon-wrap"><span class="icon">📬</span></div>
        <div class="alert-title" data-i18n="fbk_submit_modal_title">Confirm Submission</div>
        <div class="alert-desc" data-i18n="fbk_submit_modal_desc">Are you sure you want to submit your feedback? You won't be able to edit it after submission.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" type="button" onclick="closeFbkSubmitModal()" data-i18n="fbk_submit_modal_cancel">Cancel</button>
            <button class="alert-btn confirm" type="button" id="fbkConfirmBtn" data-i18n="fbk_submit_modal_confirm">Submit</button>
        </div>
    </div>
</div>

<!-- ─── REPORT VIEW MODAL ──────────────────────────────────────────────────── -->
<div id="refReportModalBackdrop">
    <div id="refReportModal">
        <div class="ref-modal-band" id="refModalBand"></div>
        <div class="ref-modal-header">
            <div>
                <div class="ref-modal-id"   id="refModalId"></div>
                <div class="ref-modal-title" id="refModalTitle"></div>
            </div>
            <button class="ref-modal-close" onclick="closeRefReportModal()">×</button>
        </div>
        <div class="ref-modal-body">
            <div class="ref-status-row">
                <span class="ref-status-pill" id="refModalStatus"></span>
            </div>

            <div class="ref-modal-field">
                <div class="ref-modal-field-label" data-i18n="modal_location">📍 Location</div>
                <div class="ref-modal-field-value" id="refModalLocation"></div>
            </div>

            <div class="ref-modal-field" id="refModalIssueFld">
                <div class="ref-modal-field-label" data-i18n="modal_issue_notes">📝 Issue / Notes</div>
                <div class="ref-modal-field-value" id="refModalIssue"></div>
            </div>

            <div class="ref-modal-divider"></div>

            <div class="ref-modal-grid-2">
                <div class="ref-modal-field">
                    <div class="ref-modal-field-label" data-i18n="modal_priority">🚦 Priority</div>
                    <div class="ref-modal-field-value" id="refModalPriority"></div>
                </div>
                <div class="ref-modal-field">
                    <div class="ref-modal-field-label" data-i18n="modal_budget">💰 Budget</div>
                    <div class="ref-modal-field-value" id="refModalBudget"></div>
                </div>
                <div class="ref-modal-field">
                    <div class="ref-modal-field-label" data-i18n="modal_start_date">📅 Start Date</div>
                    <div class="ref-modal-field-value" id="refModalStart"></div>
                </div>
                <div class="ref-modal-field">
                    <div class="ref-modal-field-label" data-i18n="modal_est_completion">🏁 Est. Completion</div>
                    <div class="ref-modal-field-value" id="refModalEnd"></div>
                </div>
            </div>

            <div id="refModalEvidenceFld" style="display:none;">
                <div class="ref-modal-divider"></div>
                <div class="ref-modal-field">
                    <div class="ref-modal-field-label" data-i18n="modal_evidence_photos">🖼️ Evidence Photos</div>
                    <div class="sched-evidence-strip" id="refModalEvidenceStrip"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─── PHOTO LIGHTBOX ─────────────────────────────────────────────────────── -->
<div id="fbkLightboxBackdrop">
    <button id="fbkLightboxClose" onclick="closeFbkLightbox()" title="Close">×</button>
    <img id="fbkLightboxImg" src="" alt="Full image" onclick="closeFbkLightbox()">
</div>

<!-- ─── MAP MODAL (full-screen, mirrors profile.php) ───────────────────────── -->
<div id="fbkMapBackdrop">
    <div id="fbkMapModal">
        <!-- Header: GPS (left) | Title (center) | Layer toggle (right) -->
        <div class="fbk-map-header">
            <button type="button" id="fbkMapGpsBtn" data-i18n-title="map_gps_title" title="Use my current location">📍</button>
            <h3 data-i18n="fbk_map_title">📍 Pick Your Location</h3>
            <button type="button" id="fbkMapLayerToggle">🛰 Satellite</button>
        </div>
        <!-- Search + detected-address inputs -->
        <div class="fbk-map-address-input">
            <div class="fbk-map-search-wrap">
                <input type="text" id="fbkMapSearchInput"
                    placeholder="🔍 Search any address or place…"
                    data-i18n-placeholder="fbk_map_search_placeholder"
                    autocomplete="off">
                <button type="button" id="fbkMapSearchClearBtn" title="Clear search">✕</button>
                <div id="fbkMapSearchDropdown">
                    <div class="fbk-map-search-spinner" id="fbkMapSearchSpinner" data-i18n="fbk_map_searching">Searching…</div>
                </div>
            </div>
            <input type="text" id="fbkMapAddrField"
                placeholder="Move the pin or search to detect address…"
                data-i18n-placeholder="fbk_map_addr_placeholder"
                readonly>
        </div>
        <!-- Map wrapper -->
        <div id="fbkMapWrapper">
            <div id="fbkLeafletMap"></div>
        </div>
        <!-- Actions -->
        <div class="fbk-map-actions">
            <button type="button" class="btn-cancel" onclick="closeFbkMap()" data-i18n="fbk_map_cancel">Cancel</button>
            <button type="button" class="btn-save" id="fbkMapSaveBtn" onclick="saveFbkMap()" data-i18n="fbk_map_save">Use This Address</button>
        </div>
    </div>
</div>

<!-- ─── SCRIPTS ────────────────────────────────────────────────────────────── -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Reference report view modal ───────────────────────────────────────────────
(function(){
    var REF_REPORTS = <?php
        $refModalData = [];
        foreach ($archiveReports as $ar) {
            $refModalData[] = [
                'rep_id'          => $ar['rep_id'],
                'infrastructure'  => $ar['infrastructure'],
                'location'        => $ar['location'],
                'issue'           => $ar['issue'],
                'status'          => $ar['status'],
                'priority'        => $ar['priority'],
                'budget'          => $ar['budget'],
                'start'           => $ar['start'],
                'end'             => $ar['end'],
                'evidence_images' => $ar['evidence_images'],
            ];
        }
        echo json_encode($refModalData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>;

    var PRIORITY_CLASS = { 'Low':'p-low','Medium':'p-medium','High':'p-high','Critical':'p-critical' };

    window.openRefReportModal = function(repId) {
        // Close any open combobox dropdown first
        document.querySelectorAll('.prof-combobox-dropdown.open').forEach(function(d){
            d.classList.remove('open');
        });
        document.querySelectorAll('.prof-combobox-display.open').forEach(function(d){
            d.classList.remove('open');
        });

        var rec = null;
        for (var i = 0; i < REF_REPORTS.length; i++) {
            if (REF_REPORTS[i].rep_id === repId) { rec = REF_REPORTS[i]; break; }
        }
        if (!rec) return;

        var isCancelled = rec.status === 'Cancelled';

        // Band + header
        document.getElementById('refModalBand').className = 'ref-modal-band' + (isCancelled ? ' status-cancelled' : '');
        document.getElementById('refModalId').textContent    = '#REP-' + String(rec.rep_id).padStart(3,'0');
        document.getElementById('refModalTitle').textContent = rec.infrastructure || '—';

        // Status pill
        var lang = localStorage.getItem('lang') || 'en';
        var tr = (window.__preloadedTranslations && window.__preloadedTranslations[lang]) || {};
        var pill = document.getElementById('refModalStatus');
        pill.textContent = isCancelled
            ? (tr['modal_status_cancelled'] || '❌ Cancelled')
            : (tr['modal_status_completed'] || '✅ Completed');
        pill.className   = 'ref-status-pill' + (isCancelled ? ' cancelled' : '');

        // Location
        document.getElementById('refModalLocation').textContent = rec.location || '—';

        // Issue / Notes
        var issueFld = document.getElementById('refModalIssueFld');
        if (rec.issue && rec.issue.trim()) {
            document.getElementById('refModalIssue').textContent = rec.issue;
            issueFld.style.display = '';
        } else {
            issueFld.style.display = 'none';
        }

        // Priority
        var prioEl = document.getElementById('refModalPriority');
        if (rec.priority) {
            var pClass = PRIORITY_CLASS[rec.priority] || 'p-low';
            prioEl.innerHTML = '<span class="sched-priority-badge ' + pClass + '">' + rec.priority + '</span>';
        } else {
            prioEl.textContent = 'Not specified';
        }

        // Budget
        document.getElementById('refModalBudget').textContent = rec.budget
            ? '₱' + parseFloat(rec.budget).toLocaleString('en-PH', {minimumFractionDigits:2})
            : '—';

        // Dates
        document.getElementById('refModalStart').textContent = rec.start || '—';
        document.getElementById('refModalEnd').textContent   = rec.end   || '—';

        // Evidence images
        var evidFld   = document.getElementById('refModalEvidenceFld');
        var evidStrip = document.getElementById('refModalEvidenceStrip');
        var imgs = rec.evidence_images || [];
        if (imgs.length > 0) {
            evidStrip.innerHTML = '';
            imgs.forEach(function(src) {
                var img = document.createElement('img');
                img.src = src;
                img.alt = 'Evidence';
                img.className = 'sched-evidence-thumb';
                img.style.cursor = 'zoom-in';
                img.onclick = function(){ openFbkLightbox(src); };
                evidStrip.appendChild(img);
            });
            evidFld.style.display = '';
        } else {
            evidFld.style.display = 'none';
        }

        document.getElementById('refReportModalBackdrop').classList.add('active');
    };
    window.closeRefReportModal = function() {
        document.getElementById('refReportModalBackdrop').classList.remove('active');
    };
    document.getElementById('refReportModalBackdrop').addEventListener('click', function(e){
        if (e.target === this) window.closeRefReportModal();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') window.closeRefReportModal();
    });
})();

// ── Photo lightbox ────────────────────────────────────────────────────────────
function openFbkLightbox(src) {
    document.getElementById('fbkLightboxImg').src = src;
    document.getElementById('fbkLightboxBackdrop').classList.add('active');
}
function closeFbkLightbox() {
    document.getElementById('fbkLightboxBackdrop').classList.remove('active');
    document.getElementById('fbkLightboxImg').src = '';
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeFbkLightbox();
});

// ── Dark mode toggle ──────────────────────────────────────────────────────────
(function(){
    const btn = document.getElementById('darkModeToggle');
    if (!btn) return;
    function applyTheme(t) {
        if (t === 'dark') {
            document.documentElement.setAttribute('data-theme','dark');
            btn.querySelector('.dark-icon').style.display  = 'none';
            btn.querySelector('.light-icon').style.display = '';
        } else {
            document.documentElement.removeAttribute('data-theme');
            btn.querySelector('.dark-icon').style.display  = '';
            btn.querySelector('.light-icon').style.display = 'none';
        }
        localStorage.setItem('theme', t);
    }
    applyTheme(localStorage.getItem('theme') || 'light');
    btn.addEventListener('click', function(){
        applyTheme(localStorage.getItem('theme') === 'dark' ? 'light' : 'dark');
    });
})();

// ── Half-star rating ─────────────────────────────────────────────────────────
(function(){
    var HINTS = {
        0.5:'Very Poor', 1:'Very Poor', 1.5:'Poor', 2:'Poor',
        2.5:'Below Average', 3:'Average', 3.5:'Above Average',
        4:'Good', 4.5:'Very Good', 5:'Excellent'
    };
    var COLORS = {
        0.5:'#ef4444', 1:'#ef4444', 1.5:'#f97316', 2:'#f97316',
        2.5:'#f59e0b', 3:'#f59e0b', 3.5:'#84cc16',
        4:'#22c55e', 4.5:'#16a34a', 5:'#16a34a'
    };
    var hiddenInput = document.getElementById('ratingVal');
    var barFill     = document.getElementById('starBarFill');
    var hintEl      = document.getElementById('starHint');
    var stars       = Array.from(document.querySelectorAll('#hsrWrap .hsr-star'));
    var wrap        = document.getElementById('hsrWrap');
    if (!wrap || !stars.length) return;

    var currentRating = parseFloat(hiddenInput ? hiddenInput.value : '3') || 3;

    function renderStars(val) {
        stars.forEach(function(star) {
            var n = parseFloat(star.dataset.star);
            if (val >= n)           star.dataset.fill = 'full';
            else if (val >= n - 0.5) star.dataset.fill = 'half';
            else                    star.dataset.fill = 'empty';
        });
    }

    var HINTS_KEYS = {
        0.5:'fbk_rating_very_poor',     1:'fbk_rating_very_poor',
        1.5:'fbk_rating_poor',          2:'fbk_rating_poor',
        2.5:'fbk_rating_below_average', 3:'fbk_rating_average',
        3.5:'fbk_rating_above_average', 4:'fbk_rating_good',
        4.5:'fbk_rating_very_good',     5:'fbk_rating_excellent'
    };

    function updateBar(val) {
        var col = COLORS[val] || '#f59e0b';
        if (barFill) {
            barFill.style.width      = (val / 5 * 100) + '%';
            barFill.style.background = 'linear-gradient(90deg,' + col + ',' + col + 'cc)';
        }
        if (hintEl) {
            var lang = localStorage.getItem('lang') || 'en';
            var tr = (window.__preloadedTranslations && window.__preloadedTranslations[lang]) || {};
            var hintKey  = HINTS_KEYS[val];
            var hintText = (hintKey && tr[hintKey]) ? tr[hintKey] : (HINTS[val] || '');
            hintEl.textContent = val + ' / 5 — ' + hintText;
        }
    }

    function getVal(star, e) {
        var rect = star.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var n = parseFloat(star.dataset.star);
        return x < rect.width / 2 ? n - 0.5 : n;
    }

    stars.forEach(function(star) {
        star.addEventListener('mousemove', function(e) {
            renderStars(getVal(star, e));
        });
        star.addEventListener('click', function(e) {
            currentRating = getVal(star, e);
            if (hiddenInput) hiddenInput.value = currentRating;
            renderStars(currentRating);
            updateBar(currentRating);
            try { localStorage.setItem('fbk_rating', currentRating); } catch(ex){}
        });
    });

    wrap.addEventListener('mouseleave', function() {
        renderStars(currentRating);
    });

    // Initialise display
    renderStars(currentRating);
    updateBar(currentRating);
})();

// ── Submit confirmation modal ─────────────────────────────────────────────────
function openFbkSubmitModal() {
    const title = document.querySelector('input[name="title"]').value.trim();
    const desc  = document.querySelector('textarea[name="description"]').value.trim();
    if (!title || !desc) {
        // Let native validation fire
        document.getElementById('feedbackForm').reportValidity();
        return;
    }
    document.getElementById('submitAlertBackdrop').classList.add('active');
}
function closeFbkSubmitModal() {
    document.getElementById('submitAlertBackdrop').classList.remove('active');
}
document.getElementById('fbkConfirmBtn').addEventListener('click', function(){
    closeFbkSubmitModal();
    // Clear all draft data from localStorage before submitting
    var keys = Object.keys(localStorage).filter(function(k){ return k.startsWith('fbk_'); });
    keys.forEach(function(k){ localStorage.removeItem(k); });
    document.getElementById('feedbackForm').submit();
});
document.getElementById('submitAlertBackdrop').addEventListener('click', function(e){
    if (e.target === this) closeFbkSubmitModal();
});

// ── Prof-combobox engine (shared for infra + reference dropdowns) ─────────────
function initProfCombobox(opts) {
    var displayEl   = document.getElementById(opts.displayId);
    var dropdownEl  = document.getElementById(opts.dropdownId);
    var hiddenEl    = document.getElementById(opts.hiddenId);
    var labelEl     = document.getElementById(opts.labelId);
    if (!displayEl || !dropdownEl) return;

    // Move dropdown to body so backdrop-filter on card doesn't trap it
    document.body.appendChild(dropdownEl);

    var searchEl    = dropdownEl.querySelector('.prof-combobox-search');
    var listEl      = dropdownEl.querySelector('.prof-combobox-list');
    var allOptions  = Array.from(listEl.querySelectorAll('.prof-combobox-option'));
    var isOpen      = false;
    var placeholder = opts.placeholder || '— Select —';

    function positionDropdown() {
        var rect = displayEl.getBoundingClientRect();
        var vh = window.innerHeight;
        dropdownEl.style.width = rect.width + 'px';
        dropdownEl.style.visibility = 'hidden';
        dropdownEl.style.display = 'block';
        var dh = dropdownEl.offsetHeight || 280;
        dropdownEl.style.display = '';
        dropdownEl.style.visibility = '';
        var top = rect.bottom + 4;
        if (top + dh > vh - 12 && rect.top > dh + 12) top = rect.top - dh - 4;
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8));
        dropdownEl.style.position = 'fixed';
        dropdownEl.style.top  = top + 'px';
        dropdownEl.style.left = left + 'px';
    }
    function filterOptions(q) {
        var vis = 0;
        allOptions.forEach(function(o) {
            var match = o.textContent.toLowerCase().includes(q.toLowerCase());
            o.style.display = match ? '' : 'none';
            if (match) vis++;
        });
        var nr = listEl.querySelector('.prof-combobox-no-results');
        if (!nr) { nr = document.createElement('div'); nr.className='prof-combobox-no-results'; nr.textContent='No results found'; listEl.appendChild(nr); }
        nr.style.display = vis === 0 ? '' : 'none';
    }
    function openDropdown() {
        isOpen = true;
        positionDropdown();
        displayEl.classList.add('open');
        dropdownEl.classList.add('open');
        if (searchEl) { searchEl.value = ''; filterOptions(''); }
        setTimeout(function(){ if (searchEl) searchEl.focus(); }, 30);
    }
    function closeDropdown() {
        isOpen = false;
        displayEl.classList.remove('open');
        dropdownEl.classList.remove('open');
        if (searchEl) { searchEl.value = ''; filterOptions(''); }
    }
    function selectOption(value, text) {
        hiddenEl.value = value;
        labelEl.textContent = text || placeholder;
        labelEl.classList.toggle('selected', !!value);
        allOptions.forEach(function(o){ o.classList.toggle('selected-opt', o.dataset.value === value); });
        // Persist to localStorage
        if (opts.storageKey) localStorage.setItem(opts.storageKey, value);
        if (opts.storageLabelKey) localStorage.setItem(opts.storageLabelKey, text || placeholder);
        closeDropdown();
    }
    displayEl.addEventListener('click', function(){ isOpen ? closeDropdown() : openDropdown(); });
    if (searchEl) searchEl.addEventListener('input', function(){ filterOptions(this.value); });
    allOptions.forEach(function(o){
        o.addEventListener('click', function(ev){
            // Don't trigger selection if clicking the View button inside the option
            if (ev.target.closest && ev.target.closest('.ref-view-btn')) return;
            var label = o.dataset.label || o.textContent.trim();
            selectOption(o.dataset.value, label);
        });
    });
    document.addEventListener('click', function(e){
        if (!displayEl.contains(e.target) && !dropdownEl.contains(e.target)) closeDropdown();
    });
    window.addEventListener('scroll', function(){ if (isOpen) positionDropdown(); }, true);
    window.addEventListener('resize', function(){ if (isOpen) positionDropdown(); });

    // Restore from localStorage
    if (opts.storageKey) {
        var savedVal   = localStorage.getItem(opts.storageKey);
        var savedLabel = opts.storageLabelKey ? localStorage.getItem(opts.storageLabelKey) : null;
        if (savedVal !== null && savedVal !== '') {
            hiddenEl.value = savedVal;
            labelEl.textContent = savedLabel || savedVal;
            labelEl.classList.add('selected');
            allOptions.forEach(function(o){ o.classList.toggle('selected-opt', o.dataset.value === savedVal); });
        }
    }
}

// Init infrastructure combobox
initProfCombobox({ displayId:'cbInfraDisplay', dropdownId:'cbInfraDropdown', hiddenId:'infraVal', labelId:'cbInfraLabel', placeholder:'— Select infrastructure —', storageKey:'fbk_infrastructure', storageLabelKey:'fbk_infrastructure_label' });
// Init reference report combobox
initProfCombobox({ displayId:'cbRefDisplay', dropdownId:'cbRefDropdown', hiddenId:'refRepIdVal', labelId:'cbRefLabel', placeholder:'— None —', storageKey:'fbk_rep_id', storageLabelKey:'fbk_rep_id_label' });

// ── i18nReady: update combobox placeholders + star hint labels on language change ──
document.addEventListener('i18nReady', function(e) {
    var lang = e.detail && e.detail.lang ? e.detail.lang : (localStorage.getItem('lang') || 'en');
    var tr = window.__preloadedTranslations;
    if (!tr || !tr[lang]) return;
    var t = tr[lang];

    // Combobox placeholder for Infrastructure (only when nothing selected)
    var infraHidden = document.getElementById('infraVal');
    var infraLabel  = document.getElementById('cbInfraLabel');
    if (infraLabel && infraHidden && !infraHidden.value) {
        infraLabel.textContent = t['form_infra_placeholder'] || '— Select infrastructure —';
    }

    // Combobox placeholder for Reference Report (only when nothing selected)
    var refHidden = document.getElementById('refRepIdVal');
    var refLabel  = document.getElementById('cbRefLabel');
    if (refLabel && refHidden && !refHidden.value) {
        refLabel.textContent = t['fbk_ref_none'] || '— None —';
    }

    // Star hint labels
    var HINTS_EN = { 0.5:'Very Poor', 1:'Very Poor', 1.5:'Poor', 2:'Poor', 2.5:'Below Average', 3:'Average', 3.5:'Above Average', 4:'Good', 4.5:'Very Good', 5:'Excellent' };
    var HINTS_TL = { 0.5:'Napaka-Sama', 1:'Napaka-Sama', 1.5:'Mahina', 2:'Mahina', 2.5:'Mas Mababa sa Average', 3:'Katamtaman', 3.5:'Mas Mataas sa Average', 4:'Maganda', 4.5:'Napakaganda', 5:'Napakahusay' };
    var HINTS_KEYS = { 0.5:'fbk_rating_very_poor', 1:'fbk_rating_very_poor', 1.5:'fbk_rating_poor', 2:'fbk_rating_poor', 2.5:'fbk_rating_below_average', 3:'fbk_rating_average', 3.5:'fbk_rating_above_average', 4:'fbk_rating_good', 4.5:'fbk_rating_very_good', 5:'fbk_rating_excellent' };
    var ratingVal = parseFloat(document.getElementById('ratingVal') ? document.getElementById('ratingVal').value : '3') || 3;
    var hintEl = document.getElementById('starHint');
    if (hintEl) {
        var hintKey  = HINTS_KEYS[ratingVal];
        var hintText = hintKey && t[hintKey] ? t[hintKey] : (lang === 'tl' ? (HINTS_TL[ratingVal] || '') : (HINTS_EN[ratingVal] || ''));
        hintEl.textContent = ratingVal + ' / 5 — ' + hintText;
    }

    // Report view modal status pill — re-translate if modal is open
    var openPill = document.querySelector('#refReportModalBackdrop.active .ref-status-pill');
    if (openPill) {
        var isCancelled = openPill.classList.contains('cancelled');
        openPill.textContent = isCancelled
            ? (t['modal_status_cancelled'] || '❌ Cancelled')
            : (t['modal_status_completed'] || '✅ Completed');
    }

    // Map layer toggle label (satellite/street)
    var layerToggle = document.getElementById('fbkMapLayerToggle');
    if (layerToggle) {
        // Preserve current state — text depends on whether satellite is currently active
        var isSat = layerToggle.dataset.isSatellite !== 'false';
        layerToggle.textContent = isSat
            ? (t['map_layer_toggle_street'] || '🗺️ Street')
            : (t['map_layer_toggle_satellite'] || '🛰️ Satellite');
    }
});

// ── Reference report clear (legacy, now handled by combobox) ──────────────────
function toggleRefClear() {}
function clearRefSelect() {}

// ── Contact number auto-format ─────────────────────────────────────────────────
(function(){
    var phoneInput = document.getElementById('fbkContactNumber');
    if (!phoneInput) return;
    phoneInput.addEventListener('input', function(e) {
        var input     = e.target;
        var cursorPos = input.selectionStart;
        var digits    = input.value.replace(/\D/g, '').slice(0, 11);
        var formatted = digits.length <= 4 ? digits
                      : digits.length <= 7 ? digits.slice(0,4)+'-'+digits.slice(4)
                      : digits.slice(0,4)+'-'+digits.slice(4,7)+'-'+digits.slice(7);
        var digitsBeforeCursor = input.value.slice(0, cursorPos).replace(/\D/g,'').length;
        input.value = formatted;
        var newCursor = 0, digitCount = 0;
        for (var i = 0; i < formatted.length; i++) {
            if (/\d/.test(formatted[i])) digitCount++;
            if (digitCount === digitsBeforeCursor) { newCursor = i + 1; break; }
        }
        input.setSelectionRange(newCursor, newCursor);
        localStorage.setItem('fbk_contact_number', input.value);
    });
    // Restore from localStorage
    var saved = localStorage.getItem('fbk_contact_number');
    if (saved) phoneInput.value = saved;
})();

// ── localStorage draft save / restore ─────────────────────────────────────────
(function(){
    var form = document.getElementById('feedbackForm');
    if (!form) return;
    // Simple text/textarea/select inputs (not file, not hidden, not radio)
    var textInputs = form.querySelectorAll('input:not([type=file]):not([type=hidden]):not([type=radio]):not([type=tel]), textarea');
    textInputs.forEach(function(input) {
        var key = 'fbk_' + (input.name || input.id);
        var saved = localStorage.getItem(key);
        if (saved !== null) input.value = saved;
        input.addEventListener('input', function() {
            localStorage.setItem(key, input.value);
        });
    });
    // Rating restore (hidden input + half-star widget)
    var savedRating = localStorage.getItem('fbk_rating');
    if (savedRating !== null) {
        var ratingInput = document.getElementById('ratingVal');
        if (ratingInput) ratingInput.value = savedRating;
        // The half-star widget reads ratingVal on init, so no extra dispatch needed
    }
    // Feedback type radio buttons
    var savedType = localStorage.getItem('fbk_feedback_type');
    if (savedType) {
        var typeRadio = form.querySelector('input[name="feedback_type"][value="' + savedType + '"]');
        if (typeRadio) typeRadio.checked = true;
    }
    form.querySelectorAll('input[name="feedback_type"]').forEach(function(r) {
        r.addEventListener('change', function() { localStorage.setItem('fbk_feedback_type', r.value); });
    });
    // Address field restoration
    var savedAddr = localStorage.getItem('fbk_address');
    if (savedAddr) {
        var addrInput = document.getElementById('addressInput');
        if (addrInput && !addrInput.value) addrInput.value = savedAddr;
    }
    var savedLat = localStorage.getItem('fbk_coord_lat');
    var savedLng = localStorage.getItem('fbk_coord_lng');
    if (savedLat && savedLng) {
        document.getElementById('coordLat').value = savedLat;
        document.getElementById('coordLng').value = savedLng;
        var badge = document.getElementById('coordsBadge');
        if (badge) badge.style.display = 'inline-block';
    }
})();

// ── Photo upload preview ──────────────────────────────────────────────────────
(function(){
    const input   = document.getElementById('photoInput');
    const grid    = document.getElementById('photoPreviewGrid');
    const zone    = document.getElementById('photoDropZone');
    const MAX     = 5;
    let fileList  = [];

    function renderPreviews() {
        grid.innerHTML = '';
        var noneLabel = document.getElementById('photoNoneLabel');
        if (noneLabel) noneLabel.style.display = fileList.length ? 'none' : 'block';
        fileList.forEach(function(f, i) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const src  = e.target.result;
                const wrap = document.createElement('div');
                wrap.className = 'photo-thumb-wrap';
                const img = document.createElement('img');
                img.src   = src;
                img.alt   = 'preview';
                img.title = 'Click to view full image';
                img.style.cursor = 'zoom-in';
                img.addEventListener('click', function(){ openFbkLightbox(src); });
                const removeBtn = document.createElement('button');
                removeBtn.type      = 'button';
                removeBtn.className = 'photo-thumb-remove';
                removeBtn.title     = 'Remove';
                removeBtn.textContent = '✕';
                removeBtn.addEventListener('click', function(ev){
                    ev.stopPropagation();
                    fileList.splice(i, 1);
                    rebuildInput();
                    renderPreviews();
                });
                wrap.appendChild(img);
                wrap.appendChild(removeBtn);
                grid.appendChild(wrap);
            };
            reader.readAsDataURL(f);
        });
    }

    function rebuildInput() {
        // Rebuild DataTransfer for the file input
        const dt = new DataTransfer();
        fileList.forEach(function(f){ dt.items.add(f); });
        input.files = dt.files;
    }

    input.addEventListener('change', function(){
        Array.from(input.files).forEach(function(f){
            if (fileList.length < MAX) fileList.push(f);
        });
        rebuildInput();
        renderPreviews();
    });

    // Drag and drop
    zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.style.borderColor='#16a34a'; });
    zone.addEventListener('dragleave', function(){ zone.style.borderColor = ''; });
    zone.addEventListener('drop', function(e){
        e.preventDefault();
        zone.style.borderColor = '';
        Array.from(e.dataTransfer.files).forEach(function(f){
            if (f.type.startsWith('image/') && fileList.length < MAX) fileList.push(f);
        });
        rebuildInput();
        renderPreviews();
    });
})();

// ── Feedback Map Modal (mirrors profile.php epAddrMap) ────────────────────────
(function () {
    'use strict';

    const backdrop       = document.getElementById('fbkMapBackdrop');
    const addrField      = document.getElementById('fbkMapAddrField');
    const searchInput    = document.getElementById('fbkMapSearchInput');
    const searchDrop     = document.getElementById('fbkMapSearchDropdown');
    const searchSpinner  = document.getElementById('fbkMapSearchSpinner');
    const searchClearBtn = document.getElementById('fbkMapSearchClearBtn');
    const gpsBtn         = document.getElementById('fbkMapGpsBtn');
    const layerToggle    = document.getElementById('fbkMapLayerToggle');
    const saveBtn        = document.getElementById('fbkMapSaveBtn');

    let fbkMap            = null;
    let fbkMarker         = null;
    let fbkSelectedLatLng = null;
    let fbkAddrTimeout    = null;
    let fbkAddrAbort      = null;
    let fbkSearchTimer    = null;
    let fbkSearchAbort    = null;
    let fbkFetchingAddr   = false;
    let satelliteLayer    = null;
    let streetLayer       = null;
    let isSatellite       = true;

    function syncLayerLabel() {
        if (layerToggle) {
            layerToggle.dataset.isSatellite = isSatellite ? 'true' : 'false';
            var tr = window.__preloadedTranslations;
            var lang = localStorage.getItem('lang') || 'en';
            var t = tr && tr[lang] ? tr[lang] : null;
            if (isSatellite) {
                layerToggle.textContent = (t && t['map_layer_toggle_street']) || '🗺️ Street';
            } else {
                layerToggle.textContent = (t && t['map_layer_toggle_satellite']) || '🛰️ Satellite';
            }
        }
    }

    function setSaveState(disabled) {
        if (!saveBtn) return;
        saveBtn.disabled      = disabled;
        saveBtn.style.opacity = disabled ? '0.55' : '';
        saveBtn.style.cursor  = disabled ? 'not-allowed' : '';
    }

    async function reverseGeocode(lat, lng) {
        addrField.value = 'Fetching address…';
        addrField.style.color = 'var(--input-placeholder)';
        fbkFetchingAddr = true;
        setSaveState(true);
        if (fbkAddrAbort) fbkAddrAbort.abort();
        fbkAddrAbort = new AbortController();
        try {
            const r = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`,
                { signal: fbkAddrAbort.signal }
            );
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d = await r.json();
            const addr = d.display_name || `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.value = addr;
            addrField.style.color = '';
        } catch (e) {
            if (e.name === 'AbortError') return;
            addrField.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.style.color = '';
        } finally {
            fbkFetchingAddr = false;
            setSaveState(false);
        }
    }

    function onPinMove(latlng) {
        fbkSelectedLatLng = latlng;
        if (fbkAddrTimeout) clearTimeout(fbkAddrTimeout);
        fbkAddrTimeout = setTimeout(() => reverseGeocode(latlng.lat, latlng.lng), 300);
    }

    function initFbkMap() {
        if (fbkMap) return;
        const startLat = parseFloat(document.getElementById('coordLat').value) || 14.6760;
        const startLng = parseFloat(document.getElementById('coordLng').value) || 121.0437;

        fbkMap = L.map('fbkLeafletMap', {
            zoomControl: true, scrollWheelZoom: true,
            touchZoom: true, doubleClickZoom: true,
        }).setView([startLat, startLng], 14);

        satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Esri Satellite' }
        );
        streetLayer = L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            { maxZoom: 19, attribution: '© OpenStreetMap contributors' }
        );
        satelliteLayer.addTo(fbkMap);
        isSatellite = true;
        syncLayerLabel();

        fbkMarker = L.marker([startLat, startLng], { draggable: true }).addTo(fbkMap);
        fbkSelectedLatLng = L.latLng(startLat, startLng);

        fbkMarker.on('dragend', () => onPinMove(fbkMarker.getLatLng()));
        fbkMap.on('click', e => { fbkMarker.setLatLng(e.latlng); onPinMove(e.latlng); });
    }

    function _setChatbotFabHidden(hide) {
        const selectors = [
            '.chatbot-fab', '#chatbotFab', '#chatbot-fab',
            '[id*="chatbot-btn"]', '[class*="chatbot-toggle"]',
            '[id*="chat-widget-btn"]', '.chat-widget-fab'
        ];
        selectors.forEach(sel => {
            document.querySelectorAll(sel).forEach(el => {
                el.classList.toggle('chatbot-fab-hidden', hide);
            });
        });
    }

    window.openMapModal = function () {
        backdrop.classList.add('show');
        _setChatbotFabHidden(true);
        requestAnimationFrame(() => {
            if (!fbkMap) initFbkMap();
            fbkMap.invalidateSize(false);
            const lat = parseFloat(document.getElementById('coordLat').value);
            const lng = parseFloat(document.getElementById('coordLng').value);
            if (lat && lng) {
                fbkMap.setView([lat, lng], 16);
                fbkMarker.setLatLng([lat, lng]);
                fbkSelectedLatLng = L.latLng(lat, lng);
                const savedAddr = document.getElementById('addressInput').value.trim();
                if (savedAddr) { addrField.value = savedAddr; addrField.style.color = ''; }
                else reverseGeocode(lat, lng);
            } else {
                addrField.value = '';
            }
        });
    };

    window.closeFbkMap = function () {
        backdrop.classList.remove('show');
        _setChatbotFabHidden(false);
        if (fbkAddrAbort)  { fbkAddrAbort.abort();  fbkAddrAbort  = null; }
        if (fbkSearchAbort){ fbkSearchAbort.abort(); fbkSearchAbort = null; }
        clearTimeout(fbkAddrTimeout);
        clearTimeout(fbkSearchTimer);
        searchInput.value = '';
        if (searchClearBtn) searchClearBtn.classList.remove('visible');
        searchDrop.classList.remove('open');
        searchDrop.innerHTML = '<div class="fbk-map-search-spinner" id="fbkMapSearchSpinner">Searching…</div>';
    };

    window.saveFbkMap = function () {
        if (!fbkSelectedLatLng) { alert('Please select a location on the map first.'); return; }
        if (fbkFetchingAddr)    { alert('Please wait — address is still loading.'); return; }
        const addrText = addrField.value.trim();
        if (!addrText || addrText === 'Fetching address…') { alert('Please wait for the address to load.'); return; }
        document.getElementById('addressInput').value = addrText;
        document.getElementById('coordLat').value     = fbkSelectedLatLng.lat.toFixed(7);
        document.getElementById('coordLng').value     = fbkSelectedLatLng.lng.toFixed(7);
        const badge = document.getElementById('coordsBadge');
        if (badge) badge.style.display = 'inline-block';
        // Persist to localStorage
        localStorage.setItem('fbk_address', addrText);
        localStorage.setItem('fbk_coord_lat', fbkSelectedLatLng.lat.toFixed(7));
        localStorage.setItem('fbk_coord_lng', fbkSelectedLatLng.lng.toFixed(7));
        window.closeFbkMap();
    };

    // GPS button
    if (gpsBtn) {
        gpsBtn.addEventListener('click', () => {
            if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
            gpsBtn.textContent = '⏳';
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const ll = L.latLng(pos.coords.latitude, pos.coords.longitude);
                    if (fbkMap) { fbkMap.setView(ll, 17); fbkMarker.setLatLng(ll); }
                    onPinMove(ll);
                    gpsBtn.textContent = '📍';
                },
                () => { alert('Unable to retrieve your location.'); gpsBtn.textContent = '📍'; },
                { enableHighAccuracy: true }
            );
        });
    }

    // Layer toggle
    if (layerToggle) {
        layerToggle.addEventListener('click', () => {
            if (!fbkMap) return;
            if (isSatellite) { fbkMap.removeLayer(satelliteLayer); streetLayer.addTo(fbkMap); }
            else             { fbkMap.removeLayer(streetLayer); satelliteLayer.addTo(fbkMap); }
            isSatellite = !isSatellite;
            syncLayerLabel();
        });
    }

    // Search clear
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchClearBtn.classList.remove('visible');
            searchDrop.classList.remove('open');
            searchDrop.innerHTML = '<div class="fbk-map-search-spinner">Searching…</div>';
            if (fbkSearchAbort) { fbkSearchAbort.abort(); fbkSearchAbort = null; }
            clearTimeout(fbkSearchTimer);
            searchInput.focus();
        });
    }

    // Address search (Nominatim)
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();
        clearTimeout(fbkSearchTimer);
        if (searchClearBtn) searchClearBtn.classList.toggle('visible', q.length > 0);
        if (!q) { searchDrop.classList.remove('open'); return; }
        const spinner = document.getElementById('fbkMapSearchSpinner');
        if (spinner) spinner.classList.add('visible');
        searchDrop.classList.add('open');
        fbkSearchTimer = setTimeout(async () => {
            if (fbkSearchAbort) fbkSearchAbort.abort();
            fbkSearchAbort = new AbortController();
            try {
                const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=6&addressdetails=1&accept-language=en&countrycodes=ph&viewbox=120.93,14.78,121.20,14.35&bounded=1`;
                const res  = await fetch(url, { signal: fbkSearchAbort.signal });
                const data = await res.json();
                searchDrop.innerHTML = '<div class="fbk-map-search-spinner" id="fbkMapSearchSpinner">Searching…</div>';
                if (!data.length) {
                    const noRes = document.createElement('div');
                    noRes.style.cssText = 'padding:10px 14px;font-size:13px;color:var(--text-secondary);';
                    noRes.textContent = 'No results found.';
                    searchDrop.appendChild(noRes);
                } else {
                    data.forEach(r => {
                        const parts   = r.display_name.split(',');
                        const name    = parts[0].trim();
                        const address = parts.slice(1).join(',').trim();
                        const item = document.createElement('div');
                        item.className = 'fbk-map-search-item';
                        item.innerHTML = `<span class="fbk-map-search-item-icon">📍</span>
                            <div class="fbk-map-search-item-text">
                                <div class="fbk-map-search-item-name">${name}</div>
                                <div class="fbk-map-search-item-addr">${address}</div>
                            </div>`;
                        item.addEventListener('mousedown', ev => {
                            ev.preventDefault();
                            const ll = L.latLng(parseFloat(r.lat), parseFloat(r.lon));
                            if (fbkMap) { fbkMap.setView(ll, 17); fbkMarker.setLatLng(ll); }
                            onPinMove(ll);
                            searchInput.value = '';
                            if (searchClearBtn) searchClearBtn.classList.remove('visible');
                            searchDrop.classList.remove('open');
                        });
                        searchDrop.appendChild(item);
                    });
                }
                searchDrop.classList.add('open');
            } catch (e) {
                if (e.name === 'AbortError') return;
                searchDrop.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--text-secondary);">Search unavailable. Try again.</div>';
            }
        }, 400);
    });

    // Close dropdown on outside click
    document.addEventListener('click', e => {
        if (!e.target.closest('.fbk-map-search-wrap')) searchDrop.classList.remove('open');
    });

    // Close backdrop on outside click
    backdrop.addEventListener('click', e => { if (e.target === backdrop) window.closeFbkMap(); });

    // Eager init so Leaflet can measure container
    document.addEventListener('DOMContentLoaded', () => {
        initFbkMap();
        requestAnimationFrame(() => { if (fbkMap) fbkMap.invalidateSize(false); });
    });
})();

// ── Navbar scroll effect ──────────────────────────────────────────────────────
window.addEventListener('scroll', function(){
    const nav = document.getElementById('citizenNav');
    if (nav) nav.classList.toggle('scrolled', window.scrollY > 50);
});
</script>

<script>
/* SERVER_TIME — required by citizen_global.php clock engine */
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
</script>
<?php include 'citizen_global.php'; ?>

<footer class="footer" style="margin-top:50px;">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p data-i18n="footer_desc">Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item"><i class="fas fa-envelope"></i><span>contact@infragovservices.com</span></div>
                <div class="contact-item"><i class="fas fa-phone"></i><span>(02) 8988-4242</span></div>
                <div class="contact-item"><i class="fas fa-map-marker-alt"></i><span>Quezon City Hall, Quezon City</span></div>
            </div>
        </div>
        <div class="footer-links">
            <h4 data-i18n="footer_quick_links">Quick Links</h4>
            <ul>
                <li><a href="<?= $BASE_URL ?>citizencimm.php" data-i18n="footer_link_home">Home</a></li>
                <li><a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="footer_link_reports">Reports</a></li>
                <li><a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="footer_link_submit">Submit Request</a></li>
                <li><a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="footer_link_feedback">Feedback</a></li>
                <li><a href="<?= $BASE_URL ?>about.php" data-i18n="footer_link_about">About Us</a></li>
            </ul>
        </div>
        <div class="footer-links">
            <h4 data-i18n="footer_resources">Resources</h4>
            <ul>
                <li><a href="#" data-i18n="footer_link_guide">User Guide</a></li>
                <li><a href="#" data-i18n="footer_link_faqs">FAQs</a></li>
                <li><a href="#" data-i18n="footer_link_areas">Service Areas</a></li>
                <li><a href="#" data-i18n="footer_link_emergency">Emergency Contacts</a></li>
            </ul>
        </div>
        <div class="footer-links">
            <h4 data-i18n="footer_legal">Legal</h4>
            <ul>
                <li><a href="privacy.php" data-i18n="footer_link_privacy">Privacy Policy</a></li>
                <li><a href="termcon.php" data-i18n="footer_link_terms">Terms of Service</a></li>
                <li><a href="#" data-i18n="footer_link_data">Data Protection</a></li>
                <li><a href="#" data-i18n="footer_link_access">Accessibility</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <div data-i18n="footer_copyright">© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
        <div class="footer-social">
            <a href="#" class="social-link" title="Facebook"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
            <a href="#" class="social-link" title="Twitter"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
            <a href="#" class="social-link" title="Instagram"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg></a>
            <a href="#" class="social-link" title="Email"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg></a>
        </div>
    </div>
</footer>

<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>chatbot.php';</script>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>