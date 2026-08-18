/**
 * CancellableTask — small, dependency-free state machine for a long-running
 * async operation that the user can cancel mid-flight.
 * ─────────────────────────────────────────────────────────────────────────
 * Built for the AI-analysis stage of the Validate (requests.php) / Verify
 * (road_monitoring.php) flow, where the underlying work (TensorFlow.js
 * model inference) has no real browser cancellation API — model.classify()/
 * model.detect() promises can't be force-stopped once started. So "cancel"
 * here means: abort every network request the task made (via AbortController),
 * stop waiting on the result, restore the caller's UI immediately, and
 * silently discard the result if the underlying work resolves anyway after
 * the fact. It never gets treated as an error.
 *
 * Also subsumes the old duplicated withOverlayTimeout() wrapper (previously
 * hand-copied in both requests.php and road_monitoring.php): pass
 * { timeoutMs } to run() and a timeout is treated as a genuine failure
 * (distinct from a user cancel), same as before, but from one place.
 *
 * States: idle -> loading -> completed | cancelled | failed
 *                  \-> cancelling -> cancelled (transient, while the
 *                      in-flight taskFn promise hasn't settled yet)
 *
 * Usage:
 *   const task = CancellableTask.create({
 *       onCancel:    () => { / * fires the instant cancel() is called * / },
 *       onCancelled: () => { / * fires once the in-flight work has actually
 *                               settled after a cancel * / },
 *       onCompleted: (result) => { / * genuine success * / },
 *       onFailed:    (err)    => { / * genuine error or timeout, never a cancel * / },
 *   });
 *   task.run(async ({ signal, isCancelled }) => {
 *       const file = await imagePathToFile(path, signal);
 *       if (isCancelled()) return; // don't bother finishing up
 *       ...
 *   }, { timeoutMs: 60000, timeoutLabel: 'AI analysis' });
 *   // later, from a Cancel button:
 *   task.cancel();
 */
(function (global) {
    'use strict';

    function create(opts) {
        opts = opts || {};
        let state = 'idle';
        let token = 0;
        let controller = null;

        function getState() { return state; }

        function isCancelled() { return state === 'cancelling' || state === 'cancelled'; }

        function cancel() {
            if (state !== 'loading') return; // no-op once settled, or before anything started
            state = 'cancelling';
            if (controller) controller.abort();
            if (typeof opts.onCancel === 'function') opts.onCancel();
        }

        /**
         * @param {(ctx: {signal: AbortSignal, isCancelled: () => boolean}) => Promise<any>} taskFn
         * @param {{timeoutMs?: number, timeoutLabel?: string}} [runOpts]
         */
        function run(taskFn, runOpts) {
            runOpts = runOpts || {};
            const myToken = ++token;
            controller = new AbortController();
            state = 'loading';

            const ctx = { signal: controller.signal, isCancelled: () => myToken !== token || isCancelled() };

            let timeoutTimer = null;
            let timedOut = false;
            const workPromise = taskFn(ctx);

            const racedPromise = runOpts.timeoutMs
                ? Promise.race([
                    workPromise,
                    new Promise((_, reject) => {
                        timeoutTimer = setTimeout(() => {
                            timedOut = true;
                            controller.abort();
                            reject(new Error((runOpts.timeoutLabel || 'Task') + ' timed out'));
                        }, runOpts.timeoutMs);
                    }),
                ])
                : workPromise;

            return racedPromise.then(
                (result) => {
                    if (timeoutTimer) clearTimeout(timeoutTimer);
                    if (myToken !== token) return; // superseded by a newer run() — discard silently
                    if (state === 'cancelling') {
                        state = 'cancelled';
                        if (typeof opts.onCancelled === 'function') opts.onCancelled();
                        return;
                    }
                    state = 'completed';
                    if (typeof opts.onCompleted === 'function') opts.onCompleted(result);
                },
                (err) => {
                    if (timeoutTimer) clearTimeout(timeoutTimer);
                    if (myToken !== token) return; // superseded — discard silently, never surfaced as an error
                    if (!timedOut && state === 'cancelling' && err && err.name === 'AbortError') {
                        state = 'cancelled';
                        if (typeof opts.onCancelled === 'function') opts.onCancelled();
                        return;
                    }
                    // Anything else — including the timeout race above, and any
                    // AbortError NOT caused by our own cancel() — is a genuine failure.
                    state = 'failed';
                    if (typeof opts.onFailed === 'function') opts.onFailed(err);
                }
            );
        }

        return { run, cancel, getState };
    }

    global.CancellableTask = { create };
})(window);
