/* ═══════════════════════════════════════════════════════════════════════
   initProgressiveList(opts) — generic "show first N, click Show more to
   reveal the rest" list limiter, shared by every admin page that has a
   long list (mobile report/request cards, the Activity History panel).

   opts:
     listSelector     — container holding the items
     itemSelector     — selector (relative to the container) for each item
     exclude          — optional (el) => bool, skip placeholder/empty-state els
     moreBtnSelector  — the "Show more" <button>
     moreWrapSelector — wrapper around the button (hidden when nothing left)
     moreLabelSelector— optional <span> inside the button showing the label
     pageSize         — how many items are revealed per batch (default 10)

   Returns a reset() function: call it again after a sort/filter/refresh
   changes what's inside the list, to re-apply the limit from page 1.
═══════════════════════════════════════════════════════════════════════ */
function initProgressiveList(opts) {
    const {
        listSelector, itemSelector, exclude,
        moreBtnSelector, moreWrapSelector, moreLabelSelector,
        pageSize = 10
    } = opts;

    const list     = document.querySelector(listSelector);
    const moreBtn  = moreBtnSelector  ? document.querySelector(moreBtnSelector)  : null;
    const moreWrap = moreWrapSelector ? document.querySelector(moreWrapSelector) : null;
    const moreLabel= moreLabelSelector? document.querySelector(moreLabelSelector): null;

    let visibleCount = pageSize;

    function apply() {
        if (!list) return;
        const items = Array.from(list.querySelectorAll(itemSelector))
            .filter(el => !(typeof exclude === 'function' && exclude(el)));

        items.forEach((el, i) => {
            el.style.display = i < visibleCount ? '' : 'none';
        });

        const remaining = items.length - visibleCount;
        if (moreWrap)  moreWrap.style.display = remaining > 0 ? '' : 'none';
        if (moreLabel) moreLabel.textContent  = remaining > 0 ? `Show more (${remaining} more)` : 'Show more';
    }

    if (moreBtn && !moreBtn.dataset.progressiveBound) {
        moreBtn.dataset.progressiveBound = '1';
        moreBtn.addEventListener('click', function () {
            visibleCount += pageSize;
            apply();
        });
    }

    apply();

    return function reset() {
        visibleCount = pageSize;
        apply();
    };
}
