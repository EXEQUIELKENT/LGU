<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit(); }

session_start();

$input       = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($input['message'])  ? trim($input['message']) : '';
$context     = isset($input['context'])  ? $input['context']       : 'general';
$lang        = isset($input['lang'])     ? $input['lang']          : 'en';
$imageBase64 = isset($input['image'])    ? $input['image']         : null;
$aiResult    = isset($input['aiResult']) ? $input['aiResult']      : null;   // TFJS result from widget

if (empty($userMessage) && empty($imageBase64)) {
    echo json_encode(['error' => 'Message or image is required']);
    exit();
}

if (!isset($_SESSION['chat_history'])) $_SESSION['chat_history'] = [];

// ============================================================
// IMAGE ANALYSIS ENGINE
// (Uses aiResult forwarded from InfraAI TFJS; falls back to
//  basic MIME/size heuristics when TFJS data is unavailable)
// ============================================================

function analyzeImageForChatbot($base64, $aiResult, $lang) {
    if (empty($base64)) return null;

    $result = [
        'hasImage'        => true,
        'detectedType'    => 'Unknown',
        'confidence'      => 0,
        'labels'          => [],
        'severity'        => 'Unknown',
        'recommendation'  => '',
        'aiCardHtml'      => ''
    ];

    // ── A) Use forwarded TFJS / InfraAI result ──────────────
    if (!empty($aiResult) && is_array($aiResult)) {
        $infraType   = isset($aiResult['infrastructureType'])  ? $aiResult['infrastructureType']  : '';
        $confidence  = isset($aiResult['confidence'])          ? floatval($aiResult['confidence']) : 0;
        $severity    = isset($aiResult['severity'])            ? $aiResult['severity']             : 'Unknown';
        $labels      = isset($aiResult['detectedObjects'])     ? $aiResult['detectedObjects']      : [];
        $recommendation = isset($aiResult['recommendation'])   ? $aiResult['recommendation']       : '';
        $mobilenetLabels = isset($aiResult['topMobileNetLabels']) ? $aiResult['topMobileNetLabels'] : [];

        if (!empty($infraType)) {
            $result['detectedType']   = $infraType;
            $result['confidence']     = $confidence;
            $result['severity']       = $severity;
            $result['labels']         = array_merge($labels, $mobilenetLabels);
            $result['recommendation'] = $recommendation;

            $confPct  = round($confidence * 100);
            $sevColor = getSeverityColor($severity);

            $labelsHtml = '';
            if (!empty($result['labels'])) {
                $shown = array_slice($result['labels'], 0, 5);
                foreach ($shown as $lbl) {
                    $labelsHtml .= '<span style="display:inline-block;background:rgba(37,99,235,.12);color:#2563eb;border-radius:12px;padding:2px 9px;font-size:11px;font-weight:600;margin:2px 3px 2px 0;">' . htmlspecialchars($lbl) . '</span>';
                }
            }

            $result['aiCardHtml'] = '
<div class="ai-label">🧠 AI Analysis
    <span class="ai-confidence">' . $confPct . '% confidence</span>
</div>
<div style="margin-bottom:6px;">
    <strong>Detected:</strong> ' . htmlspecialchars($infraType) . '
    &nbsp;·&nbsp;
    <span style="color:' . $sevColor . ';font-weight:700;">' . htmlspecialchars($severity) . ' severity</span>
</div>
' . ($labelsHtml ? '<div style="margin-bottom:6px;">' . $labelsHtml . '</div>' : '') . '
' . ($recommendation ? '<div style="font-size:12px;color:var(--text-secondary,#64748b);"><strong>Tip:</strong> ' . htmlspecialchars($recommendation) . '</div>' : '');

            return $result;
        }
    }

    // ── B) Fallback: basic heuristic from base64 header ─────
    $mimeMatch = [];
    if (preg_match('/^data:(image\/[a-zA-Z]+);base64,/', $base64, $mimeMatch)) {
        $mime = $mimeMatch[1];
        // Estimate file size
        $b64Data = substr($base64, strpos($base64, ',') + 1);
        $sizeBytes = strlen(base64_decode($b64Data));
        $sizeKb = round($sizeBytes / 1024);

        $result['detectedType']   = 'Uploaded Image';
        $result['confidence']     = 0.6;
        $result['labels']         = [strtoupper(str_replace('image/', '', $mime)), $sizeKb . ' KB'];
        $result['aiCardHtml']     = '
<div class="ai-label">📸 Image Received
    <span class="ai-confidence">' . strtoupper(str_replace('image/','',$mime)) . '</span>
</div>
<div style="font-size:12px;color:var(--text-secondary,#64748b);">
    Size: ~' . $sizeKb . ' KB · Format: ' . htmlspecialchars($mime) . '
    <br>AI model analysis requires TensorFlow.js to be loaded on the page.
</div>';
    }

    return $result;
}

function getSeverityColor($severity) {
    $map = [
        'Critical' => '#ef4444',
        'High'     => '#f97316',
        'Medium'   => '#eab308',
        'Low'      => '#22c55e',
        'None'     => '#94a3b8',
    ];
    return isset($map[$severity]) ? $map[$severity] : '#64748b';
}

// ============================================================
// INTENT DETECTION
// ============================================================

function detectIntent($message, $context) {
    $message = strtolower($message);

    $intents = [
        'image_analysis' => [
            'patterns' => [
                '/screenshot.*analyz/',
                '/analyz.*screenshot/',
                '/i submitted a screenshot/',
                '/nagsumite.*screenshot/',
                '/screenshot.*pagsusuri/',
                '/upload.*screenshot/',
            ],
            'confidence' => 0.98
        ],
        'greeting' => [
            'patterns' => [
                '/\b(hi|hello|hey|good morning|good afternoon|good evening|greetings|howdy)\b/',
                '/^(yo|sup|wassup|what\'?s up)\b/',
                '/\b(kumusta|magandang umaga|magandang hapon|magandang gabi|kamusta|helo|oi|uy|musta)\b/',
                '/\bkamusta ka\b/'
            ],
            'confidence' => 0.95
        ],
        'how_are_you' => [
            'patterns' => [
                '/how are you/',
                '/how\'?s it going/',
                '/how do you feel/',
                '/kumusta ka/',
                '/kamusta ka/',
                '/ayos ka lang/',
                '/okay ka lang/'
            ],
            'confidence' => 0.95
        ],
        'how_to_report' => [
            'patterns' => [
                '/how (to|do|can).*(report|submit|file|create).*(issue|problem|request|concern)/',
                '/\b(report|submit|file).*(how|process|steps)/',
                '/what.*(process|steps).*(report|submit)/',
                '/where.*submit/',
                '/paano.*(mag-?ulat|magsumite|mag-submit|mag-file)/',
                '/\b(iulat|mag-ulat|mag-report|mag-file|isumite)\b/',
                '/saan.*(magsumite|mag-submit|mag-ulat)/',
                '/paano.*gumawa.*kahilingan/'
            ],
            'confidence' => 0.90
        ],
        'photo_upload' => [
            'patterns' => [
                '/\b(photo|picture|evidence|image|upload|camera|capture|pic|snap)\b/',
                '/how.*(add|attach|upload|take).*photo/',
                '/\b(jpg|jpeg|png|webp)\b/',
                '/how many.*image/',
                '/\b(larawan|litrato|kuha|i-upload|mag-upload|ipadala.*larawan|picture)\b/',
                '/paano.*mag-upload/',
                '/ilan.*larawan/'
            ],
            'confidence' => 0.85
        ],
        'location_map' => [
            'patterns' => [
                '/\b(location|map|barangay|address|gps|where|place|pin)\b/',
                '/how.*(select|choose|find|mark|set).*location/',
                '/\b(quezon city|qc)\b.*location/',
                '/interactive.*map/',
                '/\b(lokasyon|mapa|lugar|barangay|saan|tirahan|address)\b/',
                '/paano.*(pumili|piliin|hanapin|markahan).*lokasyon/',
            ],
            'confidence' => 0.85
        ],
        'contact_format' => [
            'patterns' => [
                '/\b(contact|phone|number|mobile|cellphone|09)\b/',
                '/\bformat.*number\b/',
                '/\b11 digit/',
                '/phone.*format/',
                '/\b(numero|telepono|selpon|cellphone|kontak|09\d{2})\b/',
            ],
            'confidence' => 0.85
        ],
        'track_status' => [
            'patterns' => [
                '/\b(track|status|check|follow up|progress|update|monitor|view.*report)\b/',
                '/where.*(request|report)/',
                '/\b(pending|in progress|completed|delayed)\b/',
                '/how.*check.*status/',
                '/\b(subaybayan|katayuan|status|progreso|tingnan|saan na|check)\b/',
                '/nasaan.*(kahilingan|ulat)/',
            ],
            'confidence' => 0.85
        ],
        'dark_mode' => [
            'patterns' => [
                '/\b(dark mode|theme|night mode|light mode|appearance|color scheme)\b/',
                '/\bchange.*theme\b/',
                '/toggle.*dark/',
                '/\b(madilim|maliwanag|tema|dark mode|light mode|kulay)\b/',
            ],
            'confidence' => 0.90
        ],
        'language_switch' => [
            'patterns' => [
                '/\b(language|translate|translation|switch.*language|filipino|english|tagalog|fil|pilipino)\b/',
                '/change.*language/',
                '/how.*translate/',
                '/\b(globe|translate button|language button|lang button)\b/',
                '/\b(wika|salin|i-translate|tagalog|ingles|english|pilipino)\b/',
                '/paano.*mag-translate/',
                '/palitan.*wika/',
            ],
            'confidence' => 0.90
        ],
        'privacy_policy' => [
            'patterns' => [
                '/\b(privacy|privacy policy|data privacy|personal data|personal information)\b/',
                '/\b(ra 10173|data privacy act|republic act)\b/',
                '/\b(npc|national privacy commission)\b/',
                '/\b(collect.*data|data.*collect|store.*data|process.*data)\b/',
                '/\b(consent|agreement|agree|accept).*(privacy|policy|terms)\b/',
                '/\b(right to access|right to erasure|right to object|right to correct)\b/',
                '/tungkol.*privacy/',
            ],
            'confidence' => 0.90
        ],
        'terms_conditions' => [
            'patterns' => [
                '/\b(terms|terms and conditions|terms of service|terms of use|tos)\b/',
                '/\b(conditions|agreement|user agreement|eula)\b/',
                '/\b(accept|agree|consent).*(terms|conditions|agreement)\b/',
                '/\b(ai.*(disclaimer|note|caveat)|disclaimer|liability|limitation)\b/',
                '/\b(mga tuntunin|tuntunin ng serbisyo|kasunduan|mga kondisyon)\b/',
                '/tungkol.*tuntunin/',
            ],
            'confidence' => 0.90
        ],
        'about_page' => [
            'patterns' => [
                '/\b(about|about us|about page|cimms.*about|learn more)\b/',
                '/what.*about.*page/',
                '/tell me about.*system/',
                '/\b(tungkol|about|tungkol sa amin)\b/',
                '/ano.*tungkol/'
            ],
            'confidence' => 0.85
        ],
        'reports_page' => [
            'patterns' => [
                '/\b(reports page|view reports|maintenance report|recent activity|schedules)\b/',
                '/where.*reports/',
                '/how.*view.*reports/',
                '/\b(pahina.*ulat|mga ulat|maintenance.*report)\b/',
            ],
            'confidence' => 0.85
        ],
        'navigation' => [
            'patterns' => [
                '/\b(pages|navigate|menu|sections|where is|find.*page)\b/',
                '/how.*(find|get to|go to)/',
                '/\b(home|reports|requests|about)\b.*page/',
                '/show me.*pages/',
                '/\b(pahina|menu|seksyon|saan|paano pumunta|navigation)\b/',
            ],
            'confidence' => 0.80
        ],
        'reportable_types' => [
            'patterns' => [
                '/what can.*(report|submit)/',
                '/\b(types|kinds|categories).*infrastructure/',
                '/\b(roads|streetlights|drainage|water|electrical|facilities)\b/',
                '/what.*issue.*report/',
                '/ano.*(maaaring|pwedeng).*(iulat|i-report|isumite)/',
                '/\b(kalsada|ilaw|drainage|tubig|kuryente|pasilidad)\b/',
            ],
            'confidence' => 0.85
        ],
        'help_support' => [
            'patterns' => [
                '/\b(help|support|contact|assistance|phone|email|reach)\b/',
                '/\bneed help\b/',
                '/\bcontact.*support\b/',
                '/customer.*service/',
                '/\b(tulong|suporta|kontak|email|kailangan.*tulong)\b/',
            ],
            'confidence' => 0.80
        ],
        'about_system' => [
            'patterns' => [
                '/\b(about|what is|cimms|infragovservices|system|purpose|mission|vision)\b/',
                '/tell me about/',
                '/\bwhat.*(does|do).*system\b/',
                '/explain.*system/',
                '/\b(tungkol|ano ang|cimms|sistema|layunin)\b/',
            ],
            'confidence' => 0.80
        ],
        'technical_issue' => [
            'patterns' => [
                '/\b(error|bug|broken|not working|issue|problem|glitch|crash).*\b(site|system|page|website)/',
                '/\bcan\'?t.*\b(submit|upload|login|access)/',
                '/\b(stuck|freeze|loading|slow)\b/',
                '/something.*wrong/',
                '/\b(hindi gumagana|error|sira|hindi ma-submit|naka-stuck|may problema)\b/',
            ],
            'confidence' => 0.85
        ],
        'requirements' => [
            'patterns' => [
                '/what.*(need|require|necessary|needed)/',
                '/\b(requirement|needed|mandatory|must have)\b/',
                '/do i need/',
                '/what.*required/',
                '/ano.*(kailangan|kinakailangan|dapat)/',
            ],
            'confidence' => 0.75
        ],
        'gratitude' => [
            'patterns' => [
                '/\b(thank you|thanks|thank|appreciate|grateful)\b/',
                '/thanks a lot/',
                '/\b(salamat|maraming salamat|thank you)\b/'
            ],
            'confidence' => 0.95
        ],
        'capabilities' => [
            'patterns' => [
                '/what can you do/',
                '/what are you/',
                '/who are you/',
                '/your capabilities/',
                '/what.*help/',
                '/ano.*kaya mo/',
                '/sino ka/',
                '/ano.*tulong mo/'
            ],
            'confidence' => 0.90
        ],
        'yes_confirmation' => [
            'patterns' => [
                '/^(yes|yeah|yep|sure|okay|ok|yup|correct|right|exactly)$/',
                '/^(oo|opo|sige|tama|okay|ok)$/'
            ],
            'confidence' => 0.90
        ],
        'no_negation' => [
            'patterns' => [
                '/^(no|nope|nah|not really)$/',
                '/^(hindi|hindi po|ayaw|wala)$/'
            ],
            'confidence' => 0.90
        ]
    ];

    foreach ($intents as $intent => $data) {
        foreach ($data['patterns'] as $pattern) {
            if (preg_match($pattern, $message)) {
                return ['intent' => $intent, 'confidence' => $data['confidence']];
            }
        }
    }
    return ['intent' => 'general', 'confidence' => 0.50];
}

// ============================================================
// RESPONSE GENERATION — image_analysis added to both languages
// ============================================================

function generateResponse($message, $intent, $context, $history, $lang, $imageAnalysis) {

    // ── Shared image response builder ────────────────────────
    $buildImageResponse = function($imageAnalysis, $lang) {
        if (empty($imageAnalysis) || !$imageAnalysis['hasImage']) {
            return $lang === 'tl'
                ? "Natanggap ko ang iyong screenshot! 📸\n\nAko ay pag-aaralan ito upang matulungan ka nang mas mabuti. Maaari mo ring ilarawan ang iyong sinusubukang gawin at ibibigay ko ang pinakamahusay na gabay."
                : "I received your screenshot! 📸\n\nI'll analyze it to help you better. You can also describe what you're trying to do and I'll provide the best guidance.";
        }

        $type       = $imageAnalysis['detectedType'];
        $confidence = round(($imageAnalysis['confidence'] ?? 0) * 100);
        $severity   = $imageAnalysis['severity'] ?? 'Unknown';
        $labels     = $imageAnalysis['labels'] ?? [];

        // Build contextual response based on detected infrastructure type
        $infraResponses_en = [
            'Roads'             => "I can see **road infrastructure** in your image! 🛣️\n\nThis looks like a road-related issue. To report this:\n1. Go to **Requests** page\n2. Select **'Roads'** as infrastructure type\n3. Mark the exact location on the map\n4. Upload this photo as evidence\n5. Describe the damage (pothole, crack, etc.)\n\n💡 Pro tip: Take photos from multiple angles for faster processing.",
            'Street Lights'     => "I can see **street lighting** in your image! 💡\n\nThis appears to be a street light issue. To report:\n1. Go to **Requests** page\n2. Select **'Street Lights'**\n3. Note the pole number if visible\n4. Upload your photo as evidence\n5. Describe the problem (broken, flickering, out)\n\n⚡ Electrical issues are given high priority — submit ASAP!",
            'Drainage'          => "I can see a **drainage issue** in your image! 🚰\n\nClogged or damaged drainage can cause flooding. To report:\n1. Go to **Requests** page\n2. Select **'Drainage'**\n3. Pin the exact location\n4. Upload this image as evidence\n5. Note if it's causing flooding or hazards\n\n⚠️ Drainage issues during rainy season are marked urgent!",
            'Electrical'        => "I can see an **electrical hazard** in your image! ⚡\n\n⚠️ **Safety first!** Stay away from exposed wiring.\n\nTo report immediately:\n1. Go to **Requests** page\n2. Select **'Electrical'**\n3. Mark location precisely\n4. Upload this photo\n5. Describe the hazard clearly\n\n🚨 Electrical hazards get **Critical priority** — report now!",
            'Water Supply'      => "I can see a **water supply issue** in your image! 💧\n\nWater leaks or bursts need immediate attention. To report:\n1. Go to **Requests** page\n2. Select **'Water Supply'**\n3. Mark the location\n4. Upload your evidence photo\n5. Note the severity (leak vs. burst)\n\n💡 Include your contact number for urgent follow-up!",
            'Public Facilities' => "I can see a **public facility issue** in your image! 🏢\n\nTo report this facility problem:\n1. Go to **Requests** page\n2. Select **'Public Facilities'**\n3. Mark the location on the map\n4. Upload this image\n5. Describe the specific issue\n\n📍 Be specific about which facility needs attention!",
        ];

        $infraResponses_tl = [
            'Roads'             => "Nakikita ko ang **isyu sa kalsada** sa iyong larawan! 🛣️\n\nPara iulat ito:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Mga Kalsada'**\n3. Markahan ang eksaktong lokasyon sa mapa\n4. I-upload ang larawang ito bilang ebidensya\n5. Ilarawan ang pinsala (butas, bitak, atbp.)\n\n💡 Pro tip: Kumuha ng litrato mula sa iba't ibang anggulo!",
            'Street Lights'     => "Nakikita ko ang **isyu sa ilaw sa kalye** sa iyong larawan! 💡\n\nPara iulat:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Mga Ilaw sa Kalye'**\n3. Itala ang numero ng poste kung makikita\n4. I-upload ang iyong larawan\n5. Ilarawan ang problema\n\n⚡ Mataas ang priyoridad ng mga isyu sa kuryente!",
            'Drainage'          => "Nakikita ko ang **isyu sa drainage** sa iyong larawan! 🚰\n\nPara iulat:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Drainage'**\n3. I-pin ang eksaktong lokasyon\n4. I-upload ang larawang ito\n5. Tandaan kung nagdudulot ito ng pagbaha\n\n⚠️ Ang mga isyu sa drainage ay minarkahan na apurahan!",
            'Electrical'        => "Nakikita ko ang **panganib sa kuryente** sa iyong larawan! ⚡\n\n⚠️ **Kaligtasan muna!** Lumayo sa nakalantad na kawad.\n\nPara iulat agad:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Electrical'**\n3. Markahan nang tumpak ang lokasyon\n4. I-upload ang larawang ito\n5. Ilarawan ang panganib\n\n🚨 Ang mga panganib sa kuryente ay **Kritikal na Priyoridad**!",
            'Water Supply'      => "Nakikita ko ang **isyu sa suplay ng tubig** sa iyong larawan! 💧\n\nPara iulat:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Supply ng Tubig'**\n3. Markahan ang lokasyon\n4. I-upload ang iyong larawan\n5. Itala ang kalubhaan\n\n💡 Isama ang iyong numero ng kontak!",
            'Public Facilities' => "Nakikita ko ang **isyu sa pampublikong pasilidad** sa iyong larawan! 🏢\n\nPara iulat:\n1. Pumunta sa pahina ng **Mga Kahilingan**\n2. Piliin ang **'Pampublikong Pasilidad'**\n3. Markahan ang lokasyon sa mapa\n4. I-upload ang larawang ito\n5. Ilarawan ang tiyak na isyu",
        ];

        $responses = $lang === 'tl' ? $infraResponses_tl : $infraResponses_en;

        // Find matching response
        $responseText = null;
        foreach ($responses as $key => $resp) {
            if (stripos($type, $key) !== false || stripos($key, $type) !== false) {
                $responseText = $resp;
                break;
            }
        }

        if (!$responseText) {
            if ($lang === 'tl') {
                $responseText = "Natanggap at nasuri ko ang iyong screenshot! 📸\n\n"
                    . ($type !== 'Unknown' ? "**Natukoy:** " . $type . "\n\n" : "")
                    . "Maaari ko itong gamitin para matulungan ka na maiproseso ang iyong ulat nang mas mabilis. Pumunta sa pahina ng **Mga Kahilingan** para isumite ang iyong kahilingan sa pagpapanatili.\n\n"
                    . "💡 I-upload ang larawang ito bilang ebidensya sa form ng pagsusumite!";
            } else {
                $responseText = "I've received and analyzed your screenshot! 📸\n\n"
                    . ($type !== 'Unknown' && $type !== 'Uploaded Image' ? "**Detected:** " . $type . "\n\n" : "")
                    . "I can use this to help you process your report faster. Head to the **Requests** page to submit your maintenance request.\n\n"
                    . "💡 Upload this image as evidence in the submission form!";
            }
        }

        if ($confidence > 0) {
            $severityNote = '';
            if (in_array($severity, ['Critical', 'High'])) {
                $severityNote = $lang === 'tl'
                    ? "\n\n🚨 **Paunawa:** Ang isyung ito ay may mataas na kalubhaan. Isumite agad!"
                    : "\n\n🚨 **Note:** This issue has high severity. Please submit immediately!";
            }
            $responseText .= $severityNote;
        }

        return $responseText;
    };

    $responses_en = [
        'image_analysis' => function() use ($imageAnalysis, $buildImageResponse) {
            return $buildImageResponse($imageAnalysis, 'en');
        },
        'greeting' => function() {
            $greetings = [
                "Hello! 👋 I'm the InfraGovServices AI assistant. How can I help you today?",
                "Hi there! 👋 Welcome to InfraGovServices. What would you like to know about our system?",
                "Good day! 👋 I'm here to assist you with CIMMS. What can I help you with?",
                "Hey! 👋 Ready to help you navigate InfraGovServices. What's on your mind?"
            ];
            return $greetings[array_rand($greetings)];
        },
        'how_are_you' => function() {
            return "I'm doing great, thank you for asking! 😊 I'm here and ready to help you with anything related to InfraGovServices. How can I assist you today?";
        },
        'how_to_report' => function() {
            return "**Submitting a Report - Step by Step:**\n\n" .
                   "1️⃣ **Go to Requests Page** - Click 'Requests' in the navigation menu\n" .
                   "2️⃣ **Select Infrastructure Type** - Choose from Roads, Street Lights, Drainage, etc.\n" .
                   "3️⃣ **Pick Location** - Use the interactive map to mark the exact spot\n" .
                   "4️⃣ **Describe the Issue** - Provide detailed description of the problem\n" .
                   "5️⃣ **Upload Photos** - Add up to 4 clear images as evidence\n" .
                   "6️⃣ **Enter Contact** - Provide your 11-digit mobile number (09XX-XXX-XXXX)\n" .
                   "7️⃣ **Agree to Terms** - Check the box to accept our Terms & Privacy Policy\n" .
                   "8️⃣ **Submit!** - Click the submit button\n\n" .
                   "💡 **Pro tip:** Clear, well-lit photos from multiple angles help our team respond faster!\n\n" .
                   "📄 You can review our full Terms and Privacy Policy via the footer links before submitting.";
        },
        'photo_upload' => function() {
            return "**Photo Upload Guide:**\n\n" .
                   "📸 **Desktop Users:**\n" .
                   "• Click the file input field\n" .
                   "• Browse and select up to 4 images\n" .
                   "• Supported formats: JPG, JPEG, PNG, WEBP\n\n" .
                   "📱 **Mobile Users:**\n" .
                   "• Tap the camera button (📷) to capture directly\n" .
                   "• Or tap file input to choose from gallery\n" .
                   "• Maximum 4 images per report\n\n" .
                   "✨ **Tips for Best Results:**\n" .
                   "• Take photos in good lighting\n" .
                   "• Show the problem from multiple angles\n" .
                   "• Include context (nearby landmarks)\n" .
                   "• Make sure images are clear and focused\n\n" .
                   "Photos significantly speed up assessment and repair prioritization!";
        },
        'location_map' => function() {
            return "**Using the Location Map:**\n\n" .
                   "🗺️ **How to Select Your Location:**\n\n" .
                   "1. **Click Location Field** - This opens the interactive map modal\n" .
                   "2. **Choose Barangay** - Select from the dropdown menu\n" .
                   "3. **Use GPS** 📍 - Click the GPS button to auto-detect your current location\n" .
                   "4. **Manual Selection** - Click or drag the map marker to the exact spot\n" .
                   "5. **Verify Address** - System auto-fills the specific address\n" .
                   "6. **Save** - Click 'Save Location' to confirm\n\n" .
                   "⚠️ **Important:** Only locations within Quezon City are accepted.\n\n" .
                   "🔍 **Features:**\n" .
                   "• Toggle between Satellite and Street view\n" .
                   "• Show/hide location labels\n" .
                   "• Accurate boundary enforcement\n" .
                   "• Auto-detected addresses via geocoding";
        },
        'contact_format' => function() {
            return "**Contact Number Format:**\n\n" .
                   "📞 **Required Format:**\n" .
                   "✅ 09XX-XXX-XXXX (11 digits total)\n" .
                   "✅ Must start with 09\n\n" .
                   "**Examples:**\n" .
                   "• 0912-345-6789 ✓\n" .
                   "• 0917-123-4567 ✓\n" .
                   "• 0998-765-4321 ✓\n\n" .
                   "❌ **Invalid Formats:**\n" .
                   "• 912-345-6789 (missing 0)\n" .
                   "• +63912-345-6789 (international format not needed)\n\n" .
                   "💡 We'll use this number to send you updates about your request's progress!";
        },
        'track_status' => function() {
            return "**Tracking Your Request:**\n\n" .
                   "📊 **Status Types:**\n\n" .
                   "🟡 **Pending** - Your report is under review by our staff\n" .
                   "🔵 **In Progress** - Repair work is currently ongoing\n" .
                   "🟢 **Completed** - Issue has been successfully fixed!\n" .
                   "🔴 **Delayed** - Temporarily on hold (we'll notify you why)\n\n" .
                   "📍 **How to Check Status:**\n" .
                   "• Go to the **Reports Page** in the navigation menu\n" .
                   "• View recent maintenance schedules and status\n" .
                   "• Search by date, type, location, or budget\n\n" .
                   "⏱️ **Response Times:**\n" .
                   "• Review: Within 24 hours\n" .
                   "• Updates: Regular progress notifications\n" .
                   "• Completion: Depends on issue severity";
        },
        'dark_mode' => function() {
            return "**Dark Mode Feature:**\n\n" .
                   "🌙 **How to Toggle:**\n" .
                   "• Click the moon/sun icon (🌙/☀️) in the top navigation bar\n" .
                   "• Available on both desktop and mobile views\n\n" .
                   "💾 **Auto-Save:**\n" .
                   "• Your preference is automatically saved\n" .
                   "• Persists across page visits and sessions\n\n" .
                   "✨ **Benefits:**\n" .
                   "• Reduces eye strain in low-light environments\n" .
                   "• Saves battery on OLED screens\n" .
                   "• Modern, sleek interface";
        },
        'language_switch' => function() {
            return "**Language Translation Feature:**\n\n" .
                   "🌐 **How to Switch Languages:**\n" .
                   "• **Desktop:** Click the globe 🌐 icon (EN / FIL) in the top-right navigation\n" .
                   "• **Mobile:** Tap the globe icon near the top of the screen\n" .
                   "• The page translates instantly — no reload needed!\n\n" .
                   "🎯 **What Gets Translated:**\n" .
                   "• All navigation menus and buttons\n" .
                   "• Page content, headings, and descriptions\n" .
                   "• Form labels, placeholders, and instructions\n" .
                   "• Privacy Policy and Terms & Conditions pages\n" .
                   "• This chatbot's messages and suggestion chips\n\n" .
                   "💾 **Preference Saved** locally — no account needed\n\n" .
                   "🔔 **Note:** A toast notification confirms your language switch.";
        },
        'privacy_policy' => function() use ($context) {
            $onPage = ($context === 'privacy')
                ? "\n\nℹ️ You're currently on the **Privacy Policy** page — scroll up to read the full document!"
                : "\n\n📄 **Access it here:** Footer → 'Privacy Policy'";
            return "**Privacy Policy — Key Points:**\n\n" .
                   "🔒 **Governing Law:** RA 10173 (Data Privacy Act of 2012)\n\n" .
                   "📋 **What We Collect:**\n" .
                   "• Names/user identifiers & credentials\n" .
                   "• Contact info & location data for reports\n" .
                   "• System activity logs\n\n" .
                   "🎯 **Purpose:** Infrastructure coordination only — never sold.\n\n" .
                   "👤 **Your Rights (RA 10173):**\n" .
                   "✅ Right to be Informed · Access · Correction · Object · Erasure · NPC complaint\n\n" .
                   "📞 **DPO:** dpo@infragovservices.com | (02) 8988-4242" .
                   $onPage;
        },
        'terms_conditions' => function() use ($context) {
            $onPage = ($context === 'terms')
                ? "\n\nℹ️ You're currently on the **Terms & Conditions** page — scroll up to read the full document!"
                : "\n\n📄 **Access it here:** Footer → 'Terms of Service'";
            return "**Terms & Conditions — Summary:**\n\n" .
                   "📜 **Legal Basis:** RA 10173 & NPC regulations\n\n" .
                   "🤖 **AI Disclaimer:**\n" .
                   "• AI recommendations are for decision support only\n" .
                   "• Final decisions remain with authorized personnel\n\n" .
                   "✅ **Your Consent:** By using the system and checking the consent checkbox, you accept these terms.\n\n" .
                   "📞 admin@infragovservices.com | dpo@infragovservices.com | (02) 8988-4242" .
                   $onPage;
        },
        'about_page' => function() {
            return "**About CIMMS Page:**\n\n" .
                   "ℹ️ The About page covers:\n\n" .
                   "📖 System overview & how CIMMS works\n" .
                   "🎯 Mission: Improve maintenance efficiency & citizen-LGU communication\n" .
                   "🛠️ Features: Online reporting, real-time tracking, direct LGU coordination\n" .
                   "💡 Vision: Trusted digital platform for Quezon City residents\n\n" .
                   "📍 **Access:** Click 'About' in the navigation menu!";
        },
        'reports_page' => function() {
            return "**Reports Page Guide:**\n\n" .
                   "📊 **Quick Stats:** Completed, Ongoing & Pending repair counters\n\n" .
                   "📋 **Maintenance Table:** ID, dates, type, location, budget, status\n\n" .
                   "🔍 **Search:** Filter by date, type, location, budget, or status\n\n" .
                   "📱 **Mobile:** Card-style layout for easy reading\n\n" .
                   "📍 **Access:** Click 'Reports' in navigation!";
        },
        'navigation' => function() {
            return "**Portal Navigation:**\n\n" .
                   "🏠 **Home** · 📄 **Reports** · 📋 **Requests** · ℹ️ **About**\n\n" .
                   "🔗 **Footer:** Privacy Policy · Terms of Service · User Guide · FAQs\n\n" .
                   "📱 **Mobile:** Tap ☰ menu icon (top-left) for sidebar\n\n" .
                   "🌐 **Language** switchable via globe icon on any page";
        },
        'reportable_types' => function() {
            return "**Infrastructure Issues You Can Report:**\n\n" .
                   "🛣️ **Roads** - Potholes, cracks, surface damage\n" .
                   "💡 **Street Lights** - Broken/flickering lights\n" .
                   "🚰 **Drainage** - Clogged drains, flooding\n" .
                   "🏢 **Public Facilities** - Building issues, parks\n" .
                   "💧 **Water Supply** - Leaks, low pressure, pipe bursts\n" .
                   "⚡ **Electrical** - Exposed wiring, electrical hazards\n" .
                   "📝 **Other** - Any other infrastructure concern\n\n" .
                   "📍 All reports must be within **Quezon City** boundaries.\n" .
                   "📸 At least 1 photo evidence required per report.";
        },
        'help_support' => function() {
            return "**Need Additional Help?**\n\n" .
                   "📧 **Email:** contact@infragovservices.com\n" .
                   "☎️ **Phone:** (02) 8988-4242\n" .
                   "🕐 Monday–Friday, 8AM–5PM\n\n" .
                   "📍 **Office:** Quezon City Hall, Quezon City\n\n" .
                   "🔒 **Privacy:** dpo@infragovservices.com\n\n" .
                   "Just ask me anything! 😊";
        },
        'about_system' => function() {
            return "**About CIMMS:**\n\n" .
                   "🏛️ **Community Infrastructure Maintenance Management System**\n\n" .
                   "🎯 Digital platform for Quezon City residents to report & track infrastructure issues.\n\n" .
                   "✨ **Key Features:**\n" .
                   "✅ Online issue reporting · Real-time tracking · Interactive map\n" .
                   "✅ Photo evidence upload · Bilingual (EN/FIL) · RA 10173 compliant\n\n" .
                   "📍 Learn more on the **About** page!";
        },
        'technical_issue' => function() {
            return "**Technical Troubleshooting:**\n\n" .
                   "🔧 **Quick Fixes:**\n\n" .
                   "1️⃣ **Refresh** - Ctrl+F5 (Windows) / Cmd+Shift+R (Mac)\n" .
                   "2️⃣ **Clear Cache** - Chrome: Settings → Privacy → Clear browsing data\n" .
                   "3️⃣ **Try another browser** - Chrome (recommended), Firefox, Safari\n" .
                   "4️⃣ **Check Connection** - Switch between WiFi and mobile data\n\n" .
                   "❌ **Still not working?**\n" .
                   "📧 contact@infragovservices.com | ☎️ (02) 8988-4242";
        },
        'requirements' => function() {
            return "**Submission Requirements:**\n\n" .
                   "1️⃣ **Infrastructure Type** ✅\n" .
                   "2️⃣ **Location** ✅ - Within Quezon City; interactive map\n" .
                   "3️⃣ **Issue Description** ✅\n" .
                   "4️⃣ **Contact Number** ✅ - 09XX-XXX-XXXX (11 digits)\n" .
                   "5️⃣ **Photo Evidence** ✅ - Min 1, max 4 (JPG/PNG/WEBP)\n" .
                   "6️⃣ **Terms Agreement** ✅ - Consent checkbox\n\n" .
                   "📝 **Optional:** Your name (for follow-up)";
        },
        'gratitude' => function() {
            $r = ["You're very welcome! 😊 Happy to help! Is there anything else you'd like to know?","My pleasure! 🌟 Feel free to ask if you need any other assistance!","Glad I could help! 👍 Let me know if you have more questions!"];
            return $r[array_rand($r)];
        },
        'capabilities' => function() {
            return "**What I Can Help You With:**\n\n" .
                   "🤖 **I'm the InfraGovServices AI Assistant!**\n\n" .
                   "✅ Guide you through portal submission step by step\n" .
                   "✅ Analyze uploaded screenshots & images 📸\n" .
                   "✅ Answer questions about Privacy Policy & Terms\n" .
                   "✅ Help with navigation, features & requirements\n" .
                   "✅ Voice input support 🎙️ (Web Speech API)\n" .
                   "✅ English & Filipino language support 🌐\n\n" .
                   "🌟 **I'm here 24/7!** Just ask me anything.";
        },
        'yes_confirmation' => function() use ($history) {
            return !empty($history) ? "Great! I'm glad I could help. Is there anything else you'd like to know? 😊" : "Yes! How can I assist you? 😊";
        },
        'no_negation' => function() use ($history) {
            return !empty($history) ? "No problem! Feel free to ask if you need anything else. I'm here to help! 😊" : "Okay! Let me know if you need any assistance. I'm here to help! 😊";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "I see you're on the **Requests page**! 📋\n\nI can help you with:\n• Filling out the submission form\n• Using the location map\n• Photo upload requirements\n• Contact number format\n• Terms & Privacy consent checkbox\n\nWhat would you like to know?",
                'reports' => "You're viewing the **Reports page**! 📊\n\nI can help you:\n• Understand status types (Pending, In Progress, Completed, Delayed)\n• Use the search and filter function\n• Track maintenance progress\n\nWhat do you need help with?",
                'about'   => "Welcome to the **About page**! ℹ️\n\nI can explain the system purpose, CIMMS features, and how it serves QC residents.\n\nWhat interests you?",
                'home'    => "Welcome to **InfraGovServices**! 🏠\n\nI can help you submit a report, track requests, switch language, or understand our Privacy Policy.\n\nWhat would you like to do?",
                'privacy' => "You're on the **Privacy Policy page**! 🔒\n\nI can explain your rights under RA 10173, what data we collect, and how to contact our DPO.\n\nWhat would you like to know?",
                'terms'   => "You're on the **Terms & Conditions page**! 📜\n\nI can explain data collection purposes, the AI disclaimer, and your consent obligations.\n\nWhat would you like to know?"
            ];
            return $contextResponses[$context] ??
                   "I'm here to help! 😊\n\nI can assist with:\n• 📝 Reporting infrastructure issues\n• 📸 Analyzing your screenshots\n• 🔍 Tracking requests\n• 🗺️ Using the map\n• 🔒 Privacy Policy & Terms\n• 🌐 Language switching (EN/FIL)\n• ℹ️ System information\n\nWhat would you like to know?";
        }
    ];

    // ── Tagalog responses ────────────────────────────────────
    $responses_tl = [
        'image_analysis' => function() use ($imageAnalysis, $buildImageResponse) {
            return $buildImageResponse($imageAnalysis, 'tl');
        },
        'greeting' => function() {
            $greetings = [
                "Kumusta! 👋 Ako ang InfraGovServices AI assistant. Paano kita matutulungan?",
                "Magandang araw! 👋 Maligayang pagdating sa InfraGovServices. Ano ang gusto mong malaman?",
                "Helo! 👋 Nandito ako para tumulong sa iyo gamit ang CIMMS. Ano ang maitutulong ko?"
            ];
            return $greetings[array_rand($greetings)];
        },
        'how_are_you' => function() {
            return "Ayos lang ako, salamat sa pagtanong! 😊 Nandito ako at handa na tulungan ka. Paano kita matutulungan ngayon?";
        },
        'how_to_report' => function() {
            return "**Pagsusumite ng Ulat - Hakbang-Hakbang:**\n\n" .
                   "1️⃣ **Pumunta sa Pahina ng Mga Kahilingan**\n" .
                   "2️⃣ **Piliin ang Uri ng Imprastraktura**\n" .
                   "3️⃣ **Piliin ang Lokasyon** - Gamitin ang interactive na mapa\n" .
                   "4️⃣ **Ilarawan ang Isyu** - Detalyadong paglalarawan\n" .
                   "5️⃣ **Mag-upload ng Mga Larawan** - Hanggang 4 na larawan\n" .
                   "6️⃣ **Ilagay ang Numero ng Kontak** - 09XX-XXX-XXXX\n" .
                   "7️⃣ **Sumang-ayon sa mga Tuntunin** - I-check ang kahon\n" .
                   "8️⃣ **Isumite!**\n\n" .
                   "💡 Malinaw at maayos na larawan ang nakakatulong sa mas mabilis na pagtugon!";
        },
        'photo_upload' => function() {
            return "**Gabay sa Pag-upload ng Larawan:**\n\n" .
                   "📸 **Desktop:** I-click ang file input → piliin hanggang 4 na larawan\n" .
                   "📱 **Mobile:** I-tap ang 📷 para kumuha o pumili mula sa gallery\n\n" .
                   "✨ **Mga Format:** JPG, JPEG, PNG, WEBP · Max 4 larawan\n\n" .
                   "**Tips:** Kumuha sa magandang ilaw · Iba't ibang anggulo · Malinaw at naka-focus";
        },
        'location_map' => function() {
            return "**Paggamit ng Mapa ng Lokasyon:**\n\n" .
                   "1. I-click ang Location Field → bubukas ang interactive map\n" .
                   "2. Piliin ang Barangay mula sa dropdown\n" .
                   "3. Gamitin ang GPS 📍 para auto-detect\n" .
                   "4. I-click/i-drag ang marker sa eksaktong lugar\n" .
                   "5. I-save ang lokasyon\n\n" .
                   "⚠️ Tanggap lamang ang mga lokasyon sa loob ng Lungsod Quezon.";
        },
        'contact_format' => function() {
            return "**Format ng Numero ng Kontak:**\n\n" .
                   "✅ 09XX-XXX-XXXX (11 digits)\n" .
                   "✅ Dapat magsimula sa 09\n\n" .
                   "Halimbawa: 0912-345-6789 ✓\n\n" .
                   "💡 Gagamitin ang numerong ito para sa mga update ng iyong kahilingan!";
        },
        'track_status' => function() {
            return "**Pagsubaybay sa Iyong Kahilingan:**\n\n" .
                   "🟡 **Nakabinbin** · 🔵 **In Progress** · 🟢 **Natapos** · 🔴 **Naantala**\n\n" .
                   "📍 **Paano:** Pumunta sa Pahina ng Mga Ulat → maghanap ayon sa petsa, uri, o lokasyon\n\n" .
                   "⏱️ Pagsusuri: Sa loob ng 24 na oras";
        },
        'dark_mode' => function() {
            return "**Dark Mode Feature:**\n\n" .
                   "🌙 I-click ang moon/sun icon (🌙/☀️) sa itaas na navigation bar\n\n" .
                   "💾 Awtomatikong nase-save ang iyong kagustuhan\n\n" .
                   "✨ Binabawasan ang pagod ng mata at nakakatipid ng baterya sa OLED!";
        },
        'language_switch' => function() {
            return "**Feature ng Pagsasalin ng Wika:**\n\n" .
                   "🌐 **Desktop:** I-click ang globe icon 🌐 (EN / FIL) sa navigation\n" .
                   "📱 **Mobile:** I-tap ang globe icon sa itaas\n" .
                   "• Agad na nagsasalin — hindi kailangang i-reload!\n\n" .
                   "💾 Natatandaan ang iyong napiling wika sa lahat ng pahina\n\n" .
                   "🔔 Isang toast notification ang nagpapatunay ng pagpapalit.";
        },
        'privacy_policy' => function() use ($context) {
            $onPage = ($context === 'privacy')
                ? "\n\nℹ️ Kasalukuyan kang nasa **Pahina ng Privacy Policy** — mag-scroll pataas!"
                : "\n\n📄 **I-access:** Footer → 'Patakaran sa Privacy'";
            return "**Patakaran sa Privacy — Mga Pangunahing Punto:**\n\n" .
                   "🔒 **Batas:** RA 10173 (Data Privacy Act of 2012)\n\n" .
                   "📋 **Kinokolekta:** Pangalan, kontak, lokasyon, mga log ng aktibidad\n\n" .
                   "🎯 **Layunin:** Para sa koordinasyon ng imprastraktura lamang — hindi ibinibenta\n\n" .
                   "👤 **Iyong Karapatan:** Maalaman · Ma-access · Magwasto · Tumutol · Burahin · NPC\n\n" .
                   "📞 dpo@infragovservices.com | (02) 8988-4242" . $onPage;
        },
        'terms_conditions' => function() use ($context) {
            $onPage = ($context === 'terms')
                ? "\n\nℹ️ Kasalukuyan kang nasa **Pahina ng Terms & Conditions** — mag-scroll pataas!"
                : "\n\n📄 **I-access:** Footer → 'Mga Tuntunin ng Serbisyo'";
            return "**Mga Tuntunin at Kondisyon — Buod:**\n\n" .
                   "📜 **Batayan:** RA 10173 at mga regulasyon ng NPC\n\n" .
                   "🤖 **Disclaimer sa AI:** Para sa suporta sa desisyon lamang — hindi pamalit sa paghatol ng tao\n\n" .
                   "✅ **Pahintulot:** Sa paggamit ng sistema at pag-check ng consent checkbox, tinatanggap mo ang mga tuntunin\n\n" .
                   "📞 admin@infragovservices.com | (02) 8988-4242" . $onPage;
        },
        'about_page' => function() {
            return "**Tungkol sa CIMMS na Pahina:**\n\n" .
                   "📖 Pangkalahatang ideya ng sistema\n" .
                   "🎯 Layunin: Mapabuti ang kahusayan ng pagpapanatili\n" .
                   "🛠️ Mga feature: Online na pag-uulat, real-time na pagsubaybay\n\n" .
                   "📍 I-click ang 'Tungkol Sa' sa navigation menu!";
        },
        'reports_page' => function() {
            return "**Gabay sa Pahina ng Mga Ulat:**\n\n" .
                   "📈 Mabilis na stats: Natapos, Kasalukuyan, Nakabinbin\n" .
                   "📋 Talahanayan ng pagpapanatili na may ID, uri, lokasyon, badyet, katayuan\n" .
                   "🔍 Maghanap ayon sa petsa, uri, lokasyon\n\n" .
                   "📍 I-click ang 'Mga Ulat' sa navigation!";
        },
        'navigation' => function() {
            return "**Navigation sa Portal:**\n\n" .
                   "🏠 Tahanan · 📄 Mga Ulat · 📋 Mga Kahilingan · ℹ️ Tungkol Sa\n\n" .
                   "🔗 Footer: Patakaran sa Privacy · Mga Tuntunin · Gabay ng User\n\n" .
                   "📱 Mobile: I-tap ang ☰ para sa sidebar\n\n" .
                   "🌐 Wika: Globe icon sa anumang pahina";
        },
        'reportable_types' => function() {
            return "**Mga Isyu na Maaari Mong Iulat:**\n\n" .
                   "🛣️ Mga Kalsada · 💡 Mga Ilaw sa Kalye · 🚰 Drainage\n" .
                   "🏢 Pampublikong Pasilidad · 💧 Supply ng Tubig · ⚡ Elektrikal\n" .
                   "📝 Iba Pa\n\n" .
                   "📍 Dapat nasa loob ng Lungsod Quezon.\n" .
                   "📸 Kailangan ng kahit 1 larawan bilang ebidensya.";
        },
        'help_support' => function() {
            return "**Kailangan ng Tulong?**\n\n" .
                   "📧 contact@infragovservices.com\n" .
                   "☎️ (02) 8988-4242\n" .
                   "🕐 Lunes–Biyernes, 8AM–5PM\n\n" .
                   "Magtanong lang sa akin! 😊";
        },
        'about_system' => function() {
            return "**Tungkol sa CIMMS:**\n\n" .
                   "🏛️ Community Infrastructure Maintenance Management System\n\n" .
                   "🎯 Digital na platform para sa mga residente ng Lungsod Quezon.\n\n" .
                   "✅ Online na pag-uulat · Real-time na pagsubaybay · Interactive na mapa\n" .
                   "✅ Pag-upload ng larawan · Suporta sa EN/FIL · RA 10173 compliant\n\n" .
                   "📍 Alamin pa sa **Tungkol Sa** pahina!";
        },
        'technical_issue' => function() {
            return "**Pag-troubleshoot:**\n\n" .
                   "1️⃣ I-refresh: Ctrl+F5 / Cmd+Shift+R\n" .
                   "2️⃣ I-clear ang Cache\n" .
                   "3️⃣ Subukan ang ibang browser\n" .
                   "4️⃣ Suriin ang koneksyon\n\n" .
                   "❌ Hindi pa rin gumagana?\n" .
                   "📧 contact@infragovservices.com | ☎️ (02) 8988-4242";
        },
        'requirements' => function() {
            return "**Mga Kinakailangan sa Pagsusumite:**\n\n" .
                   "1️⃣ Uri ng Imprastraktura ✅\n" .
                   "2️⃣ Lokasyon ✅ - Sa loob ng Lungsod Quezon\n" .
                   "3️⃣ Paglalarawan ng Isyu ✅\n" .
                   "4️⃣ Numero ng Kontak ✅ - 09XX-XXX-XXXX\n" .
                   "5️⃣ Larawan ✅ - Min 1, max 4\n" .
                   "6️⃣ Consent Checkbox ✅";
        },
        'gratitude' => function() {
            $r = ["Walang anuman! 😊 Mayroon pa bang gusto mong malaman?","Ikinagagalak ko! 🌟 Huwag mag-atubiling magtanong!","Mabuti naman na nakatulong ako! 👍"];
            return $r[array_rand($r)];
        },
        'capabilities' => function() {
            return "**Ano ang Maitutulong Ko:**\n\n" .
                   "🤖 Ako ang InfraGovServices AI Assistant!\n\n" .
                   "✅ Gagabayan ka sa pagsusumite ng ulat\n" .
                   "✅ Magsusuri ng iyong mga screenshot 📸\n" .
                   "✅ Sasagutin ang mga tanong tungkol sa Privacy Policy at Mga Tuntunin\n" .
                   "✅ Suporta sa voice input 🎙️\n" .
                   "✅ Ingles at Filipino 🌐\n\n" .
                   "🌟 Nandito ako 24/7!";
        },
        'yes_confirmation' => function() use ($history) {
            return !empty($history) ? "Magaling! Masaya akong nakatulong. Mayroon pa bang gusto mong malaman? 😊" : "Oo! Paano kita matutulungan? 😊";
        },
        'no_negation' => function() use ($history) {
            return !empty($history) ? "Walang problema! Huwag mag-atubiling magtanong. 😊" : "Sige! Sabihin mo lang kung kailangan mo ng tulong. 😊";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "Nasa **Pahina ng Mga Kahilingan** ka! 📋\n\nMaaari kitang tulungan sa form, mapa, larawan, numero ng kontak, at consent checkbox.\n\nAno ang gusto mong malaman?",
                'reports' => "Tinitingnan mo ang **Pahina ng Mga Ulat**! 📊\n\nMaaari kitang tulungan na maunawaan ang mga status, gamitin ang search, at subaybayan ang progreso.\n\nAno ang kailangan mo?",
                'about'   => "Maligayang pagdating sa **Tungkol Sa pahina**! ℹ️\n\nAno ang nakakainteresa sa iyo?",
                'home'    => "Maligayang pagdating sa **InfraGovServices**! 🏠\n\nAno ang gusto mong gawin?",
                'privacy' => "Nasa **Pahina ng Patakaran sa Privacy** ka! 🔒\n\nAno ang gusto mong malaman?",
                'terms'   => "Nasa **Pahina ng Mga Tuntunin at Kondisyon** ka! 📜\n\nAno ang gusto mong malaman?"
            ];
            return $contextResponses[$context] ??
                   "Nandito ako para tumulong! 😊\n\n• 📝 Pag-uulat · 📸 Pagsusuri ng screenshot · 🔍 Pagsubaybay\n• 🗺️ Mapa · 🔒 Privacy · 🌐 Wika · ℹ️ Impormasyon\n\nAno ang gusto mong malaman?";
        }
    ];

    $responses = ($lang === 'tl') ? $responses_tl : $responses_en;

    if (isset($responses[$intent])) {
        $fn = $responses[$intent];
        return $fn($message, $context, $history);
    }
    $fn = $responses['general'];
    return $fn($message, $context);
}

// ============================================================
// MAIN PROCESSING
// ============================================================

// 1) Analyze image if present
$imageAnalysis = null;
$aiCardHtml    = null;

if (!empty($imageBase64)) {
    $imageAnalysis = analyzeImageForChatbot($imageBase64, $aiResult, $lang);
    $aiCardHtml    = $imageAnalysis ? ($imageAnalysis['aiCardHtml'] ?? null) : null;
}

// 2) Detect intent
// If image was sent but message is generic, force image_analysis intent
if (!empty($imageBase64) && empty($userMessage)) {
    $userMessage = $lang === 'tl'
        ? 'Nagsumite ako ng screenshot ng website para sa pagsusuri.'
        : 'I submitted a screenshot of the website for analysis.';
}

$intentData = detectIntent($userMessage, $context);
$intent     = $intentData['intent'];
$confidence = $intentData['confidence'];

// Override to image_analysis when image is present and intent isn't already specific
if (!empty($imageBase64) && in_array($intent, ['general', 'image_analysis'])) {
    $intent     = 'image_analysis';
    $confidence = 0.98;
}

// 3) Store history
$_SESSION['chat_history'][] = [
    'message'   => $userMessage,
    'timestamp' => time(),
    'intent'    => $intent,
    'context'   => $context,
    'lang'      => $lang,
    'hasImage'  => !empty($imageBase64)
];
if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

// 4) Generate response
$botResponse = generateResponse($userMessage, $intent, $context, $_SESSION['chat_history'], $lang, $imageAnalysis);

// 5) Log
logInteraction($userMessage, $botResponse, $intent, $confidence, $context, $lang, !empty($imageBase64));

// 6) Output
echo json_encode([
    'response'   => $botResponse,
    'timestamp'  => date('Y-m-d H:i:s'),
    'intent'     => $intent,
    'confidence' => $confidence,
    'aiCardHtml' => $aiCardHtml    // forwarded to widget for inline display
]);

// ============================================================
// LOGGING
// ============================================================

function logInteraction($userMessage, $botResponse, $intent, $confidence, $context, $lang, $hasImage = false) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $logFile  = $logDir . '/chatbot_interactions_' . date('Y-m-d') . '.log';
    $logEntry = json_encode([
        'timestamp'    => date('Y-m-d H:i:s'),
        'session_id'   => session_id(),
        'user_message' => $userMessage,
        'bot_response' => $botResponse,
        'intent'       => $intent,
        'confidence'   => $confidence,
        'context'      => $context,
        'lang'         => $lang,
        'has_image'    => $hasImage,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]) . PHP_EOL;

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}