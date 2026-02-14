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
// ENHANCED INTENT DETECTION
// ============================================

function detectIntent($message, $context) {
    $message = strtolower($message);
    
    $intents = [
        'greeting' => [
            'patterns' => [
                '/\b(hi|hello|hey|good morning|good afternoon|good evening|greetings|howdy)\b/',
                '/^(yo|sup|wassup|what\'?s up)\b/',
                // Tagalog greetings
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
                // Tagalog
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
                // Tagalog
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
                // Tagalog
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
                // Tagalog
                '/\b(lokasyon|mapa|lugar|barangay|saan|tirahan|address)\b/',
                '/paano.*(pumili|piliin|hanapin|markahan).*lokasyon/',
                '/\bquezon city.*lokasyon/'
            ],
            'confidence' => 0.85
        ],
        'contact_format' => [
            'patterns' => [
                '/\b(contact|phone|number|mobile|cellphone|09)\b/',
                '/\bformat.*number\b/',
                '/\b11 digit/',
                '/phone.*format/',
                // Tagalog
                '/\b(numero|telepono|selpon|cellphone|kontak|09\d{2})\b/',
                '/\bformat.*numero\b/',
                '/\b11.*numero/'
            ],
            'confidence' => 0.85
        ],
        'track_status' => [
            'patterns' => [
                '/\b(track|status|check|follow up|progress|update|monitor|view.*report)\b/',
                '/where.*(request|report)/',
                '/\b(pending|in progress|completed|delayed)\b/',
                '/how.*check.*status/',
                // Tagalog
                '/\b(subaybayan|katayuan|status|progreso|tingnan|saan na|check)\b/',
                '/nasaan.*(kahilingan|ulat)/',
                '/paano.*tingnan.*status/'
            ],
            'confidence' => 0.85
        ],
        'dark_mode' => [
            'patterns' => [
                '/\b(dark mode|theme|night mode|light mode|appearance|color scheme)\b/',
                '/\bchange.*theme\b/',
                '/toggle.*dark/',
                // Tagalog
                '/\b(madilim|maliwanag|tema|dark mode|light mode|kulay)\b/',
                '/palitan.*kulay/',
                '/paano.*dark mode/'
            ],
            'confidence' => 0.90
        ],
        'language_switch' => [
            'patterns' => [
                '/\b(language|translate|translation|switch.*language|filipino|english|tagalog)\b/',
                '/change.*language/',
                '/how.*translate/',
                // Tagalog
                '/\b(wika|salin|i-translate|tagalog|ingles|english)\b/',
                '/paano.*mag-translate/',
                '/palitan.*wika/'
            ],
            'confidence' => 0.90
        ],
        'privacy_terms' => [
            'patterns' => [
                '/\b(privacy|terms|data|personal information|policy|agreement|consent)\b/',
                '/\b(ra 10173|data privacy act)\b/',
                '/terms.*condition/',
                '/privacy.*policy/',
                // Tagalog
                '/\b(privacy|datos|personal.*impormasyon|patakaran|kasunduan|pahintulot)\b/',
                '/\bra 10173\b/',
                '/mga.*tuntunin/'
            ],
            'confidence' => 0.85
        ],
        'about_page' => [
            'patterns' => [
                '/\b(about|about us|about page|cimms.*about|learn more)\b/',
                '/what.*about.*page/',
                '/tell me about.*system/',
                // Tagalog
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
                // Tagalog
                '/\b(pahina.*ulat|mga ulat|maintenance.*report)\b/',
                '/saan.*ulat/',
                '/paano.*tingnan.*ulat/'
            ],
            'confidence' => 0.85
        ],
        'navigation' => [
            'patterns' => [
                '/\b(pages|navigate|menu|sections|where is|find.*page)\b/',
                '/how.*(find|get to|go to)/',
                '/\b(home|reports|requests|about)\b.*page/',
                '/show me.*pages/',
                // Tagalog
                '/\b(pahina|menu|seksyon|saan|paano pumunta|navigation)\b/',
                '/ipakita.*pahina/'
            ],
            'confidence' => 0.80
        ],
        'reportable_types' => [
            'patterns' => [
                '/what can.*(report|submit)/',
                '/\b(types|kinds|categories).*infrastructure/',
                '/\b(roads|streetlights|drainage|water|electrical|facilities)\b/',
                '/what.*issue.*report/',
                // Tagalog
                '/ano.*(maaaring|pwedeng).*(iulat|i-report|isumite)/',
                '/\b(kalsada|ilaw|drainage|tubig|kuryente|pasilidad)\b/',
                '/anong.*problema/'
            ],
            'confidence' => 0.85
        ],
        'help_support' => [
            'patterns' => [
                '/\b(help|support|contact|assistance|phone|email|reach)\b/',
                '/\bneed help\b/',
                '/\bcontact.*support\b/',
                '/customer.*service/',
                // Tagalog
                '/\b(tulong|suporta|kontak|email|kailangan.*tulong|customer service)\b/',
                '/paano.*makipag-ugnayan/',
                '/kailangan.*tulong/'
            ],
            'confidence' => 0.80
        ],
        'about_system' => [
            'patterns' => [
                '/\b(about|what is|cimms|infragovservices|system|purpose|mission|vision)\b/',
                '/tell me about/',
                '/\bwhat.*(does|do).*system\b/',
                '/explain.*system/',
                // Tagalog
                '/\b(tungkol|ano ang|cimms|sistema|layunin)\b/',
                '/sabihin.*tungkol/',
                '/ipaliwanag.*sistema/'
            ],
            'confidence' => 0.80
        ],
        'technical_issue' => [
            'patterns' => [
                '/\b(error|bug|broken|not working|issue|problem|glitch|crash).*\b(site|system|page|website)/',
                '/\bcan\'?t.*\b(submit|upload|login|access)/',
                '/\b(stuck|freeze|loading|slow)\b/',
                '/something.*wrong/',
                // Tagalog
                '/\b(hindi gumagana|error|sira|hindi ma-submit|naka-stuck|may problema)\b/',
                '/ayaw.*gumana/',
                '/may.*mali/'
            ],
            'confidence' => 0.85
        ],
        'requirements' => [
            'patterns' => [
                '/what.*(need|require|necessary|needed)/',
                '/\b(requirement|needed|mandatory|must have)\b/',
                '/do i need/',
                '/what.*required/',
                // Tagalog
                '/ano.*(kailangan|kinakailangan|dapat)/',
                '/\b(requirements|kinakailangan|kailangan)\b/',
                '/ano.*dapat/'
            ],
            'confidence' => 0.75
        ],
        'gratitude' => [
            'patterns' => [
                '/\b(thank you|thanks|thank|appreciate|grateful)\b/',
                '/thanks a lot/',
                // Tagalog
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
                // Tagalog
                '/ano.*kaya mo/',
                '/sino ka/',
                '/ano.*tulong mo/'
            ],
            'confidence' => 0.90
        ],
        'yes_confirmation' => [
            'patterns' => [
                '/^(yes|yeah|yep|sure|okay|ok|yup|correct|right|exactly)$/',
                // Tagalog
                '/^(oo|opo|sige|tama|okay|ok)$/'
            ],
            'confidence' => 0.90
        ],
        'no_negation' => [
            'patterns' => [
                '/^(no|nope|nah|not really)$/',
                // Tagalog
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

// ============================================
// ENHANCED BILINGUAL RESPONSE GENERATION
// ============================================

function generateResponse($message, $intent, $context, $history, $lang = 'en') {
    $responses_en = [
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
                   "7️⃣ **Agree to Terms** - Check the box to accept our policies\n" .
                   "8️⃣ **Submit!** - Click the submit button\n\n" .
                   "💡 **Pro tip:** Clear, well-lit photos from multiple angles help our team respond faster!";
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
                   "• 09123456789 (no dashes - system auto-formats this)\n" .
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
                   "• Search by date, type, location, or budget\n" .
                   "• Mobile users get card-style views for easy tracking\n\n" .
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
                   "• Persists across page visits and sessions\n" .
                   "• Works seamlessly with all pages\n\n" .
                   "✨ **Benefits:**\n" .
                   "• Reduces eye strain in low-light environments\n" .
                   "• Saves battery on OLED screens\n" .
                   "• Modern, sleek interface\n" .
                   "• Full support across all components";
        },
        'language_switch' => function() {
            return "**Language Translation:**\n\n" .
                   "🌐 **How to Switch Languages:**\n" .
                   "• Click the globe icon with language label (EN/FIL)\n" .
                   "• Toggle between English and Filipino instantly\n" .
                   "• Available on all pages\n\n" .
                   "🎯 **Features:**\n" .
                   "• Real-time translation of all page content\n" .
                   "• Translated navigation menus\n" .
                   "• Form labels and instructions in chosen language\n" .
                   "• Notification messages adapt automatically\n" .
                   "• Preference saved for future visits\n\n" .
                   "🇵🇭 **Filipino Support:**\n" .
                   "Complete translation for all:\n" .
                   "• Navigation elements\n" .
                   "• Form fields and buttons\n" .
                   "• Status messages\n" .
                   "• Help content\n" .
                   "• Error messages";
        },
        'privacy_terms' => function() {
            return "**Privacy & Terms:**\n\n" .
                   "🔒 **Data Protection:**\n" .
                   "We comply with the **Data Privacy Act of 2012 (RA 10173)**\n\n" .
                   "📋 **Our Commitments:**\n" .
                   "• Your data is used ONLY for infrastructure coordination\n" .
                   "• Secure encryption during transmission and storage\n" .
                   "• No sharing with third parties without consent\n" .
                   "• Clear purpose declaration for all data collection\n\n" .
                   "✅ **Before Submitting:**\n" .
                   "• You must agree to Terms & Privacy Policy\n" .
                   "• Checkbox required on submission form\n\n" .
                   "📄 **Full Documentation:**\n" .
                   "• View complete Terms: Footer → 'Terms of Service'\n" .
                   "• View Privacy Policy: Footer → 'Privacy Policy'\n" .
                   "• Available in both English and Filipino\n\n" .
                   "📞 **Questions?** Contact our Data Protection Officer:\n" .
                   "Email: dpo@infragovservices.com";
        },
        'about_page' => function() {
            return "**About CIMMS Page:**\n\n" .
                   "ℹ️ **What You'll Find:**\n\n" .
                   "📖 **System Overview:**\n" .
                   "• What CIMMS is and how it works\n" .
                   "• Designed for Quezon City residents\n" .
                   "• Digital platform for infrastructure management\n\n" .
                   "🎯 **Our Purpose:**\n" .
                   "• Improve maintenance efficiency\n" .
                   "• Enhance citizen-LGU communication\n" .
                   "• Faster response times\n" .
                   "• Promote transparency and accountability\n\n" .
                   "🛠️ **What CIMMS Offers:**\n" .
                   "• Easy online issue reporting\n" .
                   "• Real-time request tracking\n" .
                   "• Direct LGU coordination\n" .
                   "• Secure, role-based access\n\n" .
                   "💡 **Vision & Mission:**\n" .
                   "• Build a trusted digital platform\n" .
                   "• Enhance community engagement\n" .
                   "• Deliver efficient services\n\n" .
                   "📍 **Access:** Click 'About' in the navigation menu!";
        },
        'reports_page' => function() {
            return "**Reports Page Guide:**\n\n" .
                   "📊 **What's on the Reports Page:**\n\n" .
                   "📈 **Quick Stats (Top Section):**\n" .
                   "• Completed Repairs counter\n" .
                   "• Ongoing Repairs tracker\n" .
                   "• Pending Requests count\n\n" .
                   "📋 **Recent Maintenance Table:**\n" .
                   "• Schedule ID and dates\n" .
                   "• Infrastructure type and location\n" .
                   "• Budget allocation\n" .
                   "• Current status with color coding\n" .
                   "• Action buttons to view details\n\n" .
                   "🔍 **Search & Filter:**\n" .
                   "• Search by date, type, location, budget, or status\n" .
                   "• Live search with instant results\n" .
                   "• Matching items appear at the top\n\n" .
                   "📱 **Mobile View:**\n" .
                   "• Card-style layout for easier reading\n" .
                   "• All same information, optimized format\n" .
                   "• Touch-friendly interface\n\n" .
                   "📍 **Access:** Click 'Reports' in navigation!";
        },
        'navigation' => function() {
            return "**Portal Navigation:**\n\n" .
                   "🧭 **Main Pages:**\n\n" .
                   "🏠 **Home** - Dashboard overview with system stats and activity\n" .
                   "📄 **Reports** - View maintenance schedules and track status\n" .
                   "📋 **Requests** - Submit new infrastructure issues\n" .
                   "ℹ️ **About** - Learn about CIMMS system and mission\n\n" .
                   "🔗 **Footer Links:**\n" .
                   "• Privacy Policy\n" .
                   "• Terms of Service\n" .
                   "• User Guide\n" .
                   "• FAQs\n" .
                   "• Contact Information\n\n" .
                   "📱 **Mobile Navigation:**\n" .
                   "• Tap the ☰ menu icon (top-left)\n" .
                   "• Sidebar slides out with all page links\n" .
                   "• Tap anywhere outside to close\n\n" .
                   "💡 **Active page** is highlighted in the navigation!";
        },
        'reportable_types' => function() {
            return "**Infrastructure Issues You Can Report:**\n\n" .
                   "🛣️ **Roads**\n" .
                   "• Potholes and cracks\n" .
                   "• Road surface damage\n" .
                   "• Missing road signs\n\n" .
                   "💡 **Street Lights**\n" .
                   "• Broken or non-functional lights\n" .
                   "• Flickering lights\n" .
                   "• Missing bulbs\n\n" .
                   "🚰 **Drainage**\n" .
                   "• Clogged drains\n" .
                   "• Flooding issues\n" .
                   "• Broken drainage covers\n\n" .
                   "🏢 **Public Facilities**\n" .
                   "• Building maintenance issues\n" .
                   "• Park and playground problems\n" .
                   "• Public restroom concerns\n\n" .
                   "💧 **Water Supply**\n" .
                   "• Water leaks\n" .
                   "• Low water pressure\n" .
                   "• Pipe bursts\n\n" .
                   "⚡ **Electrical**\n" .
                   "• Power issues in public areas\n" .
                   "• Exposed wiring\n" .
                   "• Electrical hazards\n\n" .
                   "📝 **Other**\n" .
                   "• Specify any other infrastructure concern in the description field";
        },
        'help_support' => function() {
            return "**Need Additional Help?**\n\n" .
                   "📞 **Contact Information:**\n\n" .
                   "📧 **Email:**\n" .
                   "contact@infragovservices.com\n\n" .
                   "☎️ **Phone:**\n" .
                   "(02) 8988-4242\n" .
                   "Monday-Friday, 8AM-5PM\n\n" .
                   "📍 **Office Address:**\n" .
                   "Quezon City Hall\n" .
                   "Quezon City, Metro Manila\n\n" .
                   "💬 **I'm Here Too!**\n" .
                   "I can help answer questions about:\n" .
                   "• Using the portal\n" .
                   "• Submitting reports\n" .
                   "• Tracking requests\n" .
                   "• Understanding features\n" .
                   "• Navigation help\n\n" .
                   "Just ask me anything! 😊";
        },
        'about_system' => function() {
            return "**About CIMMS:**\n\n" .
                   "🏛️ **Community Infrastructure Maintenance Management System**\n\n" .
                   "🎯 **Purpose:**\n" .
                   "A digital platform exclusively for Quezon City residents to report and track infrastructure issues efficiently.\n\n" .
                   "✨ **Key Features:**\n" .
                   "✅ Easy online issue reporting\n" .
                   "✅ Real-time progress tracking\n" .
                   "✅ Interactive location mapping\n" .
                   "✅ Photo evidence upload\n" .
                   "✅ Transparent maintenance schedules\n" .
                   "✅ Direct LGU communication\n\n" .
                   "🚀 **Benefits:**\n" .
                   "• Faster response times\n" .
                   "• Improved efficiency\n" .
                   "• Enhanced transparency\n" .
                   "• Better accountability\n" .
                   "• Stronger community participation\n\n" .
                   "🔐 **Security:**\n" .
                   "• Complies with RA 10173 (Data Privacy Act)\n" .
                   "• Role-based access control\n" .
                   "• Encrypted data transmission\n\n" .
                   "📍 Learn more on the **About** page!";
        },
        'technical_issue' => function() {
            return "**Technical Troubleshooting:**\n\n" .
                   "I'm sorry you're experiencing issues! 😔 Let's try to fix this:\n\n" .
                   "🔧 **Quick Fixes:**\n\n" .
                   "1️⃣ **Refresh the Page**\n" .
                   "• Press Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)\n" .
                   "• This clears temporary cache\n\n" .
                   "2️⃣ **Clear Browser Cache**\n" .
                   "• Chrome: Settings → Privacy → Clear browsing data\n" .
                   "• Firefox: Settings → Privacy → Clear Data\n" .
                   "• Safari: Settings → Clear History\n\n" .
                   "3️⃣ **Try Different Browser**\n" .
                   "• Chrome (recommended)\n" .
                   "• Firefox\n" .
                   "• Safari\n" .
                   "• Edge\n\n" .
                   "4️⃣ **Check Connection**\n" .
                   "• Ensure stable internet\n" .
                   "• Try mobile data if on WiFi (or vice versa)\n\n" .
                   "❌ **Still Not Working?**\n" .
                   "Contact our technical support:\n" .
                   "📧 contact@infragovservices.com\n" .
                   "☎️ (02) 8988-4242\n\n" .
                   "Please include:\n" .
                   "• What you were trying to do\n" .
                   "• Error message (if any)\n" .
                   "• Browser and device type";
        },
        'requirements' => function() {
            return "**Submission Requirements:**\n\n" .
                   "📋 **Required Fields:**\n\n" .
                   "1️⃣ **Infrastructure Type** ✅\n" .
                   "• Select from dropdown or specify 'Other'\n\n" .
                   "2️⃣ **Location** ✅\n" .
                   "• Must be within Quezon City\n" .
                   "• Use interactive map to pinpoint\n\n" .
                   "3️⃣ **Issue Description** ✅\n" .
                   "• Detailed explanation of the problem\n" .
                   "• Include relevant context\n\n" .
                   "4️⃣ **Contact Number** ✅\n" .
                   "• 11 digits (09XX-XXX-XXXX format)\n" .
                   "• For progress updates\n\n" .
                   "5️⃣ **Photo Evidence** ✅\n" .
                   "• At least 1 image required\n" .
                   "• Maximum 4 images\n" .
                   "• Formats: JPG, JPEG, PNG, WEBP\n\n" .
                   "6️⃣ **Terms Agreement** ✅\n" .
                   "• Must check the consent checkbox\n" .
                   "• Agrees to Terms & Privacy Policy\n\n" .
                   "📝 **Optional:**\n" .
                   "• Your name (for follow-up)\n\n" .
                   "💡 All required fields must be filled to submit!";
        },
        'gratitude' => function() {
            $responses = [
                "You're very welcome! 😊 Happy to help! Is there anything else you'd like to know?",
                "My pleasure! 🌟 Feel free to ask if you need any other assistance!",
                "Glad I could help! 👍 Let me know if you have more questions!",
                "You're welcome! 😊 I'm always here if you need anything else!"
            ];
            return $responses[array_rand($responses)];
        },
        'capabilities' => function() {
            return "**What I Can Help You With:**\n\n" .
                   "🤖 **I'm the InfraGovServices AI Assistant!**\n\n" .
                   "💡 **My Capabilities:**\n\n" .
                   "✅ **Explain Features:**\n" .
                   "• How to submit reports\n" .
                   "• Using the map system\n" .
                   "• Uploading photos\n" .
                   "• Tracking requests\n\n" .
                   "✅ **Navigate the System:**\n" .
                   "• Guide you through pages\n" .
                   "• Explain each section\n" .
                   "• Find specific features\n\n" .
                   "✅ **Answer Questions:**\n" .
                   "• Requirements and formats\n" .
                   "• Privacy and terms\n" .
                   "• System capabilities\n" .
                   "• Troubleshooting help\n\n" .
                   "✅ **Bilingual Support:**\n" .
                   "• English and Filipino\n" .
                   "• Natural conversations\n" .
                   "• Context-aware responses\n\n" .
                   "🌟 **I'm here 24/7 to assist you!**\n" .
                   "Just ask me anything about InfraGovServices!";
        },
        'yes_confirmation' => function() use ($history) {
            if (!empty($history)) {
                return "Great! I'm glad I could help. Is there anything else you'd like to know? 😊";
            }
            return "Yes! How can I assist you? 😊";
        },
        'no_negation' => function() use ($history) {
            if (!empty($history)) {
                return "No problem! Feel free to ask if you need anything else. I'm here to help! 😊";
            }
            return "Okay! Let me know if you need any assistance. I'm here to help! 😊";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "I see you're on the **Requests page**! 📋\n\nI can help you with:\n\n• Filling out the submission form\n• Understanding photo requirements\n• Using the location map\n• Contact number format\n• Required fields\n\nWhat would you like to know?",
                'reports'  => "You're viewing the **Reports page**! 📊\n\nI can help you:\n\n• Understand status types\n• Use the search function\n• Find specific requests\n• Track maintenance progress\n• Interpret the data\n\nWhat do you need help with?",
                'about'    => "Welcome to the **About page**! ℹ️\n\nI can explain:\n\n• System purpose and mission\n• CIMMS features\n• How it benefits citizens\n• Our vision and values\n• Who can use the system\n\nWhat interests you?",
                'home'     => "Welcome to **InfraGovServices**! 🏠\n\nI can help you:\n\n• Submit a new report\n• Track existing requests\n• Navigate the system\n• Understand features\n• Answer any questions\n\nWhat would you like to do?"
            ];
            
            return $contextResponses[$context] ?? 
                   "I'm here to help! 😊\n\nI can assist with:\n\n• 📝 **Reporting issues**\n• 🔍 **Tracking requests**\n• 🗺️ **Using the map**\n• 📸 **Photo uploads**\n• 🌐 **Navigation**\n• ℹ️ **System information**\n\nWhat would you like to know?";
        }
    ];

    // ---- Tagalog responses ----
    $responses_tl = [
        'greeting' => function() {
            $greetings = [
                "Kumusta! 👋 Ako ang InfraGovServices AI assistant. Paano kita matutulungan ngayong araw?",
                "Magandang araw! 👋 Maligayang pagdating sa InfraGovServices. Ano ang gusto mong malaman tungkol sa aming sistema?",
                "Helo! 👋 Nandito ako para tumulong sa iyo gamit ang CIMMS. Ano ang maitutulong ko?",
                "Kumusta! 👋 Handa akong tulungan kayo sa InfraGovServices. Ano ang nasa isip mo?"
            ];
            return $greetings[array_rand($greetings)];
        },
        'how_are_you' => function() {
            return "Ayos lang ako, salamat sa pagtanong! 😊 Nandito ako at handa na tulungan ka sa kahit ano tungkol sa InfraGovServices. Paano kita matutulungan ngayon?";
        },
        'how_to_report' => function() {
            return "**Pagsusumite ng Ulat - Hakbang-Hakbang:**\n\n" .
                   "1️⃣ **Pumunta sa Pahina ng Mga Kahilingan** - I-click ang 'Mga Kahilingan' sa navigation menu\n" .
                   "2️⃣ **Piliin ang Uri ng Imprastraktura** - Pumili mula sa Mga Kalsada, Ilaw sa Kalye, Drainage, atbp.\n" .
                   "3️⃣ **Piliin ang Lokasyon** - Gamitin ang interactive na mapa para markahan ang eksaktong lugar\n" .
                   "4️⃣ **Ilarawan ang Isyu** - Magbigay ng detalyadong paglalarawan ng problema\n" .
                   "5️⃣ **Mag-upload ng Mga Larawan** - Magdagdag ng hanggang 4 na malinaw na larawan bilang ebidensya\n" .
                   "6️⃣ **Ilagay ang Numero ng Kontak** - Ibigay ang iyong 11-digit na numero ng selpon (09XX-XXX-XXXX)\n" .
                   "7️⃣ **Sumang-ayon sa Mga Tuntunin** - I-check ang kahon para tanggapin ang aming mga patakaran\n" .
                   "8️⃣ **Isumite!** - I-click ang submit button\n\n" .
                   "💡 **Pro tip:** Malinaw at maayos na larawan mula sa iba't ibang anggulo ay nakakatulong sa aming koponan na mas mabilis na tumugon!";
        },
        'photo_upload' => function() {
            return "**Gabay sa Pag-upload ng Larawan:**\n\n" .
                   "📸 **Para sa Desktop Users:**\n" .
                   "• I-click ang file input field\n" .
                   "• Mag-browse at pumili ng hanggang 4 na larawan\n" .
                   "• Sinusuportahang format: JPG, JPEG, PNG, WEBP\n\n" .
                   "📱 **Para sa Mobile Users:**\n" .
                   "• I-tap ang camera button (📷) para kumuha ng direkta\n" .
                   "• O i-tap ang file input para pumili mula sa gallery\n" .
                   "• Maximum 4 na larawan bawat ulat\n\n" .
                   "✨ **Tips para sa Pinakamahusay na Resulta:**\n" .
                   "• Kumuha ng larawan sa magandang ilaw\n" .
                   "• Ipakita ang problema mula sa iba't ibang anggulo\n" .
                   "• Isama ang konteksto (malapit na mga landmark)\n" .
                   "• Siguraduhing malinaw at naka-focus ang mga larawan\n\n" .
                   "Ang mga larawan ay lubhang nagpapabilis ng pagsusuri at pagbibigay-priyoridad sa pag-aayos!";
        },
        'location_map' => function() {
            return "**Paggamit ng Mapa ng Lokasyon:**\n\n" .
                   "🗺️ **Paano Pumili ng Iyong Lokasyon:**\n\n" .
                   "1. **I-click ang Location Field** - Bubuksan nito ang interactive map modal\n" .
                   "2. **Piliin ang Barangay** - Pumili mula sa dropdown menu\n" .
                   "3. **Gamitin ang GPS** 📍 - I-click ang GPS button para awtomatikong matukoy ang iyong kasalukuyang lokasyon\n" .
                   "4. **Manu-manong Pagpili** - I-click o i-drag ang map marker sa eksaktong lugar\n" .
                   "5. **I-verify ang Address** - Awtomatikong pupunan ng sistema ang tiyak na address\n" .
                   "6. **I-save** - I-click ang 'Save Location' para kumpirmahin\n\n" .
                   "⚠️ **Mahalaga:** Tanggap lamang ang mga lokasyon sa loob ng Lungsod Quezon.\n\n" .
                   "🔍 **Mga Feature:**\n" .
                   "• Toggle sa pagitan ng Satellite at Street view\n" .
                   "• Ipakita/itago ang mga label ng lokasyon\n" .
                   "• Tumpak na pagpapatupad ng hangganan\n" .
                   "• Awtomatikong natukoy na mga address sa pamamagitan ng geocoding";
        },
        'contact_format' => function() {
            return "**Format ng Numero ng Kontak:**\n\n" .
                   "📞 **Kinakailangang Format:**\n" .
                   "✅ 09XX-XXX-XXXX (11 digits kabuuan)\n" .
                   "✅ Dapat magsimula sa 09\n\n" .
                   "**Mga Halimbawa:**\n" .
                   "• 0912-345-6789 ✓\n" .
                   "• 0917-123-4567 ✓\n" .
                   "• 0998-765-4321 ✓\n\n" .
                   "❌ **Hindi Wastong Format:**\n" .
                   "• 912-345-6789 (kulang ang 0)\n" .
                   "• 09123456789 (walang gitling - awtomatikong ifa-format ng sistema)\n" .
                   "• +63912-345-6789 (hindi kailangan ang international format)\n\n" .
                   "💡 Gagamitin namin ang numerong ito para magpadala sa iyo ng mga update tungkol sa progreso ng iyong kahilingan!";
        },
        'track_status' => function() {
            return "**Pagsubaybay sa Iyong Kahilingan:**\n\n" .
                   "📊 **Mga Uri ng Katayuan:**\n\n" .
                   "🟡 **Nakabinbin** - Ang iyong ulat ay nasa ilalim ng pagsusuri ng aming staff\n" .
                   "🔵 **In Progress** - Kasalukuyang ginagawa ang pag-aayos\n" .
                   "🟢 **Natapos** - Matagumpay nang naayos ang isyu!\n" .
                   "🔴 **Naantala** - Pansamantalang naka-hold (aabisuhan ka namin kung bakit)\n\n" .
                   "📍 **Paano Tingnan ang Katayuan:**\n" .
                   "• Pumunta sa **Pahina ng Mga Ulat** sa navigation menu\n" .
                   "• Tingnan ang mga kamakailang iskedyul ng pagpapanatili at katayuan\n" .
                   "• Maghanap ayon sa petsa, uri, lokasyon, o badyet\n" .
                   "• Ang mga mobile users ay makakakuha ng card-style na mga view para sa madaling pagsubaybay\n\n" .
                   "⏱️ **Oras ng Pagtugon:**\n" .
                   "• Pagsusuri: Sa loob ng 24 na oras\n" .
                   "• Mga Update: Regular na mga abiso sa progreso\n" .
                   "• Pagkumpleto: Depende sa kalubhaan ng isyu";
        },
        'dark_mode' => function() {
            return "**Dark Mode Feature:**\n\n" .
                   "🌙 **Paano Mag-toggle:**\n" .
                   "• I-click ang moon/sun icon (🌙/☀️) sa itaas na navigation bar\n" .
                   "• Available sa desktop at mobile views\n\n" .
                   "💾 **Auto-Save:**\n" .
                   "• Awtomatikong nase-save ang iyong kagustuhan\n" .
                   "• Nananatili sa mga pagbisita at sessions\n" .
                   "• Gumagana nang walang problema sa lahat ng pahina\n\n" .
                   "✨ **Mga Benepisyo:**\n" .
                   "• Binabawasan ang pagod ng mata sa mababang ilaw\n" .
                   "• Nakakatipid ng baterya sa OLED screens\n" .
                   "• Modernong, makinis na interface\n" .
                   "• Buong suporta sa lahat ng bahagi";
        },
        'language_switch' => function() {
            return "**Pagsasalin ng Wika:**\n\n" .
                   "🌐 **Paano Magpalit ng Wika:**\n" .
                   "• I-click ang globe icon na may label ng wika (EN/FIL)\n" .
                   "• Mag-toggle sa pagitan ng Ingles at Filipino kaagad\n" .
                   "• Available sa lahat ng pahina\n\n" .
                   "🎯 **Mga Feature:**\n" .
                   "• Real-time na pagsasalin ng lahat ng nilalaman ng pahina\n" .
                   "• Isinalin na mga navigation menu\n" .
                   "• Mga label ng form at mga tagubilin sa napiling wika\n" .
                   "• Awtomatikong umaangkop ang mga mensahe ng abiso\n" .
                   "• Naka-save ang kagustuhan para sa mga susunod na pagbisita\n\n" .
                   "🇵🇭 **Suporta sa Filipino:**\n" .
                   "Kumpletong pagsasalin para sa lahat ng:\n" .
                   "• Mga elemento ng navigation\n" .
                   "• Mga field at button ng form\n" .
                   "• Mga mensahe ng katayuan\n" .
                   "• Nilalaman ng tulong\n" .
                   "• Mga mensahe ng error";
        },
        'privacy_terms' => function() {
            return "**Privacy at Mga Tuntunin:**\n\n" .
                   "🔒 **Proteksyon ng Data:**\n" .
                   "Sumusunod kami sa **Data Privacy Act of 2012 (RA 10173)**\n\n" .
                   "📋 **Aming mga Pangako:**\n" .
                   "• Ang iyong data ay ginagamit LAMANG para sa koordinasyon ng imprastraktura\n" .
                   "• Secure encryption sa panahon ng transmission at storage\n" .
                   "• Walang pagbabahagi sa third parties nang walang pahintulot\n" .
                   "• Malinaw na deklarasyon ng layunin para sa lahat ng pagkolekta ng data\n\n" .
                   "✅ **Bago Magsumite:**\n" .
                   "• Dapat kang sumang-ayon sa Mga Tuntunin at Patakaran sa Privacy\n" .
                   "• Kinakailangan ang checkbox sa submission form\n\n" .
                   "📄 **Buong Dokumentasyon:**\n" .
                   "• Tingnan ang kumpletong Mga Tuntunin: Footer → 'Mga Tuntunin ng Serbisyo'\n" .
                   "• Tingnan ang Patakaran sa Privacy: Footer → 'Patakaran sa Privacy'\n" .
                   "• Available sa Ingles at Filipino\n\n" .
                   "📞 **Mga Tanong?** Makipag-ugnayan sa aming Data Protection Officer:\n" .
                   "Email: dpo@infragovservices.com";
        },
        'about_page' => function() {
            return "**Tungkol sa CIMMS na Pahina:**\n\n" .
                   "ℹ️ **Ano ang Makikita Mo:**\n\n" .
                   "📖 **Pangkalahatang Ideya ng Sistema:**\n" .
                   "• Ano ang CIMMS at paano ito gumagana\n" .
                   "• Dinisenyo para sa mga residente ng Lungsod Quezon\n" .
                   "• Digital na platform para sa pamamahala ng imprastraktura\n\n" .
                   "🎯 **Aming Layunin:**\n" .
                   "• Mapabuti ang kahusayan ng pagpapanatili\n" .
                   "• Palakasin ang komunikasyon ng mamamayan-LGU\n" .
                   "• Mas mabilis na oras ng pagtugon\n" .
                   "• Itaguyod ang transparency at pananagutan\n\n" .
                   "🛠️ **Ano ang Inaalok ng CIMMS:**\n" .
                   "• Madaling pag-uulat ng isyu online\n" .
                   "• Real-time na pagsubaybay sa kahilingan\n" .
                   "• Direktang koordinasyon sa LGU\n" .
                   "• Secure, role-based na access\n\n" .
                   "💡 **Pananaw at Misyon:**\n" .
                   "• Bumuo ng pinagkakatiwalaang digital platform\n" .
                   "• Palakasin ang pakikipag-ugnayan ng komunidad\n" .
                   "• Maghatid ng mahusay na mga serbisyo\n\n" .
                   "📍 **Access:** I-click ang 'Tungkol Sa' sa navigation menu!";
        },
        'reports_page' => function() {
            return "**Gabay sa Pahina ng Mga Ulat:**\n\n" .
                   "📊 **Ano ang nasa Pahina ng Mga Ulat:**\n\n" .
                   "📈 **Mabilis na Stats (Itaas na Bahagi):**\n" .
                   "• Bilang ng Natapos na Pag-aayos\n" .
                   "• Tracker ng Kasalukuyang Pag-aayos\n" .
                   "• Bilang ng Nakabinbing Kahilingan\n\n" .
                   "📋 **Talahanayan ng Kamakailang Pagpapanatili:**\n" .
                   "• Schedule ID at mga petsa\n" .
                   "• Uri at lokasyon ng imprastraktura\n" .
                   "• Alokasyon ng badyet\n" .
                   "• Kasalukuyang katayuan na may color coding\n" .
                   "• Mga button ng aksyon para tingnan ang mga detalye\n\n" .
                   "🔍 **Paghahanap at Pagsala:**\n" .
                   "• Maghanap ayon sa petsa, uri, lokasyon, badyet, o katayuan\n" .
                   "• Live search na may instant na mga resulta\n" .
                   "• Mga tugmang item ay lilitaw sa itaas\n\n" .
                   "📱 **Mobile View:**\n" .
                   "• Card-style na layout para sa mas madaling pagbabasa\n" .
                   "• Pareho ang impormasyon, na-optimize ang format\n" .
                   "• Touch-friendly na interface\n\n" .
                   "📍 **Access:** I-click ang 'Mga Ulat' sa navigation!";
        },
        'navigation' => function() {
            return "**Navigation sa Portal:**\n\n" .
                   "🧭 **Mga Pangunahing Pahina:**\n\n" .
                   "🏠 **Tahanan** - Pangkalahatang ideya ng dashboard na may stats at aktibidad ng sistema\n" .
                   "📄 **Mga Ulat** - Tingnan ang mga iskedyul ng pagpapanatili at subaybayan ang katayuan\n" .
                   "📋 **Mga Kahilingan** - Magsumite ng mga bagong isyu sa imprastraktura\n" .
                   "ℹ️ **Tungkol Sa** - Alamin ang tungkol sa sistema at misyon ng CIMMS\n\n" .
                   "🔗 **Mga Link sa Footer:**\n" .
                   "• Patakaran sa Privacy\n" .
                   "• Mga Tuntunin ng Serbisyo\n" .
                   "• Gabay ng User\n" .
                   "• Mga Madalas na Tanong\n" .
                   "• Impormasyon sa Pakikipag-ugnayan\n\n" .
                   "📱 **Mobile Navigation:**\n" .
                   "• I-tap ang ☰ menu icon (itaas-kaliwa)\n" .
                   "• Lilitaw ang sidebar na may lahat ng link ng pahina\n" .
                   "• I-tap kahit saan sa labas para isara\n\n" .
                   "💡 **Aktibong pahina** ay naka-highlight sa navigation!";
        },
        'reportable_types' => function() {
            return "**Mga Isyu sa Imprastraktura na Maaari Mong Iulat:**\n\n" .
                   "🛣️ **Mga Kalsada**\n" .
                   "• Mga butas at bitak\n" .
                   "• Pinsala sa ibabaw ng kalsada\n" .
                   "• Nawawalang mga senyas sa kalsada\n\n" .
                   "💡 **Mga Ilaw sa Kalye**\n" .
                   "• Sirang o hindi gumaganang ilaw\n" .
                   "• Kumikislap na ilaw\n" .
                   "• Nawawalang mga bombilya\n\n" .
                   "🚰 **Drainage**\n" .
                   "• Nabarang mga kanal\n" .
                   "• Mga isyu sa pagbaha\n" .
                   "• Sirang mga takip ng kanal\n\n" .
                   "🏢 **Pampublikong Pasilidad**\n" .
                   "• Mga isyu sa pagpapanatili ng gusali\n" .
                   "• Mga problema sa park at playground\n" .
                   "• Mga alalahanin sa pampublikong palikuran\n\n" .
                   "💧 **Supply ng Tubig**\n" .
                   "• Mga tumatagos na tubig\n" .
                   "• Mababang presyon ng tubig\n" .
                   "• Pagputok ng tubo\n\n" .
                   "⚡ **Elektrikal**\n" .
                   "• Mga isyu sa kuryente sa pampublikong lugar\n" .
                   "• Nakalantad na kable\n" .
                   "• Mga panganib sa kuryente\n\n" .
                   "📝 **Iba Pa**\n" .
                   "• Tukuyin ang anumang iba pang alalahanin sa imprastraktura sa field ng paglalarawan";
        },
        'help_support' => function() {
            return "**Kailangan ng Karagdagang Tulong?**\n\n" .
                   "📞 **Impormasyon sa Pakikipag-ugnayan:**\n\n" .
                   "📧 **Email:**\n" .
                   "contact@infragovservices.com\n\n" .
                   "☎️ **Telepono:**\n" .
                   "(02) 8988-4242\n" .
                   "Lunes-Biyernes, 8AM-5PM\n\n" .
                   "📍 **Address ng Opisina:**\n" .
                   "Quezon City Hall\n" .
                   "Quezon City, Metro Manila\n\n" .
                   "💬 **Nandito Rin Ako!**\n" .
                   "Maaari akong tumulong na sagutin ang mga tanong tungkol sa:\n" .
                   "• Paggamit ng portal\n" .
                   "• Pagsusumite ng mga ulat\n" .
                   "• Pagsubaybay sa mga kahilingan\n" .
                   "• Pag-unawa sa mga feature\n" .
                   "• Tulong sa navigation\n\n" .
                   "Magtanong lang sa akin ng kahit ano! 😊";
        },
        'about_system' => function() {
            return "**Tungkol sa CIMMS:**\n\n" .
                   "🏛️ **Community Infrastructure Maintenance Management System**\n\n" .
                   "🎯 **Layunin:**\n" .
                   "Isang digital na platform na eksklusibo para sa mga residente ng Lungsod Quezon upang mag-ulat at subaybayan ang mga isyu sa imprastraktura nang mahusay.\n\n" .
                   "✨ **Mga Pangunahing Feature:**\n" .
                   "✅ Madaling pag-uulat ng isyu online\n" .
                   "✅ Real-time na pagsubaybay sa progreso\n" .
                   "✅ Interactive na pagmamapa ng lokasyon\n" .
                   "✅ Pag-upload ng ebidensya ng larawan\n" .
                   "✅ Transparent na mga iskedyul ng pagpapanatili\n" .
                   "✅ Direktang komunikasyon sa LGU\n\n" .
                   "🚀 **Mga Benepisyo:**\n" .
                   "• Mas mabilis na oras ng pagtugon\n" .
                   "• Pinabuting kahusayan\n" .
                   "• Pinahusay na transparency\n" .
                   "• Mas mahusay na pananagutan\n" .
                   "• Mas malakas na pakikilahok ng komunidad\n\n" .
                   "🔐 **Seguridad:**\n" .
                   "• Sumusunod sa RA 10173 (Data Privacy Act)\n" .
                   "• Role-based access control\n" .
                   "• Naka-encrypt na transmission ng data\n\n" .
                   "📍 Alamin pa sa **Tungkol Sa** pahina!";
        },
        'technical_issue' => function() {
            return "**Pag-troubleshoot sa Teknikal:**\n\n" .
                   "Paumanhin na nakakaranas ka ng mga isyu! 😔 Subukan nating ayusin ito:\n\n" .
                   "🔧 **Mabilis na Solusyon:**\n\n" .
                   "1️⃣ **I-refresh ang Pahina**\n" .
                   "• Pindutin ang Ctrl+F5 (Windows) o Cmd+Shift+R (Mac)\n" .
                   "• Nililinis nito ang pansamantalang cache\n\n" .
                   "2️⃣ **I-clear ang Browser Cache**\n" .
                   "• Chrome: Settings → Privacy → Clear browsing data\n" .
                   "• Firefox: Settings → Privacy → Clear Data\n" .
                   "• Safari: Settings → Clear History\n\n" .
                   "3️⃣ **Subukan ang Ibang Browser**\n" .
                   "• Chrome (inirerekomenda)\n" .
                   "• Firefox\n" .
                   "• Safari\n" .
                   "• Edge\n\n" .
                   "4️⃣ **Suriin ang Koneksyon**\n" .
                   "• Tiyaking matatag ang internet\n" .
                   "• Subukan ang mobile data kung naka-WiFi (o kabaliktaran)\n\n" .
                   "❌ **Hindi Pa Rin Gumagana?**\n" .
                   "Makipag-ugnayan sa aming technical support:\n" .
                   "📧 contact@infragovservices.com\n" .
                   "☎️ (02) 8988-4242\n\n" .
                   "Mangyaring isama:\n" .
                   "• Ano ang sinusubukan mong gawin\n" .
                   "• Mensahe ng error (kung mayroon)\n" .
                   "• Uri ng browser at device";
        },
        'requirements' => function() {
            return "**Mga Kinakailangan sa Pagsusumite:**\n\n" .
                   "📋 **Mga Kinakailangang Field:**\n\n" .
                   "1️⃣ **Uri ng Imprastraktura** ✅\n" .
                   "• Pumili mula sa dropdown o tukuyin ang 'Iba Pa'\n\n" .
                   "2️⃣ **Lokasyon** ✅\n" .
                   "• Dapat nasa loob ng Lungsod Quezon\n" .
                   "• Gamitin ang interactive na mapa para tukuyin\n\n" .
                   "3️⃣ **Paglalarawan ng Isyu** ✅\n" .
                   "• Detalyadong paliwanag ng problema\n" .
                   "• Isama ang nauugnay na konteksto\n\n" .
                   "4️⃣ **Numero ng Kontak** ✅\n" .
                   "• 11 digits (09XX-XXX-XXXX format)\n" .
                   "• Para sa mga update sa progreso\n\n" .
                   "5️⃣ **Ebidensya ng Larawan** ✅\n" .
                   "• Kinakailangan ng kahit 1 larawan\n" .
                   "• Maximum 4 na larawan\n" .
                   "• Mga format: JPG, JPEG, PNG, WEBP\n\n" .
                   "6️⃣ **Kasunduan sa Mga Tuntunin** ✅\n" .
                   "• Dapat i-check ang consent checkbox\n" .
                   "• Sumang-ayon sa Mga Tuntunin at Patakaran sa Privacy\n\n" .
                   "📝 **Opsyonal:**\n" .
                   "• Ang iyong pangalan (para sa follow-up)\n\n" .
                   "💡 Dapat punan ang lahat ng kinakailangang field para magsumite!";
        },
        'gratitude' => function() {
            $responses = [
                "Walang anuman! 😊 Masaya akong nakatulong! Mayroon pa bang iba na gusto mong malaman?",
                "Ikinagagalak ko! 🌟 Huwag mag-atubiling magtanong kung kailangan mo pa ng tulong!",
                "Mabuti naman na nakatulong ako! 👍 Sabihin mo lang kung may iba ka pang tanong!",
                "Walang anuman! 😊 Nandito lang ako kung kailangan mo ng kahit ano!"
            ];
            return $responses[array_rand($responses)];
        },
        'capabilities' => function() {
            return "**Ano ang Maitutulong Ko:**\n\n" .
                   "🤖 **Ako ang InfraGovServices AI Assistant!**\n\n" .
                   "💡 **Aking mga Kakayahan:**\n\n" .
                   "✅ **Magpaliwanag ng mga Feature:**\n" .
                   "• Paano magsumite ng mga ulat\n" .
                   "• Paggamit ng sistema ng mapa\n" .
                   "• Pag-upload ng mga larawan\n" .
                   "• Pagsubaybay sa mga kahilingan\n\n" .
                   "✅ **Mag-navigate sa Sistema:**\n" .
                   "• Gabayan ka sa mga pahina\n" .
                   "• Ipaliwanag ang bawat seksyon\n" .
                   "• Hanapin ang mga partikular na feature\n\n" .
                   "✅ **Sagutin ang mga Tanong:**\n" .
                   "• Mga kinakailangan at format\n" .
                   "• Privacy at mga tuntunin\n" .
                   "• Mga kakayahan ng sistema\n" .
                   "• Tulong sa pag-troubleshoot\n\n" .
                   "✅ **Suporta sa Dalawang Wika:**\n" .
                   "• Ingles at Filipino\n" .
                   "• Natural na pag-uusap\n" .
                   "• Mga tugong may konteksto\n\n" .
                   "🌟 **Nandito ako 24/7 para tumulong sa iyo!**\n" .
                   "Magtanong lang sa akin ng kahit ano tungkol sa InfraGovServices!";
        },
        'yes_confirmation' => function() use ($history) {
            if (!empty($history)) {
                return "Magaling! Masaya akong nakatulong. Mayroon pa bang iba na gusto mong malaman? 😊";
            }
            return "Oo! Paano kita matutulungan? 😊";
        },
        'no_negation' => function() use ($history) {
            if (!empty($history)) {
                return "Walang problema! Huwag mag-atubiling magtanong kung kailangan mo ng kahit ano. Nandito ako para tumulong! 😊";
            }
            return "Sige! Sabihin mo lang kung kailangan mo ng tulong. Nandito ako para tumulong! 😊";
        },
        'general' => function($message, $context) {
            $contextResponses = [
                'request' => "Nakikita ko na nasa **Pahina ng Mga Kahilingan** ka! 📋\n\nMaaari kitang tulungan sa:\n\n• Pagsagot ng submission form\n• Pag-unawa sa mga kinakailangan sa larawan\n• Paggamit ng mapa ng lokasyon\n• Format ng numero ng kontak\n• Mga kinakailangang field\n\nAno ang gusto mong malaman?",
                'reports'  => "Tinitingnan mo ang **Pahina ng Mga Ulat**! 📊\n\nMaaari kitang tulungan na:\n\n• Maunawaan ang mga uri ng katayuan\n• Gamitin ang function ng paghahanap\n• Hanapin ang mga partikular na kahilingan\n• Subaybayan ang progreso ng pagpapanatili\n• Bigyang-kahulugan ang data\n\nAno ang kailangan mong tulong?",
                'about'    => "Maligayang pagdating sa **Tungkol Sa pahina**! ℹ️\n\nMaaari kong ipaliwanag:\n\n• Layunin at misyon ng sistema\n• Mga feature ng CIMMS\n• Paano ito nakikinabang sa mga mamamayan\n• Aming pananaw at mga halaga\n• Sino ang maaaring gumamit ng sistema\n\nAno ang nakakainteresa sa iyo?",
                'home'     => "Maligayang pagdating sa **InfraGovServices**! 🏠\n\nMaaari kitang tulungan na:\n\n• Magsumite ng bagong ulat\n" .
                   "• Subaybayan ang mga kasalukuyang kahilingan\n• Mag-navigate sa sistema\n• Maunawaan ang mga feature\n• Sagutin ang anumang tanong\n\nAno ang gusto mong gawin?"
            ];
            
            return $contextResponses[$context] ?? 
                   "Nandito ako para tumulong! 😊\n\nMaaari akong tumulong sa:\n\n• 📝 **Pag-uulat ng mga isyu**\n• 🔍 **Pagsubaybay sa mga kahilingan**\n• 🗺️ **Paggamit ng mapa**\n• 📸 **Pag-upload ng mga larawan**\n• 🌐 **Navigation**\n• ℹ️ **Impormasyon ng sistema**\n\nAno ang gusto mong malaman?";
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