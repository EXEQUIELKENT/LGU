/**
 * ai_tfjs_analysis.js — InfraGovServices v4.1
 *
 * FIXES in v4.1 (severity/description no longer matched the actual photo):
 *  ! BUG — roadDamageScore (drives "Major road failure — structural collapse"
 *    verdicts) was driven almost entirely by darkVoidRatio: dark, low-saturation
 *    pixels in the lower ~55% of the frame. That test also fires on a dark pole
 *    shaft, wiring bundle, hydrant body, or shadow sitting in front of pavement —
 *    none of which are pavement damage. A photo of a traffic light against open
 *    sky could reach "severity 9 / major collapse" purely from shadow/pole pixels.
 *    Fixed by requiring concreteRatio (flat, low-saturation pavement texture) to
 *    corroborate the dark/grey area before roadDamageScore can reach full weight;
 *    without it, the score is capped at 35% of its raw value. See analyzePixels().
 *
 *  ! BUG — scoreClassifications() forced detectedType = 'Roads' whenever rds > 20,
 *    unconditionally — even when the model confidently detected a specific
 *    non-road object (e.g. "traffic light" 84%) and/or the citizen declared a
 *    non-Roads category. Now only overrides when the model signal is weak/absent
 *    or the declared type is already 'Roads'/'Other'.
 *
 *  ! BUG — COCO-SSD's COCO_INFRA map had 'traffic light' → 'Street Lights', while
 *    MobileNet's CLASS_MAP had the same label → 'Roads'. When both detectors fired
 *    on one photo they voted for two different infrastructure types, and whichever
 *    ran last could silently flip detectedType. Aligned both to 'Roads'.
 *
 *  ! BUG — buildDescription() recomputed its own infrastructure type from a single
 *    image's "most model matches" pick, which could disagree with the consensus-
 *    voted `detected_infrastructure` actually returned/stored. The description text
 *    (and its severity tier) could describe a different type than the badge shown
 *    next to it. Now always describes the same `bestType` used everywhere else.
 *
 * FIXES in v3.4 (road-image misclassification):
 *  ! BUG — CLASS_MAP: 'dam' had type:'Drainage'. MobileNet frequently hallucinates
 *    road embankments / shoulders as "dam, dike, dyke". Changed dam/dike/dyke to
 *    type:null so they contribute severity scoring but NEVER set the category.
 *
 *  ! BUG — TYPE_CONTEXT_GUARD was missing 'Drainage', 'Water Supply', and 'Other'
 *    from the guard lists for all water-barrier terms. When a user submitted with no
 *    declared type (→ 'Other'), the guard never fired and 'dam' hijacked detectedType.
 *    Added ALL_INFRA_TYPES constant; all water-barrier terms now guarded universally.
 *    Also added spillway, levee, weir, floodgate to the guard.
 *
 *  ! BUG — Pixel-based road override only fired when declaredType === 'Roads'.
 *    Empty/unknown declared type (→ 'Other') was not covered. Fixed to also override
 *    when declaredType is 'Other' and pixel evidence is road-like. Additionally,
 *    high rds (>20) now always sets detectedType = 'Roads' regardless of model output.
 *
 * CHANGES over v3.3:
 *  + COST ESTIMATION — new estimateCost() function returns a realistic
 *    Philippine Peso repair cost range based on infrastructure type,
 *    damage severity (1–10), repair complexity, and legitimacy score.
 *    Result is included in the returned object as `ai_cost_estimation`
 *    and saved to the `request_ai_analysis.ai_cost_estimation` column.
 *
 * All other logic (tensor disposal, pixel analysis, scoring, etc.) is
 * unchanged from v3.2.
 */

const InfraAI = (() => {

    // ─────────────────────────────────────────────────────────────────────────
    // 1. INFRASTRUCTURE CLASS MAP  (sv = base severity 1–10)
    //    v4.0: +30 entries — bridge/viaduct, canal/ditch/catch basin,
    //    fountain/faucet/valve/pump, lamp post/street lamp/halogen,
    //    pylon/fuse/junction box/utility pole/insulator, railing/handrail/
    //    bleachers, collapse/flood universals.
    // ─────────────────────────────────────────────────────────────────────────
    const CLASS_MAP = {
        // ── Roads ────────────────────────────────────────────────────────────
        'alley':             { type: 'Roads', sv: 4 },
        'asphalt':           { type: 'Roads', sv: 3 },
        'bridge':            { type: 'Roads', sv: 4 },   // v4.0: "steel arch bridge", "suspension bridge"
        'bulldozer':         { type: 'Roads', sv: 5 },
        'construction crane':{ type: 'Roads', sv: 5 },
        'crane':             { type: 'Roads', sv: 4 },
        'curb':              { type: 'Roads', sv: 3 },
        'gravel':            { type: 'Roads', sv: 4 },
        'guardrail':         { type: 'Roads', sv: 5 },
        'guard rail':        { type: 'Roads', sv: 5 },
        'manhole':           { type: 'Roads', sv: 5 },
        'manhole cover':     { type: 'Roads', sv: 5 },
        'mud':               { type: 'Roads', sv: 5 },
        'parking meter':     { type: 'Roads', sv: 2 },   // v4.0: road context (ImageNet n03891332)
        'pavement':          { type: 'Roads', sv: 3 },
        'pothole':           { type: 'Roads', sv: 7 },
        'road':              { type: 'Roads', sv: 4 },
        'rubble':            { type: 'Roads', sv: 6 },
        'sidewalk':          { type: 'Roads', sv: 3 },
        'steam shovel':      { type: 'Roads', sv: 5 },
        'street sign':       { type: 'Roads', sv: 3 },
        'tow truck':         { type: 'Roads', sv: 5 },
        'traffic light':     { type: 'Roads', sv: 4 },
        'traffic sign':      { type: 'Roads', sv: 3 },
        'viaduct':           { type: 'Roads', sv: 5 },   // v4.0: actual ImageNet class
        // ── Drainage ─────────────────────────────────────────────────────────
        'catch basin':       { type: 'Drainage', sv: 5 }, // v4.0
        'cistern':           { type: 'Drainage', sv: 4 },
        'culvert':           { type: 'Drainage', sv: 6 },
        // dam/dike/dyke: type:null — MobileNet frequently hallucinates these
        // for road embankments and flood barriers; lock to null so they never
        // force a wrong infrastructure type.
        'dam':               { type: null, sv: 7 },
        'dike':              { type: null, sv: 5 },
        'dyke':              { type: null, sv: 5 },
        'ditch':             { type: 'Drainage', sv: 4 }, // v4.0
        'gutter':            { type: 'Drainage', sv: 5 },
        'sewer':             { type: 'Drainage', sv: 7 },
        'storm drain':       { type: 'Drainage', sv: 6 },
        // ── Water Supply ─────────────────────────────────────────────────────
        'faucet':            { type: 'Water Supply', sv: 4 }, // v4.0
        'fire hydrant':      { type: 'Water Supply', sv: 5 },
        'fountain':          { type: 'Water Supply', sv: 3 }, // v4.0
        'pump':              { type: 'Water Supply', sv: 5 }, // v4.0
        'rain barrel':       { type: 'Water Supply', sv: 3 },
        'valve':             { type: 'Water Supply', sv: 5 }, // v4.0
        'water pipe':        { type: 'Water Supply', sv: 6 },
        'water tower':       { type: 'Water Supply', sv: 5 },
        // ── Street Lights ─────────────────────────────────────────────────────
        'fluorescent':       { type: 'Street Lights', sv: 3 },
        'halogen':           { type: 'Street Lights', sv: 3 }, // v4.0
        'lamp post':         { type: 'Street Lights', sv: 4 }, // v4.0
        'lamp shade':        { type: 'Street Lights', sv: 3 },
        'lampshade':         { type: 'Street Lights', sv: 3 },
        'lantern':           { type: 'Street Lights', sv: 3 },
        'light bulb':        { type: 'Street Lights', sv: 4 },
        'neon sign':         { type: 'Street Lights', sv: 3 },
        'pole':              { type: 'Street Lights', sv: 5 },
        'spotlight':         { type: 'Street Lights', sv: 4 },
        'street lamp':       { type: 'Street Lights', sv: 4 }, // v4.0
        'streetlamp':        { type: 'Street Lights', sv: 4 }, // v4.0
        'strobe':            { type: 'Street Lights', sv: 4 }, // v4.0
        'torch':             { type: 'Street Lights', sv: 4 },
        // ── Electrical ───────────────────────────────────────────────────────
        'cable':             { type: 'Electrical', sv: 5 },
        'coil':              { type: 'Electrical', sv: 4 },
        'fuse':              { type: 'Electrical', sv: 5 },    // v4.0
        'generator':         { type: 'Electrical', sv: 5 },
        'insulator':         { type: 'Electrical', sv: 4 },    // v4.0: ceramic insulator on lines
        'junction box':      { type: 'Electrical', sv: 5 },    // v4.0
        'power outlet':      { type: 'Electrical', sv: 5 },
        'power pole':        { type: 'Electrical', sv: 6 },
        'pylon':             { type: 'Electrical', sv: 6 },    // v4.0: electricity transmission pylon
        'transformer':       { type: 'Electrical', sv: 7 },
        'utility pole':      { type: 'Electrical', sv: 6 },    // v4.0
        'wire':              { type: 'Electrical', sv: 6 },
        // ── Public Facilities ─────────────────────────────────────────────────
        'bannister':         { type: 'Public Facilities', sv: 4 },
        'banister':          { type: 'Public Facilities', sv: 4 },
        'bleachers':         { type: 'Public Facilities', sv: 5 }, // v4.0
        'flagpole':          { type: 'Public Facilities', sv: 3 },
        'handrail':          { type: 'Public Facilities', sv: 4 }, // v4.0
        'park bench':        { type: 'Public Facilities', sv: 3 },
        'picket fence':      { type: 'Public Facilities', sv: 3 },
        'playground':        { type: 'Public Facilities', sv: 4 },
        'railing':           { type: 'Public Facilities', sv: 4 }, // v4.0
        'stadium':           { type: 'Public Facilities', sv: 4 }, // v4.0
        'toilet':            { type: 'Public Facilities', sv: 4 },
        'toilet seat':       { type: 'Public Facilities', sv: 4 },
        // ── Universal damage indicators (type: null) ──────────────────────────
        'bonfire':           { type: null, sv: 8 },
        'collapse':          { type: null, sv: 8 }, // v4.0
        'debris':            { type: null, sv: 6 },
        'flood':             { type: null, sv: 7 }, // v4.0
        'scaffold':          { type: null, sv: 4 },
        'wrecking ball':     { type: null, sv: 8 },
        'wreck':             { type: null, sv: 6 },
    };
    // ─────────────────────────────────────────────────────────────────────────
    // 2. NEGATIVE CLASS SUPPRESSION
    //    v4.0: +25 animal/vessel/scene classes that caused false-positive
    //    infrastructure matches (gondola, canoe, macaw, catfish, etc.).
    // ─────────────────────────────────────────────────────────────────────────
    const NEGATIVE_CLASSES = new Set([
        // Nature / landscape
        'geyser',    'volcano',   'alp',       'valley',    'canyon',
        'seashore',  'lakeside',  'sandbar',   'reef',      'lagoon',
        'tundra',    'savanna',   'rainforest','swamp',     'bog',
        'cumulus',   'sky',
        // Animals — common false positives on road/drainage/electrical images
        'chameleon', 'lizard',    'iguana',    'salamander','frog',
        'toad',      'tortoise',  'turtle',    'crocodile', 'alligator',
        'catfish',   'tench',     'goldfish',  'eel',       'shark',
        'stingray',  'jellyfish', 'sea urchin',
        'macaw',     'toucan',    'lorikeet',  'bee eater', 'kingfisher',
        'hummingbird','peacock',  'flamingo',  'pelican',   'cormorant',
        'zebra',     'tiger',     'lion',      'cheetah',   'leopard',
        'gorilla',   'orangutan', 'baboon',    'sloth bear',
        // Water vessels — confuse drainage / water supply photos
        'gondola',   'canoe',     'kayak',     'speedboat', 'catamaran',
        'schooner',  'rowboat',   'lifeboat',  'submarine', 'liner',
        'boathouse',
        // Unrelated objects
        'confetti',  'space shuttle', 'parachute', 'hot tub',
    ]);
    // ─────────────────────────────────────────────────────────────────────────
    // 2b. TYPE CONTEXT GUARD
    //     When a CLASS_MAP key fires, suppress it unless the declared type
    //     is one of the *allowed* types listed here (whitelist approach).
    //     Keys absent from this map are allowed for any infrastructure type.
    //     v4.0: added 'bridge', 'viaduct' guards.
    // ─────────────────────────────────────────────────────────────────────────
    const ALL_INFRA_TYPES = [
        'Roads','Drainage','Water Supply','Street Lights',
        'Electrical','Public Facilities','Other',
    ];
    const TYPE_CONTEXT_GUARD = {
        // ── Water / flood barriers: these appear in MobileNet for road embankments
        //    or irrigation features. Lock to null (universal) to prevent wrong type.
        'dam':      ALL_INFRA_TYPES,   // always null; guard is a safety belt
        'dike':     ALL_INFRA_TYPES,
        'dyke':     ALL_INFRA_TYPES,

        // ── Civil / structural — could be any infrastructure, but must not force
        //    a specific non-road type when road pixel evidence is absent.
        'scaffold': ALL_INFRA_TYPES,
        'crane':    ALL_INFRA_TYPES,
        'construction crane': ALL_INFRA_TYPES,
        'bulldozer':ALL_INFRA_TYPES,
        'steam shovel': ALL_INFRA_TYPES,
        'rubble':   ALL_INFRA_TYPES,
        'debris':   ALL_INFRA_TYPES,
        'wreck':    ALL_INFRA_TYPES,
        'wrecking ball': ALL_INFRA_TYPES,
        'collapse': ALL_INFRA_TYPES,

        // ── Bridges / viaducts: road infrastructure only; prevent a bridge photo
        //    from being categorised as Drainage or Water Supply.    [v4.0]
        'bridge':   ['Roads', 'Other'],
        'viaduct':  ['Roads', 'Other'],

        // ── Fauna: included only to ensure they can never slip through if the
        //    NEGATIVE_CLASSES set is bypassed (belt-and-suspenders).
        'ant':      ALL_INFRA_TYPES,
    };
    // ─────────────────────────────────────────────────────────────────────────
    const TYPE_KEYWORDS = {
        'Roads':             ['road','street','pavement','asphalt','pothole','curb',
                              'gravel','sidewalk','highway','lane','mud','manhole',
                              'rubble','alley'],
        'Street Lights':     ['light','lamp','bulb','lantern','torch','pole',
                              'spotlight','neon','flash','fluorescent'],
        'Drainage':          ['drain','sewer','gutter','manhole','flood','pipe',
                              'cistern','water','swamp','mud','culvert','catch'],
        'Water Supply':      ['water','pipe','tap','valve','cistern','tower',
                              'pump','hydrant'],
        'Electrical':        ['wire','cable','power','electric','transformer',
                              'coil','generator','circuit'],
        'Public Facilities': ['park','bench','church','school','stadium','bleacher',
                              'toilet','playground','library','fence','flagpole'],
        'Other':             [],
    };

    const PRIORITY_MAP   = [
        { min:8, label:'Critical' }, { min:6, label:'High' },
        { min:4, label:'Medium'  }, { min:0, label:'Low'  },
    ];
    const COMPLEXITY_MAP = [
        { min:8, label:'Major'   }, { min:6, label:'Complex'  },
        { min:3, label:'Moderate'}, { min:0, label:'Simple'   },
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // 4. MODEL MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────
    let _mobilenet   = null;
    let _cocoSsd     = null;
    let _loadPromise = null;

    async function loadModels(onProgress) {
        // Skip if models are already loaded and healthy
        if (_mobilenet) return;
        // If a load is already in flight, wait for it instead of starting a second one
        if (_loadPromise) { await _loadPromise; return; }

        try {
            _loadPromise = (async () => {
                onProgress?.('Loading AI model (1/2)…');
                _mobilenet = await mobilenet.load({ version: 2, alpha: 1.0 });
                if (typeof cocoSsd !== 'undefined') {
                    onProgress?.('Loading AI model (2/2)…');
                    try { _cocoSsd = await cocoSsd.load({ base: 'mobilenet_v2' }); }
                    catch (e) { console.warn('[InfraAI] COCO-SSD skipped:', e); }
                }
            })();
            await _loadPromise;
        } catch (err) {
            // Ensure models are fully reset so the next call can retry cleanly
            _mobilenet = null;
            _cocoSsd   = null;
            throw err;
        } finally {
            // ALWAYS clear _loadPromise after the attempt (success or failure).
            // Without this, a stale resolved Promise remains here forever.
            // When _mobilenet is later reset to null (see classifyImage fix below),
            // the stale _loadPromise would short-circuit loadModels and skip the
            // reload entirely — leaving _mobilenet null and crashing on classify().
            _loadPromise = null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. IMAGE PRE-PROCESSING
    // ─────────────────────────────────────────────────────────────────────────
    function prepareCanvasElement(img) {
        const canvas = document.createElement('canvas');
        canvas.width  = 224;
        canvas.height = 224;
        canvas.getContext('2d').drawImage(img, 0, 0, 224, 224);
        return canvas;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5b. IMAGE QUALITY ANALYSIS  [NEW v4.0]
    //     Laplacian-variance blur scoring. blurScore < 6 = very blurry,
    //     < 12 = moderately blurry, ≥ 20 = acceptably sharp.
    //     qualityPenalty (0–0.6) is subtracted from legitimacy_score and
    //     triggers 'blurry_images' / 'image_quality_poor' anomaly flags.
    // ─────────────────────────────────────────────────────────────────────────
    function analyzeQuality(img) {
        const canvas = prepareCanvasElement(img);
        const ctx    = canvas.getContext('2d');
        const { data } = ctx.getImageData(0, 0, 224, 224);
        const W = 224, H = 224;

        // Laplacian kernel: 4·C − T − B − L − R
        // The variance of the Laplacian = sharpness measure.
        let sumL2 = 0, cnt = 0;
        for (let py = 1; py < H - 1; py++) {
            for (let px = 1; px < W - 1; px++) {
                const c  = (py*W+px)*4;
                const lc = 0.299*data[c]   + 0.587*data[c+1]   + 0.114*data[c+2];
                const lt = 0.299*data[((py-1)*W+px)*4] + 0.587*data[((py-1)*W+px)*4+1] + 0.114*data[((py-1)*W+px)*4+2];
                const lb = 0.299*data[((py+1)*W+px)*4] + 0.587*data[((py+1)*W+px)*4+1] + 0.114*data[((py+1)*W+px)*4+2];
                const ll = 0.299*data[(py*W+px-1)*4]   + 0.587*data[(py*W+px-1)*4+1]   + 0.114*data[(py*W+px-1)*4+2];
                const lr = 0.299*data[(py*W+px+1)*4]   + 0.587*data[(py*W+px+1)*4+1]   + 0.114*data[(py*W+px+1)*4+2];
                const lap = 4*lc - lt - lb - ll - lr;
                sumL2 += lap * lap;
                cnt++;
            }
        }
        // Root-mean-square of Laplacian  ≈ standard deviation for zero-mean
        const blurScore = Math.sqrt(sumL2 / cnt);
        return {
            blurScore:       parseFloat(blurScore.toFixed(2)),
            isBlurry:        blurScore < 6,
            isSomewhatBlurry:blurScore < 12,
            // penalty subtracted from legitimacyScore; also reduces matchConfidence
            qualityPenalty:  blurScore < 6 ? 0.55 : blurScore < 12 ? 0.25 : 0.0,
        };
    }


    // ─────────────────────────────────────────────────────────────────────────
    // 6. PIXEL ANALYSIS — v3.2 (unchanged)
    // ─────────────────────────────────────────────────────────────────────────
    function analyzePixels(img) {
        const canvas = prepareCanvasElement(img);
        const ctx    = canvas.getContext('2d');
        const { data } = ctx.getImageData(0, 0, 224, 224);
        const W = 224, H = 224;

        const getL  = i => 0.299*data[i] + 0.587*data[i+1] + 0.114*data[i+2];
        const getSat = i => {
            const mx = Math.max(data[i],data[i+1],data[i+2]);
            const mn = Math.min(data[i],data[i+1],data[i+2]);
            return mx === 0 ? 0 : (mx-mn)/mx;
        };

        let brightness = 0, rMinusB = 0;
        let rustC = 0, waterC = 0, burnC = 0;
        const lum2d = new Float32Array(W * H);

        for (let py = 0; py < H; py++) {
            for (let px = 0; px < W; px++) {
                const i = (py*W+px)*4;
                const r = data[i], g = data[i+1], b = data[i+2];
                const l = 0.299*r + 0.587*g + 0.114*b;
                const s = getSat(i);
                lum2d[py*W+px] = l;
                brightness += l;
                rMinusB    += (r - b);

                if (r>155 && r>g*1.9 && r>b*2.8 && l>60 && l<155 && s>0.45) rustC++;
                if (b>r+12 && b>g+7  && l<85  && s<0.35) waterC++;
                if (l<32   && Math.abs(r-g)<13 && Math.abs(g-b)<13) burnC++;
            }
        }
        brightness /= (W*H);

        const meanRB = rMinusB / (W*H);
        let varRB = 0;
        for (let py = 0; py < H; py++) {
            for (let px = 0; px < W; px++) {
                const i = (py*W+px)*4;
                const v = (data[i] - data[i+2]) - meanRB;
                varRB += v*v;
            }
        }
        const globalColourVar = Math.sqrt(varRB / (W*H));

        const BOT_START = 100;
        const BOT_H     = H - BOT_START;
        const BOT_AREA  = W * BOT_H;

        let greyCount = 0;
        let sumGy_grey = 0, sumGx_grey = 0, greyPixelsSobel = 0;

        const lvBot = new Float32Array(W * BOT_H);
        for (let py = 0; py < BOT_H; py++) {
            for (let px = 0; px < W; px++) {
                const srcPy = py + BOT_START;
                const l = lum2d[srcPy*W+px];
                const i = (srcPy*W+px)*4;
                const s = getSat(i);
                if (s < 0.22 && l > 40 && l < 185) greyCount++;

                let sum = 0, sum2 = 0, cnt = 0;
                for (let dy = -1; dy <= 1; dy++) {
                    for (let dx = -1; dx <= 1; dx++) {
                        const ny = srcPy+dy, nx = px+dx;
                        if (ny>=0 && ny<H && nx>=0 && nx<W) {
                            const v = lum2d[ny*W+nx];
                            sum+=v; sum2+=v*v; cnt++;
                        }
                    }
                }
                lvBot[py*W+px] = sum2/cnt - (sum/cnt)*(sum/cnt);
            }
        }

        const greyCoverage = greyCount / BOT_AREA;

        for (let py = 1; py < BOT_H-1; py++) {
            for (let px = 1; px < W-1; px++) {
                const srcPy = py + BOT_START;
                const i = (srcPy*W+px)*4;
                const s = getSat(i);
                const l = lum2d[srcPy*W+px];
                if (s >= 0.22 || l < 40 || l > 185) continue;

                const gy = Math.abs(
                    -lum2d[(srcPy-1)*W+(px-1)] - 2*lum2d[(srcPy-1)*W+px] - lum2d[(srcPy-1)*W+(px+1)]
                    +lum2d[(srcPy+1)*W+(px-1)] + 2*lum2d[(srcPy+1)*W+px] + lum2d[(srcPy+1)*W+(px+1)]
                );
                const gx = Math.abs(
                    -lum2d[(srcPy-1)*W+(px-1)] - 2*lum2d[srcPy*W+(px-1)] - lum2d[(srcPy+1)*W+(px-1)]
                    +lum2d[(srcPy-1)*W+(px+1)] + 2*lum2d[srcPy*W+(px+1)] + lum2d[(srcPy+1)*W+(px+1)]
                );
                sumGy_grey += gy;
                sumGx_grey += gx;
                greyPixelsSobel++;
            }
        }

        const vertDom = greyPixelsSobel > 50
            ? (sumGy_grey/greyPixelsSobel + 1e-6) / (sumGx_grey/greyPixelsSobel + 1e-6)
            : 1.0;

        const greyLVs = [];
        for (let py = 0; py < BOT_H; py++) {
            for (let px = 0; px < W; px++) {
                const srcPy = py + BOT_START;
                const i = (srcPy*W+px)*4;
                const s = getSat(i);
                const l = lum2d[srcPy*W+px];
                if (s < 0.22 && l > 40 && l < 185) greyLVs.push(lvBot[py*W+px]);
            }
        }
        let roadLvCv = 0;
        if (greyLVs.length > 50) {
            const meanLV = greyLVs.reduce((a,b)=>a+b,0)/greyLVs.length;
            const stdLV  = Math.sqrt(greyLVs.map(v=>(v-meanLV)**2).reduce((a,b)=>a+b,0)/greyLVs.length);
            roadLvCv = stdLV / (meanLV + 1e-6);
        }

        let crackComponents = 0;
        const visited    = new Uint8Array(W * BOT_H);
        const darkInRoad = new Uint8Array(W * BOT_H);
        for (let py = 0; py < BOT_H; py++) {
            for (let px = 0; px < W; px++) {
                const srcPy = py + BOT_START;
                const l = lum2d[srcPy*W+px];
                const i = (srcPy*W+px)*4;
                const s = getSat(i);
                darkInRoad[py*W+px] = (l < 55 && s < 0.25) ? 1 : 0;
            }
        }
        for (let py = 0; py < BOT_H; py++) {
            for (let px = 0; px < W; px++) {
                if (!darkInRoad[py*W+px] || visited[py*W+px]) continue;
                crackComponents++;
                const queue = [[py,px]];
                visited[py*W+px] = 1;
                while (queue.length > 0) {
                    const [cy,cx] = queue.pop();
                    for (const [dy,dx] of [[-1,0],[1,0],[0,-1],[0,1]]) {
                        const ny=cy+dy, nx=cx+dx;
                        if (ny>=0&&ny<BOT_H&&nx>=0&&nx<W &&
                            darkInRoad[ny*W+nx]&&!visited[ny*W+nx]) {
                            visited[ny*W+nx]=1; queue.push([ny,nx]);
                        }
                    }
                }
            }
        }
        const crackDensity = crackComponents / BOT_AREA;

        // ── Dark void ratio ───────────────────────────────────────────────────
        // Fraction of the bottom road area that is dark void (l<55, s<0.25).
        // Key differentiator between damage levels:
        //   Thin surface crack  : darkVoidRatio ≈ 0.01–0.03  (narrow gap only)
        //   Edge cracking       : darkVoidRatio ≈ 0.04–0.10  (partial collapse)
        //   Major void/collapse : darkVoidRatio ≈ 0.15–0.35  (full structural failure)
        let _totalDarkVoid = 0;
        for (let _di = 0; _di < darkInRoad.length; _di++) _totalDarkVoid += darkInRoad[_di];
        const darkVoidRatio = _totalDarkVoid / BOT_AREA;

        const landscapePenalty = Math.min(1.0, globalColourVar / 35.0);
        const pixels = W * H;

        // Computed here (moved ahead of roadDamageScore) so it can corroborate
        // that heuristic below — see BUG note.
        let concreteC = 0;
        for (let i=0; i<data.length; i+=4) {
            const l = getL(i);
            const s = getSat(i);
            if (l>85 && l<175 && s<0.12 &&
                Math.abs(data[i]-data[i+1])<18 && Math.abs(data[i+1]-data[i+2])<18) concreteC++;
        }
        const concreteRatio = concreteC / pixels;

        let roadDamageScore = 0;
        if (greyCoverage > 0.10) {
            // Primary driver: dark void coverage.
            // Thin crack   : darkVoidRatio≈0.02 → 1.2 pts
            // Edge damage  : darkVoidRatio≈0.07 → 4.2 pts
            // Major collapse: darkVoidRatio≈0.25 → 15 pts
            roadDamageScore += darkVoidRatio * 60;
            // Texture disruption only amplifies when void area is already meaningful,
            // preventing a single thin crack from inflating the score via high local contrast.
            roadDamageScore += roadLvCv * Math.sqrt(Math.max(darkVoidRatio - 0.03, 0)) * 10;
            // Crack component density — very reduced weight vs old formula.
            roadDamageScore += crackDensity * 1500;
        }
        roadDamageScore *= (1.0 - landscapePenalty * 0.7);

        // ! BUG FIX — roadDamageScore was driven almost entirely by darkVoidRatio,
        // which is just "dark, low-saturation pixels in the lower ~55% of frame".
        // That test also matches a dark pole shaft, wiring bundle, hydrant body, or
        // shadow sitting in front of pavement — none of which are pavement damage.
        // This produced false "Major road failure — structural collapse" verdicts
        // on photos whose actual subject was a traffic light / pole / other fixture.
        // concreteRatio (flat, low-saturation, near-grey texture) corroborates that
        // the dark/grey area really is a paved surface; without it, cap the score at
        // 35% so a lone dark object can't reach "major collapse" severity on its own.
        const concreteCorroboration = Math.min(1.0, concreteRatio / 0.12);
        roadDamageScore *= (0.35 + 0.65 * concreteCorroboration);

        const rustRatio  = rustC  / pixels;
        const waterRatio = waterC / pixels;
        const burnRatio  = burnC  / pixels;

        let darkRoadC = 0;
        for (let py = BOT_START; py < H; py++) {
            for (let px = 0; px < W; px++) {
                const i = (py*W+px)*4;
                const s = getSat(i);
                const l = lum2d[py*W+px];
                if (s < 0.28 && l < 40 && l > 8) darkRoadC++;
            }
        }
        const darkRoadCoverage = darkRoadC / BOT_AREA;

        return {
            brightness, globalColourVar, greyCoverage, darkRoadCoverage,
            vertDom, roadDamageScore, crackDensity, roadLvCv,
            rustRatio, waterRatio, burnRatio, concreteRatio,
            landscapePenalty, darkVoidRatio,
            isDark:   brightness < 40,
            isBright: brightness > 220,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6b. PER-TYPE PIXEL ANALYSIS  [NEW v4.0]
    //     Returns type-specific damage signals (typePixelScore 0–10 + notes).
    //     Called once per image, after analyzePixels().  The results feed into
    //     severity, legitimacy, confidence, description, and anomaly flags.
    //     This is the primary reliability gain for non-Road infrastructure types
    //     where MobileNet rarely outputs relevant ImageNet class labels.
    // ─────────────────────────────────────────────────────────────────────────
    function analyzePixelsForType(img, declaredType, base) {
        const canvas = prepareCanvasElement(img);
        const ctx    = canvas.getContext('2d');
        const { data } = ctx.getImageData(0, 0, 224, 224);
        const W = 224, H = 224, TOTAL = W * H;

        const getL   = i => 0.299*data[i] + 0.587*data[i+1] + 0.114*data[i+2];
        const getSat = i => {
            const mx = Math.max(data[i],data[i+1],data[i+2]);
            const mn = Math.min(data[i],data[i+1],data[i+2]);
            return mx === 0 ? 0 : (mx-mn)/mx;
        };

        let typePixelScore = 0;
        let typePixelNotes = '';

        // ── DRAINAGE ─────────────────────────────────────────────────────────
        if (declaredType === 'Drainage') {
            // Water pooling in lower half: dark pixels with blue cast or low saturation
            let poolC = 0;
            for (let py = H >> 1; py < H; py++) {
                for (let px = 0; px < W; px++) {
                    const i = (py*W+px)*4;
                    const l = getL(i); const s = getSat(i);
                    if (l < 90 && (s < 0.30 || data[i+2] > data[i]+10)) poolC++;
                }
            }
            const poolRatio  = poolC / (TOTAL / 2);
            const totalWater = (base.waterRatio||0) + poolRatio * 0.55;
            if      (totalWater > 0.40) { typePixelScore = 8; typePixelNotes = 'Heavy water pooling / flooding visible'; }
            else if (totalWater > 0.25) { typePixelScore = 6; typePixelNotes = 'Significant water accumulation'; }
            else if (totalWater > 0.12) { typePixelScore = 4; typePixelNotes = 'Water / flooding evidence present'; }
            else if ((base.darkVoidRatio||0) > 0.05 && (base.concreteRatio||0) > 0.20)
                                        { typePixelScore = 5; typePixelNotes = 'Possible blocked drain or sump'; }
            else                        { typePixelScore = 1; }

        // ── ELECTRICAL / STREET LIGHTS ────────────────────────────────────────
        } else if (declaredType === 'Electrical' || declaredType === 'Street Lights') {
            // Vertical structure detection: poles / wires = strong vertical Sobel Gy
            // in upper 66 % of the image for dark-to-mid-tone pixels.
            const lum2d = new Float32Array(TOTAL);
            for (let i = 0; i < data.length; i += 4) lum2d[(i/4)|0] = getL(i);
            const TOP = (H * 0.66)|0;
            let vertGy = 0, vertGx = 0, vertCnt = 0;
            for (let py = 1; py < TOP - 1; py++) {
                for (let px = 1; px < W - 1; px++) {
                    const l = lum2d[py*W+px];
                    if (l < 25 || (l > 40 && l < 195)) {  // pole / wire tone range
                        const gy = Math.abs(
                            -lum2d[(py-1)*W+(px-1)] - 2*lum2d[(py-1)*W+px] - lum2d[(py-1)*W+(px+1)]
                            +lum2d[(py+1)*W+(px-1)] + 2*lum2d[(py+1)*W+px] + lum2d[(py+1)*W+(px+1)]
                        );
                        const gx = Math.abs(
                            -lum2d[(py-1)*W+(px-1)] - 2*lum2d[py*W+(px-1)] - lum2d[(py+1)*W+(px-1)]
                            +lum2d[(py-1)*W+(px+1)] + 2*lum2d[py*W+(px+1)] + lum2d[(py+1)*W+(px+1)]
                        );
                        vertGy += gy; vertGx += gx; vertCnt++;
                    }
                }
            }
            const vertRatio = vertCnt > 100 ? vertGy / (vertGx + 1e-6) : 1.0;
            const burn = base.burnRatio || 0;
            const rust = base.rustRatio || 0;

            if      (burn > 0.18 || (burn > 0.10 && rust > 0.15))
                                        { typePixelScore = 9; typePixelNotes = 'Burn / electrical fire damage detected'; }
            else if (rust > 0.28)       { typePixelScore = 7; typePixelNotes = 'Heavy corrosion on electrical component'; }
            else if (vertRatio > 2.0 && burn > 0.06)
                                        { typePixelScore = 7; typePixelNotes = 'Damaged vertical structure with char marks'; }
            else if (vertRatio > 1.9)   { typePixelScore = 5; typePixelNotes = 'Vertical structure (pole / wire) present'; }
            else if (rust > 0.15)       { typePixelScore = 5; typePixelNotes = 'Corrosion visible on structure'; }
            else if (burn > 0.06)       { typePixelScore = 4; typePixelNotes = 'Minor burn marks detected'; }
            else                        { typePixelScore = 1; }

        // ── WATER SUPPLY ──────────────────────────────────────────────────────
        } else if (declaredType === 'Water Supply') {
            // Leak spray (bright, near-neutral white patches)
            let sprayC = 0;
            for (let i = 0; i < data.length; i += 4) {
                if (getL(i) > 205 && getSat(i) < 0.08) sprayC++;
            }
            // Brown / ochre staining (yellow-brown discolouration around pipes)
            let stainC = 0;
            for (let i = 0; i < data.length; i += 4) {
                const r=data[i],g=data[i+1],b=data[i+2], l=getL(i);
                if (r>100&&r<210&&g>75&&g<155&&b<100&&l>65&&l<165&&r>g&&g>b) stainC++;
            }
            const combinedSignal = (base.waterRatio||0)
                + (base.rustRatio||0) * 0.7
                + (stainC/TOTAL) * 0.8
                + (sprayC/TOTAL) * 0.3;
            if      (combinedSignal > 0.45) { typePixelScore = 9; typePixelNotes = 'Burst or leaking pipe with flooding'; }
            else if (combinedSignal > 0.30) { typePixelScore = 6; typePixelNotes = 'Pipe leak with staining / corrosion'; }
            else if (combinedSignal > 0.18) { typePixelScore = 5; typePixelNotes = 'Water staining or corrosion visible'; }
            else if (combinedSignal > 0.08) { typePixelScore = 3; typePixelNotes = 'Minor moisture / corrosion signs'; }
            else                            { typePixelScore = 1; }

        // ── PUBLIC FACILITIES ─────────────────────────────────────────────────
        } else if (declaredType === 'Public Facilities') {
            // Full-image dark void (structural holes / cracks anywhere, not just road bottom)
            let fullVoid = 0;
            for (let i = 0; i < data.length; i += 4) {
                if (getL(i) < 45 && getSat(i) < 0.25) fullVoid++;
            }
            const voidR = fullVoid / TOTAL;
            const wear  = (base.rustRatio||0) + (base.waterRatio||0) + voidR * 2;
            if      (wear > 0.55 || voidR > 0.28) { typePixelScore = 8; typePixelNotes = 'Severe structural deterioration'; }
            else if (wear > 0.30 || voidR > 0.12) { typePixelScore = 5; typePixelNotes = 'Significant wear, rust or moisture damage'; }
            else if (wear > 0.15)                  { typePixelScore = 3; typePixelNotes = 'Visible deterioration'; }
            else                                   { typePixelScore = 1; }

        // ── ROADS (alias of base roadDamageScore) ────────────────────────────
        } else if (declaredType === 'Roads') {
            const rds = base.roadDamageScore || 0;
            if      (rds > 22) { typePixelScore = 9; typePixelNotes = 'Major structural collapse / surface loss'; }
            else if (rds > 13) { typePixelScore = 7; typePixelNotes = 'Significant cracking or heaving'; }
            else if (rds > 6)  { typePixelScore = 5; typePixelNotes = 'Surface cracking visible'; }
            else if (rds > 2)  { typePixelScore = 3; typePixelNotes = 'Surface irregularities detected'; }
            else               { typePixelScore = 1; }

        // ── OTHER / UNKNOWN ───────────────────────────────────────────────────
        } else {
            const rds  = base.roadDamageScore || 0;
            const rust = base.rustRatio       || 0;
            const burn = base.burnRatio       || 0;
            const comb = rds/20 + rust*3 + burn*4;
            if      (comb > 0.9) { typePixelScore = 7; typePixelNotes = 'Multiple damage indicators'; }
            else if (comb > 0.4) { typePixelScore = 5; typePixelNotes = 'Damage indicators present'; }
            else if (comb > 0.2) { typePixelScore = 3; typePixelNotes = 'Minor damage signals'; }
            else                 { typePixelScore = 1; }
        }

        return { typePixelScore, typePixelNotes };
    }


    // ─────────────────────────────────────────────────────────────────────────
    // 7. CLASSIFICATION
    // ─────────────────────────────────────────────────────────────────────────
    async function classifyImage(img) {
        const canvas = prepareCanvasElement(img);
        let mobilenetPreds;
        try {
            mobilenetPreds = await _mobilenet.classify(canvas, 20);
        } catch(e) {
            console.warn('[InfraAI] MobileNet classify failed — resetting models for next call:', e);
            // Reset ALL model state so loadModels() reloads cleanly on the next
            // analyzeImages() call. Without this, _mobilenet stays non-null (broken
            // model reference) and every subsequent call silently fails the same way.
            // _loadPromise must also be cleared here — Fix 1's finally{} clears it
            // after a load attempt, but if a prior successful load left it as a stale
            // resolved Promise it would short-circuit the next loadModels() call and
            // skip the reload even though _mobilenet is now null.
            _mobilenet   = null;
            _cocoSsd     = null;
            _loadPromise = null;
            mobilenetPreds = [];
        }

        let cocoDetections = [];
        if (_cocoSsd) {
            try {
                const cocoCanvas = prepareCanvasElement(img);
                cocoDetections = await _cocoSsd.detect(cocoCanvas);
            } catch(e) {
                console.warn('[InfraAI] COCO-SSD detect failed:', e);
                _cocoSsd = null;  // Reset so it reloads with the model on the next call
            }
        }

        return { mobilenetPreds, cocoDetections };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. SCORING ENGINE
    // ─────────────────────────────────────────────────────────────────────────
    function scoreClassifications(mobilenetPreds, cocoDetections, declaredType, pixelData) {
        let detectedType = null, maxWeighted = 0, totalScore = 0;
        const matched = [];
        let negHits = 0, posHits = 0;
        const keywords = TYPE_KEYWORDS[declaredType] || [];

        for (const pred of mobilenetPreds.slice(0, 5)) {
            const nl = pred.className.toLowerCase();
            for (const neg of NEGATIVE_CLASSES) {
                if (nl.includes(neg)) { negHits++; break; }
            }
        }

        for (const pred of mobilenetPreds) {
            const nl = pred.className.toLowerCase();
            let isNeg = false;
            for (const neg of NEGATIVE_CLASSES) {
                if (nl.includes(neg)) { isNeg = true; break; }
            }
            if (isNeg) continue;

            // Check context guard: the class can still contribute to severity scoring
            // but must NOT change detectedType when it is guarded for this declaredType.
            const _isGuarded = (key) => {
                const guarded = TYPE_CONTEXT_GUARD[key];
                return guarded && guarded.includes(declaredType);
            };

            if (CLASS_MAP[nl]) {
                const m     = CLASS_MAP[nl];
                const boost = computeBoost(nl, m, pred.probability, keywords, declaredType);
                if (boost > 0.3) {
                    matched.push({ className: pred.className, probability: pred.probability,
                                   key: nl, boost: m.sv });
                    if (m.type && boost > maxWeighted && !_isGuarded(nl)) { detectedType = m.type; maxWeighted = boost; }
                    totalScore += boost; posHits++;
                }
                continue;
            }

            for (const [key, mapping] of Object.entries(CLASS_MAP)) {
                const primaryToken = nl.split(',')[0].trim();
                if (nl.includes(key) || key.includes(primaryToken)) {
                    const boost = computeBoost(key, mapping, pred.probability, keywords, declaredType);
                    if (boost > 0.3) {
                        matched.push({ className: pred.className, probability: pred.probability,
                                       key, boost: mapping.sv });
                        if (mapping.type && boost > maxWeighted && !_isGuarded(key)) { detectedType = mapping.type; maxWeighted = boost; }
                        totalScore += boost; posHits++;
                    }
                    break;
                }
            }
        }

        const COCO_INFRA = {
            // v4.0: expanded from 6 → 11 classes
            // Road context (low sv — presence confirms road setting)
            'car':           { type: 'Roads',             sv: 1 },
            'truck':         { type: 'Roads',             sv: 2 },
            'bus':           { type: 'Roads',             sv: 2 },
            'motorcycle':    { type: 'Roads',             sv: 1 },  // v4.0
            'bicycle':       { type: 'Roads',             sv: 1 },  // v4.0
            // Signs & signals
            'stop sign':     { type: 'Roads',             sv: 4 },
            // ! BUG FIX — this disagreed with CLASS_MAP's 'traffic light' → 'Roads'
            // (used for MobileNet's own "traffic light" label). When both MobileNet
            // and COCO-SSD detected the same object in one photo, they voted for two
            // different infrastructure types, and whichever ran last could silently
            // flip detectedType. Traffic signals are road/traffic-control infrastructure
            // in this app's categories, not street illumination — aligned to 'Roads'.
            'traffic light': { type: 'Roads',             sv: 4 },
            'parking meter': { type: 'Roads',             sv: 2 },  // v4.0
            // Water infrastructure
            'fire hydrant':  { type: 'Water Supply',      sv: 5 },
            // Public facilities
            'bench':         { type: 'Public Facilities', sv: 3 },
            'chair':         { type: 'Public Facilities', sv: 2 },  // v4.0 outdoor/park
        }
        for (const det of cocoDetections) {
            if (det.score < 0.55) continue;
            const m = COCO_INFRA[det.class];
            if (m) {
                const boost = m.sv * det.score * 0.6;
                matched.push({ className:'COCO:'+det.class, probability: det.score,
                               key: det.class, boost: m.sv });
                if (m.type && boost > maxWeighted) { detectedType = m.type; maxWeighted = boost; }
                totalScore += boost; posHits++;
            }
        }

        const greyCov = pixelData ? (pixelData.greyCoverage || 0) : 0;

        let pixelSeverityBonus = 0;
        if (pixelData) {
            const rds       = pixelData.roadDamageScore || 0;
            const voidRatio = pixelData.darkVoidRatio   || 0;
            // Only grant road-pixel bonus when rds is meaningful (thin cracks ≈ rds < 2 don't qualify)
            if (greyCov > 0.10 && rds > 2) {
                if      (rds > 20) {
                    pixelSeverityBonus += 3;
                    // ! BUG FIX — this used to override detectedType unconditionally, even
                    // when the model confidently detected a specific non-road object (e.g.
                    // "traffic light" 84%) and/or the citizen declared a non-Roads category.
                    // Pixel evidence should only override a *weak or absent* model signal,
                    // not silently replace a confident, unguarded classification.
                    if (!detectedType || declaredType === 'Roads' || declaredType === 'Other' || maxWeighted < 1.5) {
                        detectedType = 'Roads';
                    }
                }
                else if (rds > 12) {
                    pixelSeverityBonus += 2;
                    // Significant damage: override only if model gave a water-structure label
                    // or the declared type is unknown ('Other').
                    if (!detectedType || declaredType === 'Roads' || declaredType === 'Other') {
                        detectedType = 'Roads';
                    }
                }
                else if (rds > 6)  { pixelSeverityBonus += 1; if (!detectedType) detectedType = 'Roads'; }
            }
            if (pixelData.concreteRatio > 0.30 && !detectedType) detectedType = 'Roads';
            // If pixel evidence is strongly road-like (lots of grey surface) and the model
            // produced a non-road type via an unguarded label, road pixels take back the
            // detected type.  This now also fires when declaredType is 'Other' (no type
            // declared), which was the gap that allowed 'dam' → 'Drainage' to slip through.
            const NON_ROAD_TYPES = ['Drainage', 'Water Supply', 'Street Lights', 'Electrical', 'Public Facilities'];
            if (greyCov > 0.20 && rds > 3 && detectedType && NON_ROAD_TYPES.includes(detectedType)) {
                if (declaredType === 'Roads' || declaredType === 'Other' || !declaredType) {
                    detectedType = 'Roads';
                }
            }
            if (posHits > 0) {
                if      (pixelData.rustRatio  > 0.20) pixelSeverityBonus += 2;
                else if (pixelData.rustRatio  > 0.10) pixelSeverityBonus += 1;
                if      (pixelData.waterRatio > 0.28) pixelSeverityBonus += 2;
                else if (pixelData.waterRatio > 0.18) pixelSeverityBonus += 1;
                // Suppress burn bonus when large dark voids explain the darkness (collapse ≠ fire)
                if ((pixelData.darkVoidRatio || 0) < 0.08) {
                    if      (pixelData.burnRatio  > 0.14) pixelSeverityBonus += 3;
                    else if (pixelData.burnRatio  > 0.09) pixelSeverityBonus += 1;
                }
            }
        }

        if (!detectedType) detectedType = declaredType;

        let rawConf = Math.min(totalScore / 5.0, 1.0);
        if      (negHits >= 3 && posHits === 0) rawConf = 0.0;
        else if (negHits >= 2)                  rawConf *= 0.4;

        return {
            detectedType, matched, totalScore, confidence: rawConf,
            pixelSeverityBonus, positiveHits: posHits, negativeHits: negHits,
        };
    }

    function computeBoost(key, mapping, probability, keywords, declaredType) {
        // v4.0 keyword matching improvements:
        //  • Short keywords (≤4 chars) use exact equality to prevent 'mud' matching
        //    'mudskipper', 'pipe' matching 'bagpipe', etc.
        //  • Longer keywords still use substring inclusion (handles multi-word labels).
        //  • +15 % bonus for high-probability predictions (>15 %) — model is more
        //    certain, so boost the signal accordingly.
        let base = mapping.sv * probability;
        const hasKwMatch = keywords.some(kw => {
            if (kw.length <= 4) return key === kw || key.startsWith(kw + ' ');
            return key.includes(kw) || kw.includes(key);
        });
        if (hasKwMatch)              base *= 1.8;
        if (mapping.type === declaredType) base *= 1.4;
        if (probability > 0.15)      base *= 1.15;   // v4.0: reward high-confidence hits
        return base;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8b. MULTI-IMAGE CONSENSUS VOTING  [NEW v4.0]
    //     Each image casts a weighted vote for the infrastructure type it
    //     most strongly supports.  Weight = confidence + typePixelScore bonus.
    //     A strong consensus (>65 % of vote weight on one type) earns a
    //     +0.12 confidence bonus.  A split vote raises 'multi_image_type_conflict'
    //     and reduces legitimacy by 0.08.  For 3+ image evidence sets this is
    //     the single largest accuracy improvement over v3.4.
    // ─────────────────────────────────────────────────────────────────────────
    function computeConsensus(scoreResults, declaredType) {
        const votes = {};
        let totalWeight = 0;
        for (const r of scoreResults) {
            const type   = r.score.detectedType || declaredType;
            // Confidence forms the base weight; typePixelScore adds up to 0.12 extra.
            const weight = Math.max(r.score.confidence, 0.02)
                         + Math.min((r.typePixels?.typePixelScore || 0) / 80, 0.12);
            votes[type]  = (votes[type] || 0) + weight;
            totalWeight += weight;
        }
        let winnerType = declaredType, maxVote = 0;
        for (const [type, vote] of Object.entries(votes)) {
            if (vote > maxVote) { maxVote = vote; winnerType = type; }
        }
        const consensusStrength = totalWeight > 0 ? maxVote / totalWeight : 1.0;
        // A type with < 15 % of vote weight does not count as a conflict.
        const significantTypes  = Object.keys(votes).filter(t =>
            (votes[t] / totalWeight) > 0.15
        );
        const hasConflict = significantTypes.length > 1;
        // Bonus: strong agreement across ≥ 2 images earns a confidence bump.
        const consensusBonus = scoreResults.length >= 2
            ? Math.max(0, (consensusStrength - 0.50) * 0.24)
            : 0;
        return { winnerType, consensusStrength, hasConflict, votes, consensusBonus };
    }


    // ─────────────────────────────────────────────────────────────────────────
    // 9. SEVERITY CALCULATOR
    // ─────────────────────────────────────────────────────────────────────────
    function calculateSeverity(scoreResults) {
        // v4.0: r.typePixels.typePixelScore now feeds into severity so non-road
        // types (electrical burns, drainage flooding, etc.) drive severity correctly
        // instead of relying solely on roadDamageScore.
        let maxSeverity = 1;
        const avgConf = scoreResults.reduce((s,r) => s+r.score.confidence, 0) / scoreResults.length;

        for (const r of scoreResults) {
            let sv = 1;
            const rds       = r.pixels.roadDamageScore || 0;
            const greyCov   = r.pixels.greyCoverage    || 0;
            const voidRatio = r.pixels.darkVoidRatio   || 0;
            const tps       = r.typePixels?.typePixelScore || 0;  // v4.0

            // ── Road pixel severity ───────────────────────────────────────────
            if (greyCov > 0.10 && rds > 0) {
                if      (rds > 22) sv = Math.max(sv, 9);
                else if (rds > 13) sv = Math.max(sv, 7);
                else if (rds > 6)  sv = Math.max(sv, 5);
                else if (rds > 2)  sv = Math.max(sv, 3);
                else               sv = Math.max(sv, 1);
                if (voidRatio < 0.04 && rds < 6) sv = Math.min(sv, 4);
            }

            // ── Type-specific pixel severity (v4.0) ──────────────────────────
            // tps 9→sv 8, tps 7→sv 6, tps 5→sv 4  (scale: tps * 0.88, rounded)
            if (tps > 0) sv = Math.max(sv, Math.round(tps * 0.88));

            // ── Model-based severity ──────────────────────────────────────────
            if (r.score.positiveHits > 0 && r.score.matched.length > 0) {
                const total   = r.score.matched.reduce((s,m) => s + m.boost*m.probability, 0);
                const count   = r.score.matched.reduce((s,m) => s + m.probability, 0);
                const modelSv = Math.round(total / Math.max(count, 0.01));
                sv = Math.max(sv, modelSv);
                // Cap model-driven severity when pixel evidence is absent
                if (voidRatio < 0.04 && greyCov < 0.15 && tps < 4)
                    sv = Math.min(sv, 5);
            }

            // ── Pixel severity bonus (fires when any evidence present) ────────
            if (r.score.positiveHits > 0 || (greyCov > 0.10 && rds > 2) || tps > 3) {
                sv += r.score.pixelSeverityBonus;
            }

            if (r.pixels.isDark && r.score.positiveHits > 0) sv += 1;
            sv = Math.max(1, Math.min(10, sv));
            if (sv > maxSeverity) maxSeverity = sv;
        }

        // Multi-image upward bump when confidence is strong
        if (scoreResults.length >= 3 && avgConf > 0.15)
            maxSeverity = Math.min(maxSeverity + 1, 10);

        // Sanity-cap: if no pixel evidence at all (road or type-specific), limit ceiling
        const anyPixelDriven = scoreResults.some(r =>
            ((r.pixels.greyCoverage||0) > 0.10 &&
             (r.pixels.roadDamageScore||0) > 4  &&
             (r.pixels.darkVoidRatio||0)  > 0.04)
            || (r.typePixels?.typePixelScore || 0) >= 4   // v4.0: non-road pixel evidence
        );
        if (!anyPixelDriven) {
            if      (avgConf < 0.12) maxSeverity = Math.min(maxSeverity, 3);
            else if (avgConf < 0.20) maxSeverity = Math.min(maxSeverity, 5);
        }
        return Math.max(1, maxSeverity);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. COST ESTIMATION  ← NEW in v3.3
    //
    //  Returns a realistic Philippine Peso cost RANGE string formatted as
    //  "₱X,XXX – ₱XX,XXX" based on:
    //    • infraType   — determines which cost table to use
    //    • severity    — (1–10) positions estimate within the range
    //    • complexity  — ('Simple'|'Moderate'|'Complex'|'Major') selects tier
    //    • legitimacy  — low legitimacy → wider/lower estimate band
    //    • pixelData   — pixel signals fine-tune within the tier
    //
    //  Cost ranges are based on Philippine DPWH unit cost schedules,
    //  Quezon City LGU project records, and PhilGEPS contractor rates (2024).
    // ─────────────────────────────────────────────────────────────────────────
    function estimateCost(infraType, severity, complexity, legitimacyScore, pixelData) {
        // ── Cost tiers per infrastructure type (in PHP) ──────────────────────
        // Each tier: [low, high]
        // Sources: DPWH Unit Cost Guide 2023, QC LGU procurement data, PhilGEPS
        const COST_TABLE = {
            'Roads': {
                'Simple':   [  1_500,     9_000],  // crack sealing, single pothole patch
                'Moderate': [ 12_000,   120_000],  // multi-patch, base repair, subsidence fix
                'Complex':  [180_000,   900_000],  // lane resurfacing, drainage + road combo
                'Major':    [900_000, 6_000_000],  // full road section reconstruction
            },
            'Street Lights': {
                'Simple':   [    800,     4_500],  // LED bulb/driver swap, minor wiring
                'Moderate': [  5_000,    25_000],  // fixture replacement, ballast, arm repair
                'Complex':  [ 30_000,   150_000],  // concrete pole replacement, cable trench
                'Major':    [150_000,   750_000],  // multi-pole replacement, transformer work
            },
            'Drainage': {
                'Simple':   [  2_000,    14_000],  // cleaning, debris clearing, minor patch
                'Moderate': [ 15_000,   100_000],  // section repair, concrete lining, grout
                'Complex':  [120_000,   650_000],  // full culvert replacement, channel reline
                'Major':    [650_000, 4_000_000],  // full drainage system overhaul
            },
            'Water Supply': {
                'Simple':   [  1_500,    10_000],  // joint leak fix, valve seal, connection
                'Moderate': [ 12_000,    85_000],  // pipe section replacement, meter vault
                'Complex':  [ 90_000,   500_000],  // main pipe relay, pump station repair
                'Major':    [500_000, 2_800_000],  // distribution main, reservoir work
            },
            'Electrical': {
                'Simple':   [  1_200,     8_000],  // loose connection, breaker, minor wiring
                'Moderate': [  9_000,    65_000],  // panel board, metering equipment repair
                'Complex':  [ 70_000,   400_000],  // transformer pad, HV cable section
                'Major':    [400_000, 2_200_000],  // substation equipment, major HV work
            },
            'Public Facilities': {
                'Simple':   [    500,     5_000],  // bench, signage, fence post repair
                'Moderate': [  6_000,    50_000],  // restroom fixture, pavillion roof patch
                'Complex':  [ 55_000,   300_000],  // full restroom rehab, bleacher section
                'Major':    [300_000, 1_800_000],  // complete facility reconstruction
            },
            'Other': {
                'Simple':   [  1_000,     8_000],
                'Moderate': [ 10_000,    80_000],
                'Complex':  [ 80_000,   450_000],
                'Major':    [450_000, 2_500_000],
            },
        };

        // Guard: fall back to 'Other' if type not found
        const infraKey = COST_TABLE[infraType] ? infraType : 'Other';

        // Guard: normalise complexity
        const validComplexities = ['Simple', 'Moderate', 'Complex', 'Major'];
        const compKey = validComplexities.includes(complexity) ? complexity : 'Moderate';

        const [tierLo, tierHi] = COST_TABLE[infraKey][compKey];

        // ── Position within tier using severity (1–10) ───────────────────────
        // severity 1 → 10% into tier, severity 10 → 90% into tier
        // This avoids always hitting the extremes and keeps it realistic.
        const sevFactor = ((severity - 1) / 9.0) * 0.80 + 0.10;  // 0.10 … 0.90
        const midpoint  = tierLo + (tierHi - tierLo) * sevFactor;

        // ── Legitimacy adjustment ────────────────────────────────────────────
        // Low legitimacy → we're less confident, so we widen the band and
        // shift the midpoint slightly lower (less severe assumed).
        const legitFactor = 0.75 + (legitimacyScore * 0.25);  // 0.75 … 1.00
        const adjusted    = midpoint * legitFactor;

        // ── Pixel signal micro-adjustment ────────────────────────────────────
        let pixelMultiplier = 1.0;
        if (pixelData) {
            const rds = pixelData.roadDamageScore || 0;
            const gc  = pixelData.greyCoverage    || 0;
            if (gc > 0.10 && rds > 15)   pixelMultiplier = 1.15;  // severe cracking
            else if (gc > 0.10 && rds > 8) pixelMultiplier = 1.08;
            if (pixelData.burnRatio  > 0.14 && (pixelData.darkVoidRatio || 0) < 0.08) pixelMultiplier *= 1.12; // fire damage
            if (pixelData.rustRatio  > 0.20) pixelMultiplier *= 1.08; // heavy corrosion
            if (pixelData.waterRatio > 0.28) pixelMultiplier *= 1.05; // flood damage
        }
        const finalMid = adjusted * pixelMultiplier;

        // ── Build symmetric ±20 % range clamped to tier ──────────────────────
        const bandPct = (legitimacyScore < 0.4) ? 0.30 : 0.20;  // wider band if uncertain
        let rangeLo = Math.max(tierLo, Math.round(finalMid * (1.0 - bandPct)));
        let rangeHi = Math.min(tierHi, Math.round(finalMid * (1.0 + bandPct)));

        // Ensure the range is at least ₱500 wide (avoids "₱X – ₱X")
        if (rangeHi - rangeLo < 500) rangeHi = rangeLo + 500;

        // ── Round to nearest clean number for readability ─────────────────────
        const roundTo = (n, nearest) => Math.round(n / nearest) * nearest;
        if      (rangeHi > 500_000) { rangeLo = roundTo(rangeLo, 10_000); rangeHi = roundTo(rangeHi, 10_000); }
        else if (rangeHi > 50_000)  { rangeLo = roundTo(rangeLo,  5_000); rangeHi = roundTo(rangeHi,  5_000); }
        else if (rangeHi > 10_000)  { rangeLo = roundTo(rangeLo,  1_000); rangeHi = roundTo(rangeHi,  1_000); }
        else                        { rangeLo = roundTo(rangeLo,    500); rangeHi = roundTo(rangeHi,    500); }

        // Clamp one final time after rounding
        rangeLo = Math.max(tierLo, rangeLo);
        rangeHi = Math.min(tierHi, rangeHi);
        if (rangeHi <= rangeLo) rangeHi = rangeLo + (rangeHi > 50_000 ? 5_000 : 500);

        // ── Format as Philippine Peso ─────────────────────────────────────────
        const fmt = n => '₱' + n.toLocaleString('en-PH');
        return `${fmt(rangeLo)} – ${fmt(rangeHi)}`;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. RESULT HELPERS  [v4.0: per-type descriptions, multi-factor legitimacy,
    //     expanded anomaly flags including blur, quality, and consensus signals]
    // ─────────────────────────────────────────────────────────────────────────

    // Per-type, per-severity actionable damage descriptions for field employees.
    const DAMAGE_DESCRIPTIONS = {
        'Roads': {
            8: 'Major road failure — structural collapse, deep voids, or extensive surface loss posing immediate danger.',
            6: 'Significant road damage — multiple cracks, heaving, or subsidence affecting surface integrity. Priority repair needed.',
            4: 'Moderate road surface damage — visible cracking, edge deterioration, or potholing. Schedule for repair.',
            2: 'Minor road surface irregularities — hairline cracks or surface wear. Flag for monitoring.',
            0: 'Road infrastructure visible. Condition assessment requires field inspection.',
        },
        'Drainage': {
            8: 'Severe drainage failure — flooding, blockage, or structural damage to drainage infrastructure. Risk to property and roads.',
            6: 'Significant drainage issue — overflow, partial blockage, or cracked drainage channel visible.',
            4: 'Moderate drainage problem — debris accumulation or minor damage to drain or culvert.',
            2: 'Minor drainage irregularity — possible partial blockage or surface wear on drain.',
            0: 'Drainage infrastructure visible. Field inspection needed for condition assessment.',
        },
        'Electrical': {
            8: 'Critical electrical hazard — downed wire, fire damage, or exposed live components detected. Immediate danger to public.',
            6: 'Significant electrical infrastructure damage — transformer, panel, or cable damage with potential service disruption.',
            4: 'Moderate electrical issue — corrosion, wear, or mechanical damage to electrical components.',
            2: 'Minor electrical infrastructure concern — surface deterioration or loose component.',
            0: 'Electrical infrastructure visible. Condition needs field verification.',
        },
        'Street Lights': {
            8: 'Street light infrastructure critically damaged — collapsed pole, fire damage, or complete fixture failure.',
            6: 'Significant street lighting problem — broken fixture, bent or damaged pole, or wiring fault.',
            4: 'Moderate street lighting issue — non-functional lamp, damaged cover, or minor pole damage.',
            2: 'Minor street lighting defect — bulb failure, minor corrosion, or loose fittings.',
            0: 'Street light infrastructure visible. Operational status needs field check.',
        },
        'Water Supply': {
            8: 'Critical water supply failure — burst pipe, major leak, or flooding from water infrastructure. Immediate response needed.',
            6: 'Significant water supply damage — pipe leakage, valve failure, or major corrosion evident.',
            4: 'Moderate water supply issue — minor leak, corrosion staining, or joint damage.',
            2: 'Minor water supply irregularity — surface moisture or light corrosion on fittings.',
            0: 'Water supply infrastructure visible. Condition needs field inspection.',
        },
        'Public Facilities': {
            8: 'Severe public facility damage — structural failure, safety hazard, or major component collapse.',
            6: 'Significant public facility damage — major wear, vandalism, or structural degradation.',
            4: 'Moderate facility deterioration — visible damage to fixtures, surfaces, or structural elements.',
            2: 'Minor facility defect — cosmetic damage or minor wear to public infrastructure.',
            0: 'Public facility visible. Condition assessment needed.',
        },
        'Other': {
            8: 'Severe infrastructure damage detected. Immediate assessment and action required.',
            6: 'Significant infrastructure damage. Priority inspection recommended.',
            4: 'Moderate infrastructure damage. Scheduled repair warranted.',
            2: 'Minor infrastructure concern. Monitor for progression.',
            0: 'Infrastructure visible. Manual inspection required for assessment.',
        },
    };

    function buildDescription(scoreResults, severity, declaredType, bestTypePixels, qualityResults, bestType) {
        // Pick the result with the most model matches to extract top detected keys
        // (used only for notes/keywords below — NOT for the infra type; see next line).
        const best = scoreResults.reduce((b, r) =>
            r.score.matched.length > b.score.matched.length ? r : b, scoreResults[0]);
        // ! BUG FIX — this used to recompute its own type from a single image's top
        // match ("most matches" pick), which could disagree with the consensus-voted
        // `detected_infrastructure` returned by analyzeImages(). That produced reports
        // where the badge said one infrastructure type but the description text (and
        // its severity tier) described a different one. Always describe the same type
        // that's actually reported as detected.
        const infra = bestType || best.score.detectedType || declaredType;
        const tierKey = severity >= 8 ? 8 : severity >= 6 ? 6 : severity >= 4 ? 4 : severity >= 2 ? 2 : 0;
        const typeMap = DAMAGE_DESCRIPTIONS[infra] || DAMAGE_DESCRIPTIONS['Other'];
        let desc = typeMap[tierKey];

        // Inline signals appended in parentheses for employee context.
        const notes = [];
        if (bestTypePixels?.typePixelNotes) notes.push(bestTypePixels.typePixelNotes);
        const topKeys = [...new Set(best.score.matched.slice(0, 3).map(m => m.key))];
        if (topKeys.length) notes.push('Detected: ' + topKeys.join(', '));
        if ((best.pixels.rustRatio  || 0) > 0.10) notes.push('corrosion present');
        if ((best.pixels.waterRatio || 0) > 0.20) notes.push('water/flooding evidence');
        if ((best.pixels.burnRatio  || 0) > 0.09 &&
            (best.pixels.darkVoidRatio || 0) < 0.10) notes.push('burn marks');
        if (qualityResults?.some(q => q.isBlurry)) notes.push('image quality poor — verify in field');
        if (scoreResults.length > 1) notes.push(`${scoreResults.length} images analysed`);
        if (notes.length) desc += ' (' + notes.join('; ') + ')';
        return desc.slice(0, 255);
    }

    function buildLegitimacyNotes(avgConf, scoreResults, avgTypePixelScore, qualityResults) {
        const notes = [];
        const allBlurry = qualityResults?.every(q => q.isBlurry);
        const anyBlurry = qualityResults?.some(q => q.isBlurry);
        if (allBlurry)       notes.push('All images blurry — evidence quality poor');
        else if (anyBlurry)  notes.push('Some images blurry');

        const anyRoadDmg = scoreResults.some(r =>
            (r.pixels.greyCoverage || 0) > 0.10 && (r.pixels.roadDamageScore || 0) > 4);
        if (anyRoadDmg) notes.push('Road structural damage confirmed via pixel analysis');
        if (avgTypePixelScore >= 4)
            notes.push(`Infrastructure-specific damage detected (pixel score: ${avgTypePixelScore.toFixed(1)}/10)`);
        const totalPos = scoreResults.reduce((s, r) => s + r.score.positiveHits, 0);
        if (avgConf < 0.05 && totalPos === 0 && avgTypePixelScore < 3)
            return 'No infrastructure indicators detected. Image may be unrelated. Manual review required.';
        if (avgConf < 0.12 && avgTypePixelScore < 3)
            notes.push(`Low AI confidence (${Math.round(avgConf * 100)}%)`);
        else if (avgConf >= 0.12)
            notes.push(`AI confidence ${Math.round(avgConf * 100)}%`);
        return notes.length ? notes.join('. ') + '.' : 'Manual review recommended.';
    }

    function buildAnomalyFlags(scoreResults, severity, qualityResults, consensus) {
        const flags = [];
        const avgConf = scoreResults.reduce((s,r) => s+r.score.confidence, 0) / scoreResults.length;
        // ── Image quality (v4.0) ──────────────────────────────────────────────
        if (qualityResults?.some(q => q.isBlurry))  flags.push('blurry_images');
        if (qualityResults?.every(q => q.isBlurry)) flags.push('image_quality_poor');
        // ── Exposure ──────────────────────────────────────────────────────────
        if (scoreResults.every(r => r.pixels.isDark))   flags.push('all_images_dark');
        if (scoreResults.every(r => r.pixels.isBright)) flags.push('all_images_overexposed');
        // ── Detection ─────────────────────────────────────────────────────────
        const noSignal = scoreResults.every(r =>
            r.score.positiveHits === 0 &&
            (r.pixels.roadDamageScore || 0) < 2 &&
            (r.typePixels?.typePixelScore || 0) < 3
        );
        if (noSignal)   flags.push('no_infrastructure_detected');
        if (avgConf < 0.08 && noSignal) flags.push('low_model_confidence');
        if (scoreResults.every(r =>
            r.score.negativeHits >= 3 &&
            ((r.pixels.greyCoverage||0) + (r.pixels.darkRoadCoverage||0)) < 0.05 &&
            (r.typePixels?.typePixelScore||0) < 3
        )) flags.push('non_infrastructure_images');
        // ── Multi-image (v4.0) ────────────────────────────────────────────────
        if (scoreResults.length === 1)     flags.push('single_image_evidence');
        if (consensus?.hasConflict)        flags.push('multi_image_type_conflict');
        // ── Damage specifics ──────────────────────────────────────────────────
        if (severity >= 8)                 flags.push('immediate_action_required');
        if (scoreResults.some(r =>
            (r.pixels.roadDamageScore||0) > 10 && (r.pixels.greyCoverage||0) > 0.10
        ))                                 flags.push('road_structural_damage');
        if (scoreResults.some(r =>
            r.pixels.burnRatio > 0.18 && (r.pixels.landscapePenalty||1) < 0.6
        ))                                 flags.push('burn_marks_detected');
        if (scoreResults.some(r => r.pixels.rustRatio  > 0.10)) flags.push('rust_detected');
        if (scoreResults.some(r => r.pixels.waterRatio > 0.25)) flags.push('water_damage_detected');
        return flags;
    }

    const getPriority   = sv => PRIORITY_MAP.find(t => sv >= t.min)?.label || 'Low';
    const getComplexity = sv => COMPLEXITY_MAP.find(t => sv >= t.min)?.label || 'Simple';


    async function analyzeImages(files, declaredType, onProgress) {
        if (!files || files.length === 0) throw new Error('No files provided.');
        if (!declaredType || !declaredType.trim()) declaredType = 'Other';

        onProgress?.('Initialising AI engine…');
        try { await loadModels(onProgress); }
        catch (err) { return buildFallbackResult(declaredType, err.message); }

        onProgress?.(`Analysing ${files.length} image${files.length > 1 ? 's' : ''}…`);

        const scoreResults   = [];
        const qualityResults = [];

        for (let idx = 0; idx < files.length; idx++) {
            onProgress?.(`Analysing image ${idx + 1} of ${files.length}…`);
            try {
                const img        = await fileToImage(files[idx]);
                const pixels     = analyzePixels(img);
                const quality    = analyzeQuality(img);
                const typePixels = analyzePixelsForType(img, declaredType, pixels);
                const { mobilenetPreds, cocoDetections } = await classifyImage(img);
                const score = scoreClassifications(mobilenetPreds, cocoDetections, declaredType, pixels);
                qualityResults.push(quality);
                scoreResults.push({ pixels, mobilenetPreds, cocoDetections, score, quality, typePixels });
            } catch (e) {
                console.warn(`[InfraAI v4.1] Image ${idx + 1} failed:`, e);
                const fallbackQ = { blurScore: 0, isBlurry: false, isSomewhatBlurry: false, qualityPenalty: 0 };
                qualityResults.push(fallbackQ);
                scoreResults.push({
                    pixels: {
                        isDark: false, isBright: false, rustRatio: 0, waterRatio: 0,
                        burnRatio: 0, concreteRatio: 0, greyCoverage: 0, darkRoadCoverage: 0,
                        roadDamageScore: 0, vertDom: 1, crackDensity: 0, roadLvCv: 0,
                        globalColourVar: 0, landscapePenalty: 0, darkVoidRatio: 0, brightness: 0,
                    },
                    mobilenetPreds: [], cocoDetections: [],
                    quality: fallbackQ,
                    typePixels: { typePixelScore: 0, typePixelNotes: '' },
                    score: {
                        detectedType: declaredType, matched: [], totalScore: 0,
                        confidence: 0, pixelSeverityBonus: 0, positiveHits: 0, negativeHits: 0,
                    },
                });
            }
        }

        // v4.0: multi-image consensus voting
        const consensus = computeConsensus(scoreResults, declaredType);

        // v4.0: aggregate type-specific pixel scores across all images
        const avgTypePixelScore = scoreResults.reduce((s, r) =>
            s + (r.typePixels?.typePixelScore || 0), 0) / scoreResults.length;
        const bestTypePixels = scoreResults.reduce((b, r) =>
            (r.typePixels?.typePixelScore || 0) > (b.typePixels?.typePixelScore || 0) ? r : b,
            scoreResults[0]
        ).typePixels || { typePixelScore: 0, typePixelNotes: '' };

        const severity   = calculateSeverity(scoreResults);
        const priority   = getPriority(severity);
        const complexity = getComplexity(severity);
        const avgConf    = scoreResults.reduce((s, r) => s + r.score.confidence, 0) / scoreResults.length;

        // Consensus winner is more reliable than single-image max-confidence pick
        const bestType = consensus.winnerType || declaredType;
        const infMatch = bestType === declaredType ? 1 : 0;

        const anyRoadDamage = scoreResults.some(r =>
            (r.pixels.greyCoverage || 0) > 0.10 &&
            (r.pixels.roadDamageScore || 0) > 4 &&
            (r.pixels.darkVoidRatio || 0) > 0.04
        );
        const bestByConf = scoreResults.reduce((b, r) =>
            r.score.confidence > b.score.confidence ? r : b, scoreResults[0]);

        // v4.0: confidence components — each named for auditability
        const pixelConfBoost    = anyRoadDamage
            ? parseFloat(Math.min((bestByConf.pixels.roadDamageScore || 0) / 50, 0.50).toFixed(3))
            : 0;
        const typePixelBoost    = parseFloat(Math.min(avgTypePixelScore / 10 * 0.35, 0.35).toFixed(3));
        const avgQualityPenalty = qualityResults.reduce((s, q) =>
            s + (q.qualityPenalty || 0), 0) / qualityResults.length;

        // v4.0: multi-factor legitimacy
        const rawLegit = Math.max(0, Math.min(1,
            avgConf + 0.05
            + pixelConfBoost
            + typePixelBoost
            + consensus.consensusBonus
            - avgQualityPenalty
            - (consensus.hasConflict ? 0.08 : 0)
        ));
        const legitimacyScore = parseFloat(rawLegit.toFixed(3));
        const isLegit = legitimacyScore >= 0.08 || anyRoadDamage || avgTypePixelScore >= 4;

        const topPreds = scoreResults
            .flatMap(r => r.mobilenetPreds)
            .sort((a, b) => b.probability - a.probability)
            .slice(0, 6)
            .map(p => `${p.className} (${Math.round(p.probability * 100)}%)`)
            .join('; ');

        onProgress?.('Estimating repair cost…');
        const costEstimation = estimateCost(bestType, severity, complexity, legitimacyScore, bestByConf.pixels);

        const matchConfidence = parseFloat(Math.min(
            avgConf + 0.10 + pixelConfBoost + typePixelBoost + consensus.consensusBonus, 1
        ).toFixed(3));
        const confidenceScore = parseFloat(Math.min(
            avgConf + pixelConfBoost + typePixelBoost + consensus.consensusBonus, 1
        ).toFixed(3));

        onProgress?.('Finalising result…');
        const result = {
            detected_infrastructure:     bestType,
            infrastructure_match:        infMatch,
            match_confidence:            matchConfidence,
            is_legitimate:               isLegit ? 1 : 0,
            legitimacy_score:            legitimacyScore,
            legitimacy_notes:            buildLegitimacyNotes(avgConf, scoreResults, avgTypePixelScore, qualityResults),
            damage_severity:             severity,
            priority_recommendation:     priority,
            damage_description:          buildDescription(scoreResults, severity, declaredType, bestTypePixels, qualityResults, bestType),
            confidence_score:            confidenceScore,
            anomaly_flags:               JSON.stringify(buildAnomalyFlags(scoreResults, severity, qualityResults, consensus)),
            combined_assessment:         topPreds,
            estimated_repair_complexity: complexity,
            requires_immediate_action:   severity >= 8 ? 1 : 0,
            images_analyzed:             files.length,
            analysis_status:             'completed',
            analysis_engine:             'tfjs-mobilenet-v2+pixel-v4.1',
            ai_cost_estimation:          costEstimation,
        };

        console.log('[InfraAI v4.1] Analysis complete:', result);
        console.log('[InfraAI v4.1] Consensus:', consensus);
        onProgress?.('Analysis complete.');
        return result;
    }
    function fileToImage(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload  = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Failed to load: ' + file.name)); };
            img.crossOrigin = 'anonymous';
            img.src = url;
        });
    }

    function buildFallbackResult(declaredType, reason) {
        return {
            detected_infrastructure:     declaredType,
            infrastructure_match:        1,
            match_confidence:            0.500,
            is_legitimate:               1,
            legitimacy_score:            0.500,
            legitimacy_notes:            'Client-side AI unavailable: '+reason+'. Manual review required.',
            damage_severity:             2,
            priority_recommendation:     'Low',
            damage_description:          'Automated analysis unavailable. Submitted for manual review.',
            confidence_score:            0.000,
            anomaly_flags:               JSON.stringify(['ai_engine_unavailable']),
            combined_assessment:         '',
            estimated_repair_complexity: 'Simple',
            requires_immediate_action:   0,
            images_analyzed:             0,
            analysis_status:             'failed',
            analysis_engine:             'tfjs-mobilenet-v2+pixel-v4.1',
            // ── NEW: sensible fallback when AI engine is unavailable ──
            ai_cost_estimation:          'N/A – manual assessment required',
        };
    }

    return { analyzeImages, loadModels };
})();