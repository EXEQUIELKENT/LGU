<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

// Apply theme immediately
(function() {
    try {
        let savedTheme = localStorage.getItem('theme');
        if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'light';
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        localStorage.setItem('theme', savedTheme);
    } catch (e) {
        document.documentElement.removeAttribute('data-theme');
    }
})();

// Load and apply Filipino translations if needed
(function() {
    const currentLang = localStorage.getItem('lang') || 'en';
    
    if (currentLang === 'tl') {
        const BASE = '<?= $BASE_URL ?>';
        const xhr = new XMLHttpRequest();
        xhr.open('GET', BASE + 'translations.json', false);
        
        try {
            xhr.send();
            if (xhr.status === 200) {
                const translations = JSON.parse(xhr.responseText);
                window.__preloadedTranslations = translations;
                window.__currentLang = 'tl';
                
                function applyWhenReady() {
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function() {
                            console.log('[i18n preload] DOMContentLoaded - applying translations');
                            applyTranslationsSync(translations.tl);
                            document.documentElement.style.cssText = '';
                        });
                    } else {
                        setTimeout(function() {
                            console.log('[i18n preload] Delayed apply - applying translations');
                            applyTranslationsSync(translations.tl);
                            document.documentElement.style.cssText = '';
                        }, 150);
                    }
                }
                
                applyWhenReady();
            } else {
                console.error('[i18n preload] Failed to load translations, status:', xhr.status);
                document.documentElement.style.cssText = '';
            }
        } catch(e) {
            console.error('[i18n preload]', e);
            document.documentElement.style.cssText = '';
        }
    }
    
    function applyTranslationsSync(t) {
        if (!t) {
            console.warn('[i18n preload] No translations object');
            return;
        }
        
        let count = 0;
        
        // data-i18n → textContent
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            if (t[key] !== undefined) {
                el.textContent = t[key];
                count++;
            }
        });
        
        // data-i18n-html → innerHTML
        document.querySelectorAll('[data-i18n-html]').forEach(el => {
            const key = el.getAttribute('data-i18n-html');
            if (t[key] !== undefined) {
                el.innerHTML = t[key];
                count++;
            }
        });
        
        // data-i18n-placeholder → placeholder
        document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (t[key] !== undefined) {
                el.placeholder = t[key];
                count++;
            }
        });
        
        // data-i18n-title → title
        document.querySelectorAll('[data-i18n-title]').forEach(el => {
            const key = el.getAttribute('data-i18n-title');
            if (t[key] !== undefined) {
                el.title = t[key];
                count++;
            }
        });
        
        // Update language labels
        const langLabel = document.getElementById('langLabel');
        const mobileLangLabel = document.getElementById('mobileLangLabel');
        
        if (langLabel) langLabel.textContent = t['lang_label'] || 'FIL';
        if (mobileLangLabel) mobileLangLabel.textContent = 'F';
        
        // Update button states
        const translateBtn = document.getElementById('translateBtn');
        const mobileTranslateBtn = document.getElementById('mobileTranslateBtn');
        
        [translateBtn, mobileTranslateBtn].forEach(btn => {
            if (btn) {
                btn.classList.add('lang-active');
                btn.title = t['translate_btn_title'] || '';
            }
        });
        
        document.documentElement.lang = 'tl';
        
        console.log('[i18n preload] ✅ Preload translated', count, 'elements');
    }
})();
</script>