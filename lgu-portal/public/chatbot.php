<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

session_start();

$input       = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($input['message']) ? trim($input['message']) : '';
$context     = isset($input['context']) ? $input['context'] : 'general';
$lang        = isset($input['lang'])    ? $input['lang']    : 'en';   // 'en' or 'tl'

if (empty($userMessage)) {
    echo json_encode(['error' => 'Message is required']);
    exit();
}

if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// ============================================
// INTENT DETECTION
// ============================================

function detectIntent($message, $context) {
    $message = strtolower($message);
    
    $intents = [
        'greeting' => [
            'patterns' => [
                '/\b(hi|hello|hey|good morning|good afternoon|good evening|greetings)\b/',
                '/^(yo|sup|wassup)\b/',
                // Tagalog greetings
                '/\b(kumusta|magandang umaga|magandang hapon|magandang gabi|kamusta|helo|oi|uy)\b/'
            ],
            'confidence' => 0.95
        ],
        'how_to_report' => [
            'patterns' => [
                '/how (to|do|can).*(report|submit|file|create).*(issue|problem|request|concern)/',
                '/\b(report|submit|file).*(how|process|steps)/',
                '/what.*(process|steps).*(report|submit)/',
                // Tagalog
                '/paano.*(mag-?ulat|magsumite|mag-submit)/',
                '/\b(iulat|mag-ulat|mag-report|mag-file)\b/'
            ],
            'confidence' => 0.90
        ],
        'photo_upload' => [
            'patterns' => [
                '/\b(photo|picture|evidence|image|upload|camera|capture|pic)\b/',
                '/how.*(add|attach|upload|take).*photo/',
                '/\b(jpg|jpeg|png|webp)\b/',
                // Tagalog
                '/\b(larawan|litrato|kuha|i-upload|mag-upload|ipadala.*larawan)\b/'
            ],
            'confidence' => 0.85
        ],
        'location_map' => [
            'patterns' => [
                '/\b(location|map|barangay|address|gps|where|place)\b/',
                '/how.*(select|choose|find|mark).*location/',
                '/\b(quezon city|qc)\b.*location/',
                // Tagalog
                '/\b(lokasyon|mapa|lugar|barangay|saan|tirahan)\b/',
                '/paano.*(pumili|piliin|hanapin).*lokasyon/'
            ],
            'confidence' => 0.85
        ],
        'contact_format' => [
            'patterns' => [
                '/\b(contact|phone|number|mobile|cellphone|09)\b/',
                '/\bformat.*number\b/',
                '/\b11 digit/',
                // Tagalog
                '/\b(numero|telepono|selpon|cellphone|kontak|09\d{2})\b/',
                '/\bformat.*numero\b/'
            ],
            'confidence' => 0.85
        ],
        'track_status' => [
            'patterns' => [
                '/\b(track|status|check|follow up|progress|update|monitor)\b/',
                '/where.*(request|report)/',
                '/\b(pending|in progress|completed)\b/',
                // Tagalog
                '/\b(subaybayan|katayuan|status|progreso|tingnan|saan na)\b/',
                '/nasaan.*(kahilingan|ulat)/'
            ],
            'confidence' => 0.85
        ],
        'dark_mode' => [
            'patterns' => [
                '/\b(dark mode|theme|night mode|light mode)\b/',
                '/\bchange.*theme\b/',
                // Tagalog
                '/\b(madilim|maliwanag|tema|dark mode|light mode)\b/',
                '/palitan.*kulay/'
            ],
            'confidence' => 0.90
        ],
        'privacy_terms' => [
            'patterns' => [
                '/\b(privacy|terms|data|personal information|policy|agreement)\b/',
                '/\b(ra 10173|data privacy act)\b/',
                // Tagalog
                '/\b(privacy|datos|personal.*impormasyon|patakaran|kasunduan)\b/',
                '/\bra 10173\b/'
            ],
            'confidence' => 0.85
        ],
        'navigation' => [
            'patterns' => [
                '/\b(pages|navigate|menu|sections|where is)\b/',
                '/how.*(find|get to|go to)/',
                '/\b(home|reports|requests|about)\b.*page/',
                // Tagalog
                '/\b(pahina|menu|seksyon|saan|paano pumunta)\b/'
            ],
            'confidence' => 0.80
        ],
        'reportable_types' => [
            'patterns' => [
                '/what can.*(report|submit)/',
                '/\b(types|kinds|categories).*infrastructure/',
                '/\b(roads|streetlights|drainage|water|electrical)\b/',
                // Tagalog
                '/ano.*(maaaring|pwedeng).*(iulat|i-report)/',
                '/\b(kalsada|ilaw|drainage|tubig|kuryente)\b/'
            ],
            'confidence' => 0.85
        ],
        'help_support' => [
            'patterns' => [
                '/\b(help|support|contact|assistance|phone|email)\b/',
                '/\bneed help\b/',
                '/\bcontact.*support\b/',
                // Tagalog
                '/\b(tulong|suporta|kontak|email|kailangan.*tulong)\b/',
                '/paano.*makipag-ugnayan/'
            ],
            'confidence' => 0.80
        ],
        'about_system' => [
            'patterns' => [
                '/\b(about|what is|cimms|infragovservices|system)\b/',
                '/tell me about/',
                '/\bwhat.*(does|do).*system\b/',
                // Tagalog
                '/\b(tungkol|ano ang|cimms|sistema)\b/',
                '/sabihin.*tungkol/'
            ],
            'confidence' => 0.80
        ],
        'technical_issue' => [
            'patterns' => [
                '/\b(error|bug|broken|not working|issue|problem).*\b(site|system|page)/',
                '/\bcan\'?t.*\b(submit|upload|login)/',
                '/\b(stuck|freeze|loading)\b/',
                // Tagalog
                '/\b(hindi gumagana|error|sira|hindi ma-submit|naka-stuck)\b/'
            ],
            'confidence' => 0.85
        ],
        'requirements' => [
            'patterns' => [
                '/what.*(need|require|necessary)/',
                '/\b(requirement|needed|mandatory)\b/',
                '/do i need/',
                // Tagalog
                '/ano.*(kailangan|kinakailangan)/',
                '/\b(requirements|kinakailangan|kailangan)\b/'
            ],
            'confidence' => 0.75
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

// ============================================
// BILINGUAL RESPONSE GENERATION
// ============================================

function generateResponse($message, $intent, $context, $history, $lang = 'en') {
    $responses_en = [
        'greeting' => function() {
            $greetings = [
                "Hello! 👋 I'm here to help you with InfraGovServices. How can I assist you today?",
                "Hi there! 👋 Welcome to InfraGovServices. What would you like to know?",
                "Good day! 👋 I'm your CIMMS assistant. How may I help you?"
            ];
            return $greetings[array_rand($greetings)];
        },
        'how_to_report' => function() {
            return "To submit a report, follow these steps:\n\n" .
                   "1. Go to the Requests page\n" .
                   "2. Select infrastructure type\n" .
                   "3. Choose location on the interactive map\n" .
                   "4. Describe the problem in detail\n" .
                   "5. Upload photos (up to 4 images)\n" .
                   "6. Enter your contact number (09XX-XXX-XXXX)\n" .
                   "7. Agree to Terms & Privacy Policy\n" .
                   "8. Click Submit!\n\n" .
                   "💡 Tip: Clear photos help us respond faster!";
        },
        'photo_upload' => function() {
            return "You can upload up to 4 photos as evidence:\n\n" .
                   "📸 Desktop: Click the file input to browse your files\n" .
                   "📱 Mobile: Tap the camera button (📷) to capture directly\n\n" .
                   "Accepted formats: JPG, JPEG, PNG, WEBP\n\n" .
                   "Photos help our team assess the issue quickly and prioritize urgent repairs!";
        },
        'location_map' => function() {
            return "Select your location using our interactive map:\n\n" .
                   "1. Click the location field to open the map\n" .
                   "2. Choose your barangay from the dropdown\n" .
                   "3. Use GPS 📍 to find your current location\n" .
                   "4. Click/drag on the map to pinpoint the exact spot\n\n" .
                   "Note: Only Quezon City locations are accepted.";
        },
        'contact_format' => function() {
            return "Your contact number must be in Philippine mobile format:\n\n" .
                   "✅ Format: 09XX-XXX-XXXX (11 digits)\n" .
                   "✅ Example: 0912-345-6789\n\n" .
                   "📞 We'll use this to update you about your request's progress!";
        },
        'track_status' => function() {
            return "Track your request status on the Reports page:\n\n" .
                   "📊 Statuses:\n" .
                   "• Pending - Under review\n" .
                   "• In Progress - Being worked on\n" .
                   "• Completed - Fixed!\n" .
                   "• Delayed - Temporarily on hold\n\n" .
                   "💡 Check the Reports page for recent maintenance schedules!";
        },
        'dark_mode' => function() {
            return "Toggle dark mode using the 🌙/☀️ icon in the top navigation.\n\n" .
                   "Your preference is saved automatically and will persist across visits!";
        },
        'privacy_terms' => function() {
            return "We comply with the Data Privacy Act of 2012 (RA 10173).\n\n" .
                   "🔒 Your data is used only for infrastructure coordination.\n" .
                   "📋 You must agree to our Terms & Privacy Policy before submitting.\n\n" .
                   "View full policies in the footer or during submission!";
        },
        'navigation' => function() {
            return "The portal has 4 main pages:\n\n" .
                   "🏠 Home - Dashboard overview\n" .
                   "📄 Reports - View maintenance status\n" .
                   "📋 Requests - Submit new issues\n" .
                   "ℹ️ About - System information\n\n" .
                   "Use the navigation menu to switch between pages!";
        },
        'reportable_types' => function() {
            return "You can report these infrastructure issues:\n\n" .
                   "🛣️ Roads - Potholes, cracks, damage\n" .
                   "💡 Street Lights - Broken, flickering\n" .
                   "🚰 Drainage - Clogs, flooding\n" .
                   "🏢 Public Facilities - Building issues\n" .
                   "💧 Water Supply - Leaks, pressure problems\n" .
                   "⚡ Electrical - Power issues\n" .
                   "📝 Other - Specify in description";
        },
        'help_support' => function() {
            return "Need assistance? Contact us:\n\n" .
                   "📧 Email: contact@infragovservices.com\n" .
                   "📞 Phone: (02) 8988-4242\n\n" .
                   "I can also help with questions about the portal!";
        },
        'about_system' => function() {
            return "CIMMS (Community Infrastructure Maintenance Management System) is Quezon City's digital platform for:\n\n" .
                   "✅ Reporting infrastructure issues\n" .
                   "✅ Tracking maintenance progress\n" .
                   "✅ Improving efficiency & transparency\n" .
                   "✅ Faster response times\n\n" .
                   "Built exclusively for Quezon City residents!";
        },
        'technical_issue' => function() {
            return "Sorry to hear you're experiencing technical issues! 😔\n\n" .
                   "Quick fixes:\n" .
                   "1. Refresh the page (Ctrl+F5)\n" .
                   "2. Clear your browser cache\n" .
                   "3. Try a different browser\n\n" .
                   "Still not working? Email contact@infragovservices.com with details!";
        },
        'requirements' => function() {
            return "To submit a request, you need:\n\n" .
                   "✅ Infrastructure type\n" .
                   "✅ Location (Quezon City only)\n" .
                   "✅ Issue description\n" .
                   "✅ Contact number (09XX-XXX-XXXX)\n" .
                   "✅ Photos (at least 1, up to 4)\n" .
                   "✅ Agreement to Terms & Privacy\n\n" .
                   "All fields are required for submission!";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "I see you're on the request form! Need help with:\n\n• Filling out the form?\n• Uploading photos?\n• Selecting location?\n• Understanding requirements?",
                'reports'  => "Looking at reports? I can help you:\n\n• Understand status types\n• Find specific requests\n• Track maintenance progress",
                'home'     => "Welcome to InfraGovServices! I can help you:\n\n• Submit a new report\n• Track existing requests\n• Navigate the system\n• Understand features",
                'about'    => "Learning about CIMMS? Ask me about:\n\n• System purpose\n• Features\n• How it works\n• Who can use it"
            ];
            return $contextResponses[$context] ??
                   "I can help with: reporting issues, tracking requests, navigating the system, or understanding features.\n\nWhat would you like to know?";
        }
    ];

    // ---- Tagalog responses ----
    $responses_tl = [
        'greeting' => function() {
            $greetings = [
                "Kumusta! 👋 Nandito ako para tulungan kayo sa InfraGovServices. Paano kita matutulungan ngayon?",
                "Magandang araw! 👋 Maligayang pagdating sa InfraGovServices. Ano ang gusto mong malaman?",
                "Helo! 👋 Ako ang inyong CIMMS assistant. Paano ko kayo matutulungan?"
            ];
            return $greetings[array_rand($greetings)];
        },
        'how_to_report' => function() {
            return "Para magsumite ng ulat, sundin ang mga hakbang na ito:\n\n" .
                   "1. Pumunta sa pahina ng Mga Kahilingan\n" .
                   "2. Piliin ang uri ng imprastraktura\n" .
                   "3. Piliin ang lokasyon sa interactive na mapa\n" .
                   "4. Ilarawan nang detalyado ang problema\n" .
                   "5. Mag-upload ng mga larawan (hanggang 4 na larawan)\n" .
                   "6. Ilagay ang iyong numero ng telepono (09XX-XXX-XXXX)\n" .
                   "7. Sumang-ayon sa Mga Tuntunin at Patakaran sa Privacy\n" .
                   "8. I-click ang Isumite!\n\n" .
                   "💡 Tip: Ang malinaw na mga larawan ay nagpapabilis ng aming tugon!";
        },
        'photo_upload' => function() {
            return "Maaari kang mag-upload ng hanggang 4 na larawan bilang ebidensya:\n\n" .
                   "📸 Desktop: I-click ang file input para mag-browse ng mga file\n" .
                   "📱 Mobile: I-tap ang button ng camera (📷) para kumuha ng larawan\n\n" .
                   "Tinatanggap na mga format: JPG, JPEG, PNG, WEBP\n\n" .
                   "Ang mga larawan ay nakakatulong sa aming koponan na mabilis na masuri ang isyu!";
        },
        'location_map' => function() {
            return "Piliin ang iyong lokasyon gamit ang aming interactive na mapa:\n\n" .
                   "1. I-click ang field ng lokasyon para buksan ang mapa\n" .
                   "2. Piliin ang iyong barangay mula sa dropdown\n" .
                   "3. Gamitin ang GPS 📍 para mahanap ang iyong kasalukuyang lokasyon\n" .
                   "4. I-click/i-drag sa mapa para tukuyin ang eksaktong lugar\n\n" .
                   "Paalala: Tinatanggap lamang ang mga lokasyon sa Lungsod Quezon.";
        },
        'contact_format' => function() {
            return "Ang iyong numero ng telepono ay dapat nasa format ng Philippine mobile:\n\n" .
                   "✅ Format: 09XX-XXX-XXXX (11 na digit)\n" .
                   "✅ Halimbawa: 0912-345-6789\n\n" .
                   "📞 Gagamitin namin ito para i-update kayo tungkol sa progreso ng inyong kahilingan!";
        },
        'track_status' => function() {
            return "Subaybayan ang katayuan ng inyong kahilingan sa pahina ng Mga Ulat:\n\n" .
                   "📊 Mga Katayuan:\n" .
                   "• Nakabinbin - Nasa ilalim ng pagsusuri\n" .
                   "• In Progress - Inaayos na\n" .
                   "• Natapos - Naayos na!\n" .
                   "• Naantala - Pansamantalang naka-hold\n\n" .
                   "💡 Tingnan ang pahina ng Mga Ulat para sa pinakabagong iskedyul ng pagpapanatili!";
        },
        'dark_mode' => function() {
            return "I-toggle ang dark mode gamit ang icon na 🌙/☀️ sa itaas na navigation.\n\n" .
                   "Ang inyong kagustuhan ay awtomatikong nase-save at mananatili sa bawat pagbisita!";
        },
        'privacy_terms' => function() {
            return "Sumusunod kami sa Data Privacy Act of 2012 (RA 10173).\n\n" .
                   "🔒 Ang inyong data ay ginagamit lamang para sa koordinasyon ng imprastraktura.\n" .
                   "📋 Kailangan ninyong sumang-ayon sa aming Mga Tuntunin at Patakaran sa Privacy bago magsumite.\n\n" .
                   "Tingnan ang buong mga patakaran sa footer o habang nagsusumite!";
        },
        'navigation' => function() {
            return "Ang portal ay may 4 na pangunahing pahina:\n\n" .
                   "🏠 Tahanan - Pangkalahatang dashboard\n" .
                   "📄 Mga Ulat - Tingnan ang katayuan ng pagpapanatili\n" .
                   "📋 Mga Kahilingan - Magsumite ng bagong isyu\n" .
                   "ℹ️ Tungkol Sa - Impormasyon ng sistema\n\n" .
                   "Gamitin ang navigation menu para lumipat sa pagitan ng mga pahina!";
        },
        'reportable_types' => function() {
            return "Maaari kang mag-ulat ng mga ganitong isyu sa imprastraktura:\n\n" .
                   "🛣️ Mga Kalsada - Mga butas, bitak, pinsala\n" .
                   "💡 Mga Ilaw ng Kalye - Sira, kumikislap\n" .
                   "🚰 Drainage - Nakabarang, pagbaha\n" .
                   "🏢 Pampublikong Pasilidad - Mga isyu sa gusali\n" .
                   "💧 Supply ng Tubig - Tumagas, problema sa presyon\n" .
                   "⚡ Elektrikal - Mga isyu sa kuryente\n" .
                   "📝 Iba Pa - Ilarawan sa kahilingan";
        },
        'help_support' => function() {
            return "Kailangan ng tulong? Makipag-ugnayan sa amin:\n\n" .
                   "📧 Email: contact@infragovservices.com\n" .
                   "📞 Telepono: (02) 8988-4242\n\n" .
                   "Maaari rin akong tumulong sa mga katanungan tungkol sa portal!";
        },
        'about_system' => function() {
            return "Ang CIMMS (Community Infrastructure Maintenance Management System) ay digital na platform ng Lungsod Quezon para sa:\n\n" .
                   "✅ Pag-uulat ng mga isyu sa imprastraktura\n" .
                   "✅ Pagsubaybay ng progreso ng pagpapanatili\n" .
                   "✅ Pagpapabuti ng kahusayan at transparency\n" .
                   "✅ Mas mabilis na mga oras ng pagtugon\n\n" .
                   "Ginawa nang eksklusibo para sa mga residente ng Lungsod Quezon!";
        },
        'technical_issue' => function() {
            return "Paumanhin na nakakaranas kayo ng mga teknikal na isyu! 😔\n\n" .
                   "Mabilis na solusyon:\n" .
                   "1. I-refresh ang pahina (Ctrl+F5)\n" .
                   "2. Linisin ang inyong browser cache\n" .
                   "3. Subukan ang ibang browser\n\n" .
                   "Hindi pa rin gumagana? Mag-email sa contact@infragovservices.com na may mga detalye!";
        },
        'requirements' => function() {
            return "Para magsumite ng kahilingan, kailangan mo ng:\n\n" .
                   "✅ Uri ng imprastraktura\n" .
                   "✅ Lokasyon (Lungsod Quezon lamang)\n" .
                   "✅ Paglalarawan ng isyu\n" .
                   "✅ Numero ng telepono (09XX-XXX-XXXX)\n" .
                   "✅ Mga larawan (kahit 1, hanggang 4)\n" .
                   "✅ Kasunduan sa Mga Tuntunin at Privacy\n\n" .
                   "Lahat ng mga field ay kinakailangan para sa pagsusumite!";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "Nasa form ng kahilingan ka! Kailangan mo bang tulungan sa:\n\n• Pagsagot ng form?\n• Pag-upload ng larawan?\n• Pagpili ng lokasyon?\n• Pag-unawa sa mga kinakailangan?",
                'reports'  => "Tinitingnan ang mga ulat? Maaari kitang tulungan na:\n\n• Maunawaan ang mga uri ng katayuan\n• Mahanap ang mga partikular na kahilingan\n• Subaybayan ang progreso ng pagpapanatili",
                'home'     => "Maligayang pagdating sa InfraGovServices! Maaari kitang tulungan na:\n\n• Magsumite ng bagong ulat\n• Subaybayan ang mga kasalukuyang kahilingan\n• Mag-navigate sa sistema\n• Maunawaan ang mga tampok",
                'about'    => "Natututo tungkol sa CIMMS? Magtanong sa akin tungkol sa:\n\n• Layunin ng sistema\n• Mga tampok\n• Paano ito gumagana\n• Sino ang maaaring gumamit nito"
            ];
            return $contextResponses[$context] ??
                   "Maaari akong tumulong sa: pag-uulat ng mga isyu, pagsubaybay ng mga kahilingan, pag-navigate sa sistema, o pag-unawa sa mga tampok.\n\nAno ang gusto mong malaman?";
        }
    ];

    // Select response set based on language
    $responses = ($lang === 'tl') ? $responses_tl : $responses_en;
    
    if (isset($responses[$intent])) {
        $fn = $responses[$intent];
        return $fn($message, $context, $history);
    }
    
    $fn = $responses['general'];
    return $fn($message, $context);
}

// ============================================
// MAIN PROCESSING
// ============================================

$intentData  = detectIntent($userMessage, $context);
$intent      = $intentData['intent'];
$confidence  = $intentData['confidence'];

$_SESSION['chat_history'][] = [
    'message'   => $userMessage,
    'timestamp' => time(),
    'intent'    => $intent,
    'context'   => $context,
    'lang'      => $lang
];

if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

$botResponse = generateResponse($userMessage, $intent, $context, $_SESSION['chat_history'], $lang);

logInteraction($userMessage, $botResponse, $intent, $confidence, $context, $lang);

echo json_encode([
    'response'   => $botResponse,
    'timestamp'  => date('Y-m-d H:i:s'),
    'intent'     => $intent,
    'confidence' => $confidence
]);

// ============================================
// LOGGING FUNCTIONS
// ============================================

function logInteraction($userMessage, $botResponse, $intent, $confidence, $context, $lang) {
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
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]) . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}