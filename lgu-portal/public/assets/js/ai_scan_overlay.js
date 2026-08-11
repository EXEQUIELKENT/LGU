/**
 * AI Scan Overlay
 * ─────────────────────────────────────────────────────────────────────────
 * Reusable "image analyzer" visualization for CIMM's AI-triggered loading
 * states. Replaces the old plain spinner + rotating text with a live
 * scanning visual over the actual evidence photo(s) being analyzed:
 * cyan grid, laser sweep, corner brackets, simulated live detection boxes,
 * a per-image filmstrip, and a real progress bar driven by
 * ai_tfjs_analysis.js's onProgress(message, percent, meta) callback.
 *
 * It attaches on top of an existing loading overlay without touching that
 * overlay's own show/hide logic — it only swaps which *inner content* is
 * visible (the page's original simple spinner block vs. this scan block),
 * so plain non-AI loading states (e.g. "Validating request", "Rejecting
 * request") keep using the original spinner untouched.
 *
 * Usage:
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

    // Rotating labels for the simulated "live detection" boxes — purely a
    // visual cue that scanning is actively happening on the frame, not a
    // real bounding-box result from the model.
    const DETECT_LABELS = ['Scanning', 'Surface', 'Region', 'Analysing', 'Texture', 'Checking'];

    function attach(hostEl, legacyEl) {
        if (!hostEl) return null;

        const wrap = document.createElement('div');
        wrap.className = 'ai-scan-wrap';
        wrap.innerHTML =
            '<div class="ai-scan-brand"><span class="ai-scan-brand-dot"></span>AI Image Analysis</div>' +
            '<div class="ai-scan-frame">' +
                '<img class="ai-scan-img" data-role="mainImg" alt="Evidence photo being analyzed">' +
                '<div class="ai-scan-noimg" data-role="noImg"><i class="fas fa-robot"></i></div>' +
                '<div class="ai-scan-grid"></div>' +
                '<div class="ai-scan-sweep" data-role="sweep"></div>' +
                '<div class="ai-scan-corner tl"></div><div class="ai-scan-corner tr"></div>' +
                '<div class="ai-scan-corner bl"></div><div class="ai-scan-corner br"></div>' +
                '<div class="ai-scan-boxes" data-role="boxes"></div>' +
                '<div class="ai-scan-badge" data-role="badge">0%</div>' +
            '</div>' +
            '<div class="ai-scan-filmstrip" data-role="filmstrip"></div>' +
            '<div class="ai-scan-progress-track"><div class="ai-scan-progress-fill" data-role="fill"></div></div>' +
            '<div class="ai-scan-status" data-role="status">Initialising AI engine…</div>';
        hostEl.appendChild(wrap);

        const els = {
            mainImg:   wrap.querySelector('[data-role="mainImg"]'),
            noImg:     wrap.querySelector('[data-role="noImg"]'),
            boxes:     wrap.querySelector('[data-role="boxes"]'),
            badge:     wrap.querySelector('[data-role="badge"]'),
            filmstrip: wrap.querySelector('[data-role="filmstrip"]'),
            fill:      wrap.querySelector('[data-role="fill"]'),
            status:    wrap.querySelector('[data-role="status"]'),
        };

        let images = [];
        let activeIdx = 0;
        let detTimer = null;

        function spawnDetectionBox() {
            if (!els.boxes || images.length === 0) return;
            const box = document.createElement('div');
            box.className = 'ai-scan-detbox';
            const w = 20 + Math.random() * 32, h = 16 + Math.random() * 24;
            const x = 6 + Math.random() * (88 - w), y = 10 + Math.random() * (78 - h);
            box.style.left = x + '%'; box.style.top = y + '%';
            box.style.width = w + '%'; box.style.height = h + '%';
            box.dataset.label = DETECT_LABELS[Math.floor(Math.random() * DETECT_LABELS.length)];
            els.boxes.appendChild(box);
            setTimeout(() => box.remove(), 1500);
        }

        function setActiveThumb(idx) {
            if (idx < 0 || idx >= images.length) return;
            activeIdx = idx;
            const thumbs = els.filmstrip.querySelectorAll('.ai-scan-thumb');
            thumbs.forEach((t, i) => {
                t.classList.toggle('active', i === idx);
                t.classList.toggle('done', i < idx);
            });
            const path = images[idx];
            if (path && els.mainImg && els.mainImg.getAttribute('src') !== path) {
                els.mainImg.classList.remove('loaded');
                els.mainImg.onload = () => els.mainImg.classList.add('loaded');
                els.mainImg.src = path;
            }
        }

        /**
         * Begin scan mode and swap the visible image set. Safe to call with
         * an empty/undefined list — falls back to a generic "no image"
         * scanning icon so the rest of the visualization (grid, sweep,
         * progress bar) still plays.
         */
        function start(imagePaths) {
            images = Array.isArray(imagePaths) ? imagePaths.filter(Boolean) : [];
            activeIdx = 0;

            wrap.classList.add('active');
            if (legacyEl) legacyEl.style.display = 'none';

            els.boxes.innerHTML = '';
            els.fill.style.width = '0%';
            els.badge.textContent = '0%';
            els.status.textContent = 'Initialising AI engine…';
            els.filmstrip.innerHTML = '';
            els.mainImg.classList.remove('loaded');
            els.mainImg.removeAttribute('src');

            if (images.length === 0) {
                els.noImg.style.display = 'flex';
            } else {
                els.noImg.style.display = 'none';
                images.forEach(src => {
                    const t = document.createElement('div');
                    t.className = 'ai-scan-thumb';
                    const img = document.createElement('img');
                    img.src = src; img.alt = '';
                    t.appendChild(img);
                    els.filmstrip.appendChild(t);
                });
                setActiveThumb(0);
            }

            if (detTimer) clearInterval(detTimer);
            detTimer = setInterval(spawnDetectionBox, 550);
        }

        /**
         * Push a progress update. `percent` (0-100) drives the bar + badge;
         * `meta.index` (1-based) highlights the matching filmstrip thumbnail
         * as the one currently being scanned.
         */
        function update(message, percent, meta) {
            if (typeof percent === 'number' && !Number.isNaN(percent)) {
                const p = Math.max(0, Math.min(100, Math.round(percent)));
                els.fill.style.width = p + '%';
                els.badge.textContent = p + '%';
            }
            if (message) els.status.textContent = message;
            if (meta && typeof meta.index === 'number') {
                setActiveThumb(Math.min(meta.index - 1, Math.max(images.length - 1, 0)));
            }
        }

        /** End scan mode and restore the page's original simple spinner block. */
        function stop() {
            wrap.classList.remove('active');
            if (detTimer) { clearInterval(detTimer); detTimer = null; }
            if (legacyEl) legacyEl.style.display = '';
        }

        return { start, update, stop };
    }

    global.AIScanOverlay = { attach };
})(window);