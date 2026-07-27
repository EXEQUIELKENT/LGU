<?php
session_start();
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once __DIR__ . '/../../includes/config/auth_config.php';
require_once __DIR__ . '/../../includes/config/db.php';

if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
} else {
    $BASE_URL = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
}

// Pre-fill from the report form's post-submit redirect, if it linked here
// with the fresh reference number (see citizenrepform.php).
$prefillRef = isset($_GET['ref']) ? preg_replace('/\D/', '', $_GET['ref']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>Track My Report - InfraGovServices</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>assets/css/citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script>
    (function() {
        const currentLang = localStorage.getItem('lang') || 'en';
        if (currentLang === 'tl') {
            document.documentElement.style.cssText = 'visibility: hidden !important;';
        }
    })();
    </script>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: rgba(255, 255, 255, 0.95);
            --bg-tertiary: rgba(255, 255, 255, 0.9);
            --text-primary: #000000;
            --text-secondary: #333333;
            --border-color: rgba(0, 0, 0, 0.1);
            --shadow-color: rgba(0, 0, 0, 0.2);
            --card-bg: #ffffff;
            --nav-bg: rgba(255, 255, 255, 0.87);
        }
        [data-theme="dark"] {
            --bg-primary: #1a1a1a;
            --bg-secondary: rgba(26, 26, 26, 0.95);
            --bg-tertiary: rgba(30, 30, 30, 0.9);
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow-color: rgba(0, 0, 0, 0.5);
            --card-bg: rgba(30, 30, 30, 0.95);
            --nav-bg: rgba(26, 26, 26, 0.87);
        }
        body {
            margin: 0; padding: 0; min-height: 100vh;
            display: flex; flex-direction: column;
            background: url("../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative; transition: background 0.3s ease;
        }
        body::before {
            content: ""; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            backdrop-filter: blur(8px); background: rgba(0,0,0,0.4); z-index: -1;
        }
        .dashboard-container { padding: 30px 0 40px; max-width: 100%; margin: 0; color: var(--text-primary); flex: 1; }
        .container { max-width: 720px; margin: auto; padding: 0 40px; box-sizing: border-box; }

        .content-card {
            background: var(--card-bg); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 32px; border: 1px solid var(--border-color);
            box-shadow: 0 4px 20px var(--shadow-color);
            box-sizing: border-box;
        }

        /* ── Hero — now lives inside the card ── */
        .track-hero { text-align: center; margin-bottom: 26px; }
        .track-hero h1 { font-size: 1.7rem; margin: 0 0 8px; }
        .track-hero p { color: var(--text-secondary); font-size: 14px; max-width: 480px; margin: 0 auto; line-height: 1.55; }
        .track-form { display: flex; flex-direction: column; gap: 16px; }
        .track-field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--text-secondary); }
        .track-field input {
            width: 100%; height: 44px; padding: 0 14px; border-radius: 10px;
            border: 1.5px solid #94a3b8; background: #fff; font-size: 14px;
            color: #111; outline: none; box-sizing: border-box; transition: border-color .15s, box-shadow .15s;
        }
        .track-field input:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.18); }
        [data-theme="dark"] .track-field input { background: rgba(255,255,255,.07); border-color: rgba(95,140,255,.22); color: var(--text-primary); }
        .track-field { position: relative; }
        .track-ref-dropdown {
            display: none; position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 20;
            background: var(--bg-primary); border: 1.5px solid #94a3b8; border-radius: 10px;
            box-shadow: 0 10px 28px var(--shadow-color); max-height: 260px; overflow-y: auto;
            /* Same thin scrollbar as the citizenreports.php schedule detail modal's body ── */
            scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07);
        }
        .track-ref-dropdown::-webkit-scrollbar { width: 5px; }
        .track-ref-dropdown::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
        .track-ref-dropdown::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }
        .track-ref-dropdown.open { display: block; }
        [data-theme="dark"] .track-ref-dropdown { border-color: rgba(95,140,255,.3); }
        .track-ref-option {
            display: flex; flex-direction: column; gap: 2px; padding: 9px 14px; cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }
        .track-ref-option:last-child { border-bottom: none; }
        .track-ref-option:hover, .track-ref-option.active { background: rgba(55,98,200,.1); }
        .track-ref-option .ref-id { font-weight: 700; font-size: 13.5px; color: #3762c8; }
        [data-theme="dark"] .track-ref-option .ref-id { color: #7c9dfb; }
        .track-ref-option .ref-meta { font-size: 12px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .track-ref-empty { padding: 12px 14px; font-size: 12.5px; color: var(--text-secondary); text-align: center; }
        .track-submit-btn {
            height: 46px; border: none; border-radius: 10px; cursor: pointer;
            background: linear-gradient(135deg, #2b6cb0, #1d4ed8); color: #fff;
            font-size: 15px; font-weight: 700; transition: all .2s ease;
        }
        .track-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(43,108,176,.35); }
        .track-submit-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }
        .track-error {
            display: none; background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b;
            padding: 10px 14px; border-radius: 10px; font-size: 13.5px; font-weight: 600;
        }
        [data-theme="dark"] .track-error { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.4); color: #fca5a5; }

        /* ── Toast notification (search-result errors — not tied to a field) ── */
        .notif-popup {
            position: fixed; top: 30px; left: 50%; transform: translateX(-50%);
            min-width: 280px; max-width: 92vw; padding: 16px 26px;
            background: var(--card-bg, #fff); border-radius: 13px;
            box-shadow: 0 8px 38px rgba(34,53,126,0.23); z-index: 10000;
            display: flex; align-items: center; gap: 14px;
            font-size: 14.5px; font-weight: 500; opacity: 1;
            transition: opacity .35s; color: var(--text-primary);
        }
        .notif-popup .notif-icon { font-size: 21px; flex-shrink: 0; }
        .notif-popup.notif-error   { border-left: 5px solid #d73f52; }
        .notif-popup.notif-success { border-left: 5px solid #4fc97a; }
        .notif-popup .notif-close { background: none; border: none; font-size: 19px; margin-left: auto; color: #888; cursor: pointer; flex-shrink: 0; }
        @media (max-width: 560px) { .notif-popup { top: 16px; left: 12px; right: 12px; transform: none; min-width: 0; } }

        /* ── Result panel — framed like a modal card (matches the citizenreports.php
           schedule detail modal: bordered box with a status-coloured top band) ── */
        .track-result {
            display: none; margin-top: 26px; border-radius: 18px; overflow: hidden;
            border: 1px solid var(--border-color); box-shadow: 0 8px 30px var(--shadow-color);
            background: var(--bg-primary, var(--card-bg));
        }
        .track-result-band { height: 8px; width: 100%; }
        .track-result-band.track-band-pending    { background: linear-gradient(90deg,#1565c0,#42a5f5); }
        .track-result-band.track-band-progress   { background: linear-gradient(90deg,#f57f17,#ffd54f); }
        .track-result-band.track-band-completed  { background: linear-gradient(90deg,#2e7d32,#66bb6a); }
        .track-result-band.track-band-rejected   { background: linear-gradient(90deg,#dc2626,#f87171); }
        .track-result-inner { padding: 24px; }
        @media (max-width: 560px) { .track-result-inner { padding: 18px 16px; } }
        .track-result-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
        .track-result-id { font-size: 13px; font-weight: 800; letter-spacing: .04em; color: #3762c8; }
        [data-theme="dark"] .track-result-id { color: #8fb4ff; }
        .track-result-title { font-size: 1.25rem; font-weight: 700; margin: 4px 0 6px; }
        .track-result-meta { font-size: 13.5px; color: var(--text-secondary); }
        .track-status-badge { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 800; white-space: nowrap; }
        .track-status-pending   { background: #e0e7ff; color: #3730a3; }
        .track-status-scheduled { background: #dbeafe; color: #1565c0; }
        .track-status-progress  { background: #fef3c7; color: #b45309; }
        .track-status-completed { background: #dcfce7; color: #166534; }
        .track-status-rejected  { background: #fee2e2; color: #991b1b; }
        [data-theme="dark"] .track-status-pending   { background: rgba(99,102,241,.20); color: #c7d2fe; }
        [data-theme="dark"] .track-status-scheduled { background: rgba(21,101,192,.22); color: #90caf9; }
        [data-theme="dark"] .track-status-progress  { background: rgba(245,158,11,.20); color: #fdd835; }
        [data-theme="dark"] .track-status-completed { background: rgba(22,163,74,.20); color: #86efac; }
        [data-theme="dark"] .track-status-rejected  { background: rgba(239,68,68,.20); color: #fca5a5; }

        /* ── Timeline — same numbered-circle-with-icon-labels visual as the
           citizenreports.php schedule modal's progress tracker ── */
        .track-timeline {
            display: flex; align-items: flex-start; margin: 10px 0 24px;
            padding: 16px 14px 12px; border-radius: 14px; border: 1px solid transparent;
            --tl-accent: #2563eb;
        }
        .track-timeline-theme-pending    { --tl-accent: #1565c0; background: rgba(21,101,192,0.07);  border-color: rgba(21,101,192,0.16); }
        .track-timeline-theme-progress   { --tl-accent: #f59e0b; background: rgba(245,158,11,0.08);  border-color: rgba(245,158,11,0.20); }
        .track-timeline-theme-completed  { --tl-accent: #2e7d32; background: rgba(46,125,50,0.08);   border-color: rgba(46,125,50,0.18); }
        .track-timeline-theme-rejected   { --tl-accent: #dc2626; background: rgba(220,38,38,0.08);   border-color: rgba(220,38,38,0.20); }
        [data-theme="dark"] .track-timeline-theme-pending   { background: rgba(21,101,192,0.14);  border-color: rgba(90,150,255,0.28); }
        [data-theme="dark"] .track-timeline-theme-progress  { background: rgba(245,158,11,0.14);  border-color: rgba(253,216,53,0.28); }
        [data-theme="dark"] .track-timeline-theme-completed { background: rgba(46,125,50,0.16);   border-color: rgba(129,199,132,0.30); }
        [data-theme="dark"] .track-timeline-theme-rejected  { background: rgba(220,38,38,0.16);   border-color: rgba(248,113,113,0.30); }
        .track-timeline-step { flex: 1; text-align: center; position: relative; }
        .track-timeline-dot {
            width: 30px; height: 30px; border-radius: 50%; margin: 0 auto 8px;
            display: flex; align-items: center; justify-content: center;
            background: #e5e7eb; color: #9ca3af; font-size: 13px; font-weight: 800;
            border: 3px solid var(--card-bg, #fff); box-shadow: 0 0 0 2px #e5e7eb; position: relative; z-index: 2;
            transition: background .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .track-timeline-step::before {
            content: ''; position: absolute; top: 14px; left: -50%; width: 100%; height: 3px;
            background: #e5e7eb; z-index: 1;
        }
        .track-timeline-step:first-child::before { display: none; }
        .track-timeline-label { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .03em; }
        .track-timeline-step.done .track-timeline-dot { background: var(--tl-accent); color: #fff; box-shadow: 0 0 0 2px var(--tl-accent); }
        .track-timeline-step.done::before { background: var(--tl-accent); }
        .track-timeline-step.current .track-timeline-dot { background: #fff; color: var(--tl-accent); box-shadow: 0 0 0 3px var(--tl-accent); }
        .track-timeline-step.current .track-timeline-label { color: var(--tl-accent); }
        [data-theme="dark"] .track-timeline-step.current .track-timeline-dot { background: var(--card-bg, #1a1a1a); }

        .track-rejection-note {
            background: #fee2e2; border: 1px solid #fca5a5; border-radius: 10px;
            padding: 12px 14px; font-size: 13.5px; color: #991b1b; margin-bottom: 18px;
        }
        [data-theme="dark"] .track-rejection-note { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.4); color: #fca5a5; }

        .track-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 6px; }
        @media (max-width: 560px) { .track-detail-grid { grid-template-columns: 1fr; } }
        .track-detail-item { background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 12px; padding: 12px 14px; }
        .track-detail-item .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); font-weight: 700; margin-bottom: 4px; }
        .track-detail-item .value { font-size: 14px; font-weight: 600; }

        /* ── AI assessment card — pill badges, same visual language as the
           admin report modal's AI analysis section (current_reports.php) ── */
        .track-ai-card {
            margin-top: 18px; border-radius: 14px; padding: 16px 18px;
            background: var(--bg-tertiary); border: 1px solid var(--border-color);
        }
        .track-ai-head { font-size: 13px; font-weight: 800; color: var(--text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .track-ai-head i { color: #7c3aed; }
        .track-ai-badges { display: flex; gap: 9px; flex-wrap: wrap; }
        .track-ai-badge {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 5px 14px 5px 5px; border-radius: 20px; font-size: 12px; font-weight: 700;
            border: 1px solid transparent; box-shadow: 0 2px 6px rgba(0,0,0,.07);
        }
        .track-ai-badge-icon {
            width: 21px; height: 21px; border-radius: 50%; flex-shrink: 0; color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 11px;
        }
        .track-ai-badge.sev-low  { background:#d1fae5; color:#065f46; } .track-ai-badge.sev-low  .track-ai-badge-icon { background:#10b981; }
        .track-ai-badge.sev-med  { background:#fef3c7; color:#92400e; } .track-ai-badge.sev-med  .track-ai-badge-icon { background:#f59e0b; }
        .track-ai-badge.sev-high { background:#fde8e8; color:#9b1c1c; } .track-ai-badge.sev-high .track-ai-badge-icon { background:#ef4444; }
        .track-ai-badge.sev-crit { background:#fce7f3; color:#831843; } .track-ai-badge.sev-crit .track-ai-badge-icon { background:#db2777; }
        .track-ai-badge.info     { background:#e0e7ff; color:#3730a3; } .track-ai-badge.info     .track-ai-badge-icon { background:#6366f1; }
        [data-theme="dark"] .track-ai-badge.sev-low  { background:rgba(16,185,129,.16);  color:#6ee7b7; }
        [data-theme="dark"] .track-ai-badge.sev-med  { background:rgba(245,158,11,.16); color:#fcd34d; }
        [data-theme="dark"] .track-ai-badge.sev-high { background:rgba(239,68,68,.18);  color:#fca5a5; }
        [data-theme="dark"] .track-ai-badge.sev-crit { background:rgba(219,39,119,.18); color:#f9a8d4; }
        [data-theme="dark"] .track-ai-badge.info     { background:rgba(99,102,241,.18); color:#a5b4fc; }
        .track-ai-desc {
            margin-top: 12px; font-size: 13px; color: var(--text-primary); line-height: 1.55;
            background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px;
        }
        .track-ai-urgent {
            margin-top: 10px; display: flex; align-items: center; gap: 8px;
            background: #fee2e2; color: #991b1b; border-radius: 8px; padding: 8px 12px;
            font-size: 12.5px; font-weight: 700;
        }
        [data-theme="dark"] .track-ai-urgent { background: rgba(239,68,68,.18); color: #fca5a5; }
        .track-ai-disclaimer { font-size: 11px; color: var(--text-secondary); margin-top: 10px; }

        .track-search-again { text-align: center; margin-top: 22px; }
        .track-again-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 44px; padding: 0 22px; border-radius: 10px; cursor: pointer;
            background: var(--bg-secondary); border: 1.5px solid #3762c8; color: #3762c8;
            font-size: 14px; font-weight: 700; font-family: inherit; transition: all .2s ease;
        }
        .track-again-btn:hover { background: #3762c8; color: #fff; box-shadow: 0 4px 14px rgba(55,98,200,.3); transform: translateY(-1px); }
        [data-theme="dark"] .track-again-btn { border-color: rgba(95,140,255,.4); color: #8fb4ff; }
        [data-theme="dark"] .track-again-btn:hover { background: #3762c8; color: #fff; }

        /* ── Confirmation modal — same pattern as citizenrepform.php's submit confirm ── */
        #trackConfirmBackdrop {
            position: fixed; z-index: 5000; inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center;
        }
        #trackConfirmBackdrop.active { display: flex; }
        #trackConfirmModal {
            background: var(--card-bg, #fff);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
            padding: 32px 30px 24px;
            width: 440px; max-width: 92vw;
            animation: trackModalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex; flex-direction: column; align-items: center; text-align: center;
            box-sizing: border-box;
        }
        @keyframes trackModalPop {
            from { transform: translateY(24px) scale(0.93); opacity: 0; }
            to   { transform: translateY(0)    scale(1);    opacity: 1; }
        }
        [data-theme="dark"] #trackConfirmModal {
            background: rgba(24, 24, 30, 0.98);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        #trackConfirmModal .icon-wrap {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, rgba(55,98,200,0.14), rgba(55,98,200,0.08));
            border-radius: 50%; margin: 0 auto 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(55,98,200,0.22);
        }
        [data-theme="dark"] #trackConfirmModal .icon-wrap {
            background: linear-gradient(135deg, rgba(95,140,255,0.20), rgba(95,140,255,0.10));
        }
        #trackConfirmModal .alert-title {
            font-size: 1.05rem; font-weight: 700; color: var(--text-primary, #1a1a2e); margin-bottom: 8px;
        }
        [data-theme="dark"] #trackConfirmModal .alert-title { color: #e2e8f0; }
        #trackConfirmModal .alert-desc {
            color: var(--text-secondary, #64748b); font-size: 0.92rem; margin-bottom: 6px; line-height: 1.5;
        }
        [data-theme="dark"] #trackConfirmModal .alert-desc { color: #94a3b8; }
        #trackConfirmModal .track-confirm-details {
            width: 100%; background: rgba(55,98,200,0.07); border: 1px solid rgba(55,98,200,0.18);
            border-radius: 12px; padding: 14px 16px; margin: 14px 0 22px; text-align: left;
        }
        [data-theme="dark"] #trackConfirmModal .track-confirm-details {
            background: rgba(95,140,255,0.10); border-color: rgba(95,140,255,0.24);
        }
        #trackConfirmModal .track-confirm-row {
            display: flex; justify-content: space-between; align-items: baseline; gap: 14px;
            font-size: 13px; padding: 6px 0;
        }
        #trackConfirmModal .track-confirm-row + .track-confirm-row { border-top: 1px solid rgba(55,98,200,0.14); }
        [data-theme="dark"] #trackConfirmModal .track-confirm-row + .track-confirm-row { border-top-color: rgba(95,140,255,0.16); }
        #trackConfirmModal .track-confirm-row .label {
            color: var(--text-secondary, #64748b); font-weight: 600; flex-shrink: 0; white-space: nowrap;
        }
        [data-theme="dark"] #trackConfirmModal .track-confirm-row .label { color: #a8b3c7; }
        #trackConfirmModal .track-confirm-row .value {
            font-weight: 800; text-align: right; color: var(--text-primary, #1a1a2e);
            word-break: break-word;
        }
        [data-theme="dark"] #trackConfirmModal .track-confirm-row .value { color: #f1f5f9; }
        @media (max-width: 420px) {
            #trackConfirmModal .track-confirm-row { flex-direction: column; align-items: flex-start; gap: 2px; }
            #trackConfirmModal .track-confirm-row .value { text-align: left; }
        }
        #trackConfirmModal .alert-btns { display: flex; gap: 10px; width: 100%; }
        #trackConfirmModal .alert-btn {
            flex: 1; padding: 10px 0; border-radius: 10px; border: none;
            font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.18s ease;
        }
        #trackConfirmModal .alert-btn.cancel {
            background: var(--bg-secondary, #f1f5f9); color: var(--text-primary, #374151);
            border: 1px solid var(--border-color, #e2e8f0);
        }
        #trackConfirmModal .alert-btn.cancel:hover { background: var(--border-color, #e2e8f0); }
        [data-theme="dark"] #trackConfirmModal .alert-btn.cancel {
            background: rgba(255, 255, 255, 0.06); color: #e2e8f0; border-color: rgba(255, 255, 255, 0.1);
        }
        [data-theme="dark"] #trackConfirmModal .alert-btn.cancel:hover { background: rgba(255, 255, 255, 0.11); }
        #trackConfirmModal .alert-btn.confirm {
            background: linear-gradient(135deg, #2b6cb0, #1d4ed8); color: #fff;
            box-shadow: 0 4px 12px rgba(43,108,176,0.35);
        }
        #trackConfirmModal .alert-btn.confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(43,108,176,0.45); }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            .container { padding: 0 16px; box-sizing: border-box; }
            .dashboard-container { padding: 20px 0 40px; }
            .content-card { padding: 22px 18px; border-radius: 16px; }
            .track-hero h1 { font-size: 1.4rem; }
            .track-hero p { font-size: 13px; }
            .track-result-head { flex-direction: column; align-items: flex-start; gap: 8px; }
            .track-timeline-label { font-size: 9.5px; }
            .footer { padding: 40px 20px 20px; }
            .footer-content { grid-template-columns: 1fr !important; gap: 30px !important; margin-bottom: 30px !important; }
            .footer-bottom { flex-direction: column !important; gap: 14px !important; text-align: center; }
        }
        @media (max-width: 400px) {
            .content-card { padding: 18px 14px; }
            .track-detail-grid, .track-ai-body { grid-template-columns: 1fr !important; }
        }
    </style>
    <?php include __DIR__ . '/../../includes/partials/citizen_rendering.php'; ?>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="../assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span data-i18n="site_title">InfraGovServices</span>
    </a>
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
            <a href="login.php" data-i18n="nav_login">Log in</a>
            <?php endif; ?>
            <a href="citizencimm.php" data-i18n="nav_home">Home</a>
            <a href="citizenreports.php" data-i18n="nav_reports">Reports</a>
            <a href="#" class="active" data-i18n="nav_track">Track</a>
            <a href="citizenrepform.php" data-i18n="nav_requests">Requests</a>
            <a href="citizen_feedback.php" data-i18n="nav_feedback">Feedback</a>
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
            <img src="../assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </a>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <?php if ($show_login): ?>
            <li><a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
            <?php endif; ?>
            <li><a href="citizencimm.php" class="nav-link"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
            <li><a href="citizenreports.php" class="nav-link"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
            <li><a href="#" class="nav-link active"><i class="fas fa-magnifying-glass-location"></i><span data-i18n="nav_track">Track</span></a></li>
            <li><a href="citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
            <li><a href="citizen_feedback.php" class="nav-link"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
            <li><a href="about.php" class="nav-link"><i class="fas fa-info-circle"></i><span data-i18n="nav_about">About</span></a></li>
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
        <img src="../assets/img/officiallogo.png" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display: none;">☀️</span>
    </button>
</div>

<!-- LANGUAGE BADGE (toast) -->
<div class="lang-badge" id="langBadge">
    <span class="badge-flag" id="badgeFlag">🇺🇸</span>
    <span id="badgeText">Switched to English</span>
</div>

<div class="main-content">
<div class="dashboard-container">
<div class="container">

    <div class="content-card">
        <div class="track-hero">
            <h1 data-i18n="track_title">Track My Report</h1>
            <p data-i18n="track_subtitle">No account needed. Enter the reference number you received when you submitted your report, along with the phone number you used, to check its current status.</p>
        </div>

        <form class="track-form" id="trackForm">
            <div class="track-field">
                <label for="trackRef" data-i18n="track_ref_label">Reference Number</label>
                <input type="text" id="trackRef" data-i18n-placeholder="track_ref_placeholder" placeholder="e.g. REQ-123 or 123" value="<?= htmlspecialchars($prefillRef) ?>" required autocomplete="off">
                <div class="track-ref-dropdown" id="trackRefDropdown"></div>
            </div>
            <div class="track-field">
                <label for="trackPhone" data-i18n="track_phone_label">Contact Number Used</label>
                <input type="tel" id="trackPhone" data-i18n-placeholder="track_phone_placeholder" placeholder="09XXXXXXXXX" required autocomplete="off">
            </div>
            <div class="track-error" id="trackError"></div>
            <button type="submit" class="track-submit-btn" id="trackSubmitBtn" data-i18n="track_submit">Track Report</button>
        </form>

        <div class="track-result" id="trackResult">
            <div class="track-result-band" id="trackResultBand"></div>
            <div class="track-result-inner">
                <div class="track-result-head">
                    <div>
                        <div class="track-result-id" id="trackResultId"></div>
                        <div class="track-result-title" id="trackResultTitle"></div>
                        <div class="track-result-meta" id="trackResultMeta"></div>
                    </div>
                    <div class="track-status-badge" id="trackResultBadge"></div>
                </div>

                <div class="track-timeline" id="trackTimeline"></div>
                <div class="track-rejection-note" id="trackRejectionNote" style="display:none;"></div>

                <div class="track-detail-grid" id="trackDetailGrid"></div>

                <div class="track-ai-card" id="trackAiCard" style="display:none;">
                    <div class="track-ai-head"><i class="fas fa-wand-magic-sparkles"></i> <span data-i18n="track_ai_title">What our system found in your photos</span></div>
                    <div class="track-ai-badges" id="trackAiBadges"></div>
                    <div class="track-ai-desc" id="trackAiDesc" style="display:none;"></div>
                    <div class="track-ai-urgent" id="trackAiUrgent" style="display:none;"><i class="fas fa-triangle-exclamation"></i> <span data-i18n="track_ai_urgent">Flagged for urgent attention</span></div>
                    <div class="track-ai-disclaimer" data-i18n="track_ai_disclaimer">Automated assessment from your submitted photos — final scope and cost are confirmed by the assigned engineer.</div>
                </div>

                <div class="track-search-again">
                    <button type="button" class="track-again-btn" id="trackAgainLink"><i class="fas fa-magnifying-glass"></i> <span data-i18n="track_search_again">Track another report</span></button>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
</div>

<!-- Confirmation modal — shown before the lookup actually runs -->
<div id="trackConfirmBackdrop">
    <div id="trackConfirmModal">
        <div class="icon-wrap"><span class="icon">🔍</span></div>
        <div class="alert-title" data-i18n="track_confirm_title">Confirm Details</div>
        <div class="alert-desc" data-i18n="track_confirm_desc">Please confirm these are the details you'd like to search with:</div>
        <div class="track-confirm-details">
            <div class="track-confirm-row">
                <span class="label" data-i18n="track_ref_label">Reference Number</span>
                <span class="value" id="trackConfirmRef"></span>
            </div>
            <div class="track-confirm-row">
                <span class="label" data-i18n="track_phone_label">Contact Number Used</span>
                <span class="value" id="trackConfirmPhone"></span>
            </div>
        </div>
        <div class="alert-btns">
            <button class="alert-btn cancel" type="button" id="trackConfirmCancelBtn" data-i18n="track_confirm_edit">Edit</button>
            <button class="alert-btn confirm" type="button" id="trackConfirmOkBtn" data-i18n="track_confirm_ok">Confirm & Search</button>
        </div>
    </div>
</div>

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
                <li><a href="<?= $BASE_URL ?>track_report.php" data-i18n="footer_link_track">Track My Report</a></li>
                <li><a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="footer_link_feedback">Feedback</a></li>
                <li><a href="<?= $BASE_URL ?>about.php" data-i18n="footer_link_about">About Us</a></li>
            </ul>
        </div>
        <div class="footer-links">
            <h4 data-i18n="footer_legal">Legal</h4>
            <ul>
                <li><a href="privacy.php" data-i18n="footer_link_privacy">Privacy Policy</a></li>
                <li><a href="termcon.php" data-i18n="footer_link_terms">Terms of Service</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <div data-i18n="footer_copyright">© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
    </div>
</footer>

<script>
(function () {
    const BASE_URL = <?= json_encode($BASE_URL) ?>;
    const form      = document.getElementById('trackForm');
    const errorBox  = document.getElementById('trackError');
    const submitBtn = document.getElementById('trackSubmitBtn');
    const resultBox = document.getElementById('trackResult');

    const STAGE_LABELS = ['track_stage_pending', 'track_stage_scheduled', 'track_stage_progress', 'track_stage_completed'];
    const STAGE_LABELS_DEFAULT = ['🕓 Pending Review', '📅 Scheduled', '🔄 In Progress', '✅ Completed'];

    function t(key, fallback) {
        const lang = localStorage.getItem('lang') || 'en';
        if (window.__preloadedTranslations && window.__preloadedTranslations[lang] && window.__preloadedTranslations[lang][key]) {
            return window.__preloadedTranslations[lang][key];
        }
        return fallback;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function showToast(type, message) {
        document.querySelectorAll('.notif-popup').forEach(n => n.remove());
        const notif = document.createElement('div');
        notif.className = 'notif-popup notif-' + type;
        const icon = type === 'success' ? '✔️' : type === 'error' ? '❌' : 'ℹ️';
        notif.innerHTML = `<span class="notif-icon">${icon}</span><span class="notif-message">${escapeHtml(message)}</span><button class="notif-close" type="button">&times;</button>`;
        document.body.appendChild(notif);
        const close = () => { notif.style.opacity = '0'; setTimeout(() => notif.remove(), 400); };
        notif.querySelector('.notif-close').addEventListener('click', close);
        setTimeout(close, 4000);
    }

    function badgeClassFor(status) {
        return {
            'Pending Review': 'track-status-pending',
            'Scheduled':       'track-status-scheduled',
            'In Progress':     'track-status-progress',
            'Completed':       'track-status-completed',
            'Rejected':        'track-status-rejected',
        }[status] || 'track-status-pending';
    }

    function bandClassFor(status) {
        return {
            'Pending Review': 'track-band-pending',
            'Scheduled':       'track-band-pending',
            'In Progress':     'track-band-progress',
            'Completed':       'track-band-completed',
            'Rejected':        'track-band-rejected',
        }[status] || 'track-band-pending';
    }

    function severityClass(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) return 'sev-low';
        if (n >= 8) return 'sev-crit';
        if (n >= 6) return 'sev-high';
        if (n >= 4) return 'sev-med';
        return 'sev-low';
    }

    const TIMELINE_THEME_BY_STAGE = ['track-timeline-theme-pending', 'track-timeline-theme-pending', 'track-timeline-theme-progress', 'track-timeline-theme-completed'];

    function renderTimeline(stage) {
        const el = document.getElementById('trackTimeline');
        if (stage === -1) {
            el.className = 'track-timeline track-timeline-theme-rejected';
            el.innerHTML = `
                <div class="track-timeline-step done">
                    <div class="track-timeline-dot"><i class="fas fa-xmark"></i></div>
                    <div class="track-timeline-label">${escapeHtml(t('track_stage_rejected', '❌ Rejected'))}</div>
                </div>`;
            return;
        }
        el.className = 'track-timeline ' + (TIMELINE_THEME_BY_STAGE[stage] || 'track-timeline-theme-pending');
        // The final stage (Completed) has no "next" step to be "current" about —
        // once reached, it's done too, so it gets the same checkmark treatment
        // as every earlier step instead of sitting as an unchecked "current" circle.
        const isFinalStageReached = stage >= STAGE_LABELS.length - 1;
        el.innerHTML = STAGE_LABELS.map((key, i) => {
            const label = t(key, STAGE_LABELS_DEFAULT[i]);
            const isDone = i < stage || (i === stage && isFinalStageReached);
            const cls = isDone ? 'done' : (i === stage ? 'current' : '');
            const icon = isDone ? '<i class="fas fa-check"></i>' : (i + 1);
            return `<div class="track-timeline-step ${cls}">
                        <div class="track-timeline-dot">${icon}</div>
                        <div class="track-timeline-label">${escapeHtml(label)}</div>
                    </div>`;
        }).join('');
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Reference number autofill dropdown — lets a citizen browse/pick an
    // existing report instead of having to remember the exact REQ-### id.
    const refInput    = document.getElementById('trackRef');
    const refDropdown = document.getElementById('trackRefDropdown');
    let refSuggestions = [];
    let refActiveIndex = -1;
    let refFetchToken   = 0;

    function closeRefDropdown() {
        refDropdown.classList.remove('open');
        refDropdown.innerHTML = '';
        refSuggestions = [];
        refActiveIndex = -1;
    }

    function renderRefDropdown(rows) {
        refSuggestions = rows;
        refActiveIndex = -1;
        if (!rows.length) {
            refDropdown.innerHTML = `<div class="track-ref-empty">${escapeHtml(t('track_ref_none', 'No matching reports found'))}</div>`;
            refDropdown.classList.add('open');
            return;
        }
        refDropdown.innerHTML = rows.map((r, i) => `
            <div class="track-ref-option" data-index="${i}">
                <span class="ref-id">${escapeHtml(r.ref_id)}</span>
                <span class="ref-meta">${escapeHtml(r.infrastructure || '')}${r.infrastructure && r.location ? ' — ' : ''}${escapeHtml(r.location || '')}</span>
            </div>`).join('');
        refDropdown.classList.add('open');
        refDropdown.querySelectorAll('.track-ref-option').forEach(opt => {
            opt.addEventListener('mousedown', (e) => {
                e.preventDefault();
                pickRefSuggestion(parseInt(opt.dataset.index, 10));
            });
        });
    }

    function pickRefSuggestion(i) {
        const row = refSuggestions[i];
        if (!row) return;
        refInput.value = row.ref_id;
        closeRefDropdown();
        refInput.focus();
    }

    async function fetchRefSuggestions(q) {
        const token = ++refFetchToken;
        try {
            const res = await fetch(`${BASE_URL}api/track-report-suggest.php?q=${encodeURIComponent(q)}`);
            const json = await res.json();
            if (token !== refFetchToken) return; // a newer keystroke superseded this request
            renderRefDropdown((json && json.data) || []);
        } catch (err) {
            if (token !== refFetchToken) return;
            closeRefDropdown();
        }
    }

    let refDebounceTimer = null;
    refInput.addEventListener('input', () => {
        clearTimeout(refDebounceTimer);
        const q = refInput.value.trim();
        refDebounceTimer = setTimeout(() => fetchRefSuggestions(q), 200);
    });
    refInput.addEventListener('focus', () => fetchRefSuggestions(refInput.value.trim()));
    refInput.addEventListener('blur', () => closeRefDropdown());
    refInput.addEventListener('keydown', (e) => {
        if (!refDropdown.classList.contains('open')) return;
        const opts = refDropdown.querySelectorAll('.track-ref-option');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            refActiveIndex = Math.min(refActiveIndex + 1, opts.length - 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            refActiveIndex = Math.max(refActiveIndex - 1, 0);
        } else if (e.key === 'Enter' && refActiveIndex >= 0) {
            e.preventDefault();
            pickRefSuggestion(refActiveIndex);
            return;
        } else if (e.key === 'Escape') {
            closeRefDropdown();
            return;
        } else {
            return;
        }
        opts.forEach((o, i) => o.classList.toggle('active', i === refActiveIndex));
        if (opts[refActiveIndex]) opts[refActiveIndex].scrollIntoView({ block: 'nearest' });
    });

    const confirmBackdrop = document.getElementById('trackConfirmBackdrop');
    const confirmOkBtn    = document.getElementById('trackConfirmOkBtn');
    const confirmEditBtn  = document.getElementById('trackConfirmCancelBtn');

    function closeConfirmModal() {
        confirmBackdrop.classList.remove('active');
    }

    // Step 1: validate, then show the confirmation box instead of searching right away.
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        errorBox.style.display = 'none';
        resultBox.style.display = 'none';

        const ref   = document.getElementById('trackRef').value.trim();
        const phone = document.getElementById('trackPhone').value.replace(/\D/g, '');

        if (!ref) {
            errorBox.textContent = t('track_err_ref_required', 'Please enter your reference number.');
            errorBox.style.display = 'block';
            return;
        }
        if (!/^09\d{9}$/.test(phone)) {
            errorBox.textContent = t('track_err_phone_invalid', 'Please enter a valid 11-digit contact number starting with 09.');
            errorBox.style.display = 'block';
            return;
        }

        const refDisplay = /^\d+$/.test(ref) ? ('REQ-' + ref) : ref.toUpperCase();
        document.getElementById('trackConfirmRef').textContent = refDisplay;
        document.getElementById('trackConfirmPhone').textContent = phone.replace(/(\d{4})(\d{3})(\d{4})/, '$1-$2-$3');
        confirmBackdrop.classList.add('active');
    });

    confirmEditBtn.addEventListener('click', () => {
        closeConfirmModal();
        document.getElementById('trackRef').focus();
    });
    confirmBackdrop.addEventListener('click', (e) => {
        if (e.target === confirmBackdrop) closeConfirmModal();
    });

    // Step 2: user confirmed — actually run the lookup.
    confirmOkBtn.addEventListener('click', async () => {
        closeConfirmModal();

        const ref   = document.getElementById('trackRef').value.trim();
        const phone = document.getElementById('trackPhone').value.replace(/\D/g, '');

        submitBtn.disabled = true;
        const originalLabel = submitBtn.textContent;
        submitBtn.textContent = t('track_searching', 'Searching…');

        try {
            const res = await fetch(`${BASE_URL}api/track-report.php?ref=${encodeURIComponent(ref)}&phone=${encodeURIComponent(phone)}`);
            const json = await res.json();

            if (!json.success) {
                showToast('error', t('track_err_not_found', 'No matching report found. Double-check your reference number and contact number.'));
                return;
            }

            const d = json.data;
            document.getElementById('trackResultId').textContent = d.req_id;
            document.getElementById('trackResultTitle').textContent = d.infrastructure || t('track_untitled', 'Infrastructure Report');
            document.getElementById('trackResultMeta').textContent = (d.location || '') + ' · ' + t('track_submitted_on', 'Submitted') + ' ' + fmtDate(d.submitted_at);

            const badge = document.getElementById('trackResultBadge');
            badge.className = 'track-status-badge ' + badgeClassFor(d.status);
            badge.textContent = t('track_status_' + d.status.toLowerCase().replace(/\s+/g, '_'), d.status);

            document.getElementById('trackResultBand').className = 'track-result-band ' + bandClassFor(d.status);

            renderTimeline(d.stage);

            const rejBox = document.getElementById('trackRejectionNote');
            if (d.status === 'Rejected' && d.rejection_reason) {
                rejBox.style.display = 'block';
                rejBox.innerHTML = '<strong>' + escapeHtml(t('track_rejection_reason_label', 'Reason:')) + '</strong> ' + escapeHtml(d.rejection_reason);
            } else {
                rejBox.style.display = 'none';
            }

            const grid = document.getElementById('trackDetailGrid');
            const items = [];
            items.push(['fa-file-lines', t('track_detail_issue', '📝 Issue Described'), d.issue || '—']);
            if (d.starting_date) items.push(['fa-calendar-days', t('track_detail_start', '📅 Scheduled Start'), fmtDate(d.starting_date)]);
            if (d.estimated_end_date) items.push(['fa-flag-checkered', t('track_detail_end', '🏁 Est. Completion'), fmtDate(d.estimated_end_date)]);
            if (d.priority) items.push(['fa-gauge-high', t('track_detail_priority', '🚦 Priority'), d.priority]);
            grid.innerHTML = items.map(([, label, value]) =>
                `<div class="track-detail-item"><div class="label">${escapeHtml(label)}</div><div class="value">${escapeHtml(value)}</div></div>`
            ).join('');

            const aiCard   = document.getElementById('trackAiCard');
            const aiBadges = document.getElementById('trackAiBadges');
            const aiDesc   = document.getElementById('trackAiDesc');
            const aiUrgent = document.getElementById('trackAiUrgent');
            if (d.ai_assessment) {
                const ai = d.ai_assessment;
                const badges = [];
                if (ai.severity != null) {
                    badges.push(`<span class="track-ai-badge ${severityClass(ai.severity)}"><span class="track-ai-badge-icon">🎯</span>${escapeHtml(t('track_ai_severity', 'Severity'))}: ${escapeHtml(ai.severity)}/10</span>`);
                }
                if (ai.complexity) {
                    badges.push(`<span class="track-ai-badge info"><span class="track-ai-badge-icon">🔧</span>${escapeHtml(t('track_ai_complexity', 'Complexity'))}: ${escapeHtml(ai.complexity)}</span>`);
                }
                if (ai.estimated_cost) {
                    badges.push(`<span class="track-ai-badge info"><span class="track-ai-badge-icon">💰</span>${escapeHtml(t('track_ai_cost', 'Est. Cost'))}: ${escapeHtml(ai.estimated_cost)}</span>`);
                }
                aiBadges.innerHTML = badges.join('');

                if (ai.description) {
                    aiDesc.innerHTML = `<strong>${escapeHtml(t('track_ai_description', '👁️ What we saw'))}:</strong> ${escapeHtml(ai.description)}`;
                    aiDesc.style.display = '';
                } else {
                    aiDesc.style.display = 'none';
                }

                aiUrgent.style.display = ai.requires_immediate_action ? 'flex' : 'none';

                aiCard.style.display = badges.length || ai.description ? 'block' : 'none';
            } else {
                aiCard.style.display = 'none';
            }

            resultBox.style.display = 'block';
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (err) {
            showToast('error', t('track_err_generic', 'Something went wrong. Please try again.'));
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        }
    });

    document.getElementById('trackAgainLink').addEventListener('click', () => {
        resultBox.style.display = 'none';
        form.reset();
        document.getElementById('trackRef').focus();
    });

    // Auto-search if a reference number was passed in the URL (e.g. straight
    // from the "your request was submitted" screen) — phone still required.
    if (document.getElementById('trackRef').value) {
        document.getElementById('trackPhone').focus();
    }
})();
</script>

<script>
(function () {
    var PAGE_TRANSLATIONS = {
        en: {
            site_title: 'InfraGovServices',
            nav_login: 'Log in', nav_home: 'Home', nav_reports: 'Reports', nav_requests: 'Requests',
            nav_track: 'Track', nav_feedback: 'Feedback', nav_about: 'About',
            translate_btn_title: 'Translate to Filipino', lang_label: 'EN',
            track_title: 'Track My Report',
            track_subtitle: 'No account needed. Enter the reference number you received when you submitted your report, along with the phone number you used, to check its current status.',
            track_ref_label: 'Reference Number',
            track_ref_placeholder: 'e.g. REQ-123 or 123',
            track_ref_none: 'No matching reports found',
            track_phone_label: 'Contact Number Used',
            track_phone_placeholder: '09XXXXXXXXX',
            track_submit: 'Track Report',
            track_searching: 'Searching…',
            track_search_again: 'Track another report',
            track_confirm_title: 'Confirm Details',
            track_confirm_desc: "Please confirm these are the details you'd like to search with:",
            track_confirm_edit: 'Edit',
            track_confirm_ok: 'Confirm & Search',
            track_err_ref_required: 'Please enter your reference number.',
            track_err_phone_invalid: 'Please enter a valid 11-digit contact number starting with 09.',
            track_err_not_found: 'No matching report found. Double-check your reference number and contact number.',
            track_err_generic: 'Something went wrong. Please try again.',
            track_untitled: 'Infrastructure Report',
            track_submitted_on: 'Submitted',
            track_stage_pending: '🕓 Pending Review',
            track_stage_scheduled: '📅 Scheduled',
            track_stage_progress: '🔄 In Progress',
            track_stage_completed: '✅ Completed',
            track_stage_rejected: '❌ Rejected',
            track_status_pending_review: 'Pending Review',
            track_status_scheduled: 'Scheduled',
            track_status_in_progress: 'In Progress',
            track_status_completed: 'Completed',
            track_status_rejected: 'Rejected',
            track_rejection_reason_label: 'Reason:',
            track_detail_issue: '📝 Issue Described',
            track_detail_start: '📅 Scheduled Start',
            track_detail_end: '🏁 Est. Completion',
            track_detail_priority: '🚦 Priority',
            track_ai_title: 'What our system found in your photos',
            track_ai_severity: 'Severity',
            track_ai_complexity: 'Complexity',
            track_ai_cost: 'Est. Cost',
            track_ai_description: '👁️ What we saw',
            track_ai_urgent: 'Flagged for urgent attention',
            track_ai_disclaimer: 'Automated assessment from your submitted photos — final scope and cost are confirmed by the assigned engineer.',
            footer_desc: 'Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.',
            footer_quick_links: 'Quick Links', footer_link_home: 'Home', footer_link_reports: 'Reports',
            footer_link_submit: 'Submit Request', footer_link_track: 'Track My Report', footer_link_feedback: 'Feedback',
            footer_link_about: 'About Us', footer_legal: 'Legal', footer_link_privacy: 'Privacy Policy',
            footer_link_terms: 'Terms of Service',
            footer_copyright: '© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved',
        },
        tl: {
            site_title: 'InfraGovServices',
            nav_login: 'Mag-login', nav_home: 'Tahanan', nav_reports: 'Mga Ulat', nav_requests: 'Mga Kahilingan',
            nav_track: 'Subaybayan', nav_feedback: 'Puna', nav_about: 'Tungkol Sa',
            translate_btn_title: 'I-translate sa Ingles', lang_label: 'FIL',
            track_title: 'Subaybayan ang Aking Ulat',
            track_subtitle: 'Walang kailangang account. Ilagay ang reference number na natanggap mo noong nagsumite ka ng ulat, kasama ang numero ng telepono na ginamit mo, para tingnan ang kasalukuyang katayuan nito.',
            track_ref_label: 'Reference Number',
            track_ref_placeholder: 'hal. REQ-123 o 123',
            track_ref_none: 'Walang natagpuang tumutugmang ulat',
            track_phone_label: 'Numero ng Telepono na Ginamit',
            track_phone_placeholder: '09XXXXXXXXX',
            track_submit: 'Subaybayan ang Ulat',
            track_searching: 'Hinahanap…',
            track_search_again: 'Subaybayan ang ibang ulat',
            track_confirm_title: 'Kumpirmahin ang mga Detalye',
            track_confirm_desc: 'Mangyaring kumpirmahin na ito ang mga detalyeng gusto mong gamitin sa paghahanap:',
            track_confirm_edit: 'I-edit',
            track_confirm_ok: 'Kumpirmahin at Maghanap',
            track_err_ref_required: 'Mangyaring ilagay ang iyong reference number.',
            track_err_phone_invalid: 'Mangyaring maglagay ng wastong 11-digit na numero na nagsisimula sa 09.',
            track_err_not_found: 'Walang natagpuang tumutugmang ulat. Suriin muli ang iyong reference number at numero ng telepono.',
            track_err_generic: 'May naganap na error. Pakisubukang muli.',
            track_untitled: 'Ulat ng Imprastraktura',
            track_submitted_on: 'Isinumite noong',
            track_stage_pending: '🕓 Sinusuri',
            track_stage_scheduled: '📅 Naka-iskedyul',
            track_stage_progress: '🔄 Isinasagawa',
            track_stage_completed: '✅ Natapos',
            track_stage_rejected: '❌ Tinanggihan',
            track_status_pending_review: 'Sinusuri',
            track_status_scheduled: 'Naka-iskedyul',
            track_status_in_progress: 'Isinasagawa',
            track_status_completed: 'Natapos',
            track_status_rejected: 'Tinanggihan',
            track_rejection_reason_label: 'Dahilan:',
            track_detail_issue: '📝 Inilarawang Isyu',
            track_detail_start: '📅 Naka-iskedyul na Simula',
            track_detail_end: '🏁 Tinatayang Pagkumpleto',
            track_detail_priority: '🚦 Prayoridad',
            track_ai_title: 'Ang natuklasan ng aming sistema sa iyong mga larawan',
            track_ai_severity: 'Antas ng Pinsala',
            track_ai_complexity: 'Kumplikasyon',
            track_ai_cost: 'Tinatayang Gastos',
            track_ai_description: '👁️ Ang aming nakita',
            track_ai_urgent: 'Na-flag para sa agarang pansin',
            track_ai_disclaimer: 'Awtomatikong pagsusuri mula sa iyong isinumiteng mga larawan — ang huling saklaw at gastos ay kukumpirmahin ng nakatalagang inhinyero.',
            footer_desc: 'Sistema ng Pamamahala ng Pagpapanatili ng Imprastraktura ng Komunidad para sa Lungsod Quezon.',
            footer_quick_links: 'Mabilis na mga Link', footer_link_home: 'Tahanan', footer_link_reports: 'Mga Ulat',
            footer_link_submit: 'Magsumite ng Kahilingan', footer_link_track: 'Subaybayan ang Aking Ulat', footer_link_feedback: 'Puna',
            footer_link_about: 'Tungkol Sa Amin', footer_legal: 'Ligal', footer_link_privacy: 'Patakaran sa Privacy',
            footer_link_terms: 'Mga Tuntunin ng Serbisyo',
            footer_copyright: '© 2026 LGU Lungsod Quezon · InfraGovServices · Lahat ng Karapatan ay Nakalaan',
        }
    };

    function getTranslation(key) {
        var lang = localStorage.getItem('lang') || 'en';
        if (window.__preloadedTranslations && window.__preloadedTranslations[lang]) {
            var val = window.__preloadedTranslations[lang][key];
            if (val) return val;
        }
        return (PAGE_TRANSLATIONS[lang] && PAGE_TRANSLATIONS[lang][key]) || (PAGE_TRANSLATIONS['en'][key]) || key;
    }

    function applyPageFallbacks() {
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var val = getTranslation(el.getAttribute('data-i18n'));
            if (val) el.textContent = val;
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
            var val = getTranslation(el.getAttribute('data-i18n-placeholder'));
            if (val) el.placeholder = val;
        });
        document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
            var val = getTranslation(el.getAttribute('data-i18n-title'));
            if (val) el.title = val;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPageFallbacks);
    } else {
        applyPageFallbacks();
    }
    document.addEventListener('i18nReady', applyPageFallbacks);
})();
</script>
<?php include __DIR__ . '/../../includes/partials/citizen_global.php'; ?>
<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>functionality/chatbot.php';</script>
<?php include __DIR__ . '/../../includes/partials/chatbot-widget.php'; ?>
</body>
</html>
