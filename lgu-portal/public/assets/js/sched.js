/* sched.js — extracted from sched.php during componentization.
   Blocks concatenated in original document order: small DOM utility
   helpers first (escH/makeDistrictBadge/fmtDate), then the two main
   logic blocks, then the mobile-sidebar-toggle block that used to sit
   after </body>. PHP-rendered bootstrap data (window.scheduleData,
   window.IS_ADMIN, etc.) stays inline in sched.php, loaded before this
   file, since it differs per request/session. No behavior change intended. */

function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── Inline notification toast (same design as requests.php's .notif-popup)
   — used for the schedule save confirmation, which reloads the page on
   success, so the flag is stashed in sessionStorage across the reload and
   shown once the fresh page loads. ── */
function showInlineNotif(type, message) {
    const existing = document.getElementById('notifPopup');
    if (existing) existing.remove();
    const icon = type === 'success' ? '✔️' : (type === 'error' ? '❌' : (type === 'warning' ? '⚠️' : 'ℹ️'));
    const div = document.createElement('div');
    div.id = 'notifPopup';
    div.className = `notif-popup notif-${type}`;
    div.innerHTML = `<span class="notif-icon">${icon}</span>
                     <span class="notif-message"></span>
                     <button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    div.querySelector('.notif-message').textContent = message;
    document.body.appendChild(div);
    setTimeout(() => { div.style.opacity = '0'; setTimeout(() => div.remove(), 400); }, 3200);
}
function queueScheduleSaveNotif(type, message) {
    try { sessionStorage.setItem('schedSaveNotif', JSON.stringify({ type, message })); } catch (e) {}
}
document.addEventListener('DOMContentLoaded', function () {
    let pending;
    try { pending = JSON.parse(sessionStorage.getItem('schedSaveNotif') || 'null'); } catch (e) { pending = null; }
    if (pending && pending.message) {
        sessionStorage.removeItem('schedSaveNotif');
        showInlineNotif(pending.type || 'success', pending.message);
    }
});
function makeDistrictBadge(district) {
    if (!district) return '';
    const map = {
        'district 1': 'd1', 'district 2': 'd2', 'district 3': 'd3',
        'district 4': 'd4', 'district 5': 'd5', 'district 6': 'd6'
    };
    const cls = map[(district || '').toLowerCase().trim()] || 'd-other';
    return `<span class="district-badge ${cls}"><i class="fas fa-location-dot"></i>${escH(district)}</span>`;
}
function fmtDate(s){ if(!s||s==='0000-00-00')return'—'; const d=new Date(s+'T00:00:00'); return isNaN(d)?s:d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }

document.addEventListener('DOMContentLoaded', function() {
    function getSafeElem(id) {
        const el = document.getElementById(id);
        if (!el) {
            console.warn('[sched.php] Missing element for:', id);
        }
        return el;
    }

    const sidebar = getSafeElem('sidebarNav');
    const mainContent = document.querySelector('.main-content');
    const sidebarNav = getSafeElem('sidebarNav');
    const sidebarNavTooltip = getSafeElem('sidebarNavTooltip');
    const profileIconBtn = getSafeElem('profileIconBtn');
    const logoutBtn = getSafeElem('logoutBtn');
    const logoutAlertBackdrop = getSafeElem('logoutAlertBackdrop');
    const logoutCancelBtn = getSafeElem('logoutCancelBtn');
    const logoutConfirmBtn = getSafeElem('logoutConfirmBtn');
    const mobileToggle = getSafeElem('mobileToggle');
    const taskModal = getSafeElem('taskModal');
    const modalBody = getSafeElem('modalBody');
    const modalClose = getSafeElem('modalClose');
    const taskChooserModal = getSafeElem('taskChooserModal');
    const taskChooserBody = getSafeElem('taskChooserBody');
    const taskChooserClose = getSafeElem('taskChooserClose');
    const calendarGrid = getSafeElem('calendarGrid');
    const calendarDetails = getSafeElem('calendarDetails');
    const monthLabel = getSafeElem('monthLabel');
    const mobileMonthLabel = getSafeElem('mobileMonthLabel');
    const calendarView = getSafeElem('calendarView');
    const scheduleView = getSafeElem('scheduleView');
    const scheduleSearch = getSafeElem('scheduleSearch');
    const scheduleListHolder = getSafeElem('scheduleListHolder');
    const noResultMsg = getSafeElem('noResultMsg');
    const toCalendarBtn = getSafeElem('toCalendarBtn');
    const toListBtn = getSafeElem('toListBtn');
    const mobileListControls = getSafeElem('mobileListControls');
    const mobileCalendarControls = getSafeElem('mobileCalendarControls');
    const mobileToCalendarBtn = getSafeElem('mobileToCalendarBtn');
    const mobileToListBtn = getSafeElem('mobileToListBtn');
    const mobilePrevMonth = getSafeElem('mobilePrevMonth');
    const mobileNextMonth = getSafeElem('mobileNextMonth');
    const mobileScheduleSearch = getSafeElem('mobileScheduleSearch');
    const prevMonthBtn = getSafeElem('prevMonth');
    const nextMonthBtn = getSafeElem('nextMonth');
    const pickerDate = getSafeElem('pickerDate');

    if (typeof window.scheduleData === "undefined") window.scheduleData = [];

    function isMobileView() {
        return window.innerWidth <= 768;
    }

    // --- Sidebar tooltips and nav ---
    let tooltipActiveLink = null;
    let tooltipHideTimeout = null;

    function hideNavTooltipImmediate() {
        if (!sidebarNavTooltip) return;
        sidebarNavTooltip.classList.remove('active', 'logout-pop');
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function hideNavTooltip() {
        if (!sidebarNavTooltip) return;
        sidebarNavTooltip.classList.remove('active', 'logout-pop');
        setTimeout(function() {
            sidebarNavTooltip.style.display = 'none';
            tooltipActiveLink = null;
        }, 150);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function showLogoutTooltip(e) {
        if (!sidebarNavTooltip || !logoutBtn || !sidebar) return;
        const tooltipText = logoutBtn.getAttribute('data-tooltip') || "Log out";
        tooltipActiveLink = logoutBtn;
        sidebarNavTooltip.textContent = tooltipText;
        sidebarNavTooltip.classList.add('logout-pop');
        sidebarNavTooltip.style.display = 'block';
        const rect = logoutBtn.getBoundingClientRect();
        const sidebarRect = sidebar.getBoundingClientRect();
        const x = sidebarRect.right + 5;
        const y = rect.top + rect.height / 2 + window.scrollY;
        sidebarNavTooltip.style.left = (x + 10) + 'px';
        sidebarNavTooltip.style.top = y + 'px';
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navTooltipHandler(e) {
        if (!sidebarNavTooltip || !sidebar) return;
        if (!sidebar.classList.contains('collapsed')) {
            hideNavTooltip();
            return;
        }
        let tooltipText = this.getAttribute('data-tooltip');
        if (!tooltipText && this.id === "profileIconBtn") tooltipText = "Profile";
        if (!tooltipText) return;
        tooltipActiveLink = this;
        sidebarNavTooltip.textContent = tooltipText;
        sidebarNavTooltip.classList.remove('logout-pop');
        sidebarNavTooltip.style.display = 'block';
        const rect = this.getBoundingClientRect();
        const sidebarRect = sidebar.getBoundingClientRect();
        const x = sidebarRect.right + 5;
        const y = rect.top + rect.height / 2 + window.scrollY;
        sidebarNavTooltip.style.left = (x + 10) + 'px';
        sidebarNavTooltip.style.top = y + 'px';
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navLinkMouseLeaveHandler(e) {
        if (!sidebarNavTooltip) return;
        if (
            e.relatedTarget === sidebarNavTooltip ||
            (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
        ) {
            return;
        }
        tooltipHideTimeout = setTimeout(() => {
            hideNavTooltip();
            tooltipActiveLink = null;
        }, 60);
    }
    if (sidebarNavTooltip) {
        sidebarNavTooltip.addEventListener('mouseleave', function() {
            tooltipHideTimeout = setTimeout(() => {
                hideNavTooltip();
                tooltipActiveLink = null;
            }, 60);
        });
        sidebarNavTooltip.addEventListener('mouseenter', function() {
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
    }

    if (sidebarNav) {
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
            link.addEventListener('mouseenter', navTooltipHandler);
            link.addEventListener('focus', navTooltipHandler);
            link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
            link.addEventListener('blur', hideNavTooltip);
        });
    }
    if (profileIconBtn) {
        profileIconBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'profile.php';
        });
        profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
        profileIconBtn.addEventListener('focus', navTooltipHandler);
        profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
        profileIconBtn.addEventListener('blur', hideNavTooltip);
    }
    if (logoutBtn) {
        logoutBtn.addEventListener('mouseenter', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('focus', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('mouseleave', function(e) {
            if (
                sidebarNavTooltip &&
                (e.relatedTarget === sidebarNavTooltip ||
                (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget)))
            ) { return; }
            sidebarNavTooltip && sidebarNavTooltip.classList.remove('active', 'logout-pop');
            sidebarNavTooltip && (sidebarNavTooltip.style.display = 'none');
            tooltipActiveLink = null;
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
        logoutBtn.addEventListener('blur', hideNavTooltip);
        logoutBtn.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (logoutAlertBackdrop) logoutAlertBackdrop.classList.add("active");
            hideNavTooltipImmediate();
        });
    }

    document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
        link.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
    });

    if (logoutAlertBackdrop && logoutCancelBtn && logoutConfirmBtn) {
        logoutCancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutAlertBackdrop.classList.remove("active");
        });
        logoutConfirmBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.location.href = '../functionality/logout.php';
        });
        logoutAlertBackdrop.addEventListener('mousedown', (e) => {
            if (e.target === logoutAlertBackdrop) {
                logoutAlertBackdrop.classList.remove("active");
            }
        });
    }

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-active');
        });
    }

    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // === Calendar & Schedule Logic ===

    if (!calendarGrid || !calendarDetails || !monthLabel || !calendarView || !scheduleView) return;

    let currentDate = new Date();
    let showingCalendar = true;

    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        if (s.indexOf('scheduled') !== -1 || s.indexOf('planned') !== -1 || s.indexOf('upcoming') !== -1) return 'upcoming';
        return 'upcoming';
    }
    function applyStatusClassesToList() {
        document.querySelectorAll('.schedule-item').forEach(item => {
            const statusLabel = item.getAttribute('data-status') || '';
            const key = getStatusKey(statusLabel);
            item.classList.add('status-' + key + '-color');
        });
    }

    // ── List view: click a row to highlight it + open its detail modal ─────
    // (previously list-view rows had no click behavior at all)
    if (scheduleListHolder) {
        scheduleListHolder.addEventListener('click', function (e) {
            const item = e.target.closest('.schedule-item');
            if (!item) return;
            const source = item.getAttribute('data-source') || 'schedule';
            const repId    = parseInt(item.getAttribute('data-rep-id') || '0', 10);
            const schedId  = parseInt(item.getAttribute('data-sched-id') || '0', 10);
            document.querySelectorAll('.schedule-item.sched-item-highlighted').forEach(function (el) {
                if (el !== item) el.classList.remove('sched-item-highlighted');
            });
            item.classList.add('sched-item-highlighted');

            const schedData = window.scheduleData || [];
            let matches = [];
            if (source === 'report' && repId > 0) {
                matches = schedData.filter(function (x) { return x.source === 'report' && parseInt(x.rep_id, 10) === repId; });
            } else if (schedId > 0) {
                matches = schedData.filter(function (x) { return x.source !== 'report' && parseInt(x.sched_id, 10) === schedId; });
            }
            if (matches.length) openModal(matches, 0);
        });
    }

    // ── LEGEND FILTER ─────────────────────────────────────────────────────────
    // Shared state: null = no filter, or one of 'upcoming'|'ongoing'|'delayed'|'completed'
    let activeLegendFilter = null;

    const LEGEND_LABELS = {
        upcoming:  'Scheduled',
        ongoing:   'In Progress',
        delayed:   'Delayed',
        completed: 'Completed',
    };

    function applyLegendFilter(filter) {
        activeLegendFilter = filter;

        // ── 1. Update all legend pill states (list + calendar legends) ──
        document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
            const f = pill.getAttribute('data-filter');
            pill.classList.remove('legend-active', 'legend-dimmed');
            if (!filter) return;
            if (f === filter) pill.classList.add('legend-active');
            else              pill.classList.add('legend-dimmed');
        });

        // ── 2. Update clear-filter badges (list, calendar, capsule) ──
        const badge    = document.getElementById('legendFilterBadge');
        const badgeCal = document.getElementById('legendFilterBadgeCal');
        const badgeCap = document.getElementById('legendFilterBadgeCap');
        const lbl      = document.getElementById('legendFilterBadgeLabel');
        const lblCal   = document.getElementById('legendFilterBadgeCalLabel');
        const lblCap   = document.getElementById('legendFilterBadgeCapLabel');

        if (filter) {
            const name = LEGEND_LABELS[filter] || filter;
            if (lbl)    lbl.textContent    = name;
            if (lblCal) lblCal.textContent = name;
            if (lblCap) lblCap.textContent = name;
            badge    && badge.classList.add('visible');
            badgeCal && badgeCal.classList.add('visible');
            badgeCap && badgeCap.classList.add('visible');
        } else {
            badge    && badge.classList.remove('visible');
            badgeCal && badgeCal.classList.remove('visible');
            badgeCap && badgeCap.classList.remove('visible');
        }

        // ── 3. Update capsule legend pills ──
        if (typeof _syncCapsuleLegendUI === 'function') _syncCapsuleLegendUI();

        // ── 4. Filter list view items ──
        if (scheduleListHolder) {
            if (scheduleSearch && scheduleSearch.value.trim().length > 0) {
                scheduleSearch.dispatchEvent(new Event('input'));
            } else {
                const items = scheduleListHolder.querySelectorAll('.schedule-item');
                let shownCount = 0;
                items.forEach(item => {
                    const statusAttr = item.getAttribute('data-status') || '';
                    const key = getStatusKey(statusAttr);
                    const show = !filter || key === filter;
                    item.classList.toggle('filter-hidden', !show);
                    if (show) shownCount++;
                });
                const noResultMsg = document.getElementById('noResultMsg');
                if (noResultMsg) noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        }

        // ── 5. Re-render calendar with filter applied ──
        renderCalendar();

        // ── 6. Re-render capsule if it's the active view ──
        if (currentView === 'capsule' && typeof renderCapsuleView === 'function') {
            renderCapsuleView();
        }
    }

    function clearLegendFilter() { applyLegendFilter(null); }

    // Wire up all legend pill clicks
    document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
        pill.addEventListener('click', function() {
            const f = this.getAttribute('data-filter');
            // Toggle: clicking active filter again clears it
            applyLegendFilter(activeLegendFilter === f ? null : f);
        });
    });

    // Wire up clear-filter badges
    const _clearBadge    = document.getElementById('legendFilterBadge');
    const _clearBadgeCal = document.getElementById('legendFilterBadgeCal');
    if (_clearBadge)    _clearBadge.addEventListener('click',    clearLegendFilter);
    if (_clearBadgeCal) _clearBadgeCal.addEventListener('click', clearLegendFilter);
    // ── END LEGEND FILTER ─────────────────────────────────────────────────────

    if (taskModal && modalBody && modalClose && taskChooserModal && taskChooserBody) {
        if (modalClose) modalClose.onclick = () => taskModal.classList.add('hidden');
        if (taskChooserClose) taskChooserClose.onclick = () => taskChooserModal.classList.add('hidden');
        window.onclick = (e)=>{
            if(e.target===taskModal) taskModal.classList.add('hidden');
            if(e.target===taskChooserModal) taskChooserModal.classList.add('hidden');
        };
    }
    // Modal task navigation state
    let _modalTasks = [];
    let _modalIndex = 0;

    const STATUS_THEME = {
        upcoming:  {
            icon: '🔵',
            headerIcons: { upcoming: '📋', ongoing: '🔧', delayed: '⚠️', completed: '✅' }
        },
        ongoing:   { icon: '🔧' },
        delayed:   { icon: '⚠️' },
        completed: { icon: '✅' },
    };

    const STATUS_ICONS = {
        upcoming:  '📋',
        ongoing:   '🔧',
        delayed:   '⚠️',
        completed: '✅',
    };

    function applyModalTheme(key) {
        const header  = document.querySelector('#taskModal .modal-header');
        const navBar  = document.getElementById('modalNavBar');
        const iconEl  = document.querySelector('#taskModal .modal-header-icon');
        const themes  = ['theme-upcoming','theme-ongoing','theme-delayed','theme-completed'];

        if (header)  { header.classList.remove(...themes);  header.classList.add('theme-' + key); }
        if (navBar)  { navBar.classList.remove(...themes);   navBar.classList.add('theme-' + key); }
        if (iconEl)  { iconEl.textContent = STATUS_ICONS[key] || '🔧'; }
    }

    function renderModalTask(index, direction) {
        if (!modalBody) return;
        const t        = _modalTasks[index];
        const category = t.category      || 'General Maintenance';
        const priority = t.priority      || 'Low';
        const statusLbl= t.status_label  || 'Planned';
        const key      = getStatusKey(statusLbl);
        const priKey   = priority.toLowerCase();

        // Update REP badge in modal header — redesigned as clickable link
        const repBadgeEl = document.getElementById('modalRepBadge');
        if (repBadgeEl) {
            if (t.rep_id) {
                const isCompleted = (t.status === 'Completed' || t.status_label === 'Completed');
                const targetPage  = isCompleted ? 'archive_reports.php' : 'pending_reports.php';
                const targetUrl   = `${targetPage}?highlight_rep=${encodeURIComponent(t.rep_id)}&open_modal=1`;
                repBadgeEl.href  = targetUrl;
                repBadgeEl.innerHTML =
                    `<i class="fas fa-file-alt rep-badge-icon"></i>` +
                    `REP-${t.rep_id}` +
                    `<i class="fas fa-arrow-right rep-badge-arrow"></i>`;
                repBadgeEl.title = `View REP-${t.rep_id} in ${isCompleted ? 'Archive' : 'Pending'} Reports`;
                repBadgeEl.style.display = '';
                // Pulse animation to draw attention
                repBadgeEl.classList.remove('rep-badge-appear');
                void repBadgeEl.offsetWidth; // reflow to restart animation
                repBadgeEl.classList.add('rep-badge-appear');
            } else {
                repBadgeEl.style.display = 'none';
            }
        }

        // Apply status theme to header + nav bar
        applyModalTheme(key);

        // CPRF vs Energy sync badge — a facility can genuinely be tracked in
        // both the Energy Management System and the CPRF catalog at once, so
        // this is not strictly either/or. When both apply, show the combined
        // badge instead of either individual one.
        const isEnergyItem = !!t.energy_source;
        const isComboItem  = !!(t.is_shared && t.energy_source);
        const modalComboBadgeEl = document.getElementById('modalComboBadge');
        const modalCprfBadgeEl = document.getElementById('modalCprfBadge');
        const modalEnergyBadgeEl = document.getElementById('modalEnergyBadge');
        if (modalComboBadgeEl) modalComboBadgeEl.style.display = isComboItem ? '' : 'none';
        if (modalCprfBadgeEl) modalCprfBadgeEl.style.display = (!isComboItem && !isEnergyItem && t.is_shared) ? '' : 'none';
        if (modalEnergyBadgeEl) modalEnergyBadgeEl.style.display = (!isComboItem && isEnergyItem) ? '' : 'none';

        // Slide animation
        if (direction) {
            modalBody.classList.remove('slide-left', 'slide-right');
            void modalBody.offsetWidth;
            modalBody.classList.add(direction === 'next' ? 'slide-left' : 'slide-right');
        }

        // Est. end date row (only when available)
        const endDateRow = t.estimated_end_date
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-flag-checkered"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Est. End Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.estimated_end_date)}</div>
                    </div>
               </div>`
            : '';

        // Assigned Engineer row — shown to non-engineers on report-source items
        const engineerRow = (!window.IS_ENGINEER && t.source === 'report' && t.engineer_name && t.engineer_name !== '—')
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-user"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Assigned Engineer</div>
                        <div class="modal-task-row-value" style="display:flex;align-items:center;gap:8px;">
                            ${t.engineer_id ? `<button class="sched-eng-profile-btn eng-btn-${key}" onclick="schedOpenEngineerProfile(${t.engineer_id}, '${key}')" title="View Engineer Profile">${buildSchedAvatar(t.engineer_pic, key)}</button>` : ''}
                            <span>${escH(t.engineer_name)}</span>
                        </div>
                    </div>
               </div>`
            : '';

        // Budget row — only for report-source items
        const budgetNum = typeof t.budget_raw === 'number' ? t.budget_raw : parseFloat(t.budget_raw || 0);
        const budgetStr = '₱' + budgetNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const budgetRow = t.source === 'report'
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-wallet"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Budget</div>
                        <div class="modal-task-row-value">${budgetStr}</div>
                    </div>
               </div>`
            : '';

        // Evidence row placeholder — populated async for report-sourced items
        const evidenceRow = t.source === 'report' && t.rep_id
            ? `<div class="modal-task-row" id="schedEvidenceRow-${t.rep_id}">
                    <div class="modal-task-row-icon"><i class="fas fa-images"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Evidence Images</div>
                        <div class="modal-task-row-value">
                            <span class="sched-evidence-loading">Loading images…</span>
                        </div>
                    </div>
               </div>`
            : '';

        const cprfFacilityRow = ((!isEnergyItem || isComboItem) && (t.cprf_facility_id || t.facility_name))
            ? `<div class="modal-task-row modal-cprf-facility-row">
                    <div class="modal-task-row-icon"><i class="fas fa-building"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">CPRF Facility</div>
                        <div class="modal-task-row-value">${escH(t.facility_name || '—')}${t.cprf_facility_id ? `<span class="cprf-id-badge">ID ${escH(t.cprf_facility_id)}</span>` : ''}</div>
                    </div>
               </div>`
            : '';

        const energyFacilityRow = (isEnergyItem && (t.energy_facility_name || t.location))
            ? `<div class="modal-task-row modal-energy-facility-row">
                    <div class="modal-task-row-icon"><i class="fas fa-bolt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Energy Facility</div>
                        <div class="modal-task-row-value">${escH(t.energy_facility_name || t.location || '—')}</div>
                    </div>
               </div>`
            : '';

        // Extra Energy Facility Registry fields (barangay, floor area, floors,
        // year built, operating hours, size) — same data shown on the
        // facility's own profile page in Energy, surfaced here too so an
        // admin doesn't have to leave CIMM to see them. Only rendered when
        // Energy actually sent at least one of these (older imports made
        // before this existed won't have them).
        const fd = (isEnergyItem && t.energy_facility_details && typeof t.energy_facility_details === 'object')
            ? t.energy_facility_details : null;
        const fdChip = (icon, label, value) => (value === null || value === undefined || value === '')
            ? '' : `<div class="modal-facility-chip"><i class="fas fa-${icon}"></i><span class="modal-facility-chip-label">${escH(label)}</span><span class="modal-facility-chip-value">${escH(String(value))}</span></div>`;
        const facilityDetailsRow = fd
            ? (() => {
                const chips = fdChip('map-pin', 'Barangay', fd.barangay)
                    + fdChip('ruler-combined', 'Floor Area', fd.floor_area_sqm ? `${fd.floor_area_sqm} sqm` : '')
                    + fdChip('building', 'Floors', fd.floors)
                    + fdChip('calendar', 'Year Built', fd.year_built)
                    + fdChip('clock', 'Operating Hours', fd.operating_hours)
                    + fdChip('chart-simple', 'Facility Size', fd.size_label && fd.size_label !== 'N/A' ? fd.size_label : '');
                return chips
                    ? `<div class="modal-task-row modal-facility-details-row">
                            <div class="modal-task-row-icon"><i class="fas fa-building-circle-check"></i></div>
                            <div class="modal-task-row-content">
                                <div class="modal-task-row-label">Facility Details</div>
                                <div class="modal-facility-chip-grid">${chips}</div>
                            </div>
                       </div>`
                    : '';
            })()
            : '';

        const editScheduleBtn = (window.IS_ADMIN && t.source === 'schedule' && t.sched_id)
            ? `<button type="button" class="sched-modal-edit-btn" onclick="schedOpenEditForm(${parseInt(t.sched_id, 10)})">
                    <i class="fas fa-pen"></i> Edit Schedule / ${isEnergyItem ? 'Energy Facility' : 'CPRF Facility'}
               </button>`
            : '';

        modalBody.innerHTML = `
            <div class="modal-task-item theme-${key}">
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-file"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Task / Infrastructure</div>
                        <div class="modal-task-row-value">${escH(t.task)}</div>
                    </div>
                </div>
                ${cprfFacilityRow}${energyFacilityRow}${facilityDetailsRow}
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Location</div>
                        <div class="modal-task-row-value">${escH(t.location)}${makeDistrictBadge(t.district || '')}</div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="far fa-calendar-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Start Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.schedule_date)}</div>
                    </div>
                </div>
                ${endDateRow}
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-fire-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Priority</div>
                        <div class="modal-task-row-value">
                            <span class="modal-priority-pill ${priKey}">${escH(priority)}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-compass"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Status</div>
                        <div class="modal-task-row-value">
                            <span class="modal-status-pill ${key}">${escH(statusLbl)}</span>
                        </div>
                    </div>
                </div>
                ${engineerRow}
                ${budgetRow}
                ${evidenceRow}
            </div>`;

        // Edit Schedule button now lives in the pinned modal footer (not the
        // scrolling body) so it's always reachable without scrolling — see
        // .task-modal-footer / #taskModalFooter.
        const taskModalFooterEl = document.getElementById('taskModalFooter');
        if (taskModalFooterEl) {
            if (editScheduleBtn) {
                taskModalFooterEl.innerHTML = editScheduleBtn;
                taskModalFooterEl.style.display = 'flex';
            } else {
                taskModalFooterEl.innerHTML = '';
                taskModalFooterEl.style.display = 'none';
            }
        }

        // Async fetch evidence for report-sourced items
        if (t.source === 'report' && t.rep_id) {
            (function(repId) {
                fetch('sched.php?action=get_evidence&rep_id=' + encodeURIComponent(repId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        const rowEl = document.getElementById('schedEvidenceRow-' + repId);
                        if (!rowEl) return;
                        const valEl = rowEl.querySelector('.modal-task-row-value');
                        if (!valEl) return;

                        const evidence = (data.evidence || []);
                        const progress = (data.progress || []);
                        const allImgs  = evidence.concat(progress);

                        if (!allImgs.length) {
                            valEl.innerHTML = '<span class="sched-no-evidence"><i class="fas fa-image"></i>No images attached.</span>';
                            return;
                        }

                        let html = '';
                        if (evidence.length) {
                            html += '<div class="sched-evidence-section-label">📸 Request Evidence</div>';
                            html += '<div class="sched-evidence-strip">';
                            evidence.forEach(function(src, i) {
                                html += `<img class="sched-evidence-thumb" src="${src}" alt="Evidence ${i+1}"
                                             data-images='${JSON.stringify(evidence).replace(/'/g,"&#39;")}' data-index="${i}"
                                             onerror="this.style.display='none'">`;
                            });
                            html += '</div>';
                        }
                        if (progress.length) {
                            html += '<div class="sched-evidence-section-label">🔧 Progress Photos</div>';
                            html += '<div class="sched-evidence-strip">';
                            progress.forEach(function(src, i) {
                                html += `<img class="sched-evidence-thumb" src="${src}" alt="Progress ${i+1}"
                                             data-images='${JSON.stringify(progress).replace(/'/g,"&#39;")}' data-index="${i}"
                                             onerror="this.style.display='none'">`;
                            });
                            html += '</div>';
                        }
                        valEl.innerHTML = html;

                        // Wire click handlers
                        valEl.querySelectorAll('.sched-evidence-thumb').forEach(function(img) {
                            img.addEventListener('click', function() {
                                try {
                                    const imgs = JSON.parse(img.getAttribute('data-images'));
                                    const idx  = parseInt(img.getAttribute('data-index') || '0', 10);
                                    schedLbOpen(imgs, idx);
                                } catch(e) {}
                            });
                        });
                    })
                    .catch(function() {
                        const rowEl = document.getElementById('schedEvidenceRow-' + repId);
                        if (rowEl) {
                            const valEl = rowEl.querySelector('.modal-task-row-value');
                            if (valEl) valEl.innerHTML = '<span class="sched-no-evidence"><i class="fas fa-triangle-exclamation"></i>Could not load images.</span>';
                        }
                    });
            })(t.rep_id);
        }

        // Update nav bar state
        const navBar     = document.getElementById('modalNavBar');
        const navPrev    = document.getElementById('modalNavPrev');
        const navNext    = document.getElementById('modalNavNext');
        const navCounter = document.getElementById('modalNavCounter');

        if (_modalTasks.length > 1) {
            navBar.style.display = 'flex';
            navCounter.textContent = `${index + 1} / ${_modalTasks.length}`;
            navPrev.disabled = (index === 0);
            navNext.disabled = (index === _modalTasks.length - 1);
        } else {
            navBar.style.display = 'none';
        }
    }

    function openModal(tasks, startIndex) {
        if (!modalBody || !taskModal) return;
        _modalTasks = tasks;
        _modalIndex = startIndex ?? 0;
        renderModalTask(_modalIndex, null);
        taskModal.classList.remove('hidden');
    }

    // Wire up nav buttons (do this once, outside openModal)
    const modalNavPrev = document.getElementById('modalNavPrev');
    const modalNavNext = document.getElementById('modalNavNext');
    if (modalNavPrev) {
        modalNavPrev.addEventListener('click', () => {
            if (_modalIndex > 0) {
                _modalIndex--;
                renderModalTask(_modalIndex, 'prev');
            }
        });
    }
    if (modalNavNext) {
        modalNavNext.addEventListener('click', () => {
            if (_modalIndex < _modalTasks.length - 1) {
                _modalIndex++;
                renderModalTask(_modalIndex, 'next');
            }
        });
    }
    function openTaskChooser(date, tasks) {
        if (!taskChooserBody || !taskChooserModal) return;
        taskChooserBody.innerHTML = '';
        tasks.forEach((t, i) => {
            const key = getStatusKey(t.status_label || '');
            const btn = document.createElement('button');
            btn.className = 'chooser-task-btn';
            btn.innerHTML = `
                <span class="chooser-task-dot ${key}"></span>
                <div class="chooser-task-info">
                    <div class="chooser-task-name">${t.task}</div>
                    <div class="chooser-task-sub">📍 ${t.location} · ${t.status_label || 'Scheduled'}</div>
                </div>
                <span class="chooser-arrow">›</span>`;
            btn.onclick = () => {
                taskChooserModal.classList.add('hidden');
                openModal(tasks, i); // pass full list + starting index
            };
            taskChooserBody.appendChild(btn);
        });
        taskChooserModal.classList.remove('hidden');
    }

    let openDropdown = null;
    let openDropdownDay = null;
    function closeDropdown(){
        if (openDropdown) {
            openDropdown.remove();
            openDropdown = null;
            if (openDropdownDay) {
                openDropdownDay.classList.remove('has-open-dropdown');
                openDropdownDay = null;
            }
            document.querySelectorAll('.more-tasks-btn.open').forEach(b => b.classList.remove('open'));
        }
    }
    function toggleTaskDropdown(dayDiv, events, arrowBtn) {
        if (openDropdown && openDropdownDay === dayDiv) {
            closeDropdown();
            return;
        }
        closeDropdown();
        const dropdown = document.createElement('div');
        dropdown.className = 'task-dropdown';
        dropdown.setAttribute('role','menu');
        dropdown.addEventListener('click', ev => { ev.stopPropagation(); });
        events.slice(1).forEach((e, i) => {
            const btn = document.createElement('button');
            btn.className = 'task-btn';
            btn.setAttribute('role','menuitem');
            if (isMobileView()) {
                btn.textContent = i + 2;
            } else {
                btn.textContent = e.task;
            }
            const key = getStatusKey(e.status_label || '');
            if (key) btn.classList.add('status-' + key + '-bg');
            btn.onclick = (ev) => {
                ev.stopPropagation();
                closeDropdown();
                openModal(events, i + 1); // i+1 because slice(1) skips first
            };
            dropdown.appendChild(btn);
        });
        dayDiv.appendChild(dropdown);
        dayDiv.classList.add('has-open-dropdown');
        openDropdown = dropdown;
        openDropdownDay = dayDiv;
        if (arrowBtn) arrowBtn.classList.add('open');
    }
    document.addEventListener('click', () => { closeDropdown(); });

    const FIXED_HOLIDAYS = {
        '01-01': { name: 'New Year\'s Day', type: 'holiday' },
        '02-14': { name: 'Valentine\'s Day', type: 'event' },
        '02-25': { name: 'EDSA People Power Revolution', type: 'holiday' },
        '03-08': { name: 'International Women\'s Day', type: 'event' },
        '04-09': { name: 'Araw ng Kagitingan (Day of Valor)', type: 'holiday' },
        '05-01': { name: 'Labor Day', type: 'holiday' },
        '06-12': { name: 'Independence Day', type: 'holiday' },
        '07-04': { name: 'Philippines-American Friendship Day', type: 'event' },
        '08-21': { name: 'Ninoy Aquino Day', type: 'holiday' },
        // National Heroes Day is NOT fixed to Aug 31 — it's the last Monday
        // of August, already computed correctly per-year by
        // getNationalHeroesDay() below. A static '08-31' entry here doesn't
        // stay in sync with that in years where Aug 31 isn't a Monday,
        // producing two different "National Heroes Day" dates in the same
        // calendar. Deliberately omitted.
        '11-01': { name: 'All Saints\' Day', type: 'holiday' },
        '11-02': { name: 'All Souls\' Day', type: 'event' },
        '11-30': { name: 'Bonifacio Day', type: 'holiday' },
        '12-08': { name: 'Feast of the Immaculate Conception', type: 'holiday' },
        '12-24': { name: 'Christmas Eve', type: 'event' },
        '12-25': { name: 'Christmas Day', type: 'holiday' },
        '12-30': { name: 'Rizal Day', type: 'holiday' },
        '12-31': { name: 'New Year\'s Eve', type: 'event' }
    };

    // ── Holy Week — derived from Easter Sunday, not looked up, so it's
    // correct for any year forever. Meeus/Jones/Butcher algorithm (the
    // standard closed-form calculation for the Gregorian Easter date).
    function getEasterSunday(year) {
        const a = year % 19;
        const b = Math.floor(year / 100);
        const c = year % 100;
        const d = Math.floor(b / 4);
        const e = b % 4;
        const f = Math.floor((b + 8) / 25);
        const g = Math.floor((b - f + 1) / 3);
        const h = (19 * a + b - d - g + 15) % 30;
        const i = Math.floor(c / 4);
        const k = c % 4;
        const l = (32 + 2 * e + 2 * i - h - k) % 7;
        const m = Math.floor((a + 11 * h + 22 * l) / 451);
        const month = Math.floor((h + l - 7 * m + 114) / 31); // 3 = March, 4 = April
        const day = ((h + l - 7 * m + 114) % 31) + 1;
        return new Date(year, month - 1, day);
    }
    function addDays(date, days) {
        const d = new Date(date);
        d.setDate(d.getDate() + days);
        return d;
    }
    function monthDayKey(date) {
        return `${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    // ── Chinese New Year — lunisolar, no closed-form formula exists, so
    // this is sourced from published astronomical calendars rather than
    // computed. Covers a wide span of years instead of just a handful —
    // outside this range the calendar simply omits it, same as before.
    const CHINESE_NEW_YEAR = {
        2020: '01-25', 2021: '02-12', 2022: '02-01', 2023: '01-22', 2024: '02-10',
        2025: '01-29', 2026: '02-17', 2027: '02-06', 2028: '01-26', 2029: '02-13',
        2030: '02-03', 2031: '01-23', 2032: '02-11', 2033: '01-31', 2034: '02-19',
        2035: '02-08', 2036: '01-28', 2037: '02-15', 2038: '02-04', 2039: '01-24',
        2040: '02-12', 2041: '02-01', 2042: '01-22', 2043: '02-10', 2044: '01-30',
        2045: '02-17',
    };

    // ── Eid al-Fitr — moon-sighting based, so "approximate" by nature (can
    // shift ±1-2 days by country/authority even after the fact); dates below
    // use the Umm al-Qura calculated calendar as one consistent reference.
    // 2033 gets two occurrences since the ~354-day Islamic lunar year
    // occasionally fits twice into one Gregorian year.
    const EID_AL_FITR = {
        2020: ['05-24'], 2021: ['05-13'], 2022: ['05-02'], 2023: ['04-22'], 2024: ['04-10'],
        2025: ['03-31'], 2026: ['03-20'], 2027: ['03-09'], 2028: ['02-26'], 2029: ['02-14'],
        2030: ['02-04'], 2031: ['01-24'], 2032: ['01-14'], 2033: ['01-02', '12-23'],
        2034: ['12-12'], 2035: ['12-01'], 2036: ['11-19'],
    };

    function getHolidaysForYear(year) {
        const holidays = { ...FIXED_HOLIDAYS };

        const easter = getEasterSunday(year);
        holidays[monthDayKey(addDays(easter, -3))] = { name: 'Maundy Thursday', type: 'holiday' };
        holidays[monthDayKey(addDays(easter, -2))] = { name: 'Good Friday',     type: 'holiday' };
        holidays[monthDayKey(addDays(easter, -1))] = { name: 'Black Saturday',  type: 'holiday' };

        if (CHINESE_NEW_YEAR[year]) {
            holidays[CHINESE_NEW_YEAR[year]] = { name: 'Chinese New Year', type: 'holiday' };
        }
        if (EID_AL_FITR[year]) {
            EID_AL_FITR[year].forEach(key => {
                holidays[key] = { name: 'Eid al-Fitr (approximate)', type: 'holiday' };
            });
        }

        return holidays;
    }

    function getNationalHeroesDay(year) {
        const lastDayOfAugust = new Date(year, 8, 0);
        const dayOfWeek = lastDayOfAugust.getDay();
        let daysToSubtract = (dayOfWeek === 0) ? 6 : (dayOfWeek - 1);
        const lastMonday = new Date(year, 7, lastDayOfAugust.getDate() - daysToSubtract);
        const month = String(lastMonday.getMonth() + 1).padStart(2, '0');
        const day = String(lastMonday.getDate()).padStart(2, '0');
        return `${month}-${day}`;
    }

    function getHolidayOrEvent(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const key = `${month}-${day}`;
        const holidays = getHolidaysForYear(year);
        const heroesDay = getNationalHeroesDay(year);
        if (key === heroesDay) {
            return { name: 'National Heroes Day', type: 'holiday' };
        }
        return holidays[key] || null;
    }

    function isWeekend(date) {
        const dayOfWeek = date.getDay();
        return dayOfWeek === 0 || dayOfWeek === 6;
    }

    function getEventInitial(name, type) {
        if (type === 'holiday') {
            if (name.includes('Christmas')) return 'XMS';
            if (name.includes('New Year\'s Day')) return 'NY';
            if (name.includes('Chinese New Year')) return 'CNY';
            if (name.includes('EDSA')) return 'EDS';
            if (name.includes('Independence')) return 'IND';
            if (name.includes('Heroes')) return 'HRO';
            if (name.includes('Rizal')) return 'RZL';
            if (name.includes('Bonifacio')) return 'BON';
            if (name.includes('Labor')) return 'LAB';
            if (name.includes('Valor')) return 'VLR';
            if (name.includes('Maundy')) return 'MT';
            if (name.includes('Good Friday')) return 'GF';
            if (name.includes('Black Saturday')) return 'BS';
            if (name.includes('Eid')) return 'EID';
            if (name.includes('All Saints')) return 'AS';
            if (name.includes('Immaculate')) return 'IC';
            return name.split(' ').map(w => w[0]).join('').substring(0, 3);
        }
        if (name.includes('Valentine')) return '❤️';
        if (name.includes('Women')) return '♀';
        if (name.includes('Christmas Eve')) return 'CE';
        if (name.includes('New Year\'s Eve')) return 'NYE';
        return name.substring(0, 3).toUpperCase();
    }

    // ── Cross-view highlight helpers ────────────────────────────────────────
    // Used both when the user clicks an item directly on this page, and when
    // arriving here from employee.php's "Upcoming Maintenance" widget via
    // ?highlight_source=&highlight_id= (see initHighlightFromQuery() below).
    function clearScheduleHighlights() {
        document.querySelectorAll('.sched-item-highlighted').forEach(function (el) {
            el.classList.remove('sched-item-highlighted');
        });
    }

    function findScheduleEntry(source, id) {
        const data = Array.isArray(window.scheduleData) ? window.scheduleData : [];
        const numId = parseInt(id, 10);
        if (source === 'report') {
            return data.find(function (x) { return x.source === 'report' && parseInt(x.rep_id, 10) === numId; }) || null;
        }
        return data.find(function (x) { return x.source !== 'report' && parseInt(x.sched_id, 10) === numId; }) || null;
    }

    function flashHighlight(el) {
        if (!el) return;
        clearScheduleHighlights();
        el.classList.add('sched-item-highlighted');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function () { el.classList.remove('sched-item-highlighted'); }, 5000);
    }

    function highlightInListView(entry) {
        if (!scheduleListHolder) return;
        const sel = entry.source === 'report'
            ? '.schedule-item[data-source="report"][data-rep-id="' + entry.rep_id + '"]'
            : '.schedule-item[data-source="schedule"][data-sched-id="' + entry.sched_id + '"]';
        flashHighlight(scheduleListHolder.querySelector(sel));
    }

    function highlightInCapsuleView(entry) {
        const board = document.getElementById('capsuleBoard');
        if (!board) return;
        const sel = entry.source === 'report'
            ? '.capsule-card[data-cap-source="report"][data-cap-rep-id="' + entry.rep_id + '"]'
            : '.capsule-card[data-cap-source="schedule"][data-cap-sched-id="' + entry.sched_id + '"]';
        flashHighlight(board.querySelector(sel));
    }

    function highlightInCalendarView(entry) {
        if (!entry.schedule_date || !calendarGrid) return;
        const target = new Date(entry.schedule_date + 'T00:00:00');
        currentDate = new Date(target.getFullYear(), target.getMonth(), 1);
        renderCalendar();
        requestAnimationFrame(function () {
            const dayEl = calendarGrid.querySelector('.calendar-day[data-date="' + entry.schedule_date + '"]');
            if (!dayEl) return;
            clearScheduleHighlights();
            dayEl.classList.add('sched-item-highlighted');
            dayEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            dayEl.click(); // opens the day's task list in the details panel below
            setTimeout(function () { dayEl.classList.remove('sched-item-highlighted'); }, 5000);
        });
    }

    // Public entry point: highlight one schedule/report item, regardless of
    // which view (calendar / list / capsule) is currently active.
    window.schedHighlightItem = function (source, id) {
        const entry = findScheduleEntry(source, id);
        if (!entry) return;
        if (currentView === 'list') {
            highlightInListView(entry);
        } else if (currentView === 'capsule') {
            renderCapsuleView();
            requestAnimationFrame(function () { highlightInCapsuleView(entry); });
        } else {
            highlightInCalendarView(entry);
        }
    };

    function renderCalendar(){
        closeDropdown && closeDropdown();
        if (!calendarGrid || !calendarDetails) return;
        calendarGrid.innerHTML='';
        calendarDetails.innerHTML='Select a date to view schedule.';

        const year=currentDate.getFullYear();
        const month=currentDate.getMonth();
        const monthText=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
        const monthLabelText = document.getElementById('monthLabelText');
        if (monthLabelText) monthLabelText.textContent = monthText;
        else if (monthLabel) monthLabel.textContent=monthText;
        const mobMonthLabelText = document.getElementById('mobileMonthLabelText');
        if (mobMonthLabelText) mobMonthLabelText.textContent = monthText;
        else if (mobileMonthLabel) mobileMonthLabel.textContent=monthText;

        const firstDay=new Date(year, month,1).getDay();
        const daysInMonth=new Date(year,month+1,0).getDate();
        const todayLocal = new Date();
        const todayStr = `${todayLocal.getFullYear()}-${String(todayLocal.getMonth()+1).padStart(2,'0')}-${String(todayLocal.getDate()).padStart(2,'0')}`;

        for(let i=0;i<firstDay;i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = "calendar-day";
            calendarGrid.appendChild(emptyDiv);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const currentDayDate = new Date(year, month, d);

            const allEvents = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : [];

            // Apply legend filter to events shown in calendar cells
            const events = activeLegendFilter
                ? allEvents.filter(e => getStatusKey(e.status_label || '') === activeLegendFilter)
                : allEvents;

            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);

            // Dim days that have tasks but none match the active filter
            if (activeLegendFilter && allEvents.length > 0 && events.length === 0) {
                dayDiv.classList.add('legend-filter-dim');
            }

            if (dateStr === todayStr) {
                dayDiv.classList.add('today');
            }

            if (isWeekend(currentDayDate)) {
                dayDiv.classList.add('weekend');
            }

            const holidayEvent = getHolidayOrEvent(currentDayDate);
            if (holidayEvent) {
                if (holidayEvent.type === 'holiday') {
                    dayDiv.classList.add('has-holiday');
                } else {
                    dayDiv.classList.add('has-event-indicator');
                }
            }

            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);

            if (holidayEvent) {
                const isMobile = isMobileView();
                const badge = document.createElement('div');
                badge.className = holidayEvent.type === 'holiday' ? 'holiday-badge' : 'event-badge';
                badge.textContent = isMobile
                    ? getEventInitial(holidayEvent.name, holidayEvent.type)
                    : (holidayEvent.type === 'holiday' ? 'HOLIDAY' : 'EVENT');
                dayDiv.appendChild(badge);
                if (!isMobile) {
                    const title = document.createElement('div');
                    title.className = holidayEvent.type === 'holiday' ? 'holiday-event-title' : 'event-title';
                    title.textContent = holidayEvent.name;          // always full name — CSS truncates
                    title.setAttribute('data-full', holidayEvent.name); // drives ::after tooltip
                    dayDiv.appendChild(title);
                }
            }

            if (events.length) {
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';

                if (events.length === 1) {
                    const e = events[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? '1' : e.task;
                    btn.title = `${e.task} (${e.status_label || ''})`;
                    const key = getStatusKey(e.status_label || '');
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal(events, 0); // <-- pass full list, index 0
                    };
                    tasksDiv.appendChild(btn);
                } else if (events.length > 1) {
                    const first = events[0];
                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? '1' : first.task;
                    firstBtn.title = `${first.task} (${first.status_label || ''})`;
                    const firstKey = getStatusKey(first.status_label || '');
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal(events, 0); // <-- pass full list, index 0
                    };
                    tasksDiv.appendChild(firstBtn);

                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';
                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        toggleTaskDropdown(dayDiv, events, arrowBtn);
                    };
                    if (isMobileView()) {
                        moreWrap.appendChild(arrowBtn);
                    } else {
                        moreWrap.appendChild(arrowBtn);
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${events.length - 1}`;
                        moreWrap.appendChild(counter);
                    }
                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            }

            dayDiv.addEventListener('click', function () {
                // Manual day selection (as opposed to a programmatic
                // highlight-driven click from schedHighlightItem) should
                // clear any lingering highlight from a previous jump.
                document.querySelectorAll('.calendar-day.sched-item-highlighted').forEach(function (el) {
                    if (el !== dayDiv) el.classList.remove('sched-item-highlighted');
                });

                const titleEl = document.getElementById('calDetailsTitle');
                const iconEl  = document.getElementById('calDetailsIcon');
                const hintEl  = document.getElementById('calScrollHint');

                // Build date label
                const datObj  = new Date(dateStr + 'T00:00:00');
                const dateLabel = datObj.toLocaleDateString('en-US', { weekday:'short', month:'long', day:'numeric', year:'numeric' });
                if (titleEl) titleEl.textContent = dateLabel;

                let html = '';

                // Weekend tag
                if (isWeekend(currentDayDate)) {
                    html += `<div class="cal-weekend-tag">🏖️ Weekend</div>`;
                }

                // Holiday / event row
                if (holidayEvent) {
                    const cls = holidayEvent.type === 'holiday' ? 'holiday' : 'event';
                    const ico = holidayEvent.type === 'holiday' ? '🎉' : '📅';
                    if (iconEl) iconEl.textContent = ico;
                    html += `<div class="cal-holiday-row ${cls}">${ico} ${holidayEvent.name}</div>`;
                } else {
                    if (iconEl) iconEl.textContent = events.length ? '🔧' : '📅';
                }

                // Task rows
                if (events.length) {
                    events.forEach(e => {
                        const key = getStatusKey(e.status_label || '');
                        const repTag = e.rep_id ? ` · REP-${e.rep_id}` : '';
                        const facilityTag = e.facility_name
                            ? `<span class="cal-facility-tag">🏢 ${escH(e.facility_name)}</span>`
                            : (e.energy_facility_name ? `<span class="cal-facility-tag">⚡ ${escH(e.energy_facility_name)}</span>` : '');
                        // Same fix as the list/capsule badges: a schedule can be linked
                        // to both integrations at once, so show both tags, not either/or.
                        const cprfSharedTag = e.is_shared
                            ? `<span class="badge-shared-cprf" style="margin-top:3px;display:inline-flex;">🔗 Shared with CPRF</span>` : '';
                        const energySharedTag = e.energy_source
                            ? `<span class="badge-shared-energy" style="margin-top:3px;display:inline-flex;">⚡ Energy</span>` : '';
                        const sharedTag = cprfSharedTag + energySharedTag;
                        html += `
                            <div class="cal-task-row">
                                <span class="cal-task-dot ${key}"></span>
                                <div class="cal-task-info">
                                    <div class="cal-task-name" title="${escH(e.task)}">${escH(e.task)}</div>
                                    <div class="cal-task-meta">📍 ${escH(e.location || '—')} · ${escH(e.status_label || 'Scheduled')}${escH(repTag)}</div>
                                    ${facilityTag}
                                    ${sharedTag}
                                </div>
                            </div>`;
                    });
                } else if (!holidayEvent && !isWeekend(currentDayDate)) {
                    html += `<div class="cal-no-tasks">No maintenance scheduled for this date.</div>`;
                }

                calendarDetails.innerHTML = html;

                // Show/hide scroll hint
                if (hintEl) {
                    setTimeout(() => {
                        const overflows = calendarDetails.scrollHeight > calendarDetails.clientHeight + 4;
                        hintEl.classList.toggle('visible', overflows);
                    }, 50);
                }
            });
            
            calendarGrid.appendChild(dayDiv);
        }
    }

    function updateCalendarDetailsScrollHint() {
        const details = document.getElementById('calendarDetails');
        const hint    = document.getElementById('calScrollHint');
        if (!details || !hint) return;
        // Let CSS (.cal-details-scroll-hint / .visible) own visibility. Previously
        // this always set an inline display:block (just fading it to opacity .3
        // when not needed), which beats the CSS class and left the hint rendered
        // — and overlapping the details text below it — even when there was
        // nothing to scroll.
        hint.style.removeProperty('display');
        hint.style.removeProperty('opacity');
        const overflows = details.scrollHeight > details.clientHeight + 4;
        hint.classList.toggle('visible', overflows);
    }

    if (typeof prevMonthBtn !== "undefined" && prevMonthBtn && nextMonthBtn) {
        prevMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()-1);
            renderCalendar();
        };
        nextMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()+1);
            renderCalendar();
        };
    }

    const originalRenderCalendar = renderCalendar;
    renderCalendar = function () {
        originalRenderCalendar();
        setTimeout(updateCalendarDetailsScrollHint, 0);
    };

    renderCalendar();
    applyStatusClassesToList();

    // ── LIST VIEW ITEM CLICK → Open Task Detail Modal ─────────────────────────
    function attachListItemClickHandlers() {
        if (!scheduleListHolder) return;
        scheduleListHolder.querySelectorAll('.schedule-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                const schedData = window.scheduleData || [];
                const taskName  = item.getAttribute('data-task') || '';
                const dateAttr  = (item.getAttribute('data-date') || '').split('|')[1] || '';
                const source    = item.getAttribute('data-source') || '';
                const repId     = parseInt(item.getAttribute('data-rep-id') || '0', 10);

                let matches = [];

                if (source === 'report' && repId > 0) {
                    // Match report-sourced tasks by rep_id
                    matches = schedData.filter(function(t) {
                        return t.source === 'report' && parseInt(t.rep_id, 10) === repId;
                    });
                }

                // Fallback: match by date + task name
                if (!matches.length) {
                    matches = schedData.filter(function(t) {
                        return t.schedule_date === dateAttr &&
                               (t.task || '').toLowerCase() === taskName;
                    });
                }

                if (matches.length) {
                    openModal(matches, 0);
                }
            });
        });
    }
    attachListItemClickHandlers();
    // ── END LIST VIEW ITEM CLICK ──────────────────────────────────────────────

    if (scheduleSearch && scheduleListHolder) {
        function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
        function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
        function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
        function highlightEl(el, kw) {
            if (!kw) return;
            const regex = new RegExp(`(${escapeRegExp(kw)})`, 'gi');
            // Walk only text nodes — never touch tag names or attribute values
            const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
            const textNodes = [];
            let node;
            while ((node = walker.nextNode())) textNodes.push(node);
            textNodes.forEach(tn => {
                if (!tn.nodeValue.trim()) return;
                const parts = tn.nodeValue.split(regex);
                if (parts.length < 2) return;
                const frag = document.createDocumentFragment();
                parts.forEach((part, i) => {
                    if (i % 2 === 1) {
                        const mark = document.createElement('span');
                        mark.className = 'search-highlight';
                        mark.textContent = part;
                        frag.appendChild(mark);
                    } else {
                        frag.appendChild(document.createTextNode(part));
                    }
                });
                tn.parentNode.replaceChild(frag, tn);
            });
        }

        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim();
            const sl = searchVal.toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;

            // Reset all existing highlights first
            scheduleListHolder.querySelectorAll('.searchable[data-original]').forEach(el => resetEl(el));

            items.forEach(item => {
                const task   = item.getAttribute('data-task') || '';
                const loc    = item.getAttribute('data-location') || '';
                const date   = item.getAttribute('data-date') || '';
                const cat    = item.getAttribute('data-category') || '';
                const stat   = item.getAttribute('data-status') || '';
                const prio   = item.getAttribute('data-priority') || '';
                const rep    = item.getAttribute('data-rep') || '';
                const budget = item.getAttribute('data-budget') || '';
                const shared = item.getAttribute('data-shared') || '';
                const energy = item.getAttribute('data-energy') || '';

                // Legend filter check
                const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;

                // Search check — includes CPRF/shared and Energy
                const searchOk = !searchVal.length || (
                    task.includes(sl)   || loc.includes(sl)    || date.includes(sl)  ||
                    cat.includes(sl)    || stat.includes(sl)   || prio.includes(sl)  ||
                    rep.includes(sl)    || budget.includes(sl) || shared.includes(sl) ||
                    energy.includes(sl) ||
                    'cprf'.includes(sl) || 'shared'.includes(sl)
                        && shared === 'cprf' ||
                    'energy'.includes(sl) && energy === 'energy'
                );

                const show = legendOk && searchOk;
                item.classList.toggle('filter-hidden', !show);

                if (show) {
                    shownCount++;
                    if (searchVal.length) {
                        item.querySelectorAll('.searchable').forEach(el => {
                            storeOriginal(el);
                            highlightEl(el, searchVal);
                        });
                    }
                }
            });

            if (noResultMsg) {
                noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        });
    }

    // ── Capsule View: Render ──────────────────────────────────────────────────
    const _EMP_ID       = window.CURRENT_EMP_ID || 0;
    const _CAP_SORT_KEY = 'cimm_cap_sort_' + _EMP_ID;
    let _capsuleSortMode = (function() {
        try { return localStorage.getItem(_CAP_SORT_KEY) || 'date-asc'; } catch(e) { return 'date-asc'; }
    })();
    // Sync dropdown active marker to match restored value
    document.querySelectorAll('.cap-sort-option').forEach(function(o) {
        o.classList.toggle('active', o.getAttribute('data-sort') === _capsuleSortMode);
    });

    // Wire capsule sort dropdown — event delegation matching #capSortWrap (uses sort-dropdown-wrap class)
    document.addEventListener('click', function(e) {
        const capSortWrap = document.getElementById('capSortWrap');
        if (!capSortWrap) return;

        if (e.target.closest('#capSortBtn')) {
            const isOpen = capSortWrap.classList.contains('open');
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
            capSortWrap.classList.toggle('open', !isOpen);
            return;
        }

        const opt = e.target.closest('.cap-sort-option');
        if (opt) {
            const sort = opt.getAttribute('data-sort');
            if (sort) {
                _capsuleSortMode = sort;
                try { localStorage.setItem(_CAP_SORT_KEY, sort); } catch(e) {}
                document.querySelectorAll('.cap-sort-option').forEach(o =>
                    o.classList.toggle('active', o.getAttribute('data-sort') === sort)
                );
                capSortWrap.classList.remove('open');
                renderCapsuleView();
            }
            return;
        }

        if (!e.target.closest('#capSortWrap')) {
            capSortWrap.classList.remove('open');
        }
    });

    // Task/infrastructure → icon (FA class + emoji fallback)
    function _capIcon(task, category, source) {
        const t = (task     || '').toLowerCase();
        const c = (category || '').toLowerCase();
        const s = 'font-size:19px;color:rgba(255,255,255,.92);';

        // Match reference image icon set — order matters (most specific first)
        if (t.includes('road') || t.includes('street') || t.includes('pavement') || t.includes('asphalt') || t.includes('highway') || c.includes('road'))
            return `<i class="fas fa-road" style="${s}"></i>`;
        if (t.includes('light') || t.includes('lamp') || t.includes('streetlight') || t.includes('lighting') || t.includes('solar'))
            return `<i class="fas fa-solar-panel" style="${s}"></i>`;
        if (t.includes('drainage') || t.includes('canal') || t.includes('flood') || t.includes('drain') || t.includes('sewage') || t.includes('sewer'))
            return `<i class="fas fa-tint" style="${s}"></i>`;
        if (t.includes('facility') || t.includes('center') || t.includes('court') || t.includes('gym') || t.includes('sports') || t.includes('hall') || t.includes('building') || t.includes('structure') || t.includes('roof') || t.includes('floor') || t.includes('ceiling') || t.includes('concrete') || t.includes('wall') || c.includes('facility'))
            return `<i class="fas fa-th-large" style="${s}"></i>`;
        if (t.includes('water') || t.includes('plumbing') || t.includes('pipe') || t.includes('pump') || t.includes('supply'))
            return `<i class="fas fa-water" style="${s}"></i>`;
        if (t.includes('electric') || t.includes('power') || t.includes('generator') || t.includes('wiring') || t.includes('cable') || c.includes('electrical') || c.includes('power'))
            return `<i class="fas fa-bolt" style="${s}"></i>`;
        if (t.includes('aircon') || t.includes('hvac') || t.includes('cooling') || t.includes('ac ') || c.includes('hvac'))
            return `<i class="fas fa-snowflake" style="${s}"></i>`;
        if (t.includes('bridge'))
            return `<i class="fas fa-archway" style="${s}"></i>`;
        if (t.includes('fire') || t.includes('extinguisher') || c.includes('safety'))
            return `<i class="fas fa-fire-extinguisher" style="${s}"></i>`;
        if (t.includes('park') || t.includes('garden') || t.includes('tree') || t.includes('landscape'))
            return `<i class="fas fa-tree" style="${s}"></i>`;
        if (t.includes('fence') || t.includes('gate'))
            return `<i class="fas fa-border-all" style="${s}"></i>`;
        if (t.includes('waiting') || t.includes('shed') || t.includes('shelter'))
            return `<i class="fas fa-home" style="${s}"></i>`;
        if (source === 'report')
            return `<i class="fas fa-file-alt" style="${s}"></i>`;
        return `<i class="fas fa-tools" style="${s}"></i>`;
    }

    // Status → short badge label
    const CAP_BADGE_LABELS = {
        upcoming:  'SCHED',
        ongoing:   'IN PROG',
        delayed:   'DELAYED',
        completed: 'DONE',
    };

    // Status sort order
    const STATUS_ORDER = { upcoming: 0, ongoing: 1, delayed: 2, completed: 3 };

    function renderCapsuleView() {
        const board = document.getElementById('capsuleBoard');
        if (!board) return;
        board.innerHTML = '';

        // Sort / Filter
        let data = (window.scheduleData || []).slice();
        const isCprfFilter   = (_capsuleSortMode === 'cprf');
        const isEnergyFilter = (_capsuleSortMode === 'energy');

        if (!isCprfFilter && !isEnergyFilter) {
            data.sort(function(a, b) {
                if (_capsuleSortMode === 'date-asc')   return (a.schedule_date || '') < (b.schedule_date || '') ? -1 : 1;
                if (_capsuleSortMode === 'date-desc')  return (a.schedule_date || '') > (b.schedule_date || '') ? -1 : 1;
                if (_capsuleSortMode === 'alpha-asc')  return (a.task || '').localeCompare(b.task || '');
                if (_capsuleSortMode === 'alpha-desc') return (b.task || '').localeCompare(a.task || '');
                if (_capsuleSortMode === 'status')     return (STATUS_ORDER[getStatusKey(a.status_label)] ?? 4) - (STATUS_ORDER[getStatusKey(b.status_label)] ?? 4);
                return 0;
            });
        }

        const searchQ = (document.getElementById('capsuleSearch') || {}).value || '';
        const sl = searchQ.trim().toLowerCase();

        let cardIndex = 0;
        let visibleCount = 0;

        data.forEach(function(t) {
            const key = getStatusKey(t.status_label || '');

            // CPRF filter: only show shared items
            if (isCprfFilter && !t.is_shared) return;

            // Energy filter: only show items imported from the Energy Management System
            if (isEnergyFilter && !t.energy_source) return;

            // Legend filter — use shared activeLegendFilter
            if (activeLegendFilter && key !== activeLegendFilter) return;

            // Search filter — includes CPRF/shared
            if (sl) {
                // Map status_label → display badge label so "done","sched","in prog","delayed" all match
                const badgeAlias = CAP_BADGE_LABELS[key] || '';
                const hay = [t.task, t.location, t.category, t.schedule_date, t.status_label,
                             badgeAlias,
                             t.rep_id ? 'rep-' + t.rep_id : '',
                             t.is_shared ? 'cprf shared' : '',
                             t.energy_source ? 'energy shared' : '',
                             t.facility_name || '',
                             t.energy_facility_name || '']
                    .map(v => (v || '').toLowerCase()).join(' ');
                if (!hay.includes(sl)) return;
            }

            cardIndex++;
            visibleCount++;

            const card = document.createElement('div');
            card.className = `capsule-card cap-${key}`;
            card.setAttribute('data-cap-task',   (t.task || '').toLowerCase());
            card.setAttribute('data-cap-location',(t.location || '').toLowerCase());
            card.setAttribute('data-cap-status',  (t.status_label || '').toLowerCase());
            card.setAttribute('data-cap-category',(t.category || '').toLowerCase());
            card.setAttribute('data-cap-date',     t.schedule_date || '');
            card.setAttribute('data-cap-rep-id',   t.rep_id || 0);
            card.setAttribute('data-cap-sched-id', t.sched_id || 0);
            card.setAttribute('data-cap-source',   t.source || 'schedule');
            card.setAttribute('data-cap-key',      key);

            const icon      = _capIcon(t.task, t.category, t.source);
            const badgeLabel = CAP_BADGE_LABELS[key] || 'SCHED';
            const locStr    = t.location || '—';
            const repTag    = t.rep_id  ? `<span class="capsule-rep-badge cap-hl-rep">REP-${escH(String(t.rep_id))}</span>` : '';
            const catTag    = (t.category && t.category !== 'Infrastructure Report' && t.category !== 'General Maintenance')
                            ? `<span class="capsule-mini-badge">${escH(t.category)}</span>` : '';
            // A schedule can be tracked in both the Energy Management System and
            // the CPRF catalog at once — show both badges together when both
            // apply (previously energyTag was suppressed whenever is_shared was
            // true, hiding it on combo items).
            const cprfTag   = t.is_shared
                            ? `<span class="cap-cprf-badge">🔗 CPRF</span>` : '';
            const energyTag = t.energy_source
                            ? `<span class="cap-energy-badge">⚡ Energy</span>` : '';
            const numStr    = String(cardIndex);

            card.innerHTML = `
                <div class="capsule-card-watermark">${numStr}</div>
                <div class="capsule-card-top">
                    <div class="capsule-card-icon">${icon}</div>
                    <div class="capsule-card-top-right">
                        ${repTag}
                        <div class="capsule-card-badge cap-hl-status">${badgeLabel}</div>
                    </div>
                </div>
                <div class="capsule-card-body">
                    <div class="capsule-card-title cap-hl-task">${escH(t.task || 'Untitled Task')}</div>
                    <div class="capsule-card-desc">
                        📍 <span class="cap-hl-loc">${escH(locStr)}</span>
                    </div>
                    ${(cprfTag || energyTag) ? `<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:7px;">${cprfTag}${energyTag}</div>` : ''}
                </div>
                <div class="capsule-card-bottom">
                    <button class="capsule-card-btn">
                        VIEW DETAILS &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                    <div class="capsule-card-extra-badges">
                        ${catTag}
                    </div>
                </div>`;

            // Apply search highlight — only task, location, REP badge, and status badge
            if (sl) {
                const CAP_HL_SELECTORS = [
                    '.cap-hl-task', '.cap-hl-loc', '.cap-hl-rep', '.cap-hl-status'
                ];
                CAP_HL_SELECTORS.forEach(function(sel) {
                    const el = card.querySelector(sel);
                    if (!el || !el.textContent.trim()) return;
                    const regex = new RegExp('(' + sl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
                    const textNodes = [];
                    let tn;
                    while ((tn = walker.nextNode())) textNodes.push(tn);
                    textNodes.forEach(function(tn) {
                        if (!tn.nodeValue.trim()) return;
                        const parts = tn.nodeValue.split(regex);
                        if (parts.length < 2) return;
                        const frag = document.createDocumentFragment();
                        parts.forEach(function(part, i) {
                            if (i % 2 === 1) {
                                const mark = document.createElement('span');
                                mark.className = 'cap-search-highlight';
                                mark.textContent = part;
                                frag.appendChild(mark);
                            } else {
                                frag.appendChild(document.createTextNode(part));
                            }
                        });
                        tn.parentNode.replaceChild(frag, tn);
                    });
                });
            }

            card.addEventListener('click', function() {
                const schedData = window.scheduleData || [];
                const repId     = parseInt(card.getAttribute('data-cap-rep-id') || '0', 10);
                const source    = card.getAttribute('data-cap-source') || '';
                const taskName  = card.getAttribute('data-cap-task') || '';
                const dateAttr  = card.getAttribute('data-cap-date') || '';

                let matches = [];
                if (source === 'report' && repId > 0) {
                    matches = schedData.filter(function(x) {
                        return x.source === 'report' && parseInt(x.rep_id, 10) === repId;
                    });
                }
                if (!matches.length) {
                    matches = schedData.filter(function(x) {
                        return x.schedule_date === dateAttr &&
                               (x.task || '').toLowerCase() === taskName;
                    });
                }
                clearScheduleHighlights();
                card.classList.add('sched-item-highlighted');
                if (matches.length) openModal(matches, 0);
            });

            board.appendChild(card);
        });

        // Empty state
        const emptyEl = document.getElementById('capsuleEmptyState');
        if (emptyEl) emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    function _applyCapsuleSearch() {
        renderCapsuleView();
    }

    function _applyCapsuleLegendFilter() {
        renderCapsuleView();
    }

    // Capsule search input handler
    document.addEventListener('input', function(e) {
        if (e.target && e.target.id === 'capsuleSearch') {
            renderCapsuleView();
        }
    });

    // Capsule legend filter — uses data-cap-filter to avoid collision with list/cal applyLegendFilter
    document.addEventListener('click', function(e) {
        // Clear-filter badge click
        if (e.target.closest('#legendFilterBadgeCap')) {
            applyLegendFilter(null);
            return;
        }

        const pill = e.target.closest('.cap-legend-filter');
        if (!pill) return;
        const f = pill.getAttribute('data-cap-filter');
        if (!f) return;
        // Toggle: clicking active filter again clears it
        applyLegendFilter(activeLegendFilter === f ? null : f);
    });

    function _syncCapsuleLegendUI() {
        const f = activeLegendFilter;   // single source of truth
        // Pills
        document.querySelectorAll('.cap-legend-filter').forEach(function(p) {
            const pf = p.getAttribute('data-cap-filter');
            p.classList.remove('legend-active', 'legend-dimmed');
            if (f) {
                if (pf === f) p.classList.add('legend-active');
                else p.classList.add('legend-dimmed');
            }
        });
        // Clear badge
        const badge = document.getElementById('legendFilterBadgeCap');
        const lbl   = document.getElementById('legendFilterBadgeCapLabel');
        if (badge) badge.classList.toggle('visible', !!f);
        if (lbl && f)   lbl.textContent = LEGEND_LABELS[f] || f;
    }

    // ── View Switching ────────────────────────────────────────────────────────
    const capsuleView = document.getElementById('capsuleView');

    // Restore saved view (default: calendar) — scoped per employee
    const _SCHED_VIEW_KEY = 'cimm_sched_view_' + (window.CURRENT_EMP_ID || 0);
    const _LIST_SORT_KEY  = 'cimm_list_sort_'  + (window.CURRENT_EMP_ID || 0);
    let currentView = (function() {
        try { return localStorage.getItem(_SCHED_VIEW_KEY) || 'calendar'; } catch(e) { return 'calendar'; }
    })();

    const VIEW_ICONS = { list: 'fa-list', calendar: 'fa-calendar-alt', capsule: 'fa-th-large' };
    const VIEW_LABELS = { list: 'List', calendar: 'Calendar', capsule: 'Capsule' };

    function switchToView(view) {
        currentView = view;
        showingCalendar = (view === 'calendar');

        // Persist preference
        try { localStorage.setItem(_SCHED_VIEW_KEY, view); } catch(e) {}

        // Toggle main panels
        if (calendarView)  calendarView.classList.toggle('hidden', view !== 'calendar');
        if (scheduleView)  scheduleView.classList.toggle('hidden', view !== 'list');
        if (capsuleView)   capsuleView.classList.toggle('hidden',  view !== 'capsule');

        // Render capsule on demand
        if (view === 'capsule') renderCapsuleView();

        updateMobileControls();
        updateWeekdayLabels();

        // Update all view-switcher dropdowns
        document.querySelectorAll('.view-switcher-option, .mob-view-switcher-option').forEach(function(opt) {
            opt.classList.toggle('active', opt.getAttribute('data-view') === view);
        });

        // Update desktop view-switcher button labels & icons
        const ICON = VIEW_ICONS[view] || 'fa-list';
        const LABEL = VIEW_LABELS[view] || 'View';
        document.querySelectorAll('.view-switcher-btn').forEach(function(btn) {
            const iEl = btn.querySelector('i:not(.view-switcher-chevron)');
            const lEl = btn.querySelector('.view-switcher-label');
            if (iEl) { iEl.className = 'fas ' + ICON; }
            if (lEl) lEl.textContent = LABEL;
        });

        // Update mobile icon buttons (mobListViewSwitcherBtn + mobCalViewSwitcherBtn)
        document.querySelectorAll('.mob-view-icon').forEach(function(iEl) {
            iEl.className = 'fas ' + ICON + ' mob-view-icon';
        });

        // Close all view-switcher dropdowns
        document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(function(w) {
            w.classList.remove('open');
        });
    }

    // ── View-switcher dropdown: single event-delegation handler (no stopPropagation needed) ──
    document.addEventListener('click', function(e) {
        // 1. Button click → toggle its own dropdown
        const btn = e.target.closest('.view-switcher-btn');
        if (btn) {
            const wrap = btn.closest('.view-switcher-wrap');
            if (wrap) {
                const isOpen = wrap.classList.contains('open');
                // close all first
                document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap, .cap-sort-wrap').forEach(w => w.classList.remove('open'));
                if (!isOpen) wrap.classList.add('open');
                return;
            }
        }

        // 2. Mobile icon-btn → toggle its mob-view-switcher-wrap
        const mobBtn = e.target.closest('.mob-view-switcher-wrap > .mob-icon-btn');
        if (mobBtn) {
            const wrap = mobBtn.closest('.mob-view-switcher-wrap');
            if (wrap) {
                const isOpen = wrap.classList.contains('open');
                document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap, .cap-sort-wrap').forEach(w => w.classList.remove('open'));
                if (!isOpen) wrap.classList.add('open');
                return;
            }
        }

        // 3. Option clicked inside a view-switcher dropdown
        const opt = e.target.closest('.view-switcher-option, .mob-view-switcher-option');
        if (opt) {
            const view = opt.getAttribute('data-view');
            if (view) switchToView(view);
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
            return;
        }

        // 4. Click anywhere else → close all
        if (!e.target.closest('.view-switcher-wrap, .mob-view-switcher-wrap')) {
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
        }
    });

    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;

        if (currentView === 'calendar') {
            mobileCalendarControls.classList.add('mob-active');
            mobileListControls.classList.remove('mob-active');
            // Sync month label text
            const mlt    = document.getElementById('monthLabelText');
            const mobMlt = document.getElementById('mobileMonthLabelText');
            const text   = mlt ? mlt.textContent : (monthLabel ? monthLabel.textContent : '');
            if (mobMlt) mobMlt.textContent = text;
        } else {
            // list OR capsule — both use the mobile list toolbar
            mobileListControls.classList.add('mob-active');
            mobileCalendarControls.classList.remove('mob-active');
        }
    }

    let lastMobileState = isMobileView();
    window.addEventListener('resize', () => {
        updateMobileControls();
        updateWeekdayLabels && updateWeekdayLabels();
        const nowMobile = isMobileView();
        if (nowMobile !== lastMobileState) {
            lastMobileState = nowMobile;
            closeDropdown();
            renderCalendar();
        }
    });

    if (mobileToCalendarBtn) mobileToCalendarBtn.onclick = showCalendarView;
    if (mobileToListBtn) mobileToListBtn.onclick = showListView;
    if (mobilePrevMonth) mobilePrevMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
        updateMobileControls();
    };
    if (mobileNextMonth) mobileNextMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
        updateMobileControls();
    };

    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            if (currentView === 'capsule') {
                // Feed mobile search into capsule search input and re-render
                const capSearch = document.getElementById('capsuleSearch');
                if (capSearch) {
                    capSearch.value = e.target.value;
                    renderCapsuleView();
                }
            } else {
                scheduleSearch.value = e.target.value;
                scheduleSearch.dispatchEvent(new Event('input'));
            }
        });
    }

    // Sync mobile sort → capsule sort when in capsule view
    // The wireSort IIFE runs below and handles list sort. Here we intercept for capsule.
    document.addEventListener('click', function(e) {
        if (currentView !== 'capsule') return;
        const opt = e.target.closest('#mobSchedSortDropdown .sort-option');
        if (!opt) return;
        const sort = opt.getAttribute('data-sort');
        if (!sort) return;
        // Map list-sort modes to capsule sort modes (id-asc/desc don't exist → skip)
        const capSortMap = {
            'date-asc':   'date-asc',
            'date-desc':  'date-desc',
            'alpha-asc':  'alpha-asc',
            'alpha-desc': 'alpha-desc',
            'cprf':       'cprf',
            'energy':     'energy',
        };
        if (capSortMap[sort]) {
            _capsuleSortMode = capSortMap[sort];
            // Sync active state in capsule sort dropdown too
            document.querySelectorAll('.cap-sort-option').forEach(o =>
                o.classList.toggle('active', o.getAttribute('data-sort') === _capsuleSortMode)
            );
            renderCapsuleView();
        }
    });

    updateMobileControls();

    window.updateWeekdayLabels = function updateWeekdayLabels() {
        const desktopDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const shortDays = ['S','M','T','W','T','F','S'];
        const weekdayDivs = document.querySelectorAll('.calendar-weekdays div');
        if (!weekdayDivs.length) return;
        if (window.innerWidth <= 768) {
            weekdayDivs.forEach((el, i) => { el.textContent = shortDays[i]; });
        } else {
            weekdayDivs.forEach((el, i) => { el.textContent = desktopDays[i]; });
        }
    };

    window.addEventListener('load', updateWeekdayLabels);
    window.addEventListener('resize', updateWeekdayLabels);

    // ═══════════════════════════════════════════
    //  DATE PICKER — REDESIGNED
    // ═══════════════════════════════════════════
    const overlayPicker  = document.getElementById('customDatePickerOverlay');
    const dpMonthBtn     = document.getElementById('dpMonthBtn');
    const dpYearBtn      = document.getElementById('dpYearBtn');
    const dpGrid         = document.getElementById('dpGrid');
    const dpPrevMonth    = document.getElementById('dpPrevMonth');
    const dpNextMonth    = document.getElementById('dpNextMonth');
    const dpTodayBtn     = document.getElementById('dpTodayBtn');
    const dpCloseBtn     = document.getElementById('dpCloseBtn');
    const dpYearDrop     = document.getElementById('dpYearDropdown');
    const dpMonthDrop    = document.getElementById('dpMonthDropdown');

    let _dpDate      = new Date(currentDate);
    let _dpSelected  = null;
    let _dpOpen      = false;

    const DP_MONTHS = ['January','February','March','April','May','June',
                       'July','August','September','October','November','December'];

    // Build a Set of all dates that have tasks — for dot indicators
    function getDatesWithTasks() {
        const set = new Set();
        (window.scheduleData || []).forEach(e => { if (e.schedule_date) set.add(e.schedule_date); });
        return set;
    }

    function buildDpYearGrid() {
        dpYearDrop.innerHTML = '';
        const endY   = new Date().getFullYear() + 5;
        const startY = endY - 110;
        for (let y = endY; y >= startY; y--) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'dp-year-opt' + (y === _dpDate.getFullYear() ? ' selected' : '');
            b.textContent = y;
            b.dataset.year = y;
            b.addEventListener('click', function(e) {
                e.stopPropagation();
                _dpDate.setFullYear(+this.dataset.year);
                renderDpGrid();
            });
            dpYearDrop.appendChild(b);
        }
        setTimeout(function() {
            const sel = dpYearDrop.querySelector('.selected');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }, 30);
    }

    function renderDpGrid() {
        if (!dpGrid || !dpMonthBtn || !dpYearBtn) return;

        // Close sub-dropdowns
        dpYearDrop.classList.remove('open');
        dpMonthDrop.classList.remove('open');
        dpYearBtn.classList.remove('active');
        dpMonthBtn.classList.remove('active');

        const year  = _dpDate.getFullYear();
        const month = _dpDate.getMonth();
        dpMonthBtn.textContent = DP_MONTHS[month].slice(0, 3);
        dpYearBtn.textContent  = year;

        const today       = new Date();
        const todayStr    = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
        const taskDates   = getDatesWithTasks();
        const firstDay    = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        dpGrid.innerHTML = '';

        for (let i = 0; i < firstDay; i++) {
            const empty = document.createElement('div');
            empty.className = 'dp-day dp-empty';
            dpGrid.appendChild(empty);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const dayOfWeek = new Date(year, month, d).getDay();
            const isWeekendDay = dayOfWeek === 0 || dayOfWeek === 6;

            const btn = document.createElement('button');
            btn.className   = 'dp-day';
            btn.textContent = d;
            btn.setAttribute('data-date', dateStr);

            if (isWeekendDay)                btn.classList.add('dp-weekend');
            if (dateStr === todayStr)        btn.classList.add('dp-today');
            if (dateStr === _dpSelected)     btn.classList.add('dp-selected');
            if (taskDates.has(dateStr))      btn.classList.add('dp-has-tasks');

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                _dpSelected = dateStr;
                const [y, m, dd] = dateStr.split('-').map(Number);
                currentDate = new Date(y, m - 1, dd);
                renderCalendar();
                updateMobileControls();
                renderDpGrid();
            });

            btn.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                const tasks = (window.scheduleData || []).filter(t => t.schedule_date === dateStr);
                if (!tasks.length) return;
                closeDatePicker();
                if (tasks.length === 1) {
                    openModal(tasks, 0);
                } else {
                    openTaskChooser(dateStr, tasks);
                }
            });

            if (taskDates.has(dateStr)) btn.title = 'Double-click to view task(s)';

            dpGrid.appendChild(btn);
        }
    }

    // Month button toggle
    if (dpMonthBtn) dpMonthBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dpYearDrop.classList.remove('open'); dpYearBtn.classList.remove('active');
        const nowOpen = dpMonthDrop.classList.toggle('open');
        dpMonthBtn.classList.toggle('active', nowOpen);
        Array.from(dpMonthDrop.querySelectorAll('.dp-month-opt')).forEach(function(b) {
            b.classList.toggle('selected', +b.dataset.month === _dpDate.getMonth());
        });
    });

    // Year button toggle
    if (dpYearBtn) dpYearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dpMonthDrop.classList.remove('open'); dpMonthBtn.classList.remove('active');
        const nowOpen = dpYearDrop.classList.toggle('open');
        dpYearBtn.classList.toggle('active', nowOpen);
        if (nowOpen) buildDpYearGrid();
    });

    // Month option clicks
    if (dpMonthDrop) dpMonthDrop.addEventListener('click', function(e) {
        const b = e.target.closest('.dp-month-opt');
        if (!b) return;
        e.stopPropagation();
        _dpDate.setMonth(+b.dataset.month);
        renderDpGrid();
    });

    function openDatePicker(event) {
        if (!overlayPicker) return;
        _dpDate     = new Date(currentDate);
        _dpSelected = `${currentDate.getFullYear()}-${String(currentDate.getMonth()+1).padStart(2,'0')}-${String(currentDate.getDate()).padStart(2,'0')}`;
        renderDpGrid();

        overlayPicker.style.removeProperty('animation');
        overlayPicker.style.display    = 'block';
        overlayPicker.style.visibility = 'hidden';

        const anchorEl = event.currentTarget
            || (event.target && event.target.closest && event.target.closest('#monthLabel, #mobileMonthLabel'))
            || event.target;
        const rect    = anchorEl.getBoundingClientRect();
        const pickerW = overlayPicker.offsetWidth  || 288;
        const pickerH = overlayPicker.offsetHeight || 380;
        const gap     = 8;
        const vw      = window.innerWidth;
        const vh      = window.innerHeight;

        let top  = rect.bottom + gap;
        let left = rect.left + rect.width / 2 - pickerW / 2;
        left = Math.max(12, Math.min(left, vw - pickerW - 12));
        if (top + pickerH > vh - 12) top = rect.top - pickerH - gap;
        if (top < 8) top = 8;

        overlayPicker.style.position  = 'fixed';
        overlayPicker.style.top       = top  + 'px';
        overlayPicker.style.left      = left + 'px';
        overlayPicker.style.removeProperty('bottom');
        overlayPicker.style.removeProperty('transform');
        overlayPicker.style.visibility = 'visible';
        void overlayPicker.offsetWidth;
        overlayPicker.style.animation = 'dpPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';

        _dpOpen = true;
    }

    function closeDatePicker() {
        if (!overlayPicker) return;
        overlayPicker.style.display = 'none';
        _dpOpen = false;
    }

    // Picker navigation
    if (dpPrevMonth) dpPrevMonth.addEventListener('click', (e) => {
        e.stopPropagation();
        _dpDate.setMonth(_dpDate.getMonth() - 1);
        renderDpGrid();
    });
    if (dpNextMonth) dpNextMonth.addEventListener('click', (e) => {
        e.stopPropagation();
        _dpDate.setMonth(_dpDate.getMonth() + 1);
        renderDpGrid();
    });
    if (dpTodayBtn) dpTodayBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const t     = new Date();
        _dpDate     = new Date(t);
        _dpSelected = `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
        currentDate = new Date(t);
        renderCalendar();
        updateMobileControls();
        renderDpGrid();
        closeDatePicker();
    });
    if (dpCloseBtn) dpCloseBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        closeDatePicker();
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        const clickedLabel = e.target.closest && (e.target.closest('#monthLabel') || e.target.closest('#mobileMonthLabel'));
        if (_dpOpen && overlayPicker && !overlayPicker.contains(e.target) && !clickedLabel) {
            closeDatePicker();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _dpOpen) closeDatePicker();
    });

    // Stop clicks inside picker from bubbling to document
    if (overlayPicker) overlayPicker.addEventListener('click', (e) => e.stopPropagation());

    // Wire up month label clicks
    if (monthLabel) {
        monthLabel.title = 'Click to jump to date';
        monthLabel.style.cursor = 'pointer';
        monthLabel.addEventListener('click', openDatePicker);
    }
    if (mobileMonthLabel) {
        mobileMonthLabel.title = 'Click to jump to date';
        mobileMonthLabel.style.cursor = 'pointer';
        mobileMonthLabel.addEventListener('click', openDatePicker);
    }

    // ═══════════════════════════════════════════════════════
    //  SORT — Schedule List View
    // ═══════════════════════════════════════════════════════
    (function initSchedSort() {
        const holder = document.getElementById('scheduleListHolder');
        if (!holder) return;

        function applySchedSort(mode) {
            const noMsg = document.getElementById('noResultMsg');
            const items = Array.from(holder.querySelectorAll('.schedule-item'));
            const searchVal = (scheduleSearch && scheduleSearch.value.trim().toLowerCase()) || '';

            // Same matching rules used by the search-input handler, so sorting
            // never un-hides items that the active search would otherwise filter out.
            function matchesSearch(item) {
                if (!searchVal) return true;
                const task   = item.getAttribute('data-task') || '';
                const loc    = item.getAttribute('data-location') || '';
                const date   = item.getAttribute('data-date') || '';
                const cat    = item.getAttribute('data-category') || '';
                const stat   = item.getAttribute('data-status') || '';
                const prio   = item.getAttribute('data-priority') || '';
                const rep    = item.getAttribute('data-rep') || '';
                const budget = item.getAttribute('data-budget') || '';
                const shared = item.getAttribute('data-shared') || '';
                const energy = item.getAttribute('data-energy') || '';
                return task.includes(searchVal)   || loc.includes(searchVal)    || date.includes(searchVal)  ||
                       cat.includes(searchVal)    || stat.includes(searchVal)   || prio.includes(searchVal)  ||
                       rep.includes(searchVal)    || budget.includes(searchVal) || shared.includes(searchVal) ||
                       energy.includes(searchVal);
            }

            // CPRF mode = FILTER: show only shared items (that also satisfy legend + search), hide the rest
            if (mode === 'cprf') {
                let shownCount = 0;
                items.forEach(item => {
                    const isShared = (item.dataset.shared || '') === 'cprf';
                    const stat = item.getAttribute('data-status') || '';
                    const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;
                    const show = isShared && legendOk && matchesSearch(item);
                    item.classList.toggle('filter-hidden', !show);
                    if (show) shownCount++;
                });
                if (noMsg) {
                    const noMsgText = noMsg.querySelector('#noResultMsgText');
                    if (noMsgText) noMsgText.textContent = 'No CPRF-shared schedules found.';
                    noMsg.style.display = shownCount === 0 ? '' : 'none';
                }
                return;
            }

            // Energy mode = FILTER: show only Energy-imported items (that also
            // satisfy legend + search), hide the rest — mirrors the CPRF mode above.
            if (mode === 'energy') {
                let shownCount = 0;
                items.forEach(item => {
                    const isEnergy = (item.dataset.energy || '') === 'energy';
                    const stat = item.getAttribute('data-status') || '';
                    const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;
                    const show = isEnergy && legendOk && matchesSearch(item);
                    item.classList.toggle('filter-hidden', !show);
                    if (show) shownCount++;
                });
                if (noMsg) {
                    const noMsgText = noMsg.querySelector('#noResultMsgText');
                    if (noMsgText) noMsgText.textContent = 'No Energy-imported schedules found.';
                    noMsg.style.display = shownCount === 0 ? '' : 'none';
                }
                return;
            }

            // All other modes: recompute visibility from the current legend + search
            // filters (not just "was it hidden by cprf"), so the count driving the
            // no-results message always matches what's actually on screen.
            let shownCount = 0;
            items.forEach(item => {
                const stat = item.getAttribute('data-status') || '';
                const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;
                const show = legendOk && matchesSearch(item);
                item.classList.toggle('filter-hidden', !show);
                if (show) shownCount++;
            });

            // Then sort
            items.sort((a, b) => {
                // data-date format: "human label|yyyy-mm-dd"
                const dateA = (a.dataset.date || '').split('|')[1] || '';
                const dateB = (b.dataset.date || '').split('|')[1] || '';
                if (mode === 'date-asc')  return dateA.localeCompare(dateB);
                if (mode === 'date-desc') return dateB.localeCompare(dateA);
                const idA = parseInt(a.dataset.repId || 0);
                const idB = parseInt(b.dataset.repId || 0);
                if (mode === 'id-asc')    return idA - idB;
                if (mode === 'id-desc')   return idB - idA;
                const tA = (a.dataset.task || '').toLowerCase();
                const tB = (b.dataset.task || '').toLowerCase();
                if (mode === 'alpha-asc')  return tA.localeCompare(tB);
                if (mode === 'alpha-desc') return tB.localeCompare(tA);
                return 0;
            });
            items.forEach(item => holder.appendChild(item));
            if (noMsg) {
                const noMsgText = noMsg.querySelector('#noResultMsgText');
                if (noMsgText) noMsgText.textContent = 'No matching data or result.';
                noMsg.style.display = shownCount === 0 ? '' : 'none';
                holder.appendChild(noMsg);
            }
        }

        function wireSort(wrapId, btnId, dropdownId, siblingDropdownId) {
            const wrap     = document.getElementById(wrapId);
            const btn      = document.getElementById(btnId);
            const dropdown = document.getElementById(dropdownId);
            if (!wrap || !btn || !dropdown) return;

            btn.addEventListener('click', e => {
                e.stopPropagation();
                // Close sibling dropdown if open
                const sibling = document.getElementById(siblingDropdownId);
                if (sibling) sibling.closest('.sort-dropdown-wrap')?.classList.remove('open');
                wrap.classList.toggle('open');
            });
            document.addEventListener('click', e => {
                if (!wrap.contains(e.target)) wrap.classList.remove('open');
            });

            dropdown.querySelectorAll('.sort-option').forEach(opt => {
                opt.addEventListener('click', () => {
                    const chosenSort = opt.dataset.sort;
                    // Sync active state across both dropdowns
                    ['schedSortDropdown', 'mobSchedSortDropdown'].forEach(id => {
                        const d = document.getElementById(id);
                        if (d) d.querySelectorAll('.sort-option').forEach(o => {
                            o.classList.toggle('active', o.dataset.sort === chosenSort);
                        });
                    });
                    wrap.classList.remove('open');
                    // Save per-user list sort preference
                    try { localStorage.setItem(_LIST_SORT_KEY, chosenSort); } catch(e) {}
                    applySchedSort(chosenSort);
                });
            });
        }

        wireSort('schedSortWrap',    'schedSortBtn',    'schedSortDropdown',    'mobSchedSortDropdown');
        wireSort('mobSchedSortWrap', 'mobSchedSortBtn', 'mobSchedSortDropdown', 'schedSortDropdown');

        // ── Restore saved list sort on page load ──────────────────────────
        (function restoreListSort() {
            let saved;
            try { saved = localStorage.getItem(_LIST_SORT_KEY); } catch(e) {}
            if (!saved) return;
            ['schedSortDropdown', 'mobSchedSortDropdown'].forEach(function(id) {
                const d = document.getElementById(id);
                if (d) d.querySelectorAll('.sort-option').forEach(function(o) {
                    o.classList.toggle('active', o.dataset.sort === saved);
                });
            });
            applySchedSort(saved);
        })();
    })();

    // Restore saved view — must be LAST so all functions and wireViewSwitcher are ready
    switchToView(currentView);

    // ── Arrived here from employee.php's "Upcoming Maintenance" widget? ────
    // ?highlight_source=schedule|report&highlight_id=<id> — locate the
    // matching item in whichever view (calendar/list/capsule) is currently
    // active and highlight it, regardless of which view that happens to be.
    (function initHighlightFromQuery() {
        let params;
        try { params = new URLSearchParams(window.location.search); } catch (e) { return; }
        const hSource = params.get('highlight_source');
        const hId     = params.get('highlight_id');
        if (!hSource || !hId) return;
        setTimeout(function () { window.schedHighlightItem(hSource, hId); }, 150);
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    })();

    // ── CPRF facility schedule form (admin) ─────────────────────────────
    if (window.IS_ADMIN) {
        const sfModal = document.getElementById('scheduleFormModal');
        const sfForm = document.getElementById('scheduleForm');
        const sfFacility = document.getElementById('sfCprfFacility');
        const sfError = document.getElementById('scheduleFormError');
        const facilities = window.cprfFacilities || [];

        // ═══════════════════════════════════════════════════════════
        // Generic searchable-combobox engine (ported from profile.php)
        // Reused for CPRF Facility (dynamic list) + Category / Priority / Status (static)
        // ═══════════════════════════════════════════════════════════
        const sfCombos = [];
        function sfInitCombo(cfg) {
            const displayEl  = document.getElementById(cfg.displayId);
            const dropdownEl = document.getElementById(cfg.dropdownId);
            const hiddenEl   = document.getElementById(cfg.hiddenId);
            const labelEl    = document.getElementById(cfg.labelId);
            const listEl     = (cfg.listId && document.getElementById(cfg.listId)) || dropdownEl.querySelector('.sf-combobox-list');
            const searchEl   = dropdownEl.querySelector('.sf-combobox-search');
            if (!displayEl || !dropdownEl || !listEl || !hiddenEl || !labelEl) return null;

            let isOpen = false;

            function getOptions() { return Array.from(listEl.querySelectorAll('.sf-combobox-option')); }

            function positionDropdown() {
                const rect = displayEl.getBoundingClientRect();
                const vw = window.innerWidth, vh = window.innerHeight;
                dropdownEl.style.width = rect.width + 'px';
                dropdownEl.style.visibility = 'hidden';
                dropdownEl.style.display = 'block';
                const dh = dropdownEl.offsetHeight || 220;
                dropdownEl.style.display = '';
                dropdownEl.style.visibility = '';
                let top = rect.bottom + 4;
                let left = rect.left;
                if (top + dh > vh - 12 && rect.top > dh + 12) top = rect.top - dh - 4;
                left = Math.max(8, Math.min(left, vw - rect.width - 8));
                dropdownEl.style.top = top + 'px';
                dropdownEl.style.left = left + 'px';
            }

            function sfEscapeHtml(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function sfEscapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
            function sfHighlightText(text, term) {
                const safe = sfEscapeHtml(text);
                if (!term) return safe;
                const re = new RegExp('(' + sfEscapeRegExp(term) + ')', 'gi');
                return safe.replace(re, '<mark class="sf-combo-hl">$1</mark>');
            }
            function filter(q) {
                const ql = q.toLowerCase().trim();
                let visible = 0;
                getOptions().forEach(function(o) {
                    if (!o.dataset.origText) o.dataset.origText = o.textContent;
                    const raw = o.dataset.origText;
                    const match = !ql || raw.toLowerCase().includes(ql);
                    o.style.display = match ? '' : 'none';
                    o.innerHTML = '<span class="sf-combobox-option-text">' + (match ? sfHighlightText(raw, q.trim()) : sfEscapeHtml(raw)) + '</span>';
                    if (match) visible++;
                });
                let noRes = listEl.querySelector('.sf-combobox-no-results');
                if (!visible) {
                    if (!noRes) {
                        noRes = document.createElement('div');
                        noRes.className = 'sf-combobox-no-results';
                        noRes.textContent = 'No results found';
                        listEl.appendChild(noRes);
                    }
                } else if (noRes) { noRes.remove(); }
            }

            function open() {
                sfCombos.forEach(function(c) { if (c !== api) c.close(); });
                sfCloseAllDatePickers();
                isOpen = true;
                positionDropdown();
                displayEl.classList.add('open');
                dropdownEl.classList.add('open');
                if (searchEl) {
                    searchEl.value = '';
                    filter('');
                    setTimeout(function() { searchEl.focus(); }, 30);
                }
                setTimeout(function() {
                    const sel = listEl.querySelector('.selected-opt');
                    if (sel) sel.scrollIntoView({ block: 'nearest' });
                }, 30);
            }

            function close() {
                isOpen = false;
                displayEl.classList.remove('open');
                dropdownEl.classList.remove('open');
                if (searchEl) { searchEl.value = ''; filter(''); }
            }

            function setValue(value, text, silent) {
                hiddenEl.value = value || '';
                labelEl.textContent = text || cfg.placeholder;
                labelEl.classList.toggle('selected', !!value);
                getOptions().forEach(function(o) { o.classList.toggle('selected-opt', o.dataset.value === value); });
                if (!silent && cfg.onChange) cfg.onChange(value, text);
            }

            displayEl.addEventListener('click', function(e) {
                e.stopPropagation();
                isOpen ? close() : open();
            });
            listEl.addEventListener('mousedown', function(e) {
                const opt = e.target.closest('.sf-combobox-option');
                if (!opt) return;
                e.preventDefault();
                setValue(opt.dataset.value, opt.textContent.trim());
                close();
            });
            if (searchEl) {
                searchEl.addEventListener('click', function(e) { e.stopPropagation(); });
                searchEl.addEventListener('input', function() { filter(searchEl.value); });
                searchEl.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') close();
                });
            }
            window.addEventListener('resize', function() { if (isOpen) positionDropdown(); });
            document.addEventListener('scroll', function() { if (isOpen) positionDropdown(); }, true);

            const api = { close: close, open: open, setValue: setValue, boxEl: displayEl, dropdownEl: dropdownEl };
            sfCombos.push(api);
            return api;
        }

        document.addEventListener('click', function(e) {
            sfCombos.forEach(function(c) {
                if (!c.boxEl.contains(e.target) && !c.dropdownEl.contains(e.target)) c.close();
            });
        });

        function facilityLabel(f) {
            const loc = f.location ? ' — ' + f.location : '';
            return '#' + f.facility_id + ' · ' + f.name + loc;
        }

        function facilityDefaultLocation(f) {
            if (!f) return '';
            return f.location ? (f.name + ', ' + f.location) : f.name;
        }

        // Build the facility option list once (data is fixed at page load)
        const sfFacilityListEl = document.getElementById('sfCprfFacilityList');
        if (sfFacilityListEl) {
            sfFacilityListEl.innerHTML = facilities.map(function(f) {
                return '<div class="sf-combobox-option" data-value="' + f.facility_id + '">' +
                       facilityLabel(f).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
            }).join('');
        }

        const sfFacilityCombo = sfInitCombo({
            displayId: 'sfCprfFacilityDisplay',
            dropdownId: 'sfCprfFacilityDropdown',
            hiddenId: 'sfCprfFacility',
            labelId: 'sfCprfFacilityLabel',
            listId: 'sfCprfFacilityList',
            placeholder: '— Select facility from CPRF —',
            onChange: function(value) {
                const id = parseInt(value, 10);
                const f = facilities.find(function(x) { return x.facility_id === id; });
                const locInput = document.getElementById('sfLocation');
                if (f && locInput && locInput.dataset.auto === '1') {
                    locInput.value = facilityDefaultLocation(f);
                }
            }
        });

        const sfCategoryCombo = sfInitCombo({
            displayId: 'sfCategoryDisplay', dropdownId: 'sfCategoryDropdown',
            hiddenId: 'sfCategory', labelId: 'sfCategoryLabel',
            placeholder: 'General Maintenance'
        });
        const sfPriorityCombo = sfInitCombo({
            displayId: 'sfPriorityDisplay', dropdownId: 'sfPriorityDropdown',
            hiddenId: 'sfPriority', labelId: 'sfPriorityLabel',
            placeholder: 'Low'
        });
        const sfStatusCombo = sfInitCombo({
            displayId: 'sfStatusDisplay', dropdownId: 'sfStatusDropdown',
            hiddenId: 'sfStatus', labelId: 'sfStatusLabel',
            placeholder: 'Scheduled'
        });

        function setFacilitySelection(id) {
            if (!sfFacilityCombo) return;
            if (!id) { sfFacilityCombo.setValue('', '', true); return; }
            const f = facilities.find(function(x) { return x.facility_id === parseInt(id, 10); });
            sfFacilityCombo.setValue(String(id), f ? facilityLabel(f) : ('#' + id), true);
        }

        // ═══════════════════════════════════════════════════════════
        // Shared calendar date-picker (ported from profile.php DOB picker)
        // Used by both Start Date and Est. Completion fields
        // ═══════════════════════════════════════════════════════════
        const sfDpOverlay   = document.getElementById('sfDatePickerOverlay');
        const sfDpMonthBtn  = document.getElementById('sfDpMonthBtn');
        const sfDpYearBtn   = document.getElementById('sfDpYearBtn');
        const sfDpPrev      = document.getElementById('sfDpPrevMonth');
        const sfDpNext      = document.getElementById('sfDpNextMonth');
        const sfDpYearDrop  = document.getElementById('sfDpYearDropdown');
        const sfDpMonthDrop = document.getElementById('sfDpMonthDropdown');
        const sfDpGrid      = document.getElementById('sfDpGrid');
        const sfDpClearBtn  = document.getElementById('sfDpClear');
        const sfDpCloseBtn  = document.getElementById('sfDpClose');

        const SF_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        let sfDpActiveField = null; // { hiddenId, textId, displayId, minDateOf }
        let sfDpViewYear = new Date().getFullYear();
        let sfDpViewMonth = new Date().getMonth();
        let sfDpSelDate = null;

        function sfPad2(n) { return String(n).padStart(2, '0'); }
        function sfFmtISO(d) { return d.getFullYear() + '-' + sfPad2(d.getMonth() + 1) + '-' + sfPad2(d.getDate()); }
        function sfFmtDisplay(d) { return SF_MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
        function sfParseISO(s) {
            if (!s) return null;
            const p = String(s).slice(0, 10).split('-');
            if (p.length !== 3) return null;
            return new Date(+p[0], +p[1] - 1, +p[2]);
        }

        function sfCloseAllDatePickers() {
            if (sfDpOverlay) sfDpOverlay.style.display = 'none';
            document.querySelectorAll('.sf-date-display.open').forEach(function(el) { el.classList.remove('open'); });
            sfDpActiveField = null;
        }

        function sfSetFieldDisplay(field, d) {
            const textEl = document.getElementById(field.textId);
            const hiddenEl = document.getElementById(field.hiddenId);
            if (!textEl || !hiddenEl) return;
            if (d) {
                hiddenEl.value = sfFmtISO(d);
                textEl.textContent = sfFmtDisplay(d);
                textEl.classList.remove('placeholder');
            } else {
                hiddenEl.value = '';
                textEl.textContent = field.placeholder;
                textEl.classList.add('placeholder');
            }
        }

        function sfDpRenderGrid() {
            sfDpYearDrop.classList.remove('open');
            sfDpMonthDrop.classList.remove('open');
            sfDpYearBtn.classList.remove('active');
            sfDpMonthBtn.classList.remove('active');

            sfDpMonthBtn.textContent = SF_MONTHS[sfDpViewMonth].slice(0, 3);
            sfDpYearBtn.textContent = sfDpViewYear;

            const firstDay = new Date(sfDpViewYear, sfDpViewMonth, 1).getDay();
            const daysInMonth = new Date(sfDpViewYear, sfDpViewMonth + 1, 0).getDate();
            const today = new Date();
            const todayStr = sfFmtISO(today);
            const selStr = sfDpSelDate ? sfFmtISO(sfDpSelDate) : '';

            // Optional lower bound (used by the End Date field: can't be before Start Date)
            let minDate = null;
            if (sfDpActiveField && sfDpActiveField.minDateOf) {
                const startVal = document.getElementById(sfDpActiveField.minDateOf).value;
                minDate = sfParseISO(startVal);
            }

            sfDpGrid.innerHTML = '';
            for (let i = 0; i < firstDay; i++) {
                const emp = document.createElement('div');
                emp.className = 'sf-dp-day sf-dp-empty';
                sfDpGrid.appendChild(emp);
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const dateObj = new Date(sfDpViewYear, sfDpViewMonth, d);
                const dateStr = sfFmtISO(dateObj);
                const dow = dateObj.getDay();
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sf-dp-day';
                btn.textContent = d;
                btn.dataset.date = dateStr;
                if (dow === 0 || dow === 6) btn.classList.add('sf-dp-weekend');
                if (dateStr === todayStr) btn.classList.add('sf-dp-today');
                if (dateStr === selStr) btn.classList.add('sf-dp-selected');
                if (minDate && dateObj < minDate) btn.classList.add('sf-dp-disabled');
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const parts = this.dataset.date.split('-');
                    sfDpSelDate = new Date(+parts[0], +parts[1] - 1, +parts[2]);
                    if (sfDpActiveField) sfSetFieldDisplay(sfDpActiveField, sfDpSelDate);
                    sfDpRenderGrid();
                });
                sfDpGrid.appendChild(btn);
            }
        }

        function sfDpBuildYearGrid() {
            sfDpYearDrop.innerHTML = '';
            const centerY = new Date().getFullYear();
            const startY = centerY - 5;
            const endY = centerY + 15;
            for (let y = endY; y >= startY; y--) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'sf-dp-year-opt' + (y === sfDpViewYear ? ' selected' : '');
                b.textContent = y;
                b.dataset.year = y;
                b.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sfDpViewYear = +this.dataset.year;
                    sfDpRenderGrid();
                });
                sfDpYearDrop.appendChild(b);
            }
            setTimeout(function() {
                const sel = sfDpYearDrop.querySelector('.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function sfDpPositionOverlay(displayEl) {
            const rect = displayEl.getBoundingClientRect();
            const vw = window.innerWidth, vh = window.innerHeight;
            sfDpOverlay.style.visibility = 'hidden';
            sfDpOverlay.style.display = 'block';
            const ow = sfDpOverlay.offsetWidth || 288;
            const oh = Math.min(sfDpOverlay.scrollHeight || 380, vh * 0.8);
            sfDpOverlay.style.visibility = '';
            let top = rect.bottom + 6;
            let left = rect.left + rect.width / 2 - ow / 2;
            left = Math.max(8, Math.min(left, vw - ow - 8));
            if (top + oh > vh - 10 && rect.top > oh + 10) top = rect.top - oh - 6;
            if (top < 8) top = 8;
            sfDpOverlay.style.top = top + 'px';
            sfDpOverlay.style.left = left + 'px';
            sfDpOverlay.style.display = 'none';
        }

        function sfOpenDatePicker(field, displayEl) {
            sfCombos.forEach(function(c) { c.close(); });
            sfDpActiveField = field;
            const curVal = document.getElementById(field.hiddenId).value;
            sfDpSelDate = sfParseISO(curVal);
            sfDpViewYear = sfDpSelDate ? sfDpSelDate.getFullYear() : new Date().getFullYear();
            sfDpViewMonth = sfDpSelDate ? sfDpSelDate.getMonth() : new Date().getMonth();
            document.querySelectorAll('.sf-date-display.open').forEach(function(el) { el.classList.remove('open'); });
            displayEl.classList.add('open');
            sfDpRenderGrid();
            sfDpPositionOverlay(displayEl);
            sfDpOverlay.style.removeProperty('animation');
            sfDpOverlay.style.display = 'block';
            sfDpOverlay.style.visibility = 'visible';
            void sfDpOverlay.offsetWidth;
            sfDpOverlay.style.animation = 'sfDpPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
        }

        const sfDateFields = [
            { hiddenId: 'sfStartDate', textId: 'sfStartDateText', displayId: 'sfStartDateDisplay', placeholder: 'Select start date' },
            { hiddenId: 'sfEndDate',   textId: 'sfEndDateText',   displayId: 'sfEndDateDisplay',   placeholder: 'Select end date', minDateOf: 'sfStartDate' }
        ];
        sfDateFields.forEach(function(field) {
            const displayEl = document.getElementById(field.displayId);
            if (!displayEl) return;
            displayEl.addEventListener('click', function(e) {
                e.stopPropagation();
                const isThisOpen = displayEl.classList.contains('open') && sfDpOverlay.style.display === 'block';
                if (isThisOpen) { sfCloseAllDatePickers(); }
                else { sfOpenDatePicker(field, displayEl); }
            });
        });

        if (sfDpPrev) sfDpPrev.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpViewMonth--; if (sfDpViewMonth < 0) { sfDpViewMonth = 11; sfDpViewYear--; }
            sfDpRenderGrid();
        });
        if (sfDpNext) sfDpNext.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpViewMonth++; if (sfDpViewMonth > 11) { sfDpViewMonth = 0; sfDpViewYear++; }
            sfDpRenderGrid();
        });
        if (sfDpYearBtn) sfDpYearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpMonthDrop.classList.remove('open'); sfDpMonthBtn.classList.remove('active');
            const nowOpen = sfDpYearDrop.classList.toggle('open');
            sfDpYearBtn.classList.toggle('active', nowOpen);
            if (nowOpen) sfDpBuildYearGrid();
        });
        if (sfDpMonthBtn) sfDpMonthBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpYearDrop.classList.remove('open'); sfDpYearBtn.classList.remove('active');
            const nowOpen = sfDpMonthDrop.classList.toggle('open');
            sfDpMonthBtn.classList.toggle('active', nowOpen);
            Array.from(sfDpMonthDrop.querySelectorAll('.sf-dp-month-opt')).forEach(function(b) {
                b.classList.toggle('selected', +b.dataset.month === sfDpViewMonth);
            });
        });
        if (sfDpMonthDrop) sfDpMonthDrop.addEventListener('click', function(e) {
            const b = e.target.closest('.sf-dp-month-opt');
            if (!b) return;
            e.stopPropagation();
            sfDpViewMonth = +b.dataset.month;
            sfDpRenderGrid();
        });
        if (sfDpClearBtn) sfDpClearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpSelDate = null;
            if (sfDpActiveField) sfSetFieldDisplay(sfDpActiveField, null);
            sfDpRenderGrid();
        });
        if (sfDpCloseBtn) sfDpCloseBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfCloseAllDatePickers();
        });
        document.addEventListener('click', function(e) {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' &&
                !sfDpOverlay.contains(e.target) &&
                !e.target.closest('.sf-date-display')) {
                sfCloseAllDatePickers();
            }
        });
        window.addEventListener('resize', function() {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField) {
                sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
            }
        });
        document.addEventListener('scroll', function(e) {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField &&
                !sfDpOverlay.contains(e.target)) {
                sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
            }
        }, true);
        if (sfDpOverlay) {
            sfDpOverlay.addEventListener('wheel', function(e) { e.stopPropagation(); }, { passive: true });
            sfDpOverlay.addEventListener('scroll', function(e) { e.stopPropagation(); }, true);
        }

        function showFormError(msg) {
            if (!sfError) return;
            if (msg) {
                sfError.textContent = msg;
                sfError.classList.remove('hidden');
            } else {
                sfError.textContent = '';
                sfError.classList.add('hidden');
            }
        }

        function closeScheduleFormModal() {
            if (sfModal) sfModal.classList.add('hidden');
            sfCloseAllDatePickers();
            showFormError('');
        }

        function openScheduleForm(data) {
            if (!sfForm || !sfModal) return;
            const isEnergyLinked = !!(data && data.energy_source);
            const cprfRow = document.getElementById('sfCprfFacilityRow');
            const energyRow = document.getElementById('sfEnergyFacilityRow');
            const cprfBadge = document.getElementById('sfCprfSyncBadge');
            const energyBadge = document.getElementById('sfEnergySyncBadge');
            if (cprfRow) cprfRow.style.display = isEnergyLinked ? 'none' : '';
            if (energyRow) energyRow.style.display = isEnergyLinked ? '' : 'none';
            if (cprfBadge) cprfBadge.style.display = isEnergyLinked ? 'none' : '';
            if (energyBadge) energyBadge.style.display = isEnergyLinked ? '' : 'none';
            if (isEnergyLinked) {
                setFacilitySelection('');
                const energyLabel = document.getElementById('sfEnergyFacilityLabel');
                if (energyLabel) energyLabel.textContent = data.energy_facility_name || data.location || '—';
            } else {
                setFacilitySelection(data && data.cprf_facility_id ? data.cprf_facility_id : '');
            }
            document.getElementById('scheduleFormTitle').textContent = (data && data.sched_id) ? 'Edit Maintenance Schedule' : 'Add Maintenance Schedule';
            document.getElementById('sfSchedId').value = (data && data.sched_id) ? String(data.sched_id) : '';
            document.getElementById('sfTask').value = (data && data.task) || '';
            const sfLocation = document.getElementById('sfLocation');
            if (sfLocation) {
                sfLocation.value = (data && data.location) || '';
                sfLocation.dataset.auto = data ? '0' : '1';
            }
            const startVal = (data && (data.schedule_date || data.starting_date)) ? String(data.schedule_date || data.starting_date).slice(0, 10) : '';
            sfSetFieldDisplay(sfDateFields[0], sfParseISO(startVal));
            const endVal = (data && (data.estimated_end_date || data.estimated_completion_date)) ? String(data.estimated_end_date || data.estimated_completion_date).slice(0, 10) : '';
            sfSetFieldDisplay(sfDateFields[1], sfParseISO(endVal));
            if (sfCategoryCombo) sfCategoryCombo.setValue((data && data.category) || 'General Maintenance', (data && data.category) || 'General Maintenance', true);
            if (sfPriorityCombo) sfPriorityCombo.setValue((data && data.priority) || 'Low', (data && data.priority) || 'Low', true);
            if (sfStatusCombo) sfStatusCombo.setValue((data && data.status) || 'Scheduled', (data && data.status) || 'Scheduled', true);
            document.getElementById('sfBudget').value = (data && data.budget_raw != null) ? data.budget_raw : ((data && data.budget) ? data.budget : '');
            document.getElementById('sfAssignedTeam').value = (data && data.assigned_team) || '';
            showFormError('');
            if (typeof taskModal !== 'undefined' && taskModal) taskModal.classList.add('hidden');
            sfModal.classList.remove('hidden');
        }

        window.schedOpenEditForm = function(schedId) {
            const row = (window.scheduleData || []).find(function(t) {
                return t.source === 'schedule' && parseInt(t.sched_id, 10) === parseInt(schedId, 10);
            });
            if (!row) { alert('Schedule not found'); return; }
            openScheduleForm(row);
        };

        const sfLocationEl = document.getElementById('sfLocation');
        if (sfLocationEl) {
            sfLocationEl.addEventListener('input', function() {
                sfLocationEl.dataset.auto = '0';
            });
        }

        const btnAdd = document.getElementById('btnAddSchedule');
        if (btnAdd) btnAdd.addEventListener('click', function() { openScheduleForm(null); });

        ['scheduleFormClose', 'scheduleFormCancel'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', closeScheduleFormModal);
        });

        if (sfModal) {
            sfModal.addEventListener('click', function(e) {
                if (e.target === sfModal) closeScheduleFormModal();
            });
        }

        const schedSaveConfirmBackdrop = document.getElementById('schedSaveConfirmBackdrop');
        const schedSaveConfirmTitle    = document.getElementById('schedSaveConfirmTitle');
        const schedSaveConfirmDesc     = document.getElementById('schedSaveConfirmDesc');
        const schedSaveConfirmCancel   = document.getElementById('schedSaveConfirmCancel');
        const schedSaveConfirmOk       = document.getElementById('schedSaveConfirmOk');
        let sfPendingPayload = null;

        function openSchedSaveConfirm(payload) {
            sfPendingPayload = payload;
            const isEdit = payload.sched_id > 0;
            const energyRow = document.getElementById('sfEnergyFacilityRow');
            const isEnergyLinked = !!(energyRow && energyRow.style.display !== 'none');
            schedSaveConfirmTitle.textContent = isEdit ? 'Save changes to this schedule?' : 'Add this maintenance schedule?';
            if (isEnergyLinked) {
                schedSaveConfirmDesc.textContent = 'This will update the schedule and push the status/date changes back to the Energy Management System. The changes will be saved immediately.';
            } else {
                schedSaveConfirmDesc.textContent = isEdit
                    ? 'This will update the maintenance schedule for the selected CPRF facility. The changes will be saved immediately.'
                    : 'This will create a new maintenance schedule for the selected CPRF facility. The changes will be saved immediately.';
            }
            schedSaveConfirmBackdrop.classList.add('active');
        }
        function closeSchedSaveConfirm() {
            schedSaveConfirmBackdrop.classList.remove('active');
            sfPendingPayload = null;
        }
        if (schedSaveConfirmCancel) schedSaveConfirmCancel.addEventListener('click', closeSchedSaveConfirm);
        if (schedSaveConfirmBackdrop) {
            schedSaveConfirmBackdrop.addEventListener('click', function(e) {
                if (e.target === schedSaveConfirmBackdrop) closeSchedSaveConfirm();
            });
        }
        if (schedSaveConfirmOk) {
            schedSaveConfirmOk.addEventListener('click', async function() {
                if (!sfPendingPayload) return;
                const payload = sfPendingPayload;
                const saveBtn = document.getElementById('scheduleFormSave');
                schedSaveConfirmOk.disabled = true;
                if (saveBtn) saveBtn.disabled = true;
                try {
                    const res = await fetch('../api/schedule-crud.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data.success) {
                        closeSchedSaveConfirm();
                        showFormError(data.error || 'Save failed');
                        return;
                    }
                    queueScheduleSaveNotif('success', payload.sched_id > 0
                        ? 'Schedule updated successfully.'
                        : 'Schedule added successfully.');
                    window.location.reload();
                } catch (err) {
                    closeSchedSaveConfirm();
                    showFormError('Network error — please try again.');
                } finally {
                    schedSaveConfirmOk.disabled = false;
                    if (saveBtn) saveBtn.disabled = false;
                }
            });
        }

        if (sfForm) {
            sfForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                showFormError('');

                const startDateVal = document.getElementById('sfStartDate').value;
                const endDateVal   = document.getElementById('sfEndDate').value;
                if (!startDateVal) {
                    showFormError('Start date is required.');
                    return;
                }
                if (!endDateVal) {
                    showFormError('Estimated completion date is required — without it, the synced duration in the Facilities Reservation System will always show as 0.');
                    return;
                }
                if (endDateVal < startDateVal) {
                    showFormError('Estimated completion date cannot be before the start date.');
                    return;
                }

                const payload = {
                    sched_id: parseInt(document.getElementById('sfSchedId').value, 10) || 0,
                    cprf_facility_id: parseInt(sfFacility.value, 10),
                    task: document.getElementById('sfTask').value.trim(),
                    location: document.getElementById('sfLocation').value.trim(),
                    starting_date: startDateVal,
                    estimated_completion_date: endDateVal,
                    category: document.getElementById('sfCategory').value,
                    priority: document.getElementById('sfPriority').value,
                    status: document.getElementById('sfStatus').value,
                    budget: parseFloat(document.getElementById('sfBudget').value) || 0,
                    assigned_team: document.getElementById('sfAssignedTeam').value.trim()
                };

                openSchedSaveConfirm(payload);
            });
        }
    }

}); // --- END DOMContentLoaded ---


// ════════════════════════════════════════════════════════════════
// SCHED — Engineer Profile Button + Details Modal
// ════════════════════════════════════════════════════════════════

const SCHED_AVATAR_THEME = {
    upcoming:  { bg: '#e3f2fd', fill: '#1565c0' },
    ongoing:   { bg: '#fff8e1', fill: '#f57f17' },
    delayed:   { bg: '#ffebee', fill: '#c62828' },
    completed: { bg: '#e8f5e9', fill: '#2e7d32' },
};
function buildSchedFallbackSVG(statusKey) {
    const t = SCHED_AVATAR_THEME[statusKey] || SCHED_AVATAR_THEME.completed;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="${t.bg}"/><circle cx="50" cy="36" r="20" fill="${t.fill}"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="${t.fill}"/></svg>`;
}

function buildSchedAvatar(picPath, statusKey) {
    const svg = buildSchedFallbackSVG(statusKey || 'completed');
    if (picPath && picPath !== 'profile.png') {
        return `<img src="${picPath}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                <span style="display:none;width:100%;height:100%;">${svg}</span>`;
    }
    return svg;
}


// renderEngMetricsFull — used by sched.php engineer profile modal
function renderEngMetricsFull(m, containerId, ratingData) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!m) {
        el.innerHTML = '<div style="font-size:12px;color:var(--text-secondary);padding:8px 0;display:flex;align-items:center;gap:6px;"><span style="font-size:16px;">⚠️</span> Could not load metrics.</div>';
        return;
    }
    const retCurrent = m.admin_returned_current ?? m.admin_rejected ?? 0;
    const retPending = m.admin_returned_pending ?? 0;
    function card(color, icon, value, title, subIcon, subText, subClass) {
        return `<div class="emc-card emc-${color}"><div class="emc-header"><div class="emc-title">${title}</div><div class="emc-icon"><i class="${icon}"></i></div></div><div class="emc-value">${value}</div><div class="emc-sub ${subClass}"><span class="emc-sub-icon">${subIcon}</span><span>${subText}</span></div></div>`;
    }
    const completedSub = m.completed > 0 ? 'positive' : 'neutral';
    const delayedSub   = m.delayed   > 0 ? 'danger'   : 'neutral';
    const declinedSub  = m.declined_count > 0 ? 'warning' : 'neutral';
    const retCurSub    = retCurrent > 0 ? 'warning' : 'neutral';
    const retPenSub    = retPending > 0 ? 'warning' : 'neutral';

    // Rating data
    const avgRating   = ratingData ? (parseFloat(ratingData.avg_rating) || 0) : 0;
    const ratingCount = ratingData ? (ratingData.total || 0) : 0;
    const ratingSub   = avgRating >= 4 ? 'positive' : avgRating > 0 ? 'neutral' : 'neutral';
    const ratingSubText = ratingCount > 0 ? `${ratingCount} valid feedback(s)` : 'No valid feedbacks yet';

    // Build half-star HTML for rating card
    let ratingStarsHtml = '<div style="display:inline-flex;align-items:center;gap:1px;font-size:15px;line-height:1;margin:4px 0 2px;position:relative;z-index:1;">';
    for (let _i = 1; _i <= 5; _i++) {
        if (avgRating >= _i)
            ratingStarsHtml += '<span style="color:#f59e0b;">★</span>';
        else if (avgRating >= _i - 0.5)
            ratingStarsHtml += '<span style="position:relative;display:inline-block;"><span style="color:#d1d5db;">★</span><span style="position:absolute;top:0;left:0;width:50%;overflow:hidden;color:#f59e0b;white-space:nowrap;">★</span></span>';
        else
            ratingStarsHtml += '<span style="color:#d1d5db;">☆</span>';
    }
    ratingStarsHtml += '</div>';

    const ratingCard = '<div class="emc-card emc-amber">' +
        '<div class="emc-header"><div class="emc-title">Rating</div><div class="emc-icon"><i class="fas fa-star"></i></div></div>' +
        '<div class="emc-value">' + (avgRating > 0 ? avgRating.toFixed(1) + '<span style="font-size:14px;font-weight:500;letter-spacing:0"> / 5</span>' : '—') + '</div>' +
        ratingStarsHtml +
        '<div class="emc-sub ' + ratingSub + '"><span class="emc-sub-icon">★</span><span>' + ratingSubText + '</span></div>' +
        '</div>';

    el.innerHTML = `<div class="emc-grid-wrap">
        <div class="emc-section-label">Report Activity</div>
        ${card('green','fas fa-check-circle',m.completed,'Completed','↗','Finished reports',completedSub)}
        ${card('orange','fas fa-spinner',m.ongoing,'Ongoing','●','Currently in progress','neutral')}
        ${card('red','fas fa-clock',m.delayed,'Delayed','↘','Past due date',delayedSub)}
        ${card('indigo','fas fa-calendar-check',m.scheduled,'Scheduled','▸','Pending reports queue','neutral')}
        ${card('teal','fas fa-clipboard-list',m.current_assigned,'Curr. Assigned','▸','In current reports','neutral')}
        ${card('blue','far fa-calendar-alt',m.pending_assigned,'Pend. Assigned','▸','In pending reports','neutral')}
        <div class="emc-section-label">Behaviour</div>
        ${card('amber','fas fa-times-circle',m.declined_count,'Times Declined','↻','Engineer declined',declinedSub)}
        ${card('purple','fas fa-undo-alt',retCurrent,'Returned (Approval)','↩','Admin sent back to revise',retCurSub)}
        ${card('purple','fas fa-ban',retPending,'Returned (Not Done)','↩','Admin marked incomplete',retPenSub)}
        ${m.pending_completion > 0 ? card('teal','fas fa-hourglass-half',m.pending_completion,'Pend. Completion','⏳','Awaiting admin review','neutral') : ''}
        ${ratingCard}
    </div>`;
}
let _schedEngCache = null;

async function _schedLoadEngineers() {
    if (_schedEngCache !== null) return _schedEngCache;
    try {
        const res  = await fetch('../functionality/get_engineers.php');
        const data = await res.json();
        _schedEngCache = (data.success && data.engineers.length) ? data.engineers : [];
    } catch(e) { _schedEngCache = []; }
    return _schedEngCache;
}

async function schedOpenEngineerProfile(engineerId, statusKey) {
    if (!engineerId) return;
    statusKey = statusKey || 'upcoming';
    let eng = null;
    const engineers = await _schedLoadEngineers();
    eng = engineers.find(e => e.id == engineerId);
    if (!eng) {
        try {
            const res  = await fetch('../functionality/get_engineers.php?id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            if (data.success && data.engineers && data.engineers.length) {
                eng = data.engineers.find(e => e.id == engineerId) || data.engineers[0];
            }
        } catch(e) {}
    }
    if (!eng) return;
    _schedPopulateEngModal(eng, statusKey);
    document.getElementById('schedEngDetailsBackdrop').classList.add('show');
}

async function _schedPopulateEngModal(eng, statusKey) {
    statusKey = statusKey || 'upcoming';

    // Apply status-based theme to the modal
    const modal = document.getElementById('schedEngDetailsModal');
    if (modal) {
        ['eng-theme-upcoming','eng-theme-ongoing','eng-theme-delayed','eng-theme-completed']
            .forEach(c => modal.classList.remove(c));
        modal.classList.add('eng-theme-' + statusKey);
    }

    // Status-aware colours for fallback SVG and discipline label
    const tc = SCHED_AVATAR_THEME[statusKey] || SCHED_AVATAR_THEME.completed;

    const wrap = document.getElementById('schedEngDetAvatarWrap');
    if (wrap) {
        const img = document.createElement('img');
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        img.alt = '';
        const fallback = 'data:image/svg+xml,' + encodeURIComponent(buildSchedFallbackSVG(statusKey));
        img.onerror = function() { this.src = fallback; };
        img.src = eng.profile_picture || fallback;
        wrap.innerHTML = '';
        wrap.appendChild(img);
    }
    const nameEl = document.getElementById('schedEngDetName');
    const discEl = document.getElementById('schedEngDetDiscipline');
    if (nameEl) nameEl.textContent = eng.name || '—';
    if (discEl) {
        discEl.textContent = eng.engineering_discipline || 'Engineer';
        discEl.style.color = tc.fill;
    }

    const fv = (v) => v ? _schedEscH(String(v)) : '<span style="opacity:.5;">—</span>';
    let html = '';

    html += `<div class="eng-det-section-title">👤 Personal Information</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label">Full Name</div>
                 <div class="eng-det-field-value">${fv(eng.full_name || eng.name)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Gender</div>
                 <div class="eng-det-field-value">${fv(eng.gender)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Date of Birth</div>
                 <div class="eng-det-field-value">${fv(eng.date_of_birth)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Contact Number</div>
                 <div class="eng-det-field-value">${fv(eng.contact_number)}</div>
               </div>
               <div style="grid-column:1/-1">
                 <div class="eng-det-field-label">Email Address</div>
                 <div class="eng-det-field-value">${fv(eng.email)}</div>
               </div>
             </div>
             <div class="eng-det-field-single">
               <div class="eng-det-field-label">Address</div>
               <div class="eng-det-field-value">${fv(eng.address)}</div>
             </div>`;

    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🏗️ Professional Details</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label">Engineering Discipline</div>
                 <div class="eng-det-field-value">${fv(eng.engineering_discipline)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Department</div>
                 <div class="eng-det-field-value">${fv(eng.department)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Years of Experience</div>
                 <div class="eng-det-field-value">${eng.years_of_experience != null && eng.years_of_experience !== '' ? _schedEscH(String(eng.years_of_experience)) + ' yr(s)' : '<span style="opacity:.5;">—</span>'}</div>
               </div>
             </div>`;

    if (eng.areas_of_specialization) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label">Areas of Specialization</div>
                   <div class="eng-det-field-value">${fv(eng.areas_of_specialization)}</div>
                 </div>`;
    }

    const skills = [];
    if (eng.skill_structural_design) skills.push('Structural Design');
    if (eng.skill_site_inspection)   skills.push('Site Inspection');
    if (eng.skill_project_planning)  skills.push('Project Planning');
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🛠️ Skills & Tools</div>`;
    if (skills.length) {
        const _tcHex = tc.fill;
        html += '<div class="eng-det-skills">' + skills.map(s => `<span class="eng-det-skill-badge" style="background:${_tcHex}1a;color:${_tcHex};border-color:${_tcHex}4d;">${s}</span>`).join('') + '</div>';
    } else {
        html += '<div class="eng-det-field-value" style="opacity:.5;">No skills listed</div>';
    }
    if (eng.cad_software) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label">CAD Software</div>
                   <div class="eng-det-field-value">${fv(eng.cad_software)}</div>
                 </div>`;
    }

    // Metrics section
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">📊 Performance Metrics</div>
             <div id="schedEngDetMetrics"><div class="eng-metrics-loading"><span style="font-size:16px;">⏳</span> Loading metrics…</div></div>`;

    document.getElementById('schedEngDetBody').innerHTML = html;

    // Async load metrics + rating in parallel
    if (eng.id) {
        const [m, ratingData] = await Promise.all([
            _schedFetchMetrics(eng.id),
            fetchEngineerRating(eng.id)
        ]);
        if (typeof renderEngMetricsFull === 'function') {
            renderEngMetricsFull(m, 'schedEngDetMetrics', ratingData);
        } else {
            // Fallback inline renderer if the function isn't available
            const el = document.getElementById('schedEngDetMetrics');
            if (el && m) {
                el.innerHTML = `<div style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    ✅ Completed: <b>${m.completed}</b> &nbsp;
                    🔄 Ongoing: <b>${m.ongoing}</b> &nbsp;
                    📅 Scheduled: <b>${m.scheduled}</b> &nbsp;
                    ⏰ Delayed: <b>${m.delayed}</b><br>
                    📋 Current Assigned: <b>${m.current_assigned}</b> &nbsp;
                    🗓️ Pending Assigned: <b>${m.pending_assigned}</b><br>
                    🚫 Declined: <b>${m.declined_count}</b> &nbsp;
                    ↩️ Approval Returns: <b>${m.admin_returned_current ?? m.admin_rejected ?? 0}</b> &nbsp;
                    ↩️ Not-Done Returns: <b>${m.admin_returned_pending ?? 0}</b>
                </div>`;
            }
        }
    }
}

async function _schedFetchMetrics(engineerId) {
    try {
        const res  = await fetch('../functionality/get_engineer_metrics.php?id=' + encodeURIComponent(engineerId));
        const data = await res.json();
        return data.success ? data.metrics : null;
    } catch(e) { return null; }
}

async function fetchEngineerRating(engineerId) {
    try {
        const res  = await fetch('archive_reports.php?ajax=engineer_rating&id=' + encodeURIComponent(engineerId));
        const data = await res.json();
        return data.success ? data : null;
    } catch(e) { return null; }
}

function _schedEscH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Wire close buttons
document.addEventListener('DOMContentLoaded', function() {
    const backdrop = document.getElementById('schedEngDetailsBackdrop');
    const closeX   = document.getElementById('schedEngDetClose');
    const closeBtn = document.getElementById('schedEngDetCloseBtn');
    function closeSchedEngModal() {
        if (backdrop) backdrop.classList.remove('show');
    }
    if (closeX)   closeX.addEventListener('click',   closeSchedEngModal);
    if (closeBtn) closeBtn.addEventListener('click',  closeSchedEngModal);
    if (backdrop) backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) closeSchedEngModal();
    });
});

// ═══════════════════════════════════════════════════════
//  SCHED EVIDENCE LIGHTBOX — exact zoom port from requests.php
// ═══════════════════════════════════════════════════════
(function() {
    const BASE_ZOOM = 2, MAX_WHEEL_ZOOM = 5, WHEEL_ZOOM_SPEED = 0.002;
    let isZoomed = false, isDragging = false;
    let startX = 0, startY = 0, translateX = 0, translateY = 0, currentScale = 1;
    let _schedLbImages = [], _schedLbIndex = 0;

    const lb        = () => document.getElementById('schedEvidenceLightbox');
    const lbImg     = () => document.getElementById('schedLightboxImg');
    const lbClose   = () => document.getElementById('schedLbCloseBtn');
    const lbCounter = () => document.getElementById('schedLbCounter');
    const lbPrev    = () => document.getElementById('schedLbPrev');
    const lbNext    = () => document.getElementById('schedLbNext');

    function resetZoom() {
        isZoomed = isDragging = false;
        translateX = translateY = 0; currentScale = 1;
        const img = lbImg(); if (!img) return;
        img.classList.remove('sched-lb-zoomed');
        img.classList.remove('sched-lb-panning');
        img.style.transform = 'scale(1)';
        img.style.cursor = 'zoom-in';
        const btn = lbClose(); if (btn) { btn.style.display = 'flex'; btn.disabled = false; }
    }

    function updateImage() {
        const img = lbImg(); if (!img || !_schedLbImages.length) return;
        img.src = _schedLbImages[_schedLbIndex];
        const single = _schedLbImages.length <= 1;
        const p = lbPrev(), n = lbNext();
        if (p) p.classList.toggle('hidden', single);
        if (n) n.classList.toggle('hidden', single);
        const c = lbCounter();
        if (c) c.textContent = single ? '' : `${_schedLbIndex + 1} / ${_schedLbImages.length}`;
        resetZoom();
    }

    window.schedLbOpen = function(images, index) {
        _schedLbImages = images; _schedLbIndex = index || 0;
        const el = lb(); if (el) el.classList.add('active');
        updateImage();
    };
    window.schedLbClose = function() {
        resetZoom();
        const el = lb(); if (el) el.classList.remove('active');
    };
    window.schedLbPrev = function() {
        if (_schedLbImages.length < 2) return;
        _schedLbIndex = (_schedLbIndex - 1 + _schedLbImages.length) % _schedLbImages.length;
        updateImage();
    };
    window.schedLbNext = function() {
        if (_schedLbImages.length < 2) return;
        _schedLbIndex = (_schedLbIndex + 1) % _schedLbImages.length;
        updateImage();
    };

    document.addEventListener('DOMContentLoaded', function() {
        const img = lbImg(); if (!img) return;

        // Prevent browser native image-drag from hijacking custom pan
        img.draggable = false;
        img.addEventListener('dragstart', function(e) { e.preventDefault(); });

        // Backdrop click
        const el = lb();
        if (el) el.addEventListener('click', function(e) { if (e.target === el) window.schedLbClose(); });

        // Keyboard
        document.addEventListener('keydown', function(e) {
            if (!el || !el.classList.contains('active')) return;
            if (e.key === 'ArrowLeft')  { window.schedLbPrev(); e.preventDefault(); }
            if (e.key === 'ArrowRight') { window.schedLbNext(); e.preventDefault(); }
            if (e.key === 'Escape')     window.schedLbClose();
        });

        // Double-click zoom (exact from requests.php)
        img.addEventListener('dblclick', function(e) {
            const rect = img.getBoundingClientRect();
            const px = (e.clientX - rect.left) / rect.width;
            const py = (e.clientY - rect.top)  / rect.height;
            if (!isZoomed) {
                isZoomed = true; currentScale = BASE_ZOOM;
                translateX = (0.5 - px) * rect.width  * (BASE_ZOOM - 1);
                translateY = (0.5 - py) * rect.height * (BASE_ZOOM - 1);
                img.classList.add('sched-lb-zoomed');
                img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
                img.style.cursor = 'grab';
                const btn = lbClose(); if (btn) { btn.style.display = 'none'; btn.disabled = true; }
            } else {
                resetZoom();
            }
        });

        // Mouse drag — disable transition during pan so movement is instant, not laggy
        img.addEventListener('mousedown', function(e) {
            if (!isZoomed || e.button !== 0) return;
            e.preventDefault(); // block any residual native drag
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            img.classList.add('sched-lb-panning');
            img.style.cursor = 'grabbing';
        });
        window.addEventListener('mouseup', function() {
            if (!isZoomed) return;
            isDragging = false;
            img.classList.remove('sched-lb-panning');
            img.style.cursor = 'grab';
        });
        window.addEventListener('mousemove', function(e) {
            if (!isZoomed || !isDragging) return;
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
        });

        // Wheel zoom (exact from requests.php)
        img.addEventListener('wheel', function(e) {
            if (!isZoomed) return;
            e.preventDefault();
            const rect = img.getBoundingClientRect();
            const px = (e.clientX - rect.left) / rect.width;
            const py = (e.clientY - rect.top)  / rect.height;
            const ns = Math.min(Math.max(currentScale + (-e.deltaY * WHEEL_ZOOM_SPEED), BASE_ZOOM), MAX_WHEEL_ZOOM);
            const sd = ns / currentScale;
            translateX = translateX * sd + (0.5 - px) * rect.width  * (sd - 1);
            translateY = translateY * sd + (0.5 - py) * rect.height * (sd - 1);
            currentScale = ns;
            img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
        }, { passive: false });

        // Mobile pinch & swipe (exact from requests.php)
        let initDist = null, touchSX = 0, touchEX = 0;
        img.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2)
                initDist = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
            else if (e.touches.length === 1)
                touchSX = e.changedTouches[0].screenX;
        }, { passive: true });
        img.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2 && initDist) {
                e.preventDefault();
                const d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
                currentScale = Math.min(Math.max(d / initDist, 0.5), 3);
                img.style.transform = `scale(${currentScale})`;
            }
        });
        img.addEventListener('touchend', function(e) {
            if (currentScale < 1) currentScale = 1;
            img.style.transform = `scale(${currentScale})`;
            initDist = null;
            if (e.changedTouches.length === 1) {
                touchEX = e.changedTouches[0].screenX;
                const dx = touchEX - touchSX;
                if (Math.abs(dx) >= 50 && _schedLbImages.length > 1) {
                    dx > 0 ? window.schedLbPrev() : window.schedLbNext();
                }
            }
        }, { passive: true });
    });
})();


// Mobile sidebar fix
document.addEventListener('DOMContentLoaded', function() {
    var mobileToggle = document.getElementById('mobileToggle');
    var sidebarNav   = document.getElementById('sidebarNav');
    if (mobileToggle && sidebarNav) {
        mobileToggle.onclick = function() {
            sidebarNav.classList.toggle('mobile-active');
        };
    }
    // Close sidebar when clicking outside
    document.addEventListener('click', function(e) {
        if (
            sidebarNav &&
            sidebarNav.classList.contains('mobile-active') &&
            !sidebarNav.contains(e.target) &&
            e.target !== mobileToggle &&
            !mobileToggle.contains(e.target)
        ) {
            sidebarNav.classList.remove('mobile-active');
        }
    });
});