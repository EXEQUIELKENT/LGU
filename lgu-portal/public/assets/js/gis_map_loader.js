/**
 * GisMapLoader — shared bounds-based, clustered, debounced, cancellable
 * data loader for CIMM's Leaflet GIS map pages.
 * ─────────────────────────────────────────────────────────────────────────
 * Replaces the old pattern (still visible in git history) of embedding an
 * entire unbounded table's worth of rows into the page via inline
 * json_encode() and rendering one L.marker() per row up front. Instead:
 *   - Fetches only what's inside the current map viewport + active filters,
 *     from a small JSON endpoint (see public/api/requests-map.php and
 *     public/api/reports-map.php for examples of the expected response
 *     shape: { data: [...], count: N }).
 *   - Debounces moveend/zoomend so panning/zooming doesn't fire a request
 *     per pixel of movement.
 *   - Aborts the previous in-flight request (AbortController) the instant a
 *     newer one starts, and additionally checks a sequence token on
 *     resolution — belt-and-suspenders against a slow response arriving
 *     after a newer one already landed (abort doesn't guarantee the network
 *     layer drops an already-in-flight response on every browser/proxy).
 *   - Renders through Leaflet.markercluster (L.markerClusterGroup) instead
 *     of individual markers, with a single clearLayers()+addLayers() bulk
 *     swap per load — never one-marker-at-a-time churn, never a full map
 *     teardown/recreate.
 *
 * Usage:
 *   const loader = GisMapLoader.create({
 *       map,                         // an already-created L.Map
 *       endpoint: '../api/requests-map.php',
 *       idField: 'req_id',
 *       getFilterParams: () => ({ status: activeStatus, ... }),
 *       buildMarker: (item) => L.marker(...).bindPopup(...).on('click', ...),
 *       onLoadStart: () => {...},
 *       onLoadEnd: (count, items) => {...},
 *       onError: (err) => {...},
 *   });
 *   map.addLayer(loader.getClusterGroup());
 *   loader.refresh({ immediate: true }); // first paint
 *
 * A page with a second, fullscreen-clone map (e.g. requests.php's
 * #gisModalMap) creates a second, independent GisMapLoader.create() call —
 * each Leaflet map instance needs its own marker/cluster layer, per
 * Leaflet's per-map-instance model — sharing the same endpoint but each
 * with their own getFilterParams()/bounds.
 */
(function (global) {
    'use strict';

    function create(opts) {
        opts = opts || {};
        const map = opts.map;
        const endpoint = opts.endpoint;
        const idField = opts.idField || 'id';
        const debounceMs = opts.debounceMs != null ? opts.debounceMs : 350;
        const limit = opts.limit || 500;

        const clusterGroup = (typeof L !== 'undefined' && L.markerClusterGroup)
            ? L.markerClusterGroup(Object.assign({
                showCoverageOnHover: false,
                spiderfyOnMaxZoom: true,
                maxClusterRadius: 55,
              }, opts.clusterOptions || {}))
            : L.layerGroup(); // graceful fallback if the cluster plugin ever fails to load from CDN

        const itemsById = new Map();
        const markersById = new Map();

        let seq = 0;
        let controller = null;
        let debounceTimer = null;

        function buildQuery() {
            const b = map.getBounds();
            const params = new URLSearchParams();
            params.set('bounds', [b.getSouth(), b.getWest(), b.getNorth(), b.getEast()].join(','));
            params.set('limit', String(limit));
            const filters = typeof opts.getFilterParams === 'function' ? (opts.getFilterParams() || {}) : {};
            Object.keys(filters).forEach((key) => {
                const val = filters[key];
                if (val !== null && val !== undefined && val !== '' && val !== 'all') {
                    params.set(key, val);
                }
            });
            return params;
        }

        function doLoad() {
            const mySeq = ++seq;
            if (controller) controller.abort();
            controller = new AbortController();
            const myController = controller;

            if (typeof opts.onLoadStart === 'function') opts.onLoadStart();

            const url = endpoint + '?' + buildQuery().toString();
            fetch(url, { signal: myController.signal, credentials: 'same-origin' })
                .then((res) => res.json())
                .then((json) => {
                    if (mySeq !== seq) return; // superseded by a newer load — discard silently
                    const rows = (json && Array.isArray(json.data)) ? json.data : [];

                    itemsById.clear();
                    clusterGroup.clearLayers();
                    markersById.clear();

                    const newMarkers = [];
                    rows.forEach((item) => {
                        const id = item[idField];
                        if (id === undefined || id === null) return;
                        itemsById.set(id, item);
                        const marker = opts.buildMarker(item);
                        if (!marker) return;
                        markersById.set(id, marker);
                        newMarkers.push(marker);
                    });

                    if (typeof clusterGroup.addLayers === 'function') {
                        clusterGroup.addLayers(newMarkers);
                    } else {
                        newMarkers.forEach((m) => clusterGroup.addLayer(m));
                    }

                    if (typeof opts.onLoadEnd === 'function') opts.onLoadEnd(rows.length, rows);
                })
                .catch((err) => {
                    if (mySeq !== seq) return;
                    if (err && err.name === 'AbortError') return; // expected — a newer load superseded this one
                    if (typeof opts.onError === 'function') opts.onError(err);
                });
        }

        function refresh(refreshOpts) {
            refreshOpts = refreshOpts || {};
            if (refreshOpts.immediate) {
                clearTimeout(debounceTimer);
                doLoad();
                return;
            }
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(doLoad, debounceMs);
        }

        map.on('moveend', () => refresh());
        map.on('zoomend', () => refresh());

        return {
            refresh,
            getItem: (id) => itemsById.get(id),
            updateItem: (id, patchFn) => {
                const item = itemsById.get(id);
                if (!item) return;
                patchFn(item);
                if (typeof opts.refreshMarker === 'function') {
                    const marker = markersById.get(id);
                    if (marker) opts.refreshMarker(marker, item);
                }
            },
            getClusterGroup: () => clusterGroup,
            destroy: () => {
                clearTimeout(debounceTimer);
                if (controller) controller.abort();
                map.off('moveend');
                map.off('zoomend');
            },
        };
    }

    global.GisMapLoader = { create };
})(window);
