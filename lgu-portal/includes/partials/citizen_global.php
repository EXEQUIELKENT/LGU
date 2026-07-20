<script>
/* ---------------------------------------------------------------
   TRANSLATIONS ENGINE
   --------------------------------------------------------------- */
(function() {
    'use strict';

    const BASE = '<?= $BASE_URL ?>';
    const JSON_PATH = BASE + 'assets/data/translations.json';

    // Use preloaded translations if available — but verify they're not stale.
    // citizen_rendering.php embeds translations inline; if translations.json was
    // updated after the last deploy, the preloaded object won't have the new keys.
    // We check for keys that only exist in the current version on BOTH the en AND tl
    // sides. Previously only pre.en was checked — meaning old embeds that had the key
    // in English but were missing the Filipino translations would pass the check and
    // cause many labels to stay in English. Now we verify the tl translations are also
    // present and up-to-date before trusting the preloaded data.
    const REQUIRED_KEY    = 'reports_stat_title_scheduled'; // present in current en + tl
    const REQUIRED_KEY_TL = 'reports_legend_filter_title_scheduled'; // new tl-only sentinel
    (function validatePreload() {
        const pre = window.__preloadedTranslations;
        const stale = pre && (
            !pre.en || pre.en[REQUIRED_KEY] === undefined ||
            !pre.tl || pre.tl[REQUIRED_KEY] === undefined ||
            pre.tl[REQUIRED_KEY_TL] === undefined
        );
        if (stale) {
            console.warn('[i18n] Preloaded translations are stale (missing tl keys) — will re-fetch from server.');
            window.__preloadedTranslations = null;
        }
    })();
    let translations = window.__preloadedTranslations || null;
    let currentLang = window.__currentLang || localStorage.getItem('lang') || 'en';

    const translateBtn       = document.getElementById('translateBtn');
    const mobileTranslateBtn = document.getElementById('mobileTranslateBtn');
    const langLabel          = document.getElementById('langLabel');
    const mobileLangLabel    = document.getElementById('mobileLangLabel');
    const langBadge          = document.getElementById('langBadge');
    const badgeFlag          = document.getElementById('badgeFlag');
    const badgeText          = document.getElementById('badgeText');

    let badgeTimer = null;

    /* --- Ripple helper --- */
    function createRipple(btn, e) {
        const rect   = btn.getBoundingClientRect();
        const size   = Math.max(rect.width, rect.height) * 2;
        const x      = (e ? e.clientX - rect.left : rect.width / 2) - size / 2;
        const y      = (e ? e.clientY - rect.top  : rect.height / 2) - size / 2;
        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        ripple.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px;`;
        btn.appendChild(ripple);
        ripple.addEventListener('animationend', () => ripple.remove());
    }

    /* --- Show toast badge --- */
    function showBadge(lang) {
        if (lang === 'tl') {
            badgeFlag.innerHTML = '&#x1F1F5;&#x1F1ED;'; // 🇵🇭
            badgeText.textContent = 'Isinalin sa Filipino';
        } else {
            badgeFlag.innerHTML = '&#x1F1FA;&#x1F1F8;'; // 🇺🇸
            badgeText.textContent = 'Switched to English';
        }
        langBadge.classList.add('show');
        clearTimeout(badgeTimer);
        badgeTimer = setTimeout(() => langBadge.classList.remove('show'), 2500);
    }

    /* ─────────────────────────────────────────────────────────────
       syncGlobal — FIX #1
       Every time this engine loads or receives translation data,
       write it to window.__preloadedTranslations so the chatbot
       widget's t() function can read it. Previously this was never
       done for data loaded via fetch(), causing the chatbot to
       always see null and stay in English after a language switch.
    ───────────────────────────────────────────────────────────── */
    function syncGlobal(data) {
        window.__preloadedTranslations = data;
    }

    /* --- Apply translations to DOM --- */
    function applyTranslations(lang) {
        console.log('[i18n] Applying translations for:', lang);
        
        if (!translations) {
            console.error('[i18n] Translations object is null/undefined!');
            if (window.__preloadedTranslations) {
                translations = window.__preloadedTranslations;
                console.log('[i18n] Recovered translations from window.__preloadedTranslations');
            } else {
                return;
            }
        }

        /* FIX #1 (continued): keep global in sync whenever we apply */
        syncGlobal(translations);

        if (typeof updateMapLayerToggleText === 'function') {
            updateMapLayerToggleText();
        }
        
        const t = translations[lang];
        if (!t) {
            console.error('[i18n] No translations found for language:', lang);
            return;
        }

        let count = 0;

        // data-i18n → textContent
        const dataI18nElements = document.querySelectorAll('[data-i18n]');
        console.log('[i18n] Processing', dataI18nElements.length, '[data-i18n] elements');
        dataI18nElements.forEach(el => {
            const key = el.getAttribute('data-i18n');
            if (t[key] !== undefined) {
                el.textContent = t[key];
                count++;
            }
        });

        // data-i18n-html → innerHTML
        const dataI18nHtmlElements = document.querySelectorAll('[data-i18n-html]');
        console.log('[i18n] Processing', dataI18nHtmlElements.length, '[data-i18n-html] elements');
        dataI18nHtmlElements.forEach(el => {
            const key = el.getAttribute('data-i18n-html');
            if (t[key] !== undefined) {
                el.innerHTML = t[key];
                count++;
            }
        });

        // data-i18n-placeholder → placeholder
        const dataI18nPlaceholderElements = document.querySelectorAll('[data-i18n-placeholder]');
        console.log('[i18n] Processing', dataI18nPlaceholderElements.length, '[data-i18n-placeholder] elements');
        dataI18nPlaceholderElements.forEach(el => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (t[key] !== undefined) {
                el.placeholder = t[key];
                count++;
            }
        });

        // data-i18n-title → title attribute
        const dataI18nTitleElements = document.querySelectorAll('[data-i18n-title]');
        console.log('[i18n] Processing', dataI18nTitleElements.length, '[data-i18n-title] elements');
        dataI18nTitleElements.forEach(el => {
            const key = el.getAttribute('data-i18n-title');
            if (t[key] !== undefined) {
                el.title = t[key];
                count++;
            }
        });

        // Update button labels & state
        const isFilipino = lang === 'tl';
        const labelText = isFilipino ? 'F' : 'E';
        
        if (langLabel) langLabel.textContent = t['lang_label'] || (lang === 'en' ? 'EN' : 'FIL');
        if (mobileLangLabel) mobileLangLabel.textContent = labelText;

        [translateBtn, mobileTranslateBtn].forEach(btn => {
            if (!btn) return;
            btn.classList.toggle('lang-active', isFilipino);
            btn.title = t['translate_btn_title'] || '';
        });

        document.documentElement.lang = lang === 'tl' ? 'tl' : 'en';

        console.log('[i18n] ✅ Total elements translated:', count);

        /* ─────────────────────────────────────────────────────────
           FIX #2 — notify the chatbot widget
           Previously only window.__chatbotRefreshLang() was called,
           but the chatbot's t() reads window.__preloadedTranslations
           which was null (see FIX #1). Now that syncGlobal() is
           called above, t() will find the data. We also dispatch
           the 'i18nReady' event that the chatbot's event listener
           is waiting for, so it works even if __chatbotRefreshLang
           is called before the chatbot script has loaded.
        ───────────────────────────────────────────────────────── */
        document.dispatchEvent(new CustomEvent('i18nReady', { detail: { lang: lang } }));

        if (typeof window.__chatbotRefreshLang === 'function') {
            window.__chatbotRefreshLang();
        }
    }

    /* --- Toggle language --- */
    function toggleLanguage(e) {
        const btn = e.currentTarget;

        createRipple(btn, e);

        btn.classList.add('translating');
        setTimeout(() => btn.classList.remove('translating'), 650);

        const newLang = currentLang === 'en' ? 'tl' : 'en';

        function afterLoad() {
            currentLang = newLang;
            localStorage.setItem('lang', currentLang);
            applyTranslations(currentLang);
            showBadge(currentLang);
            if (typeof renderClock === 'function') renderClock(new Date(currentServerTime));
        }

        if (!translations) {
            console.log('[i18n] No translations loaded, fetching...');
            fetch(JSON_PATH)
                .then(r => {
                    if (!r.ok) throw new Error('Failed to load translations.json');
                    return r.json();
                })
                .then(data => {
                    translations = data;
                    /* FIX #1: write to global so chatbot t() can read it */
                    syncGlobal(data);
                    afterLoad();
                })
                .catch(err => {
                    console.error('[i18n]', err);
                });
        } else {
            /* translations already loaded — still make sure global is in sync */
            syncGlobal(translations);
            afterLoad();
        }
    }

    /* --- Init --- */
    function init() {
        console.log('[i18n] init() called');
        console.log('[i18n] currentLang:', currentLang);
        console.log('[i18n] translations exist:', !!translations);
        
        if (!translations && window.__preloadedTranslations) {
            // Only use the preload if it has the current keys on BOTH en AND tl sides.
            // Checking en alone is insufficient — old embeds may pass the en check
            // but still be missing newer Filipino translations.
            const pre = window.__preloadedTranslations;
            const fresh = pre && pre.en && pre.en[REQUIRED_KEY] !== undefined &&
                          pre.tl && pre.tl[REQUIRED_KEY] !== undefined &&
                          pre.tl[REQUIRED_KEY_TL] !== undefined;
            if (fresh) {
                translations = pre;
                console.log('[i18n] Loaded translations from window.__preloadedTranslations');
            } else {
                console.warn('[i18n] Ignoring stale window.__preloadedTranslations in init()');
            }
        }
        
        if (!translations && currentLang === 'tl') {
            console.log('[i18n] No translations, fetching for Filipino...');
            fetch(JSON_PATH)
                .then(r => r.json())
                .then(data => {
                    translations = data;
                    /* FIX #1: write to global so chatbot t() can read it */
                    syncGlobal(data);
                    applyTranslations('tl');
                    document.documentElement.style.visibility = 'visible';
                })
                .catch((err) => {
                    console.error('[i18n] Failed to fetch translations:', err);
                    document.documentElement.style.visibility = 'visible';
                });
        } else if (translations && currentLang === 'tl') {
            console.log('[i18n] Translations exist, applying Filipino...');
            /* FIX #1: ensure global is synced before applyTranslations fires */
            syncGlobal(translations);
            setTimeout(() => {
                applyTranslations('tl');
                document.documentElement.style.visibility = 'visible';
            }, 200);
        } else {
            // English mode
            if (mobileLangLabel) mobileLangLabel.textContent = 'E';
            document.documentElement.style.visibility = 'visible';
            console.log('[i18n] English mode - no translation needed');
        }
        
        if (translateBtn) translateBtn.addEventListener('click', toggleLanguage);
        if (mobileTranslateBtn) mobileTranslateBtn.addEventListener('click', toggleLanguage);
        
        console.log('[i18n] init() completed');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 100);
    }
})();

/* ---------------------------------------------------------------
   MOBILE SIDEBAR TOGGLE
   --------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar      = document.getElementById('sidebarNav');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', e => {
            e.stopPropagation();
            if (sidebar) sidebar.classList.toggle('mobile-active');
        });
    }
    
    document.addEventListener('click', e => {
        if (sidebar && sidebar.classList.contains('mobile-active')) {
            if (!sidebar.contains(e.target) && e.target !== mobileToggle) {
                sidebar.classList.remove('mobile-active');
            }
        }
    });
    
    if (sidebar) sidebar.addEventListener('click', e => e.stopPropagation());
    
    sidebar?.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => sidebar.classList.remove('mobile-active'));
    });

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});

/* ---------------------------------------------------------------
   CLOCK
   --------------------------------------------------------------- */
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval     = null;
let lastSecond        = null;

const TL_DAYS   = ['Linggo','Lunes','Martes','Miyerkules','Huwebes','Biyernes','Sabado'];
const TL_MONTHS = ['Enero','Pebrero','Marso','Abril','Mayo','Hunyo','Hulyo','Agosto','Setyembre','Oktubre','Nobyembre','Disyembre'];

function renderClock(now) {
    const lang = localStorage.getItem('lang') || 'en';

    let datePart;
    if (lang === 'tl') {
        const dayName   = TL_DAYS[now.getDay()];
        const monthName = TL_MONTHS[now.getMonth()];
        const day       = now.getDate();
        const year      = now.getFullYear();
        datePart = `${dayName}, ${monthName} ${day}, ${year}`;
    } else {
        datePart = now.toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    const timeStr = now.toLocaleTimeString('en-US', {
        hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true
    });
    const t    = timeStr.match(/^(\d+):(\d+):(\d+)\s?(AM|PM)$/i);
    const h    = t ? t[1] : '--';
    const m    = t ? t[2] : '--';
    const s    = t ? t[3] : '--';
    const ampm = t ? t[4] : '';

    const desktopClock = document.getElementById('desktopClock');
    const mobileClock  = document.getElementById('mobileClock');

    const flip = str => str.split('').map(c => `<span>${c}</span>`).join('');

    if (desktopClock) {
        desktopClock.innerHTML = `
            <span class="date-part">${datePart}</span>
            &nbsp;&nbsp;&nbsp;
            <span class="time-part">${flip(h)}:${flip(m)}:${flip(s)} ${ampm}</span>
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
    else startClock();
});

setInterval(() => {
    fetch(location.href, { method: 'HEAD' }).then(() => { currentServerTime = SERVER_TIME; });
}, RESYNC_MINUTES * 60 * 1000);

startClock();

/* ---------------------------------------------------------------
   DARK MODE
   --------------------------------------------------------------- */
(function() {
    const darkModeBtn       = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const html = document.documentElement;
    const THEME_KEY = 'theme', BACKUP_KEY = 'theme_backup';

    function updateTheme(isDark, animate) {
        try {
            isDark ? html.setAttribute('data-theme','dark') : html.removeAttribute('data-theme');
            localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
            localStorage.setItem(BACKUP_KEY, isDark ? 'dark' : 'light');

            [darkModeBtn, mobileDarkModeBtn].forEach(btn => {
                if (!btn) return;
                btn.querySelector('.dark-icon').style.display  = isDark ? 'none'   : 'inline';
                btn.querySelector('.light-icon').style.display = isDark ? 'inline' : 'none';
                if (animate) {
                    btn.classList.add('active');
                    setTimeout(() => btn.classList.remove('active'), 500);
                }
            });
        } catch(e) {}
    }

    try {
        let t = localStorage.getItem(THEME_KEY);
        if (t !== 'dark' && t !== 'light') t = localStorage.getItem(BACKUP_KEY);
        if (t !== 'dark' && t !== 'light') t = 'light';
        updateTheme(t === 'dark', false);
    } catch(e) { updateTheme(false, false); }

    function toggle() { updateTheme(html.getAttribute('data-theme') !== 'dark', true); }
    if (darkModeBtn)       darkModeBtn.addEventListener('click', toggle);
    if (mobileDarkModeBtn) mobileDarkModeBtn.addEventListener('click', toggle);

    window.addEventListener('beforeunload', () => {
        try {
            const v = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            localStorage.setItem(THEME_KEY, v);
            localStorage.setItem(BACKUP_KEY, v);
        } catch(e) {}
    });
})();
</script>

<script>
// Clean URL after secret key authentication to prevent sharing
if (window.location.search.includes('staff=infrastructure_staff_2026_qr8p')) {
    const cleanUrl = window.location.pathname;
    window.history.replaceState({}, document.title, cleanUrl);
}
</script>

<?php if (isset($GLOBALS['clean_url_needed']) && $GLOBALS['clean_url_needed']): ?>
    <script>
    if (window.location.search.includes('staff=<?= SECRET_ACCESS_KEY ?>')) {
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
    </script>
<?php endif; ?>