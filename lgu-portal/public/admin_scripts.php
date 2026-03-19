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
    window.location.href = 'logout.php';
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
        inactivityTimer = setTimeout(() => { window.location.href = 'logout.php'; }, inactivityTime);
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

    const NOTIF_API      = 'api/notifications.php';
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
        if (u.includes('pending_reports'))  return { label: 'Pending Reports',  cls: 'notif-page-pending'  };
        if (u.includes('current_reports'))  return { label: 'Current Reports',  cls: 'notif-page-current'  };
        if (u.includes('archive_reports'))  return { label: 'Archive Reports',  cls: 'notif-page-archive'  };
        if (u.includes('requests'))         return { label: 'Requests',         cls: 'notif-page-requests' };
        if (u.includes('employee'))         return { label: 'Dashboard',        cls: 'notif-page-dashboard'};
        return null;
    }

    function escH(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

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
                        <div class="notif-item-desc">${escH(n.description)}</div>
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
    const params    = new URLSearchParams(window.location.search);
    const reqId     = params.get('highlight');        // requests.php?highlight=5
    const repId     = params.get('highlight_rep');    // *_reports.php?highlight_rep=8

    if (!reqId && !repId) return;

    /* Remove the highlight param from the address bar silently */
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('highlight');
    cleanUrl.searchParams.delete('highlight_rep');
    history.replaceState(null, '', cleanUrl);

    function applyHighlight(targetId, dataAttr) {
        const selector = '[' + dataAttr + '="' + targetId + '"]';

        /* If we're on requests.php and the GIS view is showing, switch
           to the table/list view first so the row is actually in the DOM. */
        if (typeof switchView === 'function') {
            switchView('requests');
        }

        /* Short delay so any view-switch animation can settle */
        setTimeout(function () {
            const elements = document.querySelectorAll(selector);
            if (!elements.length) return;

            const primary = elements[0];   // first match (desktop row or mobile card)

            /* Scroll smoothly into view */
            primary.scrollIntoView({ behavior: 'smooth', block: 'center' });

            /* Apply glow highlight to all matching elements
               (both the desktop <tr> and mobile card share the same data attr) */
            elements.forEach(function (el) {
                el.classList.add('notif-highlight');
                /* Auto-remove after animation finishes (5 s) */
                setTimeout(function () {
                    el.classList.remove('notif-highlight');
                    el.style.outline = '';
                }, 5500);
            });

            /* Insert a dismissable "You were directed here" banner
               just above the first visible element */
            insertHighlightBanner(primary);

        }, 700);
    }

    function insertHighlightBanner(el) {
        /* Don't double-insert */
        if (document.getElementById('notifHighlightBanner')) return;

        const banner = document.createElement('div');
        banner.id = 'notifHighlightBanner';
        banner.className = 'notif-highlight-banner';
        banner.innerHTML =
            '<span style="font-size:16px;">🔔</span>' +
            '<span>You were directed here from a notification — this item is highlighted below.</span>';

        /* Insert before the element's closest table/list ancestor,
           or directly before the element if no wrapper is found */
        const anchor =
            el.closest('.table-card, .table-wrapper, .mobile-report-list, #requestsView') ||
            el.parentElement;

        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(banner, anchor);
        } else {
            el.parentElement.insertBefore(banner, el);
        }

        /* Remove banner after 5 s (matching its CSS fade-out) */
        setTimeout(function () {
            if (banner.parentElement) banner.parentElement.removeChild(banner);
        }, 5200);
    }

    if (reqId) applyHighlight(reqId, 'data-req-id');
    if (repId) applyHighlight(repId, 'data-rep-id');
})();
</script>