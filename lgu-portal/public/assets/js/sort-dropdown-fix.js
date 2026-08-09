/*!
 * Sort-dropdown positioning fix (shared across every admin page that has a
 * .sort-dropdown-wrap / .sort-btn / .sort-dropdown component).
 * ---------------------------------------------------------------------------
 * THE PROBLEM
 * .sort-dropdown is normally `position: absolute`, anchored to its
 * .sort-dropdown-wrap button. That button sits inside `.card`, which has
 * `backdrop-filter: blur(...)`, inside `.main-content`, which scrolls
 * internally via `overflow-y: auto`. Two separate CSS effects clip/mis-place
 * the menu:
 *   1. `overflow-y: auto` on `.main-content` clips anything positioned
 *      beyond its visible box — no z-index can fix that, clipping happens
 *      before stacking order is even considered.
 *   2. `backdrop-filter` (like `transform`/`filter`/`perspective`) on an
 *      ancestor makes THAT ancestor the containing block for any
 *      `position: fixed` descendant — a lesser-known CSS rule. So even
 *      switching the menu to `position: fixed` doesn't make it relative to
 *      the viewport here; it ends up relative to `.card` instead, which
 *      silently breaks any viewport-based coordinate math and can push the
 *      menu somewhere completely wrong/invisible.
 *
 * THE FIX
 * The only positioning strategy that reliably escapes both problems is to
 * physically move the dropdown panel to be a direct child of <body> while
 * it's open (a "portal", same technique libraries like Popper/Floating UI
 * use for this exact reason). Its trigger button never moves, so visually
 * nothing changes for the user — but now there is no overflow ancestor and
 * no backdrop-filter/transform ancestor between the menu and <body>, so
 * `position: fixed` truly means "relative to the viewport" and it always
 * renders on top of everything. We restore it to its original spot in the
 * DOM the moment it closes.
 */
(function () {
    var Z_INDEX_ABOVE_EVERYTHING = 99999;
    var VIEWPORT_MARGIN = 8;

    function positionDropdown(btn, dropdown) {
        var btnRect = btn.getBoundingClientRect();
        var menuWidth = dropdown.offsetWidth || 190;
        var menuHeight = dropdown.scrollHeight || dropdown.offsetHeight || 260;
        var viewportW = window.innerWidth;
        var viewportH = window.innerHeight;

        // Right-align to the button by default — matches the original `right: 0` CSS.
        var left = btnRect.right - menuWidth;
        if (left < VIEWPORT_MARGIN) left = VIEWPORT_MARGIN;
        if (left + menuWidth > viewportW - VIEWPORT_MARGIN) {
            left = viewportW - VIEWPORT_MARGIN - menuWidth;
        }

        // Open below the button by default; flip above it if there isn't room
        // below but there IS room above (keeps it fully visible either way).
        var top = btnRect.bottom + 6;
        if (top + menuHeight > viewportH - VIEWPORT_MARGIN &&
            btnRect.top - menuHeight - 6 > VIEWPORT_MARGIN) {
            top = btnRect.top - menuHeight - 6;
        }

        dropdown.style.position = 'fixed';
        dropdown.style.top = top + 'px';
        dropdown.style.left = left + 'px';
        dropdown.style.right = 'auto';
        dropdown.style.margin = '0';
        dropdown.style.zIndex = String(Z_INDEX_ABOVE_EVERYTHING);
    }

    // Remembers where each dropdown originally lived in the DOM, so it can
    // be moved back there the moment it closes.
    function openDropdown(btn, dropdown, home) {
        if (dropdown.parentElement !== document.body) {
            home.parent = dropdown.parentElement;
            home.next = dropdown.nextSibling;
            document.body.appendChild(dropdown);
        }
        // The page's own CSS shows/hides the menu via
        // `.sort-dropdown-wrap.open .sort-dropdown { display: block; }`,
        // a descendant selector — which stops matching once the dropdown is
        // no longer a DOM descendant of its wrap. Set display directly so
        // it keeps working after the move.
        dropdown.style.display = 'block';
        requestAnimationFrame(function () { positionDropdown(btn, dropdown); });
    }

    function closeDropdown(dropdown, home) {
        dropdown.style.display = '';
        dropdown.style.position = '';
        dropdown.style.top = '';
        dropdown.style.left = '';
        dropdown.style.right = '';
        dropdown.style.margin = '';
        dropdown.style.zIndex = '';
        if (dropdown.parentElement === document.body && home.parent) {
            if (home.next && home.next.parentNode === home.parent) {
                home.parent.insertBefore(dropdown, home.next);
            } else {
                home.parent.appendChild(dropdown);
            }
        }
    }

    function watch(wrap) {
        var btn = wrap.querySelector('.sort-btn');
        var dropdown = wrap.querySelector('.sort-dropdown');
        if (!btn || !dropdown) return;

        var home = { parent: dropdown.parentElement, next: dropdown.nextSibling };
        var wasOpen = wrap.classList.contains('open');

        var observer = new MutationObserver(function () {
            var isOpen = wrap.classList.contains('open');
            if (isOpen === wasOpen) return;
            wasOpen = isOpen;
            if (isOpen) {
                openDropdown(btn, dropdown, home);
            } else {
                closeDropdown(dropdown, home);
            }
        });
        observer.observe(wrap, { attributes: true, attributeFilter: ['class'] });
    }

    function closeAllOpenDropdowns() {
        document.querySelectorAll('.sort-dropdown-wrap.open').forEach(function (wrap) {
            wrap.classList.remove('open');
        });
    }

    function init() {
        document.querySelectorAll('.sort-dropdown-wrap').forEach(watch);

        // If a dropdown is open and the page scrolls or resizes, its anchor
        // button has likely moved — closing it (rather than silently leaving
        // a stale, misplaced menu on screen) matches how native/select
        // dropdowns typically behave and keeps this fix simple and reliable.
        window.addEventListener('scroll', closeAllOpenDropdowns, true);
        window.addEventListener('resize', closeAllOpenDropdowns);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();