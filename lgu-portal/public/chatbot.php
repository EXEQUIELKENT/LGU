<?php
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Start session for conversation history
session_start();

// Get the user message and context
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($input['message']) ? trim($input['message']) : '';
$context = isset($input['context']) ? $input['context'] : 'general';

if (empty($userMessage)) {
    echo json_encode(['error' => 'Message is required']);
    exit();
}

// Initialize conversation history
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// ============================================
// ENHANCED INTENT DETECTION SYSTEM
// ============================================

function detectIntent($message, $context) {
    $message = strtolower($message);
    
    // Define intents with priority order (specific to general)
    $intents = [
        // Greeting patterns
        'greeting' => [
            'patterns' => [
                '/\b(hi|hello|hey|good morning|good afternoon|good evening|greetings)\b/',
                '/^(yo|sup|wassup)\b/'
            ],
            'confidence' => 0.95
        ],
        
        // How to report patterns
        'how_to_report' => [
            'patterns' => [
                '/how (to|do|can).*(report|submit|file|create).*(issue|problem|request|concern)/',
                '/\b(report|submit|file).*(how|process|steps)/',
                '/what.*(process|steps).*(report|submit)/'
            ],
            'confidence' => 0.90
        ],
        
        // Photo upload patterns
        'photo_upload' => [
            'patterns' => [
                '/\b(photo|picture|evidence|image|upload|camera|capture|pic)\b/',
                '/how.*(add|attach|upload|take).*photo/',
                '/\b(jpg|jpeg|png|webp)\b/'
            ],
            'confidence' => 0.85
        ],
        
        // Location/map patterns
        'location_map' => [
            'patterns' => [
                '/\b(location|map|barangay|address|gps|where|place)\b/',
                '/how.*(select|choose|find|mark).*location/',
                '/\b(quezon city|qc)\b.*location/'
            ],
            'confidence' => 0.85
        ],
        
        // Contact format patterns
        'contact_format' => [
            'patterns' => [
                '/\b(contact|phone|number|mobile|cellphone|09)\b/',
                '/\bformat.*number\b/',
                '/\b11 digit/'
            ],
            'confidence' => 0.85
        ],
        
        // Status tracking patterns
        'track_status' => [
            'patterns' => [
                '/\b(track|status|check|follow up|progress|update|monitor)\b/',
                '/where.*(request|report)/',
                '/\b(pending|in progress|completed)\b/'
            ],
            'confidence' => 0.85
        ],
        
        // Dark mode patterns
        'dark_mode' => [
            'patterns' => [
                '/\b(dark mode|theme|night mode|light mode)\b/',
                '/\bchange.*theme\b/'
            ],
            'confidence' => 0.90
        ],
        
        // Privacy/terms patterns
        'privacy_terms' => [
            'patterns' => [
                '/\b(privacy|terms|data|personal information|policy|agreement)\b/',
                '/\b(ra 10173|data privacy act)\b/'
            ],
            'confidence' => 0.85
        ],
        
        // Navigation patterns
        'navigation' => [
            'patterns' => [
                '/\b(pages|navigate|menu|sections|where is)\b/',
                '/how.*(find|get to|go to)/',
                '/\b(home|reports|requests|about)\b.*page/'
            ],
            'confidence' => 0.80
        ],
        
        // Reportable types patterns
        'reportable_types' => [
            'patterns' => [
                '/what can.*(report|submit)/',
                '/\b(types|kinds|categories).*infrastructure/',
                '/\b(roads|streetlights|drainage|water|electrical)\b/'
            ],
            'confidence' => 0.85
        ],
        
        // Help/support patterns
        'help_support' => [
            'patterns' => [
                '/\b(help|support|contact|assistance|phone|email)\b/',
                '/\bneed help\b/',
                '/\bcontact.*support\b/'
            ],
            'confidence' => 0.80
        ],
        
        // About system patterns
        'about_system' => [
            'patterns' => [
                '/\b(about|what is|cimms|infragovservices|system)\b/',
                '/tell me about/',
                '/\bwhat.*(does|do).*system\b/'
            ],
            'confidence' => 0.80
        ],
        
        // Technical issue patterns
        'technical_issue' => [
            'patterns' => [
                '/\b(error|bug|broken|not working|issue|problem).*\b(site|system|page)/',
                '/\bcan\'?t.*\b(submit|upload|login)/',
                '/\b(stuck|freeze|loading)\b/'
            ],
            'confidence' => 0.85
        ],
        
        // Requirements patterns
        'requirements' => [
            'patterns' => [
                '/what.*(need|require|necessary)/',
                '/\b(requirement|needed|mandatory)\b/',
                '/do i need/'
            ],
            'confidence' => 0.75
        ]
    ];
    
    // Check each intent
    foreach ($intents as $intent => $data) {
        foreach ($data['patterns'] as $pattern) {
            if (preg_match($pattern, $message)) {
                return [
                    'intent' => $intent,
                    'confidence' => $data['confidence']
                ];
            }
        }
    }
    
    // Default to general query
    return [
        'intent' => 'general',
        'confidence' => 0.50
    ];
}

// ============================================
// CONTEXT-AWARE SYSTEM PROMPT BUILDER
// ============================================

function buildSystemContext($context) {
    $baseContext = "You are a helpful assistant for the InfraGovServices - Community Infrastructure Maintenance Management System (CIMMS) for Quezon City, Philippines.

SYSTEM PURPOSE:
- Web portal for Quezon City residents to report infrastructure issues
- Track and manage maintenance requests efficiently
- Provide transparent communication between citizens and LGU

CORE FEATURES:
- Submit infrastructure reports with photo evidence
- Track request status in real-time
- Interactive map for precise location selection
- Secure role-based access system
- Dark mode support
- Mobile-responsive design

REPORTABLE ISSUES:
- Damaged roads and pathways
- Broken streetlights
- Clogged drainage systems
- Public facility problems
- Water supply issues
- Electrical infrastructure concerns

SUBMISSION REQUIREMENTS:
- Infrastructure type selection
- Precise location (with interactive map)
- Detailed issue description
- Contact number (11 digits, 09XX-XXX-XXXX format)
- Photo evidence (up to 4 images: JPG, JPEG, PNG, WEBP)
- Agreement to Terms and Privacy Policy

IMPORTANT GUIDELINES:
- Be concise and helpful (2-3 sentences when possible)
- Use friendly, professional tone
- Provide step-by-step guidance when needed
- Use emojis sparingly for visual clarity
- Bold important terms for emphasis
- For technical issues, direct to contact@infragovservices.com";

    // Add context-specific information
    $contextAdditions = [
        'home' => "\n\nCURRENT PAGE: Home Dashboard
- User is viewing system statistics
- Can see recent maintenance activities
- Access to all main navigation sections",
        
        'reports' => "\n\nCURRENT PAGE: Reports
- User is viewing maintenance schedules
- Can see status of recent repairs
- Can track ongoing and completed work",
        
        'request' => "\n\nCURRENT PAGE: Request Form
- User is submitting a new report
- Guide them through the submission process
- Emphasize required fields and photo evidence",
        
        'about' => "\n\nCURRENT PAGE: About
- User is learning about CIMMS
- Can explain system purpose and features
- Can discuss vision and mission"
    ];
    
    return $baseContext . ($contextAdditions[$context] ?? '');
}

// ============================================
// ENHANCED RESPONSE GENERATION
// ============================================

function generateResponse($message, $intent, $context, $history) {
    $responses = [
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
        
        'general' => function($message, $context, $history) {
            // Extract previous context
            $previousTopics = extractPreviousContext($history);
            
            // Context-based fallback
            if (!empty($previousTopics)) {
                return "I can help with: reporting issues, uploading photos, using the map, tracking requests, or system features.\n\n" .
                       "What specifically would you like to know?";
            }
            
            // Smart fallback based on page context
            $contextResponses = [
                'request' => "I see you're on the request form! Need help with:\n\n• Filling out the form?\n• Uploading photos?\n• Selecting location?\n• Understanding requirements?",
                'reports' => "Looking at reports? I can help you:\n\n• Understand status types\n• Find specific requests\n• Track maintenance progress",
                'home' => "Welcome to InfraGovServices! I can help you:\n\n• Submit a new report\n• Track existing requests\n• Navigate the system\n• Understand features",
                'about' => "Learning about CIMMS? Ask me about:\n\n• System purpose\n• Features\n• How it works\n• Who can use it"
            ];
            
            return $contextResponses[$context] ?? 
                   "I can help with: reporting issues, tracking requests, navigating the system, or understanding features.\n\n" .
                   "What would you like to know?";
        }
    ];
    
    // Get response based on intent
    if (isset($responses[$intent])) {
        $responseFunction = $responses[$intent];
        return $responseFunction($message, $context, $history);
    }
    
    return $responses['general']($message, $context, $history);
}

// ============================================
// CONVERSATION MEMORY
// ============================================

function extractPreviousContext($history) {
    $topics = [];
    $recentMessages = array_slice($history, -5); // Last 5 messages
    
    foreach ($recentMessages as $msg) {
        if (preg_match('/report|submit|request/', $msg['message'])) {
            $topics[] = 'reporting';
        }
        if (preg_match('/photo|image|upload/', $msg['message'])) {
            $topics[] = 'photos';
        }
        if (preg_match('/location|map|barangay/', $msg['message'])) {
            $topics[] = 'location';
        }
        if (preg_match('/track|status|progress/', $msg['message'])) {
            $topics[] = 'tracking';
        }
    }
    
    return array_unique($topics);
}

// ============================================
// CONFIDENCE SCORING
// ============================================

function calculateConfidence($intent, $message) {
    // Base confidence from intent detection
    $detection = detectIntent($message, 'general');
    $baseConfidence = $detection['confidence'];
    
    // Adjust based on message length and clarity
    $wordCount = str_word_count($message);
    if ($wordCount < 3) {
        $baseConfidence *= 0.8; // Shorter messages are less clear
    } elseif ($wordCount > 15) {
        $baseConfidence *= 0.9; // Very long messages might be complex
    }
    
    // Adjust based on question markers
    if (preg_match('/\?$/', $message)) {
        $baseConfidence *= 1.1; // Clear questions are good
    }
    
    return min($baseConfidence, 1.0);
}

// ============================================
// MAIN PROCESSING
// ============================================

// Detect intent
$intentData = detectIntent($userMessage, $context);
$intent = $intentData['intent'];
$confidence = $intentData['confidence'];

// Add to conversation history
$_SESSION['chat_history'][] = [
    'message' => $userMessage,
    'timestamp' => time(),
    'intent' => $intent,
    'context' => $context
];

// Limit history to last 10 messages
if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

// Generate response
$botResponse = generateResponse($userMessage, $intent, $context, $_SESSION['chat_history']);

// Log interaction for analytics
logInteraction($userMessage, $botResponse, $intent, $confidence, $context);

// Log response for analytics
logResponse($intent, $confidence, $botResponse);

// Send response
echo json_encode([
    'response' => $botResponse,
    'timestamp' => date('Y-m-d H:i:s'),
    'intent' => $intent,
    'confidence' => $confidence
]);

// ============================================
// LOGGING FUNCTIONS
// ============================================

function logInteraction($userMessage, $botResponse, $intent, $confidence, $context) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/chatbot_interactions_' . date('Y-m-d') . '.log';
    $logEntry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => session_id(),
        'user_message' => $userMessage,
        'bot_response' => $botResponse,
        'intent' => $intent,
        'confidence' => $confidence,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]) . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function logResponse($intent, $confidence, $response) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/chatbot_responses_' . date('Y-m-d') . '.log';
    $logEntry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'intent' => $intent,
        'confidence' => $confidence,
        'response_length' => strlen($response)
    ]) . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}