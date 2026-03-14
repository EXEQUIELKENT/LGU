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
    const notifBtn       = document.getElementById('notifBtn');
    const mobileNotifBtn = document.getElementById('mobileNotifBtn');
    const notifDropdown  = document.getElementById('notifDropdown');
    const notifBody      = document.getElementById('notifBody');
    const notifBadge     = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileNotifBadge');
    const clearNotifBtn  = document.getElementById('clearNotifBtn');
    if ((!notifBtn && !mobileNotifBtn) || !notifDropdown) return;

    let notifications = [];
    let unreadCount   = 0;
    const NOTIF_SEEN_KEY = 'notif_seen_ids';
    let seenNotifIds = new Set(JSON.parse(localStorage.getItem(NOTIF_SEEN_KEY) || '[]'));
    let isFirstLoad = true;

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
        if (notifBtn) { if (count > 0) notifBtn.classList.add('has-notif'); else notifBtn.classList.remove('has-notif'); }
    }

    function updateNotificationUI() {
        if (!notifications.length) {
            notifBody.innerHTML = '<div class="notif-empty">No new notifications</div>';
            return;
        }
        const groups = {};
        notifications.forEach(n => {
            const type = n.request_type || 'Other';
            if (!groups[type]) groups[type] = [];
            groups[type].push(n);
        });
        notifBody.innerHTML = Object.keys(groups).map(type => `
            <div class="notif-group">
                <div class="notif-group-title">${type}</div>
                ${groups[type].map(n => `
                    <div class="notif-item ${n.read ? '' : 'unread'}" data-id="${n.id}">
                        <div class="notif-item-title">${n.title}</div>
                        <div class="notif-item-desc">${n.description}</div>
                        <div class="notif-item-time">
                            <span class="notif-time">${n.time}</span>
                            <span class="notif-date">${n.date}</span>
                        </div>
                    </div>
                `).join('')}
            </div>
        `).join('');
        notifBody.querySelectorAll('.notif-item').forEach(item => {
            item.addEventListener('click', () => {
                const notif = notifications.find(n => n.id == item.dataset.id);
                if (notif?.url) window.location.href = notif.url;
            });
        });
    }

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
            o.type = "triangle"; o.frequency.value = 880; g.gain.value = 0.18;
            o.connect(g).connect(notifAudioCtx.destination);
            o.start(); o.stop(notifAudioCtx.currentTime + 0.17);
        } catch (e) {}
    }

    async function fetchNotifications() {
        try {
            const res = await fetch('api/notifications.php');
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
        } catch (err) { console.error('Error fetching notifications:', err); }
    }

    if (clearNotifBtn) {
        clearNotifBtn.addEventListener('click', async () => {
            try {
                await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all' })
                });
                seenNotifIds.clear();
                localStorage.removeItem(NOTIF_SEEN_KEY);
                await fetchNotifications();
            } catch (err) { console.error('Error clearing notifications:', err); }
        });
    }

    function toggleDropdown(e) { e.stopPropagation(); notifDropdown.classList.toggle('show'); }
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