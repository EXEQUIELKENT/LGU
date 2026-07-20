<script>
// Sidebar & Navigation Scripts
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const mainContent = document.querySelector('.main-content');
const sidebarNav = document.getElementById('sidebarNav');

function isMobileView() {
    return window.innerWidth <= 900;
}

const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
    document.body.classList.add('sidebar-collapsed');
}
// Remove preload class so transitions are restored after initial state is applied
requestAnimationFrame(function() {
    document.documentElement.classList.remove('sidebar-preload-collapsed');
});

let lastMobileState = isMobileView();
window.addEventListener('resize', () => {
    const isNowMobile = isMobileView();
    if (isNowMobile && !lastMobileState && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    }
    lastMobileState = isNowMobile;
});

// Merged sidebarToggle click handler
sidebarToggle.addEventListener('click', () => {
    const isCollapsed = sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded', isCollapsed);
    document.body.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebarCollapsed', isCollapsed);

    sidebar.style.overflowX = "hidden";

    if (!isCollapsed) {
        sidebarNavTooltip.classList.remove('active');
        sidebarNavTooltip.style.display = 'none';
    }

    // Dropdowns stay open in collapsed mode (CSS renders icon-only sub-items)

    // Clear tooltip state
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

// =============================================
// REPORTS DROPDOWN — Click-only toggle
// Works in both expanded AND collapsed states
// =============================================
document.querySelectorAll('.nav-dropdown-toggle').forEach(function(toggle) {
    toggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const parentItem = this.closest('.nav-dropdown-item');
        const isOpen = parentItem.classList.contains('open');

        // Close all other open dropdowns first
        document.querySelectorAll('.nav-dropdown-item.open').forEach(function(item) {
            if (item !== parentItem) item.classList.remove('open');
        });

        // Toggle this one
        parentItem.classList.toggle('open', !isOpen);
    });
});

// Close dropdown when clicking outside the sidebar
// (exclude mobileToggle — it opens the sidebar and should preserve open dropdowns)
document.addEventListener('click', function(e) {
    const mobileToggleBtn = document.getElementById('mobileToggle');
    if (!sidebar.contains(e.target) && !(mobileToggleBtn && mobileToggleBtn.contains(e.target))) {
        document.querySelectorAll('.nav-dropdown-item.open').forEach(function(item) {
            item.classList.remove('open');
        });
    }
});

// Persist dropdown open state across page loads
(function() {
    const openKey = 'sidebarDropdownOpen';
    const savedOpen = localStorage.getItem(openKey);

    if (savedOpen) {
        const item = document.querySelector('.nav-dropdown-item[data-dropdown="' + savedOpen + '"]');
        if (item) {
            item.classList.add('open');
        }
    }

    document.querySelectorAll('.nav-dropdown-item').forEach(function(item) {
        const toggle = item.querySelector('.nav-dropdown-toggle');
        if (!toggle) return;

        // Auto-open if a sub-link is the active page
        const hasActive = item.querySelector('.nav-sub-link.active');
        if (hasActive) {
            item.classList.add('open');
        }

        // Also open if current page URL matches any sub-link href
        item.querySelectorAll('.nav-sub-link').forEach(function(link) {
            const href = link.getAttribute('href');
            if (href && window.location.href.includes(href.replace('.php', ''))) {
                link.classList.add('active');
                item.classList.add('open');
            }
        });
    });
})();
// =============================================

const sidebarNavTooltip = document.getElementById('sidebarNavTooltip');
let tooltipActiveLink = null;
let tooltipHideTimeout = null;

// ── Helper: show tooltip for any element ──────────────────────────
function showTooltipFor(el, text) {
    tooltipActiveLink = el;
    sidebarNavTooltip.textContent = text;
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'block';

    const rect       = el.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    sidebarNavTooltip.style.left = (sidebarRect.right + 15) + 'px';
    sidebarNavTooltip.style.top  = (rect.top + rect.height / 2 + window.scrollY) + 'px';

    setTimeout(function() { sidebarNavTooltip.classList.add('active'); }, 5);

    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
}

// ── Main nav-link tooltips ────────────────────────────────────────
document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    link.addEventListener('mouseenter', navTooltipHandler);
    link.addEventListener('focus',      navTooltipHandler);
    link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    link.addEventListener('blur',       hideNavTooltip);
});

// ── Sub-link tooltips (collapsed sidebar only) ───────────────────
document.querySelectorAll('.sidebar-nav .nav-sub-link').forEach(function(link) {
    link.addEventListener('mouseenter', function() {
        if (!sidebar.classList.contains('collapsed')) return;

        // Build tooltip text from the link's span or data-tooltip attribute
        let text = link.getAttribute('data-tooltip');
        if (!text) {
            const span = link.querySelector('span');
            if (span) text = span.textContent.trim();
        }
        if (!text) return;

        showTooltipFor(link, text);
    });

    link.addEventListener('mouseleave', function(e) {
        if (
            e.relatedTarget === sidebarNavTooltip ||
            (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
        ) return;

        tooltipHideTimeout = setTimeout(() => {
            hideNavTooltip();
            tooltipActiveLink = null;
        }, 60);
    });

    link.addEventListener('blur', hideNavTooltip);
});

const profileIconBtn = document.getElementById('profileIconBtn');
if (profileIconBtn) {
    profileIconBtn.addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'profile.php';
    });

    const engWarningPop = document.getElementById('engProfileWarningPop');

    function positionEngWarning() {
        if (!engWarningPop) return;
        const rect = profileIconBtn.getBoundingClientRect();
        engWarningPop.style.left = (rect.left) + 'px';
        engWarningPop.style.top  = (rect.bottom + 10 + window.scrollY) + 'px';
    }

    if (window.empEngineerIncomplete) {
        // Always visible — position on load and on sidebar resize/toggle
        positionEngWarning();
        if (engWarningPop) engWarningPop.classList.add('persistent');

        // Reposition on sidebar collapse/expand
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                setTimeout(positionEngWarning, 320); // after transition
            });
        }
        window.addEventListener('resize', positionEngWarning);

    } else {
        // Normal tooltip behaviour (collapsed sidebar only)
        profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
        profileIconBtn.addEventListener('focus',      navTooltipHandler);
        profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
        profileIconBtn.addEventListener('blur',       hideNavTooltip);
    }
}

const logoutBtn = document.getElementById('logoutBtn');
logoutBtn.addEventListener('mouseenter', function(e) {
    if (!sidebar.classList.contains('collapsed')) { hideNavTooltipImmediate(); return; }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('focus', function(e) {
    if (!sidebar.classList.contains('collapsed')) { hideNavTooltipImmediate(); return; }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('mouseleave', function(e) {
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) return;
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
});
logoutBtn.addEventListener('blur', hideNavTooltip);

function showLogoutTooltip(e) {
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
    sidebarNavTooltip.style.top  = y + 'px';
    setTimeout(function() { sidebarNavTooltip.classList.add('active'); }, 5);
    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
}

function hideNavTooltipImmediate() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
}

function hideNavTooltip() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    setTimeout(function() {
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
    }, 150);
    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
}

function navTooltipHandler(e) {
    if (!sidebar.classList.contains('collapsed')) { hideNavTooltip(); return; }
    let tooltipText = this.getAttribute('data-tooltip');
    if (!tooltipText && this.id === "profileIconBtn") tooltipText = "Profile";
    if (!tooltipText) return;
    showTooltipFor(this, tooltipText);
}

function navLinkMouseLeaveHandler(e) {
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) return;
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
}

sidebarNavTooltip.addEventListener('mouseleave', function() {
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
});

sidebarNavTooltip.addEventListener('mouseenter', function() {
    if (tooltipHideTimeout) { clearTimeout(tooltipHideTimeout); tooltipHideTimeout = null; }
});

document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
    link.addEventListener('keydown', function(e) {
        if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
            e.preventDefault();
            this.focus();
        }
    });
});

logoutBtn.addEventListener('keydown', function(e) {
    if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
        e.preventDefault();
        this.focus();
    }
});

const logoutAlertBackdrop = document.getElementById('logoutAlertBackdrop');
const logoutCancelBtn     = document.getElementById('logoutCancelBtn');
const logoutConfirmBtn    = document.getElementById('logoutConfirmBtn');

logoutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.add("active");
    hideNavTooltipImmediate();
});

logoutCancelBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.remove("active");
});

logoutConfirmBtn.addEventListener('click', (e) => {
    e.preventDefault();
    window.location.href = '../functionality/logout.php';
});

logoutAlertBackdrop.addEventListener('mousedown', (e) => {
    if (e.target === logoutAlertBackdrop) logoutAlertBackdrop.classList.remove("active");
});

const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-active');
    });
}

window.addEventListener("pageshow", function (event) {
    if (event.persisted) { window.location.reload(); }
});
</script>

<script>
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');
    if (!img) return;
    
    const checkImage = () => {
        if (!img.src || img.src.endsWith('profile.png') || img.src.includes('profile.png')) {
            img.style.display = 'none';
            if (fallback) fallback.style.display = 'flex';
        } else {
            const testImg = new Image();
            testImg.onload  = () => { img.style.display = 'block'; if (fallback) fallback.style.display = 'none'; };
            testImg.onerror = () => { img.style.display = 'none';  if (fallback) fallback.style.display = 'flex'; };
            testImg.src = img.src;
        }
    };
    
    img.onerror = () => { img.style.display = 'none'; if (fallback) fallback.style.display = 'flex'; };
    img.onload  = () => {
        if (img.src && !img.src.endsWith('profile.png') && !img.src.includes('profile.png')) {
            img.style.display = 'block'; if (fallback) fallback.style.display = 'none';
        } else {
            img.style.display = 'none'; if (fallback) fallback.style.display = 'flex';
        }
    };
    checkImage();
}

document.addEventListener('DOMContentLoaded', handleProfilePicture);
setTimeout(handleProfilePicture, 100);
</script>

<script>
// AFTER
const _isLocalhost = ['localhost', '127.0.0.1', ''].includes(window.location.hostname);
const inactivityTime = 2 * 60 * 1000; // 2 minutes (only enforced on live domain)
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    if (!_isLocalhost) {
        inactivityTimer = setTimeout(() => { window.location.href = '../functionality/logout.php'; }, inactivityTime);
    }
}

['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, true);
});
resetInactivityTimer();
</script>

<script>
// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

function getTimezoneLabel() {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = -new Date().getTimezoneOffset() / 60;
    const sign = offset >= 0 ? '+' : '-';
    return `${tz} (GMT${sign}${Math.abs(offset)})`;
}

function renderClock(now) {
    const datePart = now.toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
    const timeStr = now.toLocaleTimeString('en-US', {
        hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true
    });
    const t = timeStr.match(/^(\d+):(\d+):(\d+)\s?(AM|PM)$/i);
    let h = t ? t[1] : "--", m = t ? t[2] : "--", s = t ? t[3] : "--", ampm = t ? t[4] : "";

    const desktopClock = document.getElementById('desktopClock');
    const mobileClock  = document.getElementById('mobileClock');

    function flipSpan(str) {
        return str.split('').map(chr => `<span>${chr}</span>`).join('');
    }

    if (desktopClock) {
        desktopClock.innerHTML = `
            <span class="date-part">${datePart}</span>
            &nbsp;&nbsp;&nbsp;
            <span class="time-part">${flipSpan(h)}:${flipSpan(m)}:${flipSpan(s)} ${ampm}</span>
            <span class="clock-timezone">${getTimezoneLabel()}</span>
        `;
    }
    if (mobileClock) mobileClock.textContent = `${h}:${m}:${s} ${ampm}`;
}

function tick() {
    const now = new Date(currentServerTime);
    const sec = now.getSeconds();
    if (sec !== lastSecond) {
        document.querySelectorAll('.time-part').forEach(el => {
            el.classList.add('flip');
            setTimeout(() => el.classList.remove('flip'), 250);
        });
        lastSecond = sec;
    }
    renderClock(now);
    currentServerTime += 1000;
}

function startClock() {
    if (clockInterval) return;
    tick();
    clockInterval = setInterval(tick, 1000);
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearInterval(clockInterval); clockInterval = null; }
    else { startClock(); }
});

setInterval(() => {
    fetch(location.href, { method: 'HEAD' }).then(() => { currentServerTime = SERVER_TIME; });
}, RESYNC_MINUTES * 60 * 1000);

startClock();
</script>

<script>
// Dark Mode Toggle
(function() {
    const darkModeBtn       = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const darkIcon       = darkModeBtn?.querySelector('.dark-icon')  || mobileDarkModeBtn?.querySelector('.dark-icon');
    const lightIcon      = darkModeBtn?.querySelector('.light-icon') || mobileDarkModeBtn?.querySelector('.light-icon');
    const mobileDarkIcon  = mobileDarkModeBtn?.querySelector('.dark-icon');
    const mobileLightIcon = mobileDarkModeBtn?.querySelector('.light-icon');
    const html = document.documentElement;

    const THEME_KEY        = 'theme';
    const THEME_BACKUP_KEY = 'theme_backup';

    function updateTheme(isDark, animate = false) {
        try {
            if (isDark) { html.setAttribute('data-theme', 'dark'); }
            else        { html.removeAttribute('data-theme'); }

            localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
            localStorage.setItem(THEME_BACKUP_KEY, isDark ? 'dark' : 'light');

            if (darkIcon)       darkIcon.style.display  = isDark ? 'none'   : 'inline';
            if (lightIcon)      lightIcon.style.display = isDark ? 'inline' : 'none';
            if (mobileDarkIcon)  mobileDarkIcon.style.display  = isDark ? 'none'   : 'inline';
            if (mobileLightIcon) mobileLightIcon.style.display = isDark ? 'inline' : 'none';

            if (animate) {
                if (darkModeBtn)       darkModeBtn.classList.add('active');
                if (mobileDarkModeBtn) mobileDarkModeBtn.classList.add('active');
                setTimeout(() => {
                    if (darkModeBtn)       darkModeBtn.classList.remove('active');
                    if (mobileDarkModeBtn) mobileDarkModeBtn.classList.remove('active');
                }, 500);
            }
        } catch (e) { console.error('Theme update error:', e); }
    }

    try {
        let savedTheme = localStorage.getItem(THEME_KEY);
        if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = localStorage.getItem(THEME_BACKUP_KEY);
        if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'light';
        updateTheme(savedTheme === 'dark', false);
    } catch (e) { console.error('Theme load error:', e); updateTheme(false, false); }

    function toggleTheme() {
        updateTheme(html.getAttribute('data-theme') !== 'dark', true);
    }

    if (darkModeBtn)       darkModeBtn.addEventListener('click', toggleTheme);
    if (mobileDarkModeBtn) mobileDarkModeBtn.addEventListener('click', toggleTheme);

    window.addEventListener('beforeunload', function() {
        try {
            const t = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            localStorage.setItem(THEME_KEY, t);
            localStorage.setItem(THEME_BACKUP_KEY, t);
        } catch (e) { console.error('Theme save error:', e); }
    });
})();

// ===== NOTIFICATION SYSTEM =====
(function() {
    const notifBtn         = document.getElementById('notifBtn');
    const mobileNotifBtn   = document.getElementById('mobileNotifBtn');
    const notifDropdown    = document.getElementById('notifDropdown');
    const notifBody        = document.getElementById('notifBody');
    const notifBadge       = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileNotifBadge');
    const clearNotifBtn    = document.getElementById('clearNotifBtn');
    if ((!notifBtn && !mobileNotifBtn) || !notifDropdown) return;

    const NOTIF_API      = '../api/notifications.php';
    let notifications    = [];
    let unreadCount      = 0;
    const NOTIF_SEEN_KEY = 'notif_seen_ids';
    let seenNotifIds = new Set(JSON.parse(localStorage.getItem(NOTIF_SEEN_KEY) || '[]'));
    let isFirstLoad = true;

    /* ── Badge + bell ──────────────────────────────────────────── */
    function updateBadge(count) {
        if (notifBadge) {
            if (count > 0) {
                notifBadge.textContent = count > 99 ? '99+' : count;
                notifBadge.classList.remove('hidden');
                notifBadge.classList.add('show');
            } else {
                notifBadge.textContent = '';
                notifBadge.classList.add('hidden');
                notifBadge.classList.remove('show');
            }
        }
        if (mobileNotifBadge) {
            if (count > 0) {
                mobileNotifBadge.textContent = count > 99 ? '99+' : count;
                mobileNotifBadge.classList.add('show');
                mobileNotifBtn?.classList.add('has-notif');
            } else {
                mobileNotifBadge.textContent = '';
                mobileNotifBadge.classList.remove('show');
                mobileNotifBtn?.classList.remove('has-notif');
            }
        }
        if (notifBtn) { count > 0 ? notifBtn.classList.add('has-notif') : notifBtn.classList.remove('has-notif'); }
        // Inline unread pill inside dropdown header
        const cntEl = document.getElementById('notifUnreadCount');
        if (cntEl) { if (count > 0) { cntEl.textContent = count; cntEl.style.display = ''; } else { cntEl.style.display = 'none'; } }
    }

    /* ── Icon/colour map by title keywords ────────────────────── */
    function getNotifMeta(title) {
        const t = (title || '').toLowerCase();
        if (t.includes('return') || t.includes('revision') || t.includes('not complete')) return { icon: '↩️', cls: 'type-return'   };
        if (t.includes('complete') || t.includes('completed'))                             return { icon: '✅', cls: 'type-complete' };
        if (t.includes('approved') || t.includes('scheduled'))                             return { icon: '🎉', cls: 'type-approved' };
        if (t.includes('review')   || t.includes('submitted') || t.includes('pending'))   return { icon: '⏳', cls: 'type-review'   };
        if (t.includes('new')      || t.includes('citizen'))                               return { icon: '📋', cls: 'type-new'      };
        return { icon: '🔔', cls: '' };
    }

    function getPageLabel(url) {
        if (!url) return null;
        const u = url.toLowerCase().split('?')[0];
        if (u.includes('pending_reports'))  return { label: 'Pending Reports',   cls: 'notif-page-pending'  };
        if (u.includes('current_reports'))  return { label: 'Current Reports',   cls: 'notif-page-current'  };
        if (u.includes('archive_reports'))  return { label: 'Archive Reports',   cls: 'notif-page-archive'  };
        if (u.includes('emp_feedback'))     return { label: 'Citizen Feedback',  cls: 'notif-page-feedback' };
        if (u.includes('requests'))         return { label: 'Requests',          cls: 'notif-page-requests' };
        if (u.includes('employee'))         return { label: 'Dashboard',         cls: 'notif-page-dashboard'};
        return null;
    }

    function escH(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    const NOTIF_DESC_LIMIT = 80; // kept for reference but no longer truncates

    function buildDescHtml(desc, notifId) {
        if (!desc) return '';
        return `<div class="notif-item-desc">${escH(String(desc))}</div>`;
    }

    /* ── Render notifications grouped by date ──────────────────── */
    function updateNotificationUI() {
        if (!notifBody) return;
        if (!notifications.length) {
            notifBody.innerHTML = '<div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>';
            return;
        }

        // Group by date string
        const groups = {}, order = [];
        notifications.forEach(n => {
            const d = n.date || 'Today';
            if (!groups[d]) { groups[d] = []; order.push(d); }
            groups[d].push(n);
        });

        notifBody.innerHTML = order.map(date => `
            <div class="notif-date-group"><div class="notif-date-label">${escH(date)}</div></div>
            ${groups[date].map(n => {
                const m = getNotifMeta(n.title);
                return `<div class="notif-item${n.read ? '' : ' unread'}" data-id="${escH(String(n.id))}" data-url="${escH(n.url || '')}">
                    <div class="notif-icon-wrap ${m.cls}">${m.icon}</div>
                    <div class="notif-content">
                        <div class="notif-item-title">${escH(n.title)}</div>
                        ${buildDescHtml(n.description, n.id)}
                        <div class="notif-item-meta">
                            <span class="notif-time-label">${escH(n.time)}</span>
                            ${(function(){ const p = getPageLabel(n.url); return p ? `<span class="notif-page-pill ${p.cls}"><i class="fas fa-external-link-alt" style="font-size:8px;margin-right:3px;"></i>${p.label}</span>` : ''; })()}
                        </div>
                    </div>
                    ${n.read ? '' : '<div class="notif-unread-dot"></div>'}
                </div>`;
            }).join('')}
        `).join('');

        // Click: mark read then navigate
        notifBody.querySelectorAll('.notif-item').forEach(item => {
            item.addEventListener('click', async () => {
                const id  = parseInt(item.dataset.id);
                const url = item.dataset.url;
                // Instant visual feedback
                item.classList.remove('unread');
                const dot = item.querySelector('.notif-unread-dot');
                if (dot) dot.remove();
                // Persist mark-read
                try {
                    await fetch(NOTIF_API, {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'mark_read', id })
                    });
                } catch(e) {}
                if (url) window.location.href = url;
            });
        });
    }

    /* ── Sound ─────────────────────────────────────────────────── */
    let notifAudioCtx = null, notifAudioReady = false;

    function initNotifAudioContext() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!notifAudioCtx) notifAudioCtx = new AudioCtx();
            if (notifAudioCtx.state === 'suspended') notifAudioCtx.resume();
            notifAudioReady = true;
        } catch (e) { notifAudioReady = false; }
    }

    document.addEventListener('click', function onFirstClick() {
        if (!notifAudioReady) initNotifAudioContext();
        document.removeEventListener('click', onFirstClick);
    }, { once: true });

    function playNotifSound() {
        if (!notifAudioReady) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx || !notifAudioCtx || notifAudioCtx.state === 'suspended') return;
            const o = notifAudioCtx.createOscillator();
            const g = notifAudioCtx.createGain();
            o.type = 'triangle'; o.frequency.value = 880; g.gain.value = 0.18;
            o.connect(g).connect(notifAudioCtx.destination);
            o.start(); o.stop(notifAudioCtx.currentTime + 0.17);
        } catch (e) {}
    }

    /* ── Fetch ──────────────────────────────────────────────────── */
    async function fetchNotifications() {
        try {
            const res = await fetch(NOTIF_API);
            // 403 = not logged in yet — silent, not a real error
            if (res.status === 403) return;
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            notifications = data.notifications || [];
            unreadCount   = notifications.filter(n => !n.read).length;

            if (!isFirstLoad) {
                const newUnread = notifications.filter(n => !n.read && !seenNotifIds.has(n.id));
                if (newUnread.length > 0) {
                    playNotifSound();
                    [notifBtn, mobileNotifBtn].forEach(btn => {
                        if (!btn) return;
                        btn.classList.remove('ringing');
                        void btn.offsetWidth;
                        btn.classList.add('ringing');
                        btn.addEventListener('animationend', () => btn.classList.remove('ringing'), { once: true });
                    });
                    newUnread.forEach(n => seenNotifIds.add(n.id));
                    localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));
                }
            }

            notifications.forEach(n => seenNotifIds.add(n.id));
            localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));
            isFirstLoad = false;
            updateBadge(unreadCount);
            updateNotificationUI();
        } catch (err) { /* silent — notification errors shouldn't disrupt the page */ }
    }

    /* ── Mark all read (Clear button) ──────────────────────────── */
    if (clearNotifBtn) {
        clearNotifBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await fetch(NOTIF_API, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all' })
                });
                seenNotifIds.clear();
                localStorage.removeItem(NOTIF_SEEN_KEY);
                await fetchNotifications();
            } catch (err) { console.error('Error clearing notifications:', err); }
        });
    }

    /* ── Toggle dropdown (refresh on open) ─────────────────────── */
    function toggleDropdown(e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('show');
        if (notifDropdown.classList.contains('show')) fetchNotifications();
    }
    if (notifBtn)       notifBtn.addEventListener('click', toggleDropdown);
    if (mobileNotifBtn) mobileNotifBtn.addEventListener('click', toggleDropdown);

    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) &&
            !(notifBtn && notifBtn.contains(e.target)) &&
            !(mobileNotifBtn && mobileNotifBtn.contains(e.target))) {
            notifDropdown.classList.remove('show');
        }
    });

    setTimeout(() => {
        fetchNotifications();
        setInterval(() => { if (!document.hidden) fetchNotifications(); }, 3000);
    }, 150);
})();
</script>

<style>
/* ═══════════════════════════════════════════════════════════════════════
   NOTIFICATION HIGHLIGHT — row/card glow + banner styles
═══════════════════════════════════════════════════════════════════════ */

/* Glow animation keyframes */
@keyframes notifHighlightPulse {
    0%   { box-shadow: 0 0 0 0 rgba(55, 98, 200, 0); background-color: rgba(55, 98, 200, 0); }
    15%  { box-shadow: 0 0 0 6px rgba(55, 98, 200, 0.35); background-color: rgba(55, 98, 200, 0.13); }
    50%  { box-shadow: 0 0 0 4px rgba(55, 98, 200, 0.20); background-color: rgba(55, 98, 200, 0.08); }
    80%  { box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.10); background-color: rgba(55, 98, 200, 0.04); }
    100% { box-shadow: 0 0 0 0 rgba(55, 98, 200, 0); background-color: rgba(55, 98, 200, 0); }
}

/* Applied to <tr> and mobile cards */
.notif-highlight {
    animation: notifHighlightPulse 5s ease forwards;
    outline: 2.5px solid #3762c8 !important;
    outline-offset: -2px;
    border-radius: 8px;
    position: relative;
    z-index: 1;
}

/* Dark mode variant */
[data-theme="dark"] .notif-highlight {
    animation: none;
    outline: 2.5px solid #5f8cff !important;
    box-shadow: 0 0 0 4px rgba(95, 140, 255, 0.25), inset 0 0 0 9999px rgba(95, 140, 255, 0.09) !important;
    transition: box-shadow 0.4s ease, outline 0.4s ease;
}

/* Banner that appears above the table/list */
.notif-highlight-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    margin-bottom: 12px;
    background: linear-gradient(135deg, #eef2ff 0%, #dce6f8 100%);
    border: 1.5px solid #3762c8;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 600;
    color: #1e3a8a;
    box-shadow: 0 3px 12px rgba(55, 98, 200, 0.18);
    animation: notifBannerFadeOut 0.5s ease 4.7s forwards;
}

[data-theme="dark"] .notif-highlight-banner {
    background: linear-gradient(135deg, rgba(55,98,200,0.18) 0%, rgba(30,58,138,0.25) 100%);
    border-color: #5f8cff;
    color: #a5c0ff;
    box-shadow: 0 3px 12px rgba(95, 140, 255, 0.22);
}

@keyframes notifBannerFadeOut {
    from { opacity: 1; transform: translateY(0); }
    to   { opacity: 0; transform: translateY(-6px); pointer-events: none; }
}

/* ── Relocated badge — amber pill on notification items ── */
.notif-relocated-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .03em;
    background: linear-gradient(135deg, rgba(245,158,11,.18), rgba(234,88,12,.13));
    color: #b45309;
    border: 1px solid rgba(245,158,11,.4);
    white-space: nowrap;
    vertical-align: middle;
}
[data-theme="dark"] .notif-relocated-badge {
    background: linear-gradient(135deg, rgba(245,158,11,.25), rgba(234,88,12,.18));
    color: #fbbf24;
    border-color: rgba(251,191,36,.5);
}
/* Left accent on notification items that are relocated */
.notif-item.is-relocated {
    border-left: 3px solid rgba(245,158,11,.6) !important;
}
[data-theme="dark"] .notif-item.is-relocated {
    border-left-color: rgba(251,191,36,.6) !important;
}

/* ── On-page relocated banner — item was not found here ── */
.notif-relocated-page-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    margin-bottom: 12px;
    background: linear-gradient(135deg, rgba(245,158,11,.13), rgba(234,88,12,.08));
    border: 1.5px solid rgba(245,158,11,.40);
    border-left: 4px solid #f59e0b;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    color: #92400e;
}
[data-theme="dark"] .notif-relocated-page-banner {
    background: linear-gradient(135deg, rgba(245,158,11,.18), rgba(234,88,12,.12));
    border-color: rgba(251,191,36,.45);
    border-left-color: #fbbf24;
    color: #fde68a;
}
/* ── Citizen Feedback page pill ── */
.notif-page-feedback {
    background: linear-gradient(135deg, rgba(16,185,129,.18), rgba(5,150,105,.13));
    color: #065f46;
    border-color: rgba(16,185,129,.45);
}
[data-theme="dark"] .notif-page-feedback {
    background: linear-gradient(135deg, rgba(16,185,129,.25), rgba(5,150,105,.18));
    color: #6ee7b7;
    border-color: rgba(52,211,153,.5);
}
</style>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   NOTIFICATION HIGHLIGHT
   Reads ?highlight={req_id} or ?highlight_rep={rep_id} from the URL,
   scrolls to the matching row/card, applies a glow animation, and shows
   a brief "You were directed here" banner above the element.
   Works on: requests.php, pending_reports.php, current_reports.php,
             archive_reports.php  (any page using data-req-id / data-rep-id).
═══════════════════════════════════════════════════════════════════════ */
(function initNotifHighlight() {
    const params = new URLSearchParams(window.location.search);
    const reqId  = params.get('highlight');        // requests.php?highlight=5
    const repId  = params.get('highlight_rep');    // *_reports.php?highlight_rep=8

    if (!reqId && !repId) return;

    /* Remove the highlight param from the address bar silently */
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('highlight');
    cleanUrl.searchParams.delete('highlight_rep');
    history.replaceState(null, '', cleanUrl);

    function doHighlight(targetId, dataAttr) {
        const selector = '[' + dataAttr + '="' + targetId + '"]';

        /* Step 1 — If on requests.php, force the table view to be visible.
           switchView() is defined after this script is included so we wait
           for DOMContentLoaded to be sure it exists, then call it and also
           overwrite localStorage so the view-restore IIFE can't race back
           to GIS view. */
        function ensureTableView() {
            if (typeof switchView === 'function') {
                try { localStorage.setItem('activeView', 'requests'); } catch(e) {}
                switchView('requests');
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', ensureTableView);
        } else {
            ensureTableView();
        }

        /* Step 2 — After view animation settles, scroll + highlight */
        function runHighlight() {
            const elements = document.querySelectorAll(selector);

            /* ── Not found: item has been relocated to another page ── */
            if (!elements.length) {
                showRelocatedBanner(dataAttr, targetId);
                return;
            }

            /* Pick the element that is actually visible in the current layout.
               On mobile the <tr> is hidden (table display:none), so offsetParent
               is null — prefer the .request-card instead. */
            function isVisible(el) { return el && el.offsetParent !== null; }
            const primary = Array.from(elements).find(isVisible) || elements[0];

            /* Scroll smoothly to center the visible element in the viewport */
            primary.scrollIntoView({ behavior: 'smooth', block: 'center' });

            /* Apply glow highlight to ALL matching elements
               (desktop <tr> AND mobile card both share the same data-attr) */
            elements.forEach(function (el) {
                el.classList.add('notif-highlight');
                setTimeout(function () {
                    el.classList.remove('notif-highlight');
                    el.style.outline = '';
                    el.style.boxShadow = '';
                }, 5500);
            });

            /* Insert the "You were directed here" banner */
            insertHighlightBanner(primary);
        }

        /* Give the view-switch 400 ms to render, then scroll */
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(runHighlight, 500);
            });
        } else {
            setTimeout(runHighlight, 500);
        }
    }

    function insertHighlightBanner(el) {
        if (document.getElementById('notifHighlightBanner')) return;

        const banner = document.createElement('div');
        banner.id = 'notifHighlightBanner';
        banner.className = 'notif-highlight-banner';
        banner.innerHTML =
            '<span style="font-size:16px;flex-shrink:0;">🔔</span>' +
            '<span>You were directed here from a notification — this item is highlighted.</span>';

        /* ── Find the right insertion point ────────────────────────────────
           Query from document root so we always land INSIDE the card/section
           container, never before it.

           Strategy (in priority order):
           1. Desktop report pages: insert before .table-wrapper  (inside .card)
           2. Mobile report pages : insert before .mobile-report-list (inside .card)
           3. Desktop requests    : insert before <table> inside .table-card
           4. Mobile requests     : insert before .mobile-request-list (inside .table-card)
           5. Last resort         : el's direct parent
        ────────────────────────────────────────────────────────────────── */
        const isMobile   = window.matchMedia('(max-width: 768px)').matches;
        const tableWrap  = document.querySelector('.table-wrapper');          // report pages
        const mobileList = document.querySelector('.mobile-report-list, .mobile-request-list');
        const tableCard  = document.querySelector('.table-card');             // requests.php
        const plainTable = tableCard ? tableCard.querySelector('table') : null;

        let target = null;

        if (!isMobile && tableWrap) {
            target = tableWrap;                    // before .table-wrapper inside .card
        } else if (isMobile && mobileList) {
            target = mobileList;                   // before mobile list inside .card/.table-card
        } else if (!isMobile && plainTable) {
            target = plainTable;                   // before <table> inside .table-card
        } else if (tableWrap) {
            target = tableWrap;                    // fallback: hidden table-wrapper
        } else if (mobileList) {
            target = mobileList;
        }

        if (target && target.parentElement) {
            target.parentElement.insertBefore(banner, target);
        } else if (el.tagName === 'TR') {
            /* Last-resort for <tr>: insert before the <table>, inside its parent */
            const tbl = el.closest('table');
            if (tbl && tbl.parentElement) tbl.parentElement.insertBefore(banner, tbl);
        } else if (el.parentElement) {
            el.parentElement.insertBefore(banner, el);
        }

        setTimeout(function () {
            if (banner.parentElement) banner.parentElement.removeChild(banner);
        }, 5200);
    }

    if (reqId) doHighlight(reqId, 'data-req-id');
    if (repId) doHighlight(repId, 'data-rep-id');

    /* ── Show amber "relocated" banner when the item isn't on this page ── */
    function showRelocatedBanner(dataAttr, targetId) {
        if (document.getElementById('notifRelocatedBanner')) return;

        const param = dataAttr === 'data-req-id' ? 'highlight' : 'highlight_rep';
        const pageMap = {
            'pending_reports.php': 'Pending Reports',
            'current_reports.php': 'Current Reports',
            'archive_reports.php': 'Archive Reports',
            'requests.php':        'Requests',
        };
        const currentPage = window.location.pathname.split('/').pop();
        const links = Object.entries(pageMap)
            .filter(function(e) { return e[0] !== currentPage; })
            .map(function(e) {
                return '<a href="' + e[0] + '?' + param + '=' + encodeURIComponent(targetId) + '" ' +
                       'style="color:inherit;font-weight:700;text-decoration:underline;margin-left:6px;">' +
                       e[1] + '</a>';
            });

        const banner = document.createElement('div');
        banner.id = 'notifRelocatedBanner';
        banner.className = 'notif-relocated-page-banner';
        banner.innerHTML =
            '<span style="font-size:15px;flex-shrink:0;">📦</span>' +
            '<span><strong>Item not found here.</strong> It may have moved to: ' + links.join('') + '</span>' +
            '<button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:inherit;margin-left:auto;flex-shrink:0;">&times;</button>';

        /* Same safe insertion: inside the section container, before the table/list */
        const isMobile   = window.matchMedia('(max-width: 768px)').matches;
        const tableWrap  = document.querySelector('.table-wrapper');
        const mobileList = document.querySelector('.mobile-report-list, .mobile-request-list');
        const tableCard  = document.querySelector('.table-card');
        const plainTable = tableCard ? tableCard.querySelector('table') : null;

        let target = null;
        if (!isMobile && tableWrap)      target = tableWrap;
        else if (isMobile && mobileList) target = mobileList;
        else if (!isMobile && plainTable) target = plainTable;
        else if (tableWrap)              target = tableWrap;
        else if (mobileList)             target = mobileList;

        if (target && target.parentElement) {
            target.parentElement.insertBefore(banner, target);
        } else {
            const main = document.querySelector('.main-content') || document.body;
            main.insertBefore(banner, main.firstChild);
        }
    }
})();
</script>