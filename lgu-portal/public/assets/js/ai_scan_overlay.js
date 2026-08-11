/**
 * AI Scan Overlay — v3 (freeze fix + scan-frame outline)
 * ─────────────────────────────────────────────────────────────────────────
 * Reusable "image analyzer" visualization for CIMM's AI-triggered loading
 * states (road_monitoring.php's report verification, requests.php's
 * validate-request flow). Shows the evidence photo currently being scored,
 * with a scan-line sweep, an animated corner-bracket outline framing the
 * photo, a live % badge, status text, and — when there's more than one
 * photo — a small step-dot row underneath so the batch pages through one
 * photo at a time instead of showing them all.
 *
 * WHY THIS LOOKS THE WAY IT DOES (perf history):
 *  v1 rendered a cyan grid, corner brackets, and a setInterval-driven stream
 *  of simulated "detection boxes" over the image, plus a full filmstrip that
 *  pointed every thumbnail at the full-resolution original photo, all at
 *  once. That was a burst of concurrent large-image decodes stacked on top
 *  of ai_tfjs_analysis.js's own heavy synchronous scoring pass — the
 *  combination is what froze the page on multi-image batches.
 *  v2 kept the image but generated small canvas thumbnails for every photo
 *  up front — better, but still decoded every photo in the batch even
 *  though only one is shown at a time. v2 also dropped the corner brackets
 *  entirely, which made the frame feel like a plain photo preview rather
 *  than a "being analyzed" state.
 *  This version (v3) only ever decodes the ONE photo currently on screen —
 *  same as v2 — but brings the corner-bracket outline back as pure CSS
 *  (four positioned elements + a couple of compositor-only animations, no
 *  per-frame JS, no simulated detection boxes, no filmstrip). It costs
 *  nothing extra on the main thread: there is still no timer-driven DOM
 *  churn — the sweep AND the corner pulse are CSS animations that run on
 *  the compositor — so there's nothing that can pile up after a main-thread
 *  stall.
 *
 * FIX (v3) — perceived + actual freeze on slow/stalled connections:
 *  ai_tfjs_analysis.js now times out its network/GPU calls instead of
 *  hanging forever (see withTimeout() there), so a genuinely stuck load can
 *  no longer freeze the overlay permanently. On top of that, this file adds
 *  a lightweight stall watchdog: if update() hasn't been called for a few
 *  seconds while a scan is running (normal during the first model
 *  download, which can legitimately take a while on a slow connection),
 *  the status line gets a subtle "still working" pulse and, after longer,
 *  a reassuring note — so a long-but-healthy wait no longer *looks*
 *  identical to a frozen one. The watchdog is a single 1s interval, cleared
 *  on stop(); it never touches image decoding or the analysis pipeline.
 *
 * GALLERY BEHAVIOUR:
 *  - 0 images  → generic "no image" icon, no dots.
 *  - 1 image   → that photo fills the frame; no dot row (nothing to page
 *                through).
 *  - 2+ images → one photo shown at a time, dot row below shows position
 *                (active/done/pending), driven by the {index, total} meta
 *                ai_tfjs_analysis.js already sends per image via onProgress.
 *
 * Usage (unchanged):
 *   const scan = AIScanOverlay.attach(
 *       document.getElementById('loadingOverlay'),      // host to append into
 *       document.getElementById('loadingSimpleContent') // legacy block to hide while active
 *   );
 *   scan.start(['/path/to/evidence1.jpg', '/path/to/evidence2.jpg']);
 *   scan.update('Analysing image 1 of 2…', 42, { index: 1, total: 2 });
 *   scan.stop(); // reverts to the legacy spinner block for next use
 */
(function (global) {
    'use strict';

    const MAIN_MAX_DIM = 640; // the frame is capped well under this in practice — no point keeping a multi-MP original decoded
    const IMG_DECODE_TIMEOUT_MS = 8000; // decorative-image safety net — never block the real analysis on this
    const STALL_PULSE_MS  = 2500;  // no update() in this long → gentle "still working" pulse on the status line
    const STALL_NOTICE_MS = 9000;  // no update() in this long → reassure the user it hasn't actually frozen

    function attach(hostEl, legacyEl) {
        if (!hostEl) return null;

        const wrap = document.createElement('div');
        wrap.className = 'ai-scan-wrap';
        wrap.innerHTML =
            '<div class="ai-scan-brand"><span class="ai-scan-brand-dot"></span>AI Image Analysis</div>' +
            '<div class="ai-scan-frame">' +
                '<img class="ai-scan-img" data-role="mainImg" alt="Evidence photo being analyzed" decoding="async">' +
                '<div class="ai-scan-noimg" data-role="noImg"><i class="fas fa-robot"></i></div>' +
                '<div class="ai-scan-sweep"></div>' +
                '<div class="ai-scan-corner ai-scan-corner-tl"></div>' +
                '<div class="ai-scan-corner ai-scan-corner-tr"></div>' +
                '<div class="ai-scan-corner ai-scan-corner-bl"></div>' +
                '<div class="ai-scan-corner ai-scan-corner-br"></div>' +
                '<div class="ai-scan-badge" data-role="badge">0%</div>' +
            '</div>' +
            '<div class="ai-scan-status" data-role="status">Initialising AI engine…</div>' +
            '<div class="ai-scan-dots" data-role="dots"></div>';
        hostEl.appendChild(wrap);

        const els = {
            mainImg: wrap.querySelector('[data-role="mainImg"]'),
            noImg:   wrap.querySelector('[data-role="noImg"]'),
            badge:   wrap.querySelector('[data-role="badge"]'),
            status:  wrap.querySelector('[data-role="status"]'),
            dots:    wrap.querySelector('[data-role="dots"]'),
        };

        let images = [];
        let activeIdx = 0;
        let running = false;
        let loadToken = 0; // bumped on every image switch so a slow/stale decode can't clobber a newer one
        // Scoped to this scan session (reset in start()) rather than kept forever,
        // so a long admin session running many validations doesn't accumulate
        // decoded bitmaps in memory across unrelated reports.
        let decodedCache = new Map(); // src -> Promise<HTMLImageElement>

        // ── Stall watchdog [freeze fix] ─────────────────────────────────────
        // Purely cosmetic — never gates or delays the real analysis pipeline.
        // Tracks how long it's been since the last update() call and nudges
        // the status line so a long-but-healthy wait (e.g. first-time model
        // download on a slow connection) doesn't read as identical to a
        // frozen page. lastUpdateText remembers what update() actually said
        // so the notice can be cleanly restored the moment progress resumes.
        let lastUpdateAt = 0;
        let lastUpdateText = '';
        let watchdogTimer = null;
        function startWatchdog() {
            stopWatchdog();
            lastUpdateAt = Date.now();
            watchdogTimer = setInterval(() => {
                if (!running) return;
                const idleFor = Date.now() - lastUpdateAt;
                wrap.classList.toggle('ai-scan-stalled', idleFor > STALL_PULSE_MS);
                if (idleFor > STALL_NOTICE_MS) {
                    els.status.textContent = 'Still working — this can take a little longer on a slow connection…';
                } else if (idleFor <= STALL_PULSE_MS && els.status.textContent !== lastUpdateText) {
                    els.status.textContent = lastUpdateText;
                }
            }, 1000);
        }
        function stopWatchdog() {
            if (watchdogTimer) { clearInterval(watchdogTimer); watchdogTimer = null; }
            wrap.classList.remove('ai-scan-stalled');
        }

        function loadDecodedImage(src) {
            if (decodedCache.has(src)) return decodedCache.get(src);
            const promise = new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = 'anonymous'; // harmless for same-origin evidence paths
                img.decoding = 'async';
                const timer = setTimeout(() => reject(new Error('Timed out loading evidence image: ' + src)), IMG_DECODE_TIMEOUT_MS);
                img.onload = () => {
                    clearTimeout(timer);
                    if (img.decode) img.decode().then(() => resolve(img)).catch(() => resolve(img));
                    else resolve(img);
                };
                img.onerror = () => { clearTimeout(timer); reject(new Error('Failed to load evidence image: ' + src)); };
                img.src = src;
            });
            decodedCache.set(src, promise);
            return promise;
        }

        function toDownscaledDataUrl(imgEl, maxDim) {
            const w = imgEl.naturalWidth || imgEl.width || maxDim;
            const h = imgEl.naturalHeight || imgEl.height || maxDim;
            const scale = Math.min(1, maxDim / Math.max(w, h, 1));
            const cw = Math.max(1, Math.round(w * scale));
            const ch = Math.max(1, Math.round(h * scale));
            try {
                const canvas = document.createElement('canvas');
                canvas.width = cw; canvas.height = ch;
                canvas.getContext('2d').drawImage(imgEl, 0, 0, cw, ch);
                return canvas.toDataURL('image/jpeg', 0.75);
            } catch (e) {
                return imgEl.src; // e.g. a tainted canvas from an unexpected cross-origin source
            }
        }

        function renderDots() {
            els.dots.innerHTML = '';
            if (images.length <= 1) { els.dots.style.display = 'none'; return; }
            els.dots.style.display = 'flex';
            images.forEach((_, i) => {
                const dot = document.createElement('div');
                dot.className = 'ai-scan-dot' + (i === activeIdx ? ' active' : i < activeIdx ? ' done' : '');
                els.dots.appendChild(dot);
            });
        }

        function setActiveIndex(idx) {
            if (idx < 0 || idx >= images.length) return;
            activeIdx = idx;
            renderDots();

            const src = images[idx];
            const myToken = ++loadToken;
            els.mainImg.classList.remove('loaded');
            els.noImg.style.display = 'none';
            loadDecodedImage(src).then(imgEl => {
                if (!running || myToken !== loadToken) return; // superseded by a newer image or scan already stopped
                els.mainImg.onload = () => els.mainImg.classList.add('loaded');
                els.mainImg.src = toDownscaledDataUrl(imgEl, MAIN_MAX_DIM);
            }).catch(() => {
                // Failed or timed out (see IMG_DECODE_TIMEOUT_MS) — fall back to the
                // generic icon instead of leaving a blank frame that looks stuck.
                // Purely cosmetic: the real analysis pipeline has its own independent
                // timeout/fallback handling in ai_tfjs_analysis.js and isn't affected.
                if (!running || myToken !== loadToken) return;
                els.mainImg.classList.remove('loaded');
                els.noImg.style.display = 'flex';
            });
        }

        /**
         * Begin scan mode and swap the visible image set. Safe to call with
         * an empty/undefined list — falls back to a generic "no image" icon
         * so the rest of the visualization (sweep, badge, status) still plays.
         */
        function start(imagePaths) {
            images = Array.isArray(imagePaths) ? imagePaths.filter(Boolean) : [];
            activeIdx = 0;
            running = true;
            decodedCache = new Map();

            wrap.classList.add('active');
            if (legacyEl) legacyEl.style.display = 'none';

            els.badge.textContent = '0%';
            els.status.textContent = 'Initialising AI engine…';
            lastUpdateText = els.status.textContent;
            els.mainImg.classList.remove('loaded');
            els.mainImg.removeAttribute('src');
            startWatchdog();

            if (images.length === 0) {
                els.noImg.style.display = 'flex';
                els.dots.style.display = 'none';
                els.dots.innerHTML = '';
            } else {
                els.noImg.style.display = 'none';
                setActiveIndex(0);
            }
        }

        /**
         * Push a progress update. `percent` (0-100) drives the badge;
         * `meta.index` (1-based) swaps the frame to that image and advances
         * the dot row — this is what pages a multi-image batch through its
         * photos one by one instead of showing them all simultaneously.
         */
        function update(message, percent, meta) {
            // Any real progress update means we're not stalled — reset the watchdog clock.
            lastUpdateAt = Date.now();
            wrap.classList.remove('ai-scan-stalled');
            if (typeof percent === 'number' && !Number.isNaN(percent)) {
                els.badge.textContent = Math.max(0, Math.min(100, Math.round(percent))) + '%';
            }
            if (message) { els.status.textContent = message; lastUpdateText = message; }
            if (meta && typeof meta.index === 'number') {
                const idx = Math.min(meta.index - 1, Math.max(images.length - 1, 0));
                if (idx !== activeIdx) setActiveIndex(idx);
            }
        }

        /** End scan mode and restore the page's original simple spinner block. */
        function stop() {
            wrap.classList.remove('active');
            running = false;
            stopWatchdog();
            if (legacyEl) legacyEl.style.display = '';
        }

        return { start, update, stop };
    }

    global.AIScanOverlay = { attach };
})(window);