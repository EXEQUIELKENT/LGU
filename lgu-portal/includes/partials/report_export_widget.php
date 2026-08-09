<?php
/**
 * report_export_widget.php — shared "Export CSV/PDF" button + modal, reused
 * across requests.php, current_reports.php, pending_reports.php,
 * archive_reports.php, sched.php, emp_feedback.php, road_monitoring.php and
 * case_management.php.
 *
 * Same functionality/design as employee.php's own "Report Generation"
 * feature (password-gated, date-range, CSV/PDF via
 * functionality/generate_report.php) — extracted here instead of duplicated
 * per page because the full feature (CSS + modal + password gate + custom
 * date pickers) is ~1100 lines; every other feature ported this session was
 * small enough to duplicate per-page, this one isn't.
 *
 * Usage — before including this file, the including page must define:
 *   $canGenerateReports = cimm_is_admin() || cimm_is_office_staff();
 *   $exportReportType   = 'requests'; // one of generate_report.php's report_type values
 *   $exportReportLabel  = 'Requests';  // short label for the button/modal title
 *   $exportReportIcon   = '📋';        // emoji icon shown in the modal header
 *
 * The button is only rendered when $canGenerateReports is true. All markup
 * below is already wrapped in that check, so a page can safely include this
 * file unconditionally once those 4 variables are set.
 */
if (!isset($canGenerateReports) || !$canGenerateReports) {
    return;
}
$exportReportType  = $exportReportType  ?? 'requests';
$exportReportLabel = $exportReportLabel ?? 'Report';
$exportReportIcon  = $exportReportIcon  ?? '📄';
?>
<style>
/* ── Export trigger button (top-right, compact — collapses to icon-only on mobile) ── */
.export-report-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    background: linear-gradient(135deg, #1e3faa 0%, #3762c8 55%, #5f8cff 100%);
    color: #fff; border: none; border-radius: 12px;
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 14px rgba(55,98,200,.35);
    transition: transform .2s, box-shadow .2s;
    white-space: nowrap;
}
.export-report-btn:hover { transform: translateY(-2px); box-shadow: 0 7px 20px rgba(55,98,200,.48); }
.export-report-btn:active { transform: translateY(0) scale(.97); }
.export-report-btn i { font-size: 14px; }
@media (max-width: 768px) {
    .export-report-btn { padding: 9px 11px; gap: 0; }
    .export-report-btn .export-btn-label { display: none; }
}

/* ── Report Generation Section (report-modal core — ported from employee.php) ── */
.report-modal-backdrop, #reportModalBackdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.55);
    display: none; align-items: center; justify-content: center;
    z-index: 9100; backdrop-filter: blur(6px);
    animation: modalBackdropIn .22s ease;
}
#reportModalBackdrop.active { display: flex; }
@keyframes modalBackdropIn { from { opacity: 0; } to { opacity: 1; } }
.report-modal {
    background: var(--bg-primary, #fff); border-radius: 20px;
    width: 420px; max-width: 92vw; max-height: 88vh; overflow-y: auto;
    box-shadow: 0 25px 60px rgba(0,0,0,.3);
    animation: reportModalPop .28s cubic-bezier(.34,1.56,.64,1);
}
@keyframes reportModalPop { from { opacity: 0; transform: translateY(20px) scale(.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
.report-modal-header {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1e3faa 0%, #3762c8 55%, #5f8cff 100%);
    padding: 22px 26px; border-radius: 20px 20px 0 0;
    display: flex; align-items: center; justify-content: space-between;
}
.report-modal-header-left { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
.report-modal-icon-wrap {
    width: 44px; height: 44px; border-radius: 12px;
    background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.report-modal-header h3 { color: #fff; font-size: 16.5px; font-weight: 700; margin: 0; }
.report-modal-header h3 small { display: block; font-size: 11.5px; font-weight: 500; color: rgba(255,255,255,.78); margin-top: 3px; }
.report-modal-close {
    background: rgba(255,255,255,.15); border: none; color: #fff;
    width: 30px; height: 30px; border-radius: 9px; font-size: 18px; cursor: pointer;
    position: relative; z-index: 1; transition: background .2s;
}
.report-modal-close:hover { background: rgba(255,255,255,.28); }
.report-modal-body { padding: 24px 26px 26px; }
.report-modal-body .form-group { margin-bottom: 20px; animation: sectionReveal .35s ease both; }
@keyframes sectionReveal { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.report-modal-body label { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 9px; }
.date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.date-field-wrap { position: relative; }
.date-field-wrap:focus-within { z-index: 2; }
.date-field-sub-label { display: block; font-size: 10.5px; color: var(--text-secondary); margin-bottom: 4px; opacity: .75; }
.rpt-date-display {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 11px 13px; border: 1.5px solid var(--border-color); border-radius: 11px;
    background: var(--bg-secondary); color: var(--text-primary); font-size: 13px; cursor: pointer;
    transition: border-color .2s, box-shadow .2s;
}
.rpt-date-display:hover { border-color: #3762c8; }
.rpt-date-display:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.12); outline: none; }
.rpt-date-display .rdt-text { flex: 1; }
.rpt-date-display .rdt-text.placeholder { color: var(--text-secondary); opacity: .6; }
.rpt-date-display .rdt-icon { font-size: 15px; margin-left: 8px; flex-shrink: 0; opacity: .7; }

/* ── Custom date picker overlay (shared component, ported verbatim) ── */
.rdt-picker-overlay {
    position: fixed; display: none; z-index: 9300; width: 284px;
    background: var(--bg-primary, #fff); border: 1px solid var(--border-color);
    border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.25), 0 4px 16px rgba(0,0,0,.12);
    overflow: hidden; overflow-y: auto; max-height: 80vh;
}
.rdt-picker-overlay::-webkit-scrollbar { width: 5px; }
.rdt-picker-overlay::-webkit-scrollbar-track { background: transparent; }
.rdt-picker-overlay::-webkit-scrollbar-thumb { background: rgba(55,98,200,.25); border-radius: 4px; }
@keyframes rdtPopIn { from { opacity: 0; transform: scale(0.94) translateY(-6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.rdt-dp-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; background: linear-gradient(135deg, #1e3faa, #3762c8); }
.rdt-dp-nav { width: 28px; height: 28px; border-radius: 8px; border: none; background: rgba(255,255,255,.18); color: #fff; font-size: 13px; cursor: pointer; transition: all .15s; }
.rdt-dp-nav:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.rdt-dp-nav:active { transform: scale(0.95); }
.rdt-dp-header-center { display: flex; gap: 6px; }
.rdt-dp-month-btn, .rdt-dp-year-btn { background: rgba(255,255,255,.12); border: none; color: #fff; font-size: 12.5px; font-weight: 700; padding: 5px 10px; border-radius: 7px; cursor: pointer; transition: background .15s; }
.rdt-dp-month-btn:hover, .rdt-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.rdt-dp-month-btn.active, .rdt-dp-year-btn.active { background: #fff; color: #3762c8; }
.rdt-year-dropdown { display: none; max-height: 200px; overflow-y: auto; padding: 8px; border-bottom: 1px solid var(--border-color); }
.rdt-year-dropdown::-webkit-scrollbar { width: 5px; }
.rdt-year-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.rdt-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.rdt-year-opt { padding: 7px 0; border: none; border-radius: 7px; background: transparent; color: var(--text-primary); font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; }
.rdt-year-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.rdt-year-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.rdt-month-dropdown { display: none; padding: 8px; border-bottom: 1px solid var(--border-color); }
.rdt-month-dropdown::-webkit-scrollbar { width: 5px; }
.rdt-month-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.rdt-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.rdt-month-opt { padding: 8px 0; border: none; border-radius: 7px; background: transparent; color: var(--text-primary); font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; }
.rdt-month-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.rdt-month-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.rdt-dp-weekdays { display: grid; grid-template-columns: repeat(7,1fr); padding: 8px 8px 0; }
.rdt-dp-weekdays span { text-align: center; font-size: 10.5px; font-weight: 700; color: var(--text-secondary); padding-bottom: 6px; }
.rdt-dp-weekdays span:first-child, .rdt-dp-weekdays span:last-child { color: #f87171; }
.rdt-dp-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 2px; padding: 0 8px 8px; }
.rdt-dp-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border: none; border-radius: 8px; background: transparent; color: var(--text-primary); font-size: 12px; cursor: pointer; transition: all .12s; }
.rdt-dp-day:hover         { background: #eef2ff; color: #3762c8; transform: scale(1.12); }
.rdt-dp-day:active        { transform: scale(0.95); }
.rdt-dp-day.rdt-empty     { cursor: default; pointer-events: none; }
.rdt-dp-day.rdt-weekend   { color: #ef4444; }
.rdt-dp-day.rdt-weekend:hover { background: #fff0f0; color: #dc2626; }
.rdt-dp-day.rdt-today     { background: rgba(55,98,200,.1); color: #3762c8; font-weight: 700; position: relative; }
.rdt-dp-day.rdt-today::after { content: ''; position: absolute; bottom: 3px; width: 4px; height: 4px; border-radius: 50%; background: #3762c8; }
.rdt-dp-day.rdt-selected  { background: #3762c8 !important; color: #fff !important; font-weight: 700; }
.rdt-dp-day.rdt-selected::after { display: none; }
.rdt-dp-footer { display: flex; justify-content: space-between; padding: 10px 12px; border-top: 1px solid var(--border-color); }
.rdt-dp-clear { background: transparent; border: 1px solid var(--border-color); color: #ef4444; font-size: 11.5px; font-weight: 600; padding: 6px 12px; border-radius: 8px; cursor: pointer; transition: all .15s; }
.rdt-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.rdt-dp-done { background: #3762c8; border: none; color: #fff; font-size: 11.5px; font-weight: 700; padding: 6px 14px; border-radius: 8px; cursor: pointer; transition: opacity .15s; }
.rdt-dp-done:hover { opacity: .88; }
[data-theme="dark"] .rdt-picker-overlay { background: #1e2235; border-color: rgba(95,140,255,.2); box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3); }
[data-theme="dark"] .rdt-dp-day  { color: #e2e8f0; }
[data-theme="dark"] .rdt-dp-day:hover { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-day.rdt-weekend { color: #f87171; }
[data-theme="dark"] .rdt-dp-day.rdt-today   { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-day.rdt-today::after { background: #8ab4f8; }
[data-theme="dark"] .rdt-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdt-dp-weekdays span  { color: #64748b; }
[data-theme="dark"] .rdt-dp-weekdays span:first-child, [data-theme="dark"] .rdt-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .rdt-year-dropdown, [data-theme="dark"] .rdt-month-dropdown { background: #1e2235; border-bottom-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdt-year-opt, [data-theme="dark"] .rdt-month-opt { color: #e2e8f0; }
[data-theme="dark"] .rdt-year-opt:hover, [data-theme="dark"] .rdt-month-opt:hover { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .rdt-dp-clear:hover { background: rgba(239,68,68,.1); }

/* ── Format toggle ── */
.format-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.fmt-btn { padding: 12px 10px; border: 1.5px solid var(--border-color); border-radius: 12px; background: var(--bg-secondary); color: var(--text-primary); font-size: 13.5px; font-weight: 600; cursor: pointer; transition: all .2s; text-align: center; display: flex; align-items: center; justify-content: center; gap: 7px; }
.fmt-btn i { font-size: 15px; }
.fmt-btn:hover:not(.active) { border-color: rgba(55,98,200,.4); background: rgba(55,98,200,.06); }
.fmt-btn.active { border-color: #3762c8; background: linear-gradient(135deg, rgba(55,98,200,.14), rgba(95,140,255,.10)); color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.10); }

/* ── Generate button with ripple ── */
.btn-generate { width: 100%; padding: 14px; background: linear-gradient(135deg, #1e3faa 0%, #3762c8 55%, #5f8cff 100%); color: #fff; border: none; border-radius: 14px; font-size: 15px; font-weight: 700; cursor: pointer; transition: transform .2s, box-shadow .2s, opacity .2s; margin-top: 4px; display: flex; align-items: center; justify-content: center; gap: 9px; position: relative; overflow: hidden; letter-spacing: .01em; box-shadow: 0 4px 18px rgba(55,98,200,.35); }
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(55,98,200,.50); }
.btn-generate:active { transform: scale(.97); }
.btn-generate:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.btn-ripple { position: absolute; border-radius: 50%; width: 80px; height: 80px; margin-top: -40px; margin-left: -40px; background: rgba(255,255,255,.45); pointer-events: none; animation: rippleExpand .55s ease-out forwards; }
@keyframes rippleExpand { from { transform: scale(0); opacity: 1; } to { transform: scale(3); opacity: 0; } }
.report-info-text { font-size: 11px; color: var(--text-secondary); text-align: center; margin-top: 11px; opacity: .8; }

/* ── Password confirmation modal ── */
#pwModalBackdrop { position: fixed; inset: 0; background: rgba(0,0,0,.60); display: none; align-items: center; justify-content: center; z-index: 9400; backdrop-filter: blur(6px); animation: modalBackdropIn .22s ease; }
#pwModalBackdrop.active { display: flex; }
.pw-modal { background: var(--bg-primary, #fff); border-radius: 20px; width: 380px; max-width: 90vw; box-shadow: 0 25px 60px rgba(0,0,0,.3); animation: reportModalPop .28s cubic-bezier(.34,1.56,.64,1); }
.pw-modal-header { display: flex; align-items: center; gap: 14px; padding: 24px 26px 4px; }
.pw-modal-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(55,98,200,.12); color: #3762c8; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.pw-modal-header-text h3 { font-size: 15.5px; font-weight: 700; color: var(--text-primary); margin: 0 0 3px; }
.pw-modal-header-text p { font-size: 12px; color: var(--text-secondary); }
.pw-modal-body { padding: 18px 26px; }
.pw-modal-body label { display: block; font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
.pw-input-wrap { position: relative; }
.pw-input-wrap input { width: 100%; padding: 12px 42px 12px 13px; border: 1.5px solid var(--border-color); border-radius: 11px; background: var(--bg-secondary); color: var(--text-primary); font-size: 13.5px; transition: border-color .2s, box-shadow .2s; }
.pw-input-wrap input:focus { outline: none; border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.12); }
.pw-input-wrap input.pw-error { border-color: #ef4444; }
.pw-toggle-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: var(--text-secondary); font-size: 15px; cursor: pointer; padding: 6px; }
.pw-error-msg { display: none; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; color: #ef4444; }
.pw-error-msg.show { display: flex; }
.pw-attempts-msg { display: none; margin-top: 8px; font-size: 11px; color: var(--text-secondary); text-align: center; opacity: .8; }
.pw-attempts-msg.show { display: block; }
.pw-modal-footer { padding: 4px 26px 26px; display: flex; gap: 10px; }
.pw-cancel-btn { flex: 1; padding: 13px; border: 1.5px solid var(--border-color); border-radius: 14px; background: var(--bg-secondary); color: var(--text-primary); font-size: 14px; font-weight: 600; cursor: pointer; transition: all .2s; }
.pw-cancel-btn:hover { background: rgba(55,98,200,.07); border-color: rgba(55,98,200,.4); color: #3762c8; }
.pw-confirm-btn { flex: 2; padding: 13px; background: linear-gradient(135deg, #1e3faa 0%, #3762c8 55%, #5f8cff 100%); color: #fff; border: none; border-radius: 14px; font-size: 14px; font-weight: 700; cursor: pointer; transition: transform .2s, box-shadow .2s, opacity .2s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 18px rgba(55,98,200,.35); position: relative; overflow: hidden; letter-spacing: .01em; }
.pw-confirm-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(55,98,200,.50); }
.pw-confirm-btn:active:not(:disabled) { transform: scale(.97); }
.pw-confirm-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.pw-spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: pw-spin .7s linear infinite; display: none; }
@keyframes pw-spin { to { transform: rotate(360deg); } }
</style>

<button type="button" class="export-report-btn" id="exportReportTriggerBtn" title="Export <?= htmlspecialchars($exportReportLabel) ?> report">
    <i class="fas fa-download"></i><span class="export-btn-label">Export</span>
</button>

<div id="reportModalBackdrop">
    <div class="report-modal">
        <div class="report-modal-header">
            <div class="report-modal-header-left">
                <div class="report-modal-icon-wrap" id="reportModalIconWrap"><?= $exportReportIcon ?></div>
                <h3 id="reportModalTitle"><?= htmlspecialchars($exportReportLabel) ?> Report<small>Select date range &amp; export format</small></h3>
            </div>
            <button class="report-modal-close" id="reportModalClose" title="Close">&times;</button>
        </div>
        <div class="report-modal-body">
            <div class="form-group">
                <label>Date Range</label>
                <div class="date-row">
                    <div class="date-field-wrap">
                        <span class="date-field-sub-label">From</span>
                        <div class="rpt-date-display" id="rptFromDisplay" tabindex="0" role="button" aria-label="Select start date">
                            <span class="rdt-text" id="rptFromText"><?= date('M d, Y', strtotime(date('Y-m-01'))) ?></span>
                            <span class="rdt-icon"><i class="far fa-calendar-alt"></i></span>
                        </div>
                        <input type="hidden" id="rptDateFrom" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="date-field-wrap">
                        <span class="date-field-sub-label">To</span>
                        <div class="rpt-date-display" id="rptToDisplay" tabindex="0" role="button" aria-label="Select end date">
                            <span class="rdt-text" id="rptToText"><?= date('M d, Y') ?></span>
                            <span class="rdt-icon"><i class="far fa-calendar-alt"></i></span>
                        </div>
                        <input type="hidden" id="rptDateTo" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Export Format</label>
                <div class="format-toggle">
                    <button class="fmt-btn active" id="fmtExcel" onclick="selectFormat('csv')" type="button">
                        <i class="fas fa-file-csv"></i> CSV (.csv)
                    </button>
                    <button class="fmt-btn" id="fmtPdf" onclick="selectFormat('pdf')" type="button">
                        <i class="fas fa-file-pdf"></i> PDF (Print)
                    </button>
                </div>
            </div>
            <button class="btn-generate" id="btnGenerate" onclick="startGenerate(event)" type="button">
                <span id="btnGenerateText"><i class="fas fa-lock"></i> Verify &amp; Generate</span>
            </button>
            <p class="report-info-text">
                You will be asked to confirm your password before the report is created.
            </p>
            <form id="reportForm" action="../functionality/generate_report.php" method="POST" target="_blank" style="display:none">
                <input type="hidden" name="report_type"   id="rptTypeInput" value="<?= htmlspecialchars($exportReportType) ?>">
                <input type="hidden" name="format"        id="rptFormatInput">
                <input type="hidden" name="date_from"     id="rptFromInput">
                <input type="hidden" name="date_to"       id="rptToInput">
                <input type="hidden" name="report_token"  id="rptTokenInput">
            </form>
        </div>
    </div>
</div>

<div class="rdt-picker-overlay" id="rptFromPickerOverlay">
    <div class="rdt-dp-header">
        <button class="rdt-dp-nav" id="rptFromPrev" type="button">&#8592;</button>
        <div class="rdt-dp-header-center">
            <button class="rdt-dp-month-btn" id="rptFromMonthBtn" type="button"></button>
            <button class="rdt-dp-year-btn"  id="rptFromYearBtn"  type="button"></button>
        </div>
        <button class="rdt-dp-nav" id="rptFromNext" type="button">&#8594;</button>
    </div>
    <div class="rdt-year-dropdown"  id="rptFromYearDrop"></div>
    <div class="rdt-month-dropdown" id="rptFromMonthDrop">
        <button class="rdt-month-opt" data-month="0"  type="button">Jan</button><button class="rdt-month-opt" data-month="1"  type="button">Feb</button><button class="rdt-month-opt" data-month="2"  type="button">Mar</button>
        <button class="rdt-month-opt" data-month="3"  type="button">Apr</button><button class="rdt-month-opt" data-month="4"  type="button">May</button><button class="rdt-month-opt" data-month="5"  type="button">Jun</button>
        <button class="rdt-month-opt" data-month="6"  type="button">Jul</button><button class="rdt-month-opt" data-month="7"  type="button">Aug</button><button class="rdt-month-opt" data-month="8"  type="button">Sep</button>
        <button class="rdt-month-opt" data-month="9"  type="button">Oct</button><button class="rdt-month-opt" data-month="10" type="button">Nov</button><button class="rdt-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdt-dp-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
    <div class="rdt-dp-grid" id="rptFromGrid"></div>
    <div class="rdt-dp-footer">
        <button class="rdt-dp-clear" id="rptFromClear" type="button">Clear</button>
        <button class="rdt-dp-done"  id="rptFromDone"  type="button">Done</button>
    </div>
</div>
<div class="rdt-picker-overlay" id="rptToPickerOverlay">
    <div class="rdt-dp-header">
        <button class="rdt-dp-nav" id="rptToPrev" type="button">&#8592;</button>
        <div class="rdt-dp-header-center">
            <button class="rdt-dp-month-btn" id="rptToMonthBtn" type="button"></button>
            <button class="rdt-dp-year-btn"  id="rptToYearBtn"  type="button"></button>
        </div>
        <button class="rdt-dp-nav" id="rptToNext" type="button">&#8594;</button>
    </div>
    <div class="rdt-year-dropdown"  id="rptToYearDrop"></div>
    <div class="rdt-month-dropdown" id="rptToMonthDrop">
        <button class="rdt-month-opt" data-month="0"  type="button">Jan</button><button class="rdt-month-opt" data-month="1"  type="button">Feb</button><button class="rdt-month-opt" data-month="2"  type="button">Mar</button>
        <button class="rdt-month-opt" data-month="3"  type="button">Apr</button><button class="rdt-month-opt" data-month="4"  type="button">May</button><button class="rdt-month-opt" data-month="5"  type="button">Jun</button>
        <button class="rdt-month-opt" data-month="6"  type="button">Jul</button><button class="rdt-month-opt" data-month="7"  type="button">Aug</button><button class="rdt-month-opt" data-month="8"  type="button">Sep</button>
        <button class="rdt-month-opt" data-month="9"  type="button">Oct</button><button class="rdt-month-opt" data-month="10" type="button">Nov</button><button class="rdt-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdt-dp-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
    <div class="rdt-dp-grid" id="rptToGrid"></div>
    <div class="rdt-dp-footer">
        <button class="rdt-dp-clear" id="rptToClear" type="button">Clear</button>
        <button class="rdt-dp-done"  id="rptToDone"  type="button">Done</button>
    </div>
</div>

<div id="pwModalBackdrop">
    <div class="pw-modal">
        <div class="pw-modal-header">
            <div class="pw-modal-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="pw-modal-header-text">
                <h3>Confirm Your Identity</h3>
                <p>Enter your account password to generate this report.</p>
            </div>
        </div>
        <div class="pw-modal-body">
            <!--
                Fix: this field previously had no enclosing <form>, so when a
                browser detected it as password-like (it becomes type=password
                on focus) it searched the WHOLE document — not just this modal
                — for the nearest text input to pair as "username", and could
                land on the page's own search box, stuffing a saved email into
                it. Scoping everything in its own <form> with a hidden decoy
                username field keeps that autofill pairing confined to here.
                autocomplete="new-password" is used instead of "off" because
                Chrome specifically ignores "off" for password-like fields but
                does honor "new-password".
            -->
            <form id="pwConfirmForm" autocomplete="off" onsubmit="return false;">
            <label for="pwInput">Password</label>
            <div class="pw-input-wrap">
                <input type="text" name="username" autocomplete="username"
                       tabindex="-1" aria-hidden="true"
                       style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
                <input type="text" id="pwInput" name="export_identity_pw"
                       placeholder="Enter your password"
                       autocomplete="new-password"
                       data-lpignore="true"
                       data-form-type="other"
                       style="-webkit-text-security:disc;font-family:text-security-disc,inherit"
                       onfocus="this.type='password';this.style.removeProperty('-webkit-text-security')"
                       onblur="if(!this.value){this.type='text';this.style.setProperty('-webkit-text-security','disc')}">
                <button class="pw-toggle-btn" type="button"
                        id="pwToggleBtn" title="Show/hide password"
                        tabindex="-1"><i class="far fa-eye-slash"></i></button>
            </div>
            </form>
            <div class="pw-error-msg" id="pwErrorMsg">
                <span>⚠️</span><span id="pwErrorText">Incorrect password.</span>
            </div>
            <div class="pw-attempts-msg" id="pwAttemptsMsg"></div>
        </div>
        <div class="pw-modal-footer">
            <button class="pw-cancel-btn" id="pwCancelBtn">Cancel</button>
            <button class="pw-confirm-btn" id="pwConfirmBtn">
                <div class="pw-spinner" id="pwSpinner"></div>
                <span id="pwConfirmText"><i class="fas fa-lock"></i> Verify &amp; Continue</span>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Escape whatever ancestor this widget was included inside ──────────
    // The trigger button lives inline wherever a page puts the include (e.g.
    // inside .page-header, itself inside .card/.chart-card) — but several of
    // those ancestors set backdrop-filter (or similar), which per the CSS
    // spec creates a new containing block for position:fixed descendants.
    // That silently turns "cover the whole viewport, centered, backdrop
    // blurred" into "cover just the ancestor's box" — the modal/pickers
    // rendered squashed inside the card instead of centered over the page.
    // employee.php's own reference implementation never hit this because its
    // modal markup sits at the very end of <body>, outside any such
    // ancestor. Moving these 4 fixed-position overlays to be direct children
    // of <body> reproduces that same safe placement regardless of where the
    // trigger button itself lives.
    ['reportModalBackdrop', 'rptFromPickerOverlay', 'rptToPickerOverlay', 'pwModalBackdrop'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentElement !== document.body) document.body.appendChild(el);
    });

    // ── State ────────────────────────────────────────────────────────────
    let _rptFormat = 'csv';

    document.getElementById('exportReportTriggerBtn').addEventListener('click', openReportModal);

    function openReportModal() {
        document.getElementById('reportModalBackdrop').classList.add('active');
        resetBtnGenerate();
    }
    window.openReportModal = openReportModal;

    function closeReportModal() {
        document.getElementById('reportModalBackdrop').classList.remove('active');
        resetBtnGenerate();
    }

    window.selectFormat = function (fmt) {
        _rptFormat = fmt;
        document.getElementById('fmtExcel').classList.toggle('active', fmt === 'csv');
        document.getElementById('fmtPdf').classList.toggle('active',   fmt === 'pdf');
    };

    function resetBtnGenerate() {
        const btn = document.getElementById('btnGenerate');
        btn.disabled = false;
        document.getElementById('btnGenerateText').innerHTML = '<i class="fas fa-lock"></i> Verify & Generate';
    }

    // ── Step 1: Validate date inputs, then open password gate ────────────
    window.startGenerate = function (event) {
        const from = document.getElementById('rptDateFrom').value;
        const to   = document.getElementById('rptDateTo').value;
        if (!from || !to) { alert('Please select both a start and end date.'); return; }
        if (from > to)    { alert('Start date must be before or equal to end date.'); return; }

        const btn = document.getElementById('btnGenerate');
        if (event) {
            const rect   = btn.getBoundingClientRect();
            const x      = event.clientX - rect.left;
            const y      = event.clientY - rect.top;
            const ripple = document.createElement('span');
            ripple.className = 'btn-ripple';
            ripple.style.left = x + 'px';
            ripple.style.top  = y + 'px';
            btn.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());
        }

        document.getElementById('rptFormatInput').value = _rptFormat;
        document.getElementById('rptFromInput').value   = from;
        document.getElementById('rptToInput').value     = to;

        setTimeout(() => {
            closeReportModal();
            openPwModal();
        }, 260);
    };

    // ── Password modal ────────────────────────────────────────────────────
    function openPwModal() {
        const backdrop = document.getElementById('pwModalBackdrop');
        backdrop.classList.add('active');
        const input = document.getElementById('pwInput');
        input.value = '';
        input.type  = 'text';
        input.style.setProperty('-webkit-text-security', 'disc');
        const toggle = document.getElementById('pwToggleBtn');
        if (toggle) toggle.innerHTML = '<i class="far fa-eye-slash"></i>';
        hidePwError();
        document.getElementById('pwAttemptsMsg').classList.remove('show');
        document.getElementById('pwConfirmBtn').disabled = false;
        document.getElementById('pwConfirmText').style.display = '';
        document.getElementById('pwSpinner').style.display = 'none';
        setTimeout(() => input.focus(), 80);
    }

    function closePwModal() {
        document.getElementById('pwModalBackdrop').classList.remove('active');
        document.getElementById('pwInput').value = '';
        hidePwError();
    }

    function showPwError(msg) {
        const el = document.getElementById('pwErrorMsg');
        document.getElementById('pwErrorText').textContent = msg;
        el.classList.add('show');
        document.getElementById('pwInput').classList.add('pw-error');
    }

    function hidePwError() {
        document.getElementById('pwErrorMsg').classList.remove('show');
        document.getElementById('pwInput').classList.remove('pw-error');
    }

    document.getElementById('pwToggleBtn').addEventListener('click', function () {
        const input = document.getElementById('pwInput');
        const isHidden = input.type === 'password' ||
                         (input.type === 'text' && input.style.webkitTextSecurity === 'disc');
        if (isHidden) {
            input.type = 'text';
            input.style.removeProperty('-webkit-text-security');
            this.innerHTML = '<i class="far fa-eye"></i>';
        } else {
            input.type = 'text';
            input.style.setProperty('-webkit-text-security', 'disc');
            this.innerHTML = '<i class="far fa-eye-slash"></i>';
        }
    });

    // ── Step 2: Verify password via AJAX ──────────────────────────────────
    async function verifyAndGenerate() {
        const password = document.getElementById('pwInput').value;
        if (!password) {
            showPwError('Please enter your password.');
            document.getElementById('pwInput').focus();
            return;
        }

        const confirmBtn = document.getElementById('pwConfirmBtn');
        const confirmTxt = document.getElementById('pwConfirmText');
        const spinner    = document.getElementById('pwSpinner');
        confirmBtn.disabled = true;
        confirmTxt.style.display = 'none';
        spinner.style.display    = 'block';
        hidePwError();

        let resp;
        try {
            resp = await fetch('../functionality/verify_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ password })
            });

            let data;
            try { data = await resp.json(); }
            catch (e) {
                showPwError('Server error. Please try again.');
                return;
            }

            if (data.success && data.token) {
                document.getElementById('rptTokenInput').value = data.token;
                closePwModal();

                const form = document.getElementById('reportForm');
                form.target = (_rptFormat === 'csv') ? '_self' : '_blank';
                form.submit();

                if (_rptFormat === 'csv') {
                    setTimeout(resetBtnGenerate, 4500);
                }
            } else {
                showPwError(data.message || 'Incorrect password. Please try again.');
                document.getElementById('pwInput').value = '';
                document.getElementById('pwInput').focus();

                const attemptsMsg = document.getElementById('pwAttemptsMsg');
                attemptsMsg.textContent = 'Note: Multiple failed attempts will temporarily lock verification.';
                attemptsMsg.classList.add('show');

                if (resp.status === 429) {
                    showPwError(data.message || 'Too many attempts. Please wait before trying again.');
                    confirmBtn.disabled = true;
                }
            }
        } catch (err) {
            showPwError('Network error. Please check your connection.');
        } finally {
            if (!resp || resp.status !== 429) {
                confirmBtn.disabled = false;
                confirmTxt.style.display = '';
                spinner.style.display    = 'none';
            }
        }
    }

    document.getElementById('pwConfirmBtn').addEventListener('click', async function () {
        await verifyAndGenerate();
        if (!this.disabled) {
            document.getElementById('pwSpinner').style.display = 'none';
            document.getElementById('pwConfirmText').style.display = '';
        }
    });

    document.getElementById('pwInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('pwConfirmBtn').click();
        }
    });

    document.getElementById('pwCancelBtn').addEventListener('click', closePwModal);
    document.getElementById('pwModalBackdrop').addEventListener('click', function (e) {
        if (e.target === this) closePwModal();
    });
    document.getElementById('reportModalClose').addEventListener('click', closeReportModal);
    document.getElementById('reportModalBackdrop').addEventListener('click', function (e) {
        if (e.target === this) closeReportModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (document.getElementById('pwModalBackdrop').classList.contains('active'))  closePwModal();
        if (document.getElementById('reportModalBackdrop').classList.contains('active')) closeReportModal();
    });

    // ── Custom date pickers (shared component) ────────────────────────────
    var MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var today = new Date();

    function pad2(n) { return String(n).padStart(2,'0'); }
    function fmtISO(d) { return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }
    function fmtDisplay(d) { return MONTHS_SHORT[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }
    function parseISO(s) { var p=s.split('-'); return new Date(+p[0],+p[1]-1,+p[2]); }

    function makePicker(cfg) {
        var viewYear, viewMonth, selDate;

        function init() {
            var v = cfg.hiddenInput.value;
            selDate = v ? parseISO(v) : null;
            viewYear  = selDate ? selDate.getFullYear()  : today.getFullYear();
            viewMonth = selDate ? selDate.getMonth()     : today.getMonth();
        }

        function setSelected(d) {
            selDate = d;
            cfg.hiddenInput.value = d ? fmtISO(d) : '';
            cfg.textEl.textContent = d ? fmtDisplay(d) : cfg.placeholder;
            cfg.textEl.classList.toggle('placeholder', !d);
        }

        function renderGrid() {
            cfg.yearDrop.classList.remove('open');
            cfg.monthDrop.classList.remove('open');
            cfg.yearBtn.classList.remove('active');
            cfg.monthBtn.classList.remove('active');

            cfg.monthBtn.textContent = MONTHS_SHORT[viewMonth];
            cfg.yearBtn.textContent  = viewYear;

            var firstDay    = new Date(viewYear, viewMonth, 1).getDay();
            var daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
            var todayStr    = fmtISO(today);
            var selStr      = selDate ? fmtISO(selDate) : '';

            cfg.grid.innerHTML = '';
            for (var i = 0; i < firstDay; i++) {
                var emp = document.createElement('div');
                emp.className = 'rdt-dp-day rdt-empty';
                cfg.grid.appendChild(emp);
            }
            for (var d = 1; d <= daysInMonth; d++) {
                var dateObj = new Date(viewYear, viewMonth, d);
                var dateStr = fmtISO(dateObj);
                var dow     = dateObj.getDay();
                var btn     = document.createElement('button');
                btn.type = 'button'; btn.className = 'rdt-dp-day';
                btn.textContent  = d;
                btn.dataset.date = dateStr;
                if (dow === 0 || dow === 6)  btn.classList.add('rdt-weekend');
                if (dateStr === todayStr)    btn.classList.add('rdt-today');
                if (dateStr === selStr)      btn.classList.add('rdt-selected');
                if (!cfg.allowFuture && dateObj > today) btn.classList.add('rdt-future');
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var p = this.dataset.date.split('-');
                    setSelected(new Date(+p[0],+p[1]-1,+p[2]));
                    renderGrid();
                });
                cfg.grid.appendChild(btn);
            }
        }

        function buildYearGrid() {
            cfg.yearDrop.innerHTML = '';
            var endY = today.getFullYear() + (cfg.allowFuture ? 10 : 0);
            for (var y = endY; y >= endY - 109; y--) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'rdt-year-opt' + (y === viewYear ? ' selected' : '');
                b.textContent  = y; b.dataset.year = y;
                b.addEventListener('click', function (e) {
                    e.stopPropagation(); viewYear = +this.dataset.year; renderGrid();
                });
                cfg.yearDrop.appendChild(b);
            }
            setTimeout(function () {
                var sel = cfg.yearDrop.querySelector('.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function positionOverlay() {
            var rect = cfg.display.getBoundingClientRect();
            var vw = window.innerWidth, vh = window.innerHeight;
            cfg.overlay.style.visibility = 'hidden';
            cfg.overlay.style.display    = 'block';
            var ow = cfg.overlay.offsetWidth  || 284;
            var oh = Math.min(cfg.overlay.scrollHeight || 380, vh * 0.8);
            cfg.overlay.style.visibility = '';
            var top  = rect.bottom + 6;
            var left = rect.left + rect.width / 2 - ow / 2;
            left = Math.max(8, Math.min(left, vw - ow - 8));
            if (top + oh > vh - 10 && rect.top > oh + 10) top = rect.top - oh - 6;
            if (top < 8) top = 8;
            cfg.overlay.style.top  = top  + 'px';
            cfg.overlay.style.left = left + 'px';
            cfg.overlay.style.display = 'none';
        }

        function openPicker() {
            init();
            renderGrid();
            positionOverlay();
            cfg.overlay.style.removeProperty('animation');
            cfg.overlay.style.display    = 'block';
            cfg.overlay.style.visibility = 'visible';
            void cfg.overlay.offsetWidth;
            cfg.overlay.style.animation = 'rdtPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
        }
        function closePicker() { cfg.overlay.style.display = 'none'; }
        function isOpen() { return cfg.overlay.style.display === 'block'; }

        cfg.display.addEventListener('click', function () {
            isOpen() ? closePicker() : openPicker();
        });
        cfg.display.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); isOpen() ? closePicker() : openPicker(); }
            if (e.key === 'Escape') closePicker();
        });
        cfg.prevBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
            renderGrid();
        });
        cfg.nextBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
            renderGrid();
        });
        cfg.yearBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            cfg.monthDrop.classList.remove('open'); cfg.monthBtn.classList.remove('active');
            var nowOpen = cfg.yearDrop.classList.toggle('open');
            cfg.yearBtn.classList.toggle('active', nowOpen);
            if (nowOpen) buildYearGrid();
        });
        cfg.monthBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            cfg.yearDrop.classList.remove('open'); cfg.yearBtn.classList.remove('active');
            var nowOpen = cfg.monthDrop.classList.toggle('open');
            cfg.monthBtn.classList.toggle('active', nowOpen);
            Array.from(cfg.monthDrop.querySelectorAll('.rdt-month-opt')).forEach(function (b) {
                b.classList.toggle('selected', +b.dataset.month === viewMonth);
            });
        });
        cfg.monthDrop.addEventListener('click', function (e) {
            var b = e.target.closest('.rdt-month-opt'); if (!b) return;
            e.stopPropagation(); viewMonth = +b.dataset.month; renderGrid();
        });
        cfg.clearBtn.addEventListener('click', function (e) { e.stopPropagation(); setSelected(null); renderGrid(); });
        cfg.doneBtn.addEventListener('click',  function (e) { e.stopPropagation(); closePicker(); });

        document.addEventListener('click', function (e) {
            if (isOpen() && !cfg.overlay.contains(e.target) && !cfg.display.contains(e.target)) closePicker();
        });
        window.addEventListener('resize', function () { if (isOpen()) positionOverlay(); });
        cfg.overlay.addEventListener('wheel',  function (e) { e.stopPropagation(); }, { passive: true });
        cfg.overlay.addEventListener('scroll', function (e) { e.stopPropagation(); }, true);

        cfg.overlay.style.display = 'none';
    }

    makePicker({
        overlay: document.getElementById('rptFromPickerOverlay'), display: document.getElementById('rptFromDisplay'),
        textEl: document.getElementById('rptFromText'), hiddenInput: document.getElementById('rptDateFrom'),
        prevBtn: document.getElementById('rptFromPrev'), nextBtn: document.getElementById('rptFromNext'),
        monthBtn: document.getElementById('rptFromMonthBtn'), yearBtn: document.getElementById('rptFromYearBtn'),
        yearDrop: document.getElementById('rptFromYearDrop'), monthDrop: document.getElementById('rptFromMonthDrop'),
        grid: document.getElementById('rptFromGrid'), clearBtn: document.getElementById('rptFromClear'),
        doneBtn: document.getElementById('rptFromDone'), placeholder: 'Select start date', allowFuture: false
    });

    makePicker({
        overlay: document.getElementById('rptToPickerOverlay'), display: document.getElementById('rptToDisplay'),
        textEl: document.getElementById('rptToText'), hiddenInput: document.getElementById('rptDateTo'),
        prevBtn: document.getElementById('rptToPrev'), nextBtn: document.getElementById('rptToNext'),
        monthBtn: document.getElementById('rptToMonthBtn'), yearBtn: document.getElementById('rptToYearBtn'),
        yearDrop: document.getElementById('rptToYearDrop'), monthDrop: document.getElementById('rptToMonthDrop'),
        grid: document.getElementById('rptToGrid'), clearBtn: document.getElementById('rptToClear'),
        doneBtn: document.getElementById('rptToDone'), placeholder: 'Select end date', allowFuture: false
    });
})();
</script>