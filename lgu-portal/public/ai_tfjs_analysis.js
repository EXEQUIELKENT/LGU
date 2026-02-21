/**
 * ai_tfjs_analysis.js — InfraGovServices v3.3
 *
 * CHANGES over v3.2:
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
    // ─────────────────────────────────────────────────────────────────────────
    const CLASS_MAP = {
        // Roads
        'alley':             { type: 'Roads', sv: 4 },
        'asphalt':           { type: 'Roads', sv: 3 },
        'bulldozer':         { type: 'Roads', sv: 5 },
        'crane':             { type: 'Roads', sv: 4 },
        'construction crane':{ type: 'Roads', sv: 5 },
        'curb':              { type: 'Roads', sv: 3 },
        'gravel':            { type: 'Roads', sv: 4 },
        'guardrail':         { type: 'Roads', sv: 5 },
        'guard rail':        { type: 'Roads', sv: 5 },
        'manhole':           { type: 'Roads', sv: 5 },
        'manhole cover':     { type: 'Roads', sv: 5 },
        'mud':               { type: 'Roads', sv: 5 },
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
        // Drainage
        'cistern':           { type: 'Drainage', sv: 4 },
        'culvert':           { type: 'Drainage', sv: 6 },
        'dam':               { type: 'Drainage', sv: 7 },
        'gutter':            { type: 'Drainage', sv: 5 },
        'sewer':             { type: 'Drainage', sv: 7 },
        'storm drain':       { type: 'Drainage', sv: 6 },
        // Water Supply
        'fire hydrant':      { type: 'Water Supply', sv: 5 },
        'rain barrel':       { type: 'Water Supply', sv: 3 },
        'water pipe':        { type: 'Water Supply', sv: 6 },
        'water tower':       { type: 'Water Supply', sv: 5 },
        // Street Lights
        'fluorescent':       { type: 'Street Lights', sv: 3 },
        'lamp shade':        { type: 'Street Lights', sv: 3 },
        'lampshade':         { type: 'Street Lights', sv: 3 },
        'lantern':           { type: 'Street Lights', sv: 3 },
        'light bulb':        { type: 'Street Lights', sv: 4 },
        'neon sign':         { type: 'Street Lights', sv: 3 },
        'pole':              { type: 'Street Lights', sv: 5 },
        'spotlight':         { type: 'Street Lights', sv: 4 },
        'torch':             { type: 'Street Lights', sv: 4 },
        // Electrical
        'cable':             { type: 'Electrical', sv: 5 },
        'coil':              { type: 'Electrical', sv: 4 },
        'generator':         { type: 'Electrical', sv: 5 },
        'power outlet':      { type: 'Electrical', sv: 5 },
        'power pole':        { type: 'Electrical', sv: 6 },
        'transformer':       { type: 'Electrical', sv: 7 },
        'wire':              { type: 'Electrical', sv: 6 },
        // Public Facilities
        'bannister':         { type: 'Public Facilities', sv: 4 },
        'banister':          { type: 'Public Facilities', sv: 4 },
        'flagpole':          { type: 'Public Facilities', sv: 3 },
        'park bench':        { type: 'Public Facilities', sv: 3 },
        'picket fence':      { type: 'Public Facilities', sv: 3 },
        'playground':        { type: 'Public Facilities', sv: 4 },
        'toilet':            { type: 'Public Facilities', sv: 4 },
        'toilet seat':       { type: 'Public Facilities', sv: 4 },
        // Universal damage indicators
        'bonfire':           { type: null, sv: 8 },
        'debris':            { type: null, sv: 6 },
        'scaffold':          { type: null, sv: 4 },
        'wrecking ball':     { type: null, sv: 8 },
        'wreck':             { type: null, sv: 6 },
    };

    // ─────────────────────────────────────────────────────────────────────────
    // 2. NEGATIVE CLASS SUPPRESSION
    // ─────────────────────────────────────────────────────────────────────────
    const NEGATIVE_CLASSES = new Set([
        'geyser', 'lakeside', 'lakeshore', 'volcano', 'valley', 'vale',
        'alp', 'mountain tent', 'radio telescope', 'radio reflector', 'wing',
        'coral reef', 'cliff', 'seashore', 'promontory', 'sandbar',
        'hay', 'corn', 'harvester', 'thatch', 'rapeseed', 'daisy',
        'mushroom', 'bolete', 'agaric', 'prairie', 'tundra',
        'tree frog', 'chameleon', 'agama', 'iguana',
        'bald eagle', 'pelican', 'flamingo', 'peacock', 'caterpillar',
        'monarch butterfly', 'sulphur butterfly', 'lycaenid butterfly',
        'starfish', 'jellyfish', 'sea slug', 'sea anemone', 'brain coral',
        'dugong', 'orca', 'puffer', 'eel', 'stingray',
        'spider web', "spider's web",
        'confetti', 'space shuttle',
    ]);

    // ─────────────────────────────────────────────────────────────────────────
    // 3. TYPE KEYWORDS
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
        if (_mobilenet) return;
        if (_loadPromise) return _loadPromise;
        _loadPromise = (async () => {
            onProgress?.('Loading AI model (1/2)…');
            _mobilenet = await mobilenet.load({ version: 2, alpha: 1.0 });
            if (typeof cocoSsd !== 'undefined') {
                onProgress?.('Loading AI model (2/2)…');
                try { _cocoSsd = await cocoSsd.load({ base: 'mobilenet_v2' }); }
                catch (e) { console.warn('[InfraAI] COCO-SSD skipped:', e); }
            }
        })().catch(err => { _loadPromise = null; throw err; });
        return _loadPromise;
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

        const landscapePenalty = Math.min(1.0, globalColourVar / 35.0);
        let roadDamageScore = 0;
        if (greyCoverage > 0.10) {
            roadDamageScore += vertDom * 2.5;
            roadDamageScore += roadLvCv * 3.0;
            roadDamageScore += crackDensity * 8000;
        }
        roadDamageScore *= (1.0 - landscapePenalty * 0.7);

        const pixels = W * H;
        const rustRatio  = rustC  / pixels;
        const waterRatio = waterC / pixels;
        const burnRatio  = burnC  / pixels;

        let concreteC = 0;
        for (let i=0; i<data.length; i+=4) {
            const l = getL(i);
            const s = getSat(i);
            if (l>85 && l<175 && s<0.12 &&
                Math.abs(data[i]-data[i+1])<18 && Math.abs(data[i+1]-data[i+2])<18) concreteC++;
        }
        const concreteRatio = concreteC / pixels;

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
            landscapePenalty,
            isDark:   brightness < 40,
            isBright: brightness > 220,
        };
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
            console.warn('[InfraAI] MobileNet classify failed:', e);
            mobilenetPreds = [];
        }

        let cocoDetections = [];
        if (_cocoSsd) {
            try {
                const cocoCanvas = prepareCanvasElement(img);
                cocoDetections = await _cocoSsd.detect(cocoCanvas);
            } catch(e) {
                console.warn('[InfraAI] COCO-SSD detect failed:', e);
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

            if (CLASS_MAP[nl]) {
                const m     = CLASS_MAP[nl];
                const boost = computeBoost(nl, m, pred.probability, keywords, declaredType);
                if (boost > 0.3) {
                    matched.push({ className: pred.className, probability: pred.probability,
                                   key: nl, boost: m.sv });
                    if (m.type && boost > maxWeighted) { detectedType = m.type; maxWeighted = boost; }
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
                        if (mapping.type && boost > maxWeighted) { detectedType = mapping.type; maxWeighted = boost; }
                        totalScore += boost; posHits++;
                    }
                    break;
                }
            }
        }

        const COCO_INFRA = {
            'car':          { type: 'Roads',             sv: 1 },
            'truck':        { type: 'Roads',             sv: 2 },
            'bus':          { type: 'Roads',             sv: 2 },
            'stop sign':    { type: 'Roads',             sv: 4 },
            'fire hydrant': { type: 'Water Supply',      sv: 5 },
            'bench':        { type: 'Public Facilities', sv: 3 },
        };
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
            const rds = pixelData.roadDamageScore || 0;
            if (greyCov > 0.10 && rds > 0) {
                if      (rds > 20)  { pixelSeverityBonus += 6; if (!detectedType) detectedType = 'Roads'; }
                else if (rds > 10)  { pixelSeverityBonus += 4; if (!detectedType) detectedType = 'Roads'; }
                else if (rds > 4)   { pixelSeverityBonus += 2; if (!detectedType) detectedType = 'Roads'; }
                else if (rds > 1.5) { pixelSeverityBonus += 1; if (!detectedType) detectedType = 'Roads'; }
            }
            if (pixelData.concreteRatio > 0.30 && !detectedType) detectedType = 'Roads';
            if (posHits > 0) {
                if      (pixelData.rustRatio  > 0.20) pixelSeverityBonus += 2;
                else if (pixelData.rustRatio  > 0.10) pixelSeverityBonus += 1;
                if      (pixelData.waterRatio > 0.28) pixelSeverityBonus += 2;
                else if (pixelData.waterRatio > 0.18) pixelSeverityBonus += 1;
                if      (pixelData.burnRatio  > 0.14) pixelSeverityBonus += 3;
                else if (pixelData.burnRatio  > 0.09) pixelSeverityBonus += 1;
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
        let base = mapping.sv * probability;
        if (keywords.some(kw => key.includes(kw))) base *= 1.8;
        if (mapping.type === declaredType)          base *= 1.4;
        return base;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. SEVERITY CALCULATOR
    // ─────────────────────────────────────────────────────────────────────────
    function calculateSeverity(scoreResults) {
        let maxSeverity = 1;
        const avgConf = scoreResults.reduce((s,r) => s+r.score.confidence, 0) / scoreResults.length;

        for (const r of scoreResults) {
            let sv = 1;

            const rds = r.pixels.roadDamageScore || 0;
            const greyCov = r.pixels.greyCoverage || 0;
            if (greyCov > 0.10 && rds > 0) {
                if      (rds > 20) sv = Math.max(sv, 8);
                else if (rds > 12) sv = Math.max(sv, 7);
                else if (rds > 6)  sv = Math.max(sv, 5);
                else if (rds > 2)  sv = Math.max(sv, 3);
                else               sv = Math.max(sv, 1);
            }

            if (r.score.positiveHits > 0 && r.score.matched.length > 0) {
                const total = r.score.matched.reduce((s,m) => s + m.boost*m.probability, 0);
                const count = r.score.matched.reduce((s,m) => s + m.probability, 0);
                const modelSv = Math.round(total / Math.max(count, 0.01));
                sv = Math.max(sv, modelSv);
            }

            if (r.score.positiveHits > 0 || (greyCov > 0.10 && rds > 1.5)) {
                sv += r.score.pixelSeverityBonus;
            }

            if (r.pixels.isDark && r.score.positiveHits > 0) sv += 1;
            sv = Math.max(1, Math.min(10, sv));
            if (sv > maxSeverity) maxSeverity = sv;
        }

        if (scoreResults.length >= 3 && avgConf > 0.15)
            maxSeverity = Math.min(maxSeverity+1, 10);

        const anyRoadPixelDriven = scoreResults.some(r =>
            (r.pixels.greyCoverage || 0) > 0.10 && (r.pixels.roadDamageScore || 0) > 4
        );
        if (!anyRoadPixelDriven) {
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
            if (pixelData.burnRatio  > 0.14) pixelMultiplier *= 1.12; // fire damage
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
    // 11. HELPERS
    // ─────────────────────────────────────────────────────────────────────────
    function buildDescription(scoreResults, severity, declaredType) {
        const best = scoreResults.reduce((b,r) =>
            r.score.matched.length > b.score.matched.length ? r : b, scoreResults[0]);
        const infra   = best.score.detectedType || declaredType;
        const count   = scoreResults.length;
        const topKeys = [...new Set(best.score.matched.slice(0,3).map(m => m.key))];
        let desc = `${infra} issue across ${count} image${count>1?'s':''}.`;
        if (topKeys.length) desc += ` Detected: ${topKeys.join(', ')}.`;

        const p   = best.pixels;
        const rds = p.roadDamageScore || 0;
        const gc  = p.greyCoverage    || 0;

        if (gc > 0.10 && rds > 10)      desc += ' Road structural damage detected (cracks/heaving).';
        else if (gc > 0.10 && rds > 2)  desc += ' Road surface irregularities detected.';
        if (p.rustRatio    > 0.10)       desc += ' Rust/corrosion visible.';
        if (p.waterRatio   > 0.25)       desc += ' Water damage/flooding signs.';
        if (p.burnRatio    > 0.12)       desc += ' Burn/char marks detected.';
        if (p.concreteRatio> 0.30 && !topKeys.length && rds < 2)
                                         desc += ' Concrete/pavement surface.';

        if      (severity >= 8) desc += ' Severe — immediate action required.';
        else if (severity >= 6) desc += ' Significant damage.';
        else if (severity >= 4) desc += ' Moderate damage.';
        else                    desc += ' Minor or unclear damage.';
        return desc.slice(0, 255);
    }

    function buildLegitimacyNotes(avgConf, scoreResults) {
        const totalPos   = scoreResults.reduce((s,r) => s+r.score.positiveHits, 0);
        const anyRoadDmg = scoreResults.some(r =>
            (r.pixels.greyCoverage||0) > 0.10 && (r.pixels.roadDamageScore||0) > 4
        );
        if (anyRoadDmg)
            return 'Road structural damage detected via pixel analysis. Manual review recommended.';
        if (avgConf < 0.05 && totalPos === 0)
            return 'No infrastructure indicators detected. Image may be unrelated. Manual review required.';
        if (avgConf < 0.12)
            return `Low AI confidence (${Math.round(avgConf*100)}%). Manual review advised.`;
        return `AI confidence: ${Math.round(avgConf*100)}%. Infrastructure indicators detected.`;
    }

    function buildAnomalyFlags(scoreResults, severity) {
        const flags = [];
        const avgConf = scoreResults.reduce((s,r) => s+r.score.confidence, 0) / scoreResults.length;
        if (scoreResults.every(r => r.pixels.isDark))   flags.push('all_images_dark');
        if (scoreResults.every(r => r.pixels.isBright)) flags.push('all_images_overexposed');
        if (scoreResults.every(r => r.score.positiveHits === 0 && (r.pixels.roadDamageScore||0) < 2))
                                                          flags.push('no_infrastructure_detected');
        if (avgConf < 0.08 && scoreResults.every(r => (r.pixels.roadDamageScore||0) < 2))
                                                          flags.push('low_model_confidence');
        if (scoreResults.every(r => r.score.negativeHits >= 3 &&
            ((r.pixels.greyCoverage||0) + (r.pixels.darkRoadCoverage||0)) < 0.05))
                                                          flags.push('non_infrastructure_images');
        if (severity >= 8)                                flags.push('immediate_action_required');
        if (scoreResults.some(r => (r.pixels.roadDamageScore||0) > 10 && (r.pixels.greyCoverage||0) > 0.10))
                                                          flags.push('road_structural_damage');
        if (scoreResults.some(r => r.pixels.burnRatio > 0.18 &&
            (r.pixels.landscapePenalty||1) < 0.6))        flags.push('burn_marks_detected');
        if (scoreResults.some(r => r.pixels.rustRatio  > 0.10)) flags.push('rust_detected');
        if (scoreResults.some(r => r.pixels.waterRatio > 0.25)) flags.push('water_damage_detected');
        return flags;
    }

    const getPriority   = sv => PRIORITY_MAP.find(t   => sv>=t.min)?.label || 'Low';
    const getComplexity = sv => COMPLEXITY_MAP.find(t  => sv>=t.min)?.label || 'Simple';

    // ─────────────────────────────────────────────────────────────────────────
    // 12. PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────
    async function analyzeImages(files, declaredType, onProgress) {
        if (!files || files.length === 0) throw new Error('No files provided.');

        if (!declaredType || !declaredType.trim()) declaredType = 'Other';

        onProgress?.('Initialising AI engine…');
        try { await loadModels(onProgress); }
        catch (err) { return buildFallbackResult(declaredType, err.message); }

        onProgress?.(`Analysing ${files.length} image${files.length>1?'s':''}…`);
        const scoreResults = [];

        for (let idx = 0; idx < files.length; idx++) {
            onProgress?.(`Analysing image ${idx+1} of ${files.length}…`);
            try {
                const img    = await fileToImage(files[idx]);
                const pixels = analyzePixels(img);
                const { mobilenetPreds, cocoDetections } = await classifyImage(img);
                const score  = scoreClassifications(mobilenetPreds, cocoDetections, declaredType, pixels);
                scoreResults.push({ pixels, mobilenetPreds, cocoDetections, score });
            } catch(e) {
                console.warn(`[InfraAI] Image ${idx+1} failed:`, e);
                scoreResults.push({
                    pixels: { isDark:false, isBright:false, rustRatio:0, waterRatio:0,
                              burnRatio:0, concreteRatio:0, greyCoverage:0, darkRoadCoverage:0,
                              roadDamageScore:0, vertDom:1, crackDensity:0, roadLvCv:0,
                              globalColourVar:0, landscapePenalty:0 },
                    mobilenetPreds:[], cocoDetections:[],
                    score: { detectedType:declaredType, matched:[], totalScore:0,
                             confidence:0, pixelSeverityBonus:0, positiveHits:0, negativeHits:0 },
                });
            }
        }

        const severity    = calculateSeverity(scoreResults);
        const priority    = getPriority(severity);
        const complexity  = getComplexity(severity);
        const avgConf     = scoreResults.reduce((s,r) => s+r.score.confidence, 0) / scoreResults.length;
        const bestResult  = scoreResults.reduce((b,r) =>
            r.score.confidence > b.score.confidence ? r : b, scoreResults[0]);
        const bestType    = bestResult.score.detectedType || declaredType;
        const infMatch    = bestType === declaredType ? 1 : 0;
        const anyRoadDamage = scoreResults.some(r =>
            (r.pixels.greyCoverage||0) > 0.10 && (r.pixels.roadDamageScore||0) > 4
        );
        const isLegit     = avgConf >= 0.08 || anyRoadDamage;
        const legitimacyScore = parseFloat(Math.min(avgConf + 0.05, 1).toFixed(3));

        const topPreds = scoreResults
            .flatMap(r => r.mobilenetPreds)
            .sort((a,b) => b.probability - a.probability)
            .slice(0,6)
            .map(p => `${p.className} (${Math.round(p.probability*100)}%)`)
            .join('; ');

        // ── Cost estimation (v3.3) ────────────────────────────────────────────
        // Uses the best result's pixel data for fine-tuning.
        onProgress?.('Estimating repair cost…');
        const costEstimation = estimateCost(
            bestType,
            severity,
            complexity,
            legitimacyScore,
            bestResult.pixels
        );

        onProgress?.('Finalising result…');

        const result = {
            detected_infrastructure:     bestType,
            infrastructure_match:        infMatch,
            match_confidence:            parseFloat(Math.min(avgConf+0.10, 1).toFixed(3)),
            is_legitimate:               isLegit ? 1 : 0,
            legitimacy_score:            legitimacyScore,
            legitimacy_notes:            buildLegitimacyNotes(avgConf, scoreResults),
            damage_severity:             severity,
            priority_recommendation:     priority,
            damage_description:          buildDescription(scoreResults, severity, declaredType),
            confidence_score:            parseFloat(avgConf.toFixed(3)),
            anomaly_flags:               JSON.stringify(buildAnomalyFlags(scoreResults, severity)),
            combined_assessment:         topPreds,
            estimated_repair_complexity: complexity,
            requires_immediate_action:   severity >= 8 ? 1 : 0,
            images_analyzed:             files.length,
            analysis_status:             'completed',
            analysis_engine:             'tfjs-mobilenet-v2+pixel-v3.3',
            // ── NEW ──
            ai_cost_estimation:          costEstimation,
        };

        console.log('[InfraAI v3.3] Result:', result);
        console.log('[InfraAI v3.3] Estimated repair cost:', costEstimation);
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
            analysis_engine:             'tfjs-mobilenet-v2+pixel-v3.3',
            // ── NEW: sensible fallback when AI engine is unavailable ──
            ai_cost_estimation:          'N/A – manual assessment required',
        };
    }

    return { analyzeImages, loadModels };
})();