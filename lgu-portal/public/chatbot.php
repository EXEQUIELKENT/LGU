<?php
/**
 * InfraGovServices Chatbot Backend v4.0
 * ─────────────────────────────────────────────────────────────
 * Improvements over v3.1:
 *  • Expanded knowledge base — 18 intents (was 8), covering status, urgency,
 *    barangays, GIS map, evidence tips, maintenance schedules, chatbot help, etc.
 *  • Smarter intent detection — phrase matching + weighted scoring + minimum threshold
 *  • Conversation memory — last 8 turns sent to Claude (was 4), topic extraction
 *    from history for follow-up awareness
 *  • Follow-up detection — "what about", "also", "explain more", etc. route through
 *    Claude or KB intelligently instead of always hitting fallback
 *  • Stronger Claude system prompt — persona, scope, tone, examples, anti-hallucination
 *  • Better context injection — current topic inferred from history is sent to Claude
 *  • Graceful degradation — richer local fallback when no API key
 *  • Sanitization hardened — limits trimmed per field
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ─── API key ──────────────────────────────────────────────────
$CLAUDE_API_KEY = getenv('CLAUDE_API_KEY') ?: (defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '');
$USE_CLAUDE_API = !empty($CLAUDE_API_KEY);

// ─── Parse request ────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['response' => 'Invalid request.', 'aiCardHtml' => null]);
    exit;
}

$userMessage = mb_substr(strip_tags(trim($data['message'] ?? '')), 0, 1200);
$context     = strtolower(trim($data['context']  ?? 'general'));
$lang        = strtolower(trim($data['lang']      ?? 'en'));
$history     = is_array($data['history'] ?? null) ? $data['history'] : [];
$imageBase64 = $data['image']    ?? null;
$aiResult    = $data['aiResult'] ?? null;
$images      = is_array($data['images'] ?? null) ? $data['images'] : [];

// Normalise context
$allowedContexts = ['home','reports','request','about','privacy','terms','feedback','general'];
if (!in_array($context, $allowedContexts)) $context = 'general';

$isTagalog = ($lang === 'tl');

// ─── RESPONSE BUILDER ─────────────────────────────────────────
function respond(string $text, ?string $aiCardHtml = null): void {
    echo json_encode([
        'response'   => $text,
        'aiCardHtml' => $aiCardHtml,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── BILINGUAL HELPER ─────────────────────────────────────────
function bi(string $en, string $tl, bool $isTagalog): string {
    return $isTagalog ? $tl : $en;
}

// ════════════════════════════════════════════════════════════
//  CONVERSATION ANALYSIS HELPERS
// ════════════════════════════════════════════════════════════

/**
 * Extract the dominant topic discussed in recent history.
 * Returns a short string hint injected into the Claude prompt.
 */
function extractConversationTopic(array $history): string {
    if (empty($history)) return '';
    $recent = array_slice($history, -6);
    $text   = implode(' ', array_column($recent, 'text'));
    $text   = mb_strtolower($text);

    $topicMap = [
        'reporting / submission'    => ['report','submit','form','issue','damage','request','concern'],
        'tracking / status'         => ['track','status','pending','approved','rejected','progress','update'],
        'evidence / photos'         => ['photo','image','evidence','upload','picture','screenshot','camera'],
        'location / map'            => ['location','map','gis','coordinates','barangay','address','pin'],
        'privacy policy'            => ['privacy','data','personal','consent','rights','dpo','ra 10173'],
        'terms and conditions'      => ['terms','conditions','agreement','accept'],
        'account / login'           => ['login','logout','password','account','username','credentials'],
        'navigation / how to use'   => ['navigate','menu','button','how to','where','find','go to'],
        'infrastructure types'      => ['road','drainage','streetlight','sidewalk','electrical','water','facility'],
        'maintenance schedules'     => ['schedule','completion','budget','timeline','sched'],
        'feedback / rating'         => ['feedback','rating','star','review','complaint','suggestion','acknowledgement','concern','improvement','rate','comment','opinion','evaluate'],
    ];

    $best = ''; $bestScore = 0;
    foreach ($topicMap as $topic => $keywords) {
        $score = 0;
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) $score++;
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $topic; }
    }
    return $best;
}

/**
 * Detect follow-up phrasing — signals the user is continuing a previous thread
 * rather than starting a new topic.
 */
function isFollowUp(string $msg): bool {
    $lower = mb_strtolower(trim($msg));
    $phrases = [
        'what about','how about','and also','tell me more','explain more',
        'elaborate','can you explain','more details','what else','anything else',
        'follow up','follow-up','related to that','related to this','in that case',
        'so what','so how','then what','what do i do next','next step','next steps',
        'after that','and then','one more','another question','one question',
        // Tagalog
        'paano naman','ano pa','at ano','at paano','dagdag pa','ipaliwanag pa',
        'ano ang susunod','ano pa ang','tungkol doon','tungkol diyan','kaugnay',
        'sunod na hakbang','tapos ano','isa pang tanong',
    ];
    foreach ($phrases as $p) {
        if (mb_strpos($lower, $p) !== false) return true;
    }
    return false;
}

/**
 * Returns true for vague questions that should NOT trigger intent lookup
 * when they accompany an image (e.g. "what is this", "ano ito", "explain").
 */
function isGenericQuestion(string $msg): bool {
    $lower = mb_strtolower(trim($msg));
    $genericPhrases = [
        'what is this','what\'s this','what is it','what is that',
        'what am i looking at','explain this','explain','describe this',
        'can you explain','tell me about this','what does this show',
        'what does this mean','what do i see','whats this','what\'s here',
        'ano ito','ano ba ito','ano ang nakikita ko','ipaliwanag',
        'ipaliwanag mo','ano ang ibig sabihin','narito','ano na ito',
        'paano ito','i-explain','ano dito',
        'i submitted screenshots of the website for analysis.',
        'nagsumite ako ng mga screenshot ng website para sa pagsusuri.',
        'nagsumite ako ng screenshot ng website para sa pagsusuri.',
    ];
    foreach ($genericPhrases as $phrase) {
        if (mb_strpos($lower, mb_strtolower($phrase)) !== false) return true;
    }
    return mb_strlen($lower) <= 15;
}

// ════════════════════════════════════════════════════════════
//  PAGE STRUCTURE KNOWLEDGE
// ════════════════════════════════════════════════════════════

$PAGE_STRUCTURE = [

    'home' => "
## Home Page (citizencimm) — Full Structure

**Navigation Bar (top):**
- Logo: 'InfraGovServices' (links to infragovservices.com)
- Nav links: Home | Reports | Requests | About | Log in
- Right side: 🌐 language toggle (EN/FIL) | 🌙 dark mode toggle | live digital clock

**Hero Section:**
- Headline: 'Welcome to InfraGovServices'
- Subtitle: 'Community Infrastructure Maintenance Management System for Quezon City'
- CTA buttons: [Submit a Report] → citizenrepform.php | [Learn More] → scrolls down

**Stats Bar (3 animated counter cards):**
- 🛠️ Completed Repairs — total from repair_archive table
- ⏳ On-Going Repairs — In Progress status in maintenance_schedule
- 📍 Pending Requests — Pending approval in requests table

**Trust Strip (4 icons):**
🔒 Secure & Private | ⚡ Fast Response | ✅ Verified Reports | 🏆 Service Excellence

**How It Works (4 numbered steps):**
1. Report the Issue → 2. Review & Verification → 3. Maintenance Scheduled → 4. Issue Resolved

**Our Services (5 feature cards):**
- 📋 Submit Requests — 'Submit a Request' link
- 📍 Track Maintenance — 'View Reports' link
- 🗺️ Location-Based Reporting — GPS map integration
- 🔔 Real-Time Updates — status change alerts
- 👥 Community Engagement — transparent tracking

**Recent Maintenance Activity Section:**
- Table/list of 10 latest maintenance_schedule records
- Columns: Sched # | Task | Location | Status | Date

**About Section:** Brief system overview, mission snippet, [Learn More] button

**Footer:** Quick Links | Resources | Legal | Contact: contact@infragovservices.com | (02) 8988-4242 | Quezon City Hall
",

    'reports' => "
## Reports Page (citizenreports) — Full Structure

**Page Title:** 'Recent Maintenance Reports'

**Stats Row (3 cards, same as Home):**
- 🛠️ Repairs | ⏳ On-Going Repairs | 📍 Pending

**Search Bar:**
- Input placeholder: 'Search by Date, Type, Location, Budget, or Status…'
- Live-filters both the desktop table AND the mobile card list simultaneously
- Matching text is highlighted in yellow

**Desktop Table (hidden on mobile < 768px):**
Columns: Sched # | Date | Type | Location | Budget | Status | Action
- Sched # format: #SCH-001
- Status badge pills:
  🟡 Pending (yellow) | 🔵 In Progress (blue) | 🟢 Completed (green) | 🔴 Delayed (red)
- [View] button → opens Schedule Detail Modal overlay

**Schedule Detail Modal (new in v4):**
- Colored status band at top (green/blue/red/orange)
- Header: Schedule ID + Task name + ✕ close button
- Body sections:
  • Status pill
  • 📍 Location
  • 🏷️ Category
  • Divider
  • 2-column grid: Start Date | Estimated Completion | Budget
- Closes on ✕, backdrop click, or Escape key

**Mobile Cards (shown on mobile, hidden on desktop):**
Each card: Schedule ID | Category | Task | Location | Start Date | Budget | Status | [View] button

**Empty / No-match States:**
- 'No maintenance schedules available'
- 'No matching data' (when search returns nothing)
",

    'request' => "
## Request / Submit Form Page (citizenrepform) — Full Structure

**Page title:** 'Maintenance Request'

**Required fields (marked *):**
1. Infrastructure Type * — dropdown:
   Roads | Street Lights | Drainage | Public Facilities | Water Supply | Electrical | Other
   → 'Other' reveals a text field: 'Please specify the infrastructure type'
2. Location * — text input with 📍 map pin button; clicking opens the Map Modal
3. Contact Number * — format: 09XX-XXX-XXXX (11 digits starting with 09)
4. Issue / Damage Description * — textarea, describe problem in detail

**Optional fields:**
5. Name — text input (optional)

**Evidence Upload:**
6. Upload Images — multi-file input (up to 4 images; JPEG/PNG/GIF/WEBP only)
   → Shows thumbnail previews with ✕ remove button per image
   → 📷 camera capture button available on mobile devices

**Consent:**
7. Checkbox — 'I agree to Terms and Conditions and Privacy Policy' (required)

**Submit Button:** [Submit Request]

**Map Modal (location picker):**
- Interactive Leaflet map centered on Quezon City
- Barangay dropdown — filters map to the selected barangay
- Address field — auto-filled via reverse geocoding when pin is dropped
- 📍 GPS button — uses device location
- Layer toggle: Street ↔ Satellite
- Buttons: [Cancel] | [Save Location]
- Bounds-locked to Quezon City — pins outside QC are rejected

**Validation rules (frontend + backend):**
- Contact: must be exactly 11 digits, starts with 09
- Location: must be within Quezon City geographic bounds
- Images: at least 1 required before submit
- Consent checkbox: must be ticked
- All * fields must be non-empty

**Confirm modal before final submit:**
'Are you sure you want to submit this maintenance request?'
Buttons: [Cancel] | [Submit]

**Success redirect:** After submit, user is redirected back to the form with a success notification toast.
",

    'about' => "
## About Page — Full Structure

**Hero section:** 'About CIMMS – Quezon City' with subtitle

**Main content sections (in order):**
1. **Transforming Infrastructure Management** — intro text about the platform
2. **Highlights grid (4 cards):**
   📋 Easy Reporting | 🗺️ GPS Tracking | 🔔 Real-Time Updates | 📊 Transparent Tracking
3. **About CIMMS intro paragraphs** — detailed system description
4. **Our Purpose** — 4 goals: efficiency, communication, faster response, transparency
5. **What CIMMS Offers** — 5 features: reporting, tracking, coordination, secure access, dashboards
6. **For Quezon City Citizens** — system is exclusively for QC residents
7. **Our Vision** — vision statement about service excellence
8. **Our Mission** — mission statement about inclusive infrastructure governance
9. **Our Core Values** — 4 values: Efficiency | Transparency | Community First | Security
10. **CTA:** [Submit a Report] button

**Footer:** Standard footer with Quick Links, Resources, Legal
",

    'privacy' => "
## Privacy Policy Page — Full Structure

**Page title:** 'Privacy Policy'

**Sections (in order):**
1. **Intro** — RA 10173 (Data Privacy Act 2012) compliance, periodic updates notice
2. **Data Collection and Processing** — commitment statement
3. **Lawful Processing Principles** — data collected only for legitimate, declared purposes
4. **Types of Information Collected (5 items):**
   - Full name and contact information
   - Login credentials (hashed)
   - Location data (GPS coordinates)
   - Device and browser activity logs
   - Uploaded images/evidence
5. **Data Security and Protection** — encryption in transit and at rest, technical + organizational measures
6. **Your Rights as a Data Subject (RA 10173 — 5 rights):**
   - Right to be Informed — about collection and use
   - Right to Access — to your personal data
   - Right to Correction — of inaccurate data
   - Right to Object — to processing
   - Right to Erasure or Blocking
7. **User Consent and Agreement** — what you agree to by using the system
8. **Contact Information:**
   - Data Privacy Officer: dpo@infragovservices.com
   - Phone: (02) 8988-4242
9. **Policy Updates** — Last updated: February 2026

**Back button:** 'Back to Home'
",

    'terms' => "
## Terms and Conditions Page — Full Structure

**Page title:** 'Terms and Conditions'

**Sections (in order):**
1. **Intro** — RA 10173 compliance statement, agreement to terms on use
2. **Information Collection (5 items):**
   - Name and contact details
   - Login credentials
   - Location and GPS data
   - Activity logs
   - Uploaded evidence images
3. **Purpose of Data Collection (5 purposes + disclaimer):**
   - System operations and service delivery
   - Inter-departmental coordination
   - AI-assisted decision support
   - Academic and policy research
   - Legal compliance
4. **Data Processing and Storage (4 rules):**
   - Stored in secure servers
   - Retained only as long as necessary
   - Accessible to authorized LGU personnel only
   - Subject to regular security audits
5. **Data Sharing and Disclosure (3 exception cases):**
   - With user consent
   - When required by law / court order
   - With third parties strictly for system operations
6. **Data Subject Rights (5 rights with descriptions):**
   Right to be Informed | Right to Access | Right to Correction | Right to Object | Right to Erasure or Blocking
7. **AI-Assisted Decision Support (4 disclaimer items):**
   - AI recommendations are for support only, not replacing official authority
   - Human review required for all enforcement actions
   - System may not be 100% accurate
   - LGU retains final decision authority
8. **Contact and Data Privacy Concerns:**
   - admin@infragovservices.com
   - dpo@infragovservices.com
   - (02) 8988-4242
9. **Acceptance of Terms** — agreement statement
10. **Last Updated:** February 2026

**Back button:** 'Back to Home'
",

    'feedback' => "
## Feedback Page (citizen_feedback) — Full Structure

**Navigation Bar (top):**
- Same as other citizen pages: Home | Reports | Requests | Feedback (active) | About
- Right side: 🌐 language toggle | 🌙 dark mode | live digital clock

**Hero Section:**
- Headline: 'Submit Feedback — CIMM LGU'

**Feedback Form Card (max-width 820px):**

**Section 1 — Your Information:**
- Full Name (optional) — text input, defaults to 'Citizen'
- Contact Number (optional) — 09XX-XXX-XXXX format, auto-formatted
- Email Address (optional) — if provided, LGU will send a notification on your feedback

**Section 2 — Feedback Details:**
- Type of Feedback — 5 colorful radio-card options:
  ⚠️ Concern (orange) | 👍 Acknowledgement (green) | 💡 Improvement (blue) | 📢 Complaint (red) | ✏️ Suggestion (purple)
- Feedback Title * — required text input
- Description * — required textarea
- Star Rating * — half-star interactive widget (0.5–5.0), with progress bar and label:
  0.5–1 = Very Poor | 1.5–2 = Poor | 2.5 = Below Average | 3 = Average | 3.5 = Above Average | 4 = Good | 4.5 = Very Good | 5 = Excellent

**Section 3 — Infrastructure & Location:**
- Infrastructure / Facility (optional) — searchable combobox dropdown:
  Roads | Street Lights | Drainage | Public Facilities | Water Supply | Electrical
- Reference Completed Report (optional) — searchable dropdown of archived/completed reports with [View] button per entry
- Address / Location (optional) — text field + interactive Leaflet map picker (same GPS/satellite features as citizenrepform.php)

**Section 4 — Photo Evidence (optional, max 5 photos):**
- Drag-and-drop or click upload zone (JPG, PNG, WEBP, 5 MB each)
- Thumbnail previews with ✕ remove button
- Full lightbox view on thumbnail click

**Submit Button:** [Submit Feedback] → opens confirm modal

**Confirm Submission Modal:**
- Icon: 📬
- Title: 'Confirm Submission'
- Body: 'Are you sure you want to submit your feedback? You won't be able to edit it after submission.'
- Buttons: [Cancel] | [Submit]

**After Submit:** Redirects to handle_feedback.php, shows success notification

**Form preserves drafts via localStorage** — data is restored if user navigates away and returns

**Footer:** Same as other citizen pages
",

    'general' => "
## InfraGovServices Portal — General Overview

This is the InfraGovServices (CIMMS) portal for Quezon City, Philippines.
The system allows citizens to report, track, monitor infrastructure maintenance issues, and submit feedback.

**All available pages:**
- Home / Dashboard (citizencimm.php)
- Maintenance Reports list (citizenreports.php)
- Submit Request form (citizenrepform.php)
- Feedback Form (citizen_feedback.php)
- About page (about.php)
- Privacy Policy (privacy.php)
- Terms and Conditions (termcon.php)
- Login page (login.php) — for LGU employees and administrators

**Common UI elements on all pages:**
- Top navigation bar with logo, nav links, 🌐 language toggle (EN/FIL), 🌙 dark mode toggle, live digital clock
- Floating chatbot button (bottom-right corner) — that's me!
- Footer with Quick Links, Resources, Legal sections

**Contact:**
- General: contact@infragovservices.com | admin@infragovservices.com
- Data Privacy Officer: dpo@infragovservices.com
- Phone: (02) 8988-4242
- Address: Quezon City Hall, Quezon City, Philippines

**Key features of the system:**
- Citizen infrastructure reporting with GPS location pinning
- Evidence upload (up to 4 photos per report)
- GIS map visualization of all reports (employee portal)
- AI-assisted damage analysis from uploaded images
- Bilingual interface: English / Filipino (Tagalog)
- Real-time status tracking: Pending → In Progress → Completed / Delayed
- Citizen feedback with star ratings, feedback types, and reference to completed reports
",
];

// ════════════════════════════════════════════════════════════
//  EXPANDED KNOWLEDGE BASE — 18 intents
// ════════════════════════════════════════════════════════════

$KB = [

    'reporting' => [
        'keywords' => [
            'report','submit','request','issue','problem','concern','complaint',
            'broken','damage','pothole','flood','road','sidewalk','streetlight',
            'drainage','how do i report','how to report','mag-report','i-report',
            'ipag-ulat','ulat','isyu','problema','reklamo','sira','baha','kalsada',
            'ipaalam','paano mag-report','paano mag-submit',
        ],
        'phrases' => [
            'how do i report','how to report','how to submit','submit a report',
            'make a report','file a complaint','paano mag-ulat','paano mag-reklamo',
            'saan ako mag-rereport','saan mag-submit',
        ],
        'en' => "**How to Report an Infrastructure Issue** 🏗️\n\n" .
                "1️⃣ **Go to the Request Form** — click **Requests** in the nav menu (or tap the nav icon on mobile).\n\n" .
                "2️⃣ **Fill in the required fields:**\n" .
                "   • **Infrastructure Type** — Roads, Street Lights, Drainage, Public Facilities, Water Supply, Electrical, or Other\n" .
                "   • **Location** — click the 📍 field to open the map and pin your location\n" .
                "   • **Contact Number** — 09XX-XXX-XXXX format\n" .
                "   • **Issue / Damage Description** — describe the problem in as much detail as possible\n\n" .
                "3️⃣ **Upload evidence photos** — up to 4 images. On mobile, tap 📷 to take a photo on the spot.\n\n" .
                "4️⃣ **Tick the consent checkbox** and click **[Submit Request]**.\n\n" .
                "5️⃣ A confirmation dialog will appear — click **[Submit]** to confirm.\n\n" .
                "💡 *The more detailed your description and the more photos you provide, the faster the LGU can act!*",
        'tl' => "**Paano Mag-ulat ng Isyu sa Imprastraktura** 🏗️\n\n" .
                "1️⃣ **Pumunta sa Request Form** — i-click ang **Requests** sa navigation menu.\n\n" .
                "2️⃣ **Punan ang mga kinakailangang field:**\n" .
                "   • **Uri ng Imprastraktura** — Mga Kalsada, Mga Ilaw, Drainage, Pampublikong Pasilidad, Suplay ng Tubig, Elektrikal, o Iba Pa\n" .
                "   • **Lokasyon** — i-click ang 📍 field para buksan ang mapa at i-pin ang lokasyon\n" .
                "   • **Numero ng Kontak** — format na 09XX-XXX-XXXX\n" .
                "   • **Paglalarawan ng Isyu** — ilarawan ang problema nang detalyado\n\n" .
                "3️⃣ **Mag-upload ng mga larawan** — hanggang 4. Sa mobile, i-tap ang 📷 para kumuha ng litrato.\n\n" .
                "4️⃣ **Lagyan ng tsek ang consent checkbox** at i-click ang **[Isumite ang Kahilingan]**.\n\n" .
                "5️⃣ May lalabas na dialog — i-click ang **[Isumite]** para kumpirmahin.\n\n" .
                "💡 *Mas detalyado ang paglalarawan at mas maraming larawan, mas mabilis ang tugon ng LGU!*",
    ],

    'tracking' => [
        'keywords' => [
            'track','status','pending','approved','rejected','progress','in progress',
            'completed','update','check','where','my report','my request','reference',
            'sched','schedule id','req id','subaybayan','katayuan','nakabinbin',
            'inaprubahan','tinanggihan','natapos','update','nasaan','aking ulat',
        ],
        'phrases' => [
            'how to track','track my report','check status','where is my report',
            'what happened to my report','how do i know','check my request',
            'paano subaybayan','nasaan ang aking','ano ang status','check ko ang',
        ],
        'en' => "**Tracking Your Request / Report Status** 📍\n\n" .
                "To check the status of a maintenance report, go to the **Reports page** (click **Reports** in the menu).\n\n" .
                "**Status meanings:**\n" .
                "🟡 **Pending** — submitted and awaiting LGU review\n" .
                "🔵 **In Progress** — engineer or crew has been assigned; work has started\n" .
                "🟢 **Completed** — the issue has been fixed and archived\n" .
                "🔴 **Delayed** — work has been postponed (due to resources, weather, etc.)\n\n" .
                "**How to find your specific report:**\n" .
                "• Use the **search bar** on the Reports page — type the Schedule ID (#SCH-XXX), location, or task description\n" .
                "• The search filters both the table and mobile cards in real time\n\n" .
                "💡 *If you submitted recently, your report may take a short time to appear while it's under initial review.*",
        'tl' => "**Pagsubaybay sa Status ng Iyong Kahilingan** 📍\n\n" .
                "Para tingnan ang status, pumunta sa **Reports page** (i-click ang **Reports** sa menu).\n\n" .
                "**Kahulugan ng status:**\n" .
                "🟡 **Nakabinbin (Pending)** — naisumite na, naghihintay ng pagsusuri ng LGU\n" .
                "🔵 **Isinasagawa (In Progress)** — naka-assign na ang inhinyero; nagsimula na ang trabaho\n" .
                "🟢 **Natapos (Completed)** — naayos na ang isyu at naka-archive\n" .
                "🔴 **Naantala (Delayed)** — napospone ang trabaho (dahil sa resources, panahon, atbp.)\n\n" .
                "**Paano mahanap ang iyong ulat:**\n" .
                "• Gamitin ang **search bar** sa Reports page — i-type ang Schedule ID (#SCH-XXX), lokasyon, o paglalarawan\n" .
                "• Ang paghahanap ay nag-a-apply sa table at mobile cards nang sabay-sabay\n\n" .
                "💡 *Kung kakasubmit mo lang, maaaring may sandaling pagkaantala bago lumabas.*",
    ],

    'location_map' => [
        'keywords' => [
            'map','location','gps','pin','coordinate','barangay','address','area','street',
            'where','zone','satellite','interactive','leaflet','mapa','lokasyon','lugar',
            'koordinasyon','saan','kalye','barangay','saan nandoon','lugar ng isyu',
        ],
        'phrases' => [
            'how to set location','pin my location','use gps','use my location',
            'find my barangay','where to put location','location not saving',
            'paano ilagay ang lokasyon','paano gamitin ang mapa','i-pin ang lokasyon',
        ],
        'en' => "**Setting Your Location on the Map** 🗺️\n\n" .
                "On the Request Form, click the **📍 Location field** to open the interactive map.\n\n" .
                "**3 ways to set your location:**\n\n" .
                "1️⃣ **Click/tap directly on the map** — drop a pin at the exact spot\n" .
                "2️⃣ **GPS button (📍)** — tap to auto-detect your current device location\n" .
                "3️⃣ **Barangay dropdown** — select your barangay to zoom the map to that area first, then fine-tune by clicking\n\n" .
                "**Important rules:**\n" .
                "• The location pin **must be within Quezon City** — pins outside QC bounds will be rejected\n" .
                "• The address is auto-filled by reverse geocoding when you drop a pin\n" .
                "• Toggle between 🗺️ Street and 🛰️ Satellite views for better landmark visibility\n\n" .
                "**When done:** Click **[Save Location]** to confirm. Click **[Cancel]** to discard.\n\n" .
                "💡 *Zoom in as much as possible for the most accurate pin placement.*",
        'tl' => "**Pagtakda ng Lokasyon sa Mapa** 🗺️\n\n" .
                "Sa Request Form, i-click ang **📍 Location field** para buksan ang interactive na mapa.\n\n" .
                "**3 paraan para itakda ang lokasyon:**\n\n" .
                "1️⃣ **Mag-click/tap sa mapa** — mag-drop ng pin sa eksaktong lugar\n" .
                "2️⃣ **GPS button (📍)** — i-tap para awtomatikong ma-detect ang iyong kasalukuyang lokasyon\n" .
                "3️⃣ **Barangay dropdown** — piliin ang iyong barangay para mag-zoom sa lugar, tapos i-fine-tune\n\n" .
                "**Mahahalagang alituntunin:**\n" .
                "• Ang pin ay **dapat nasa loob ng Quezon City** — tinatanggihan ang mga pin sa labas ng QC\n" .
                "• Ang address ay awtomatikong nalalagay sa pamamagitan ng reverse geocoding\n" .
                "• Mag-toggle sa pagitan ng 🗺️ Street at 🛰️ Satellite para mas makita ang mga landmark\n\n" .
                "**Kapag tapos na:** I-click ang **[I-save ang Lokasyon]**.\n\n" .
                "💡 *Mag-zoom in nang mataas para sa mas tumpak na paglalagay ng pin.*",
    ],

    'evidence_photos' => [
        'keywords' => [
            'photo','image','evidence','upload','picture','attach','file','camera','screenshot',
            'larawan','litrato','ebidensya','mag-upload','kuha ng larawan','i-attach','i-upload',
            'file size','format','jpg','png','image type',
        ],
        'phrases' => [
            'how to upload photos','upload images','attach photos','take a photo',
            'camera button','what images to upload','photo requirements','image not uploading',
            'paano mag-upload ng larawan','kumuha ng larawan','mag-attach ng litrato',
        ],
        'en' => "**Uploading Evidence Photos** 📸\n\n" .
                "On the Request Form, the **Evidence — Upload Images** section accepts up to **4 photos**.\n\n" .
                "**How to upload:**\n" .
                "• **Desktop:** Click the upload area or drag-and-drop image files\n" .
                "• **Mobile:** Tap the 📷 camera button to take a photo directly, or tap the upload area to choose from your gallery\n\n" .
                "**Accepted formats:** JPEG, PNG, GIF, WEBP\n\n" .
                "**Tips for effective evidence photos:**\n" .
                "📌 Take the photo as close to the damage as possible\n" .
                "📌 Include a wider context shot to show the surrounding area\n" .
                "📌 Shoot in good lighting (daytime preferred)\n" .
                "📌 Capture any hazard markings, water levels, or broken elements clearly\n\n" .
                "**After upload:** Thumbnails appear with an ✕ to remove. At least 1 image is required before you can submit.\n\n" .
                "💡 *The AI in the system analyzes your photos to help classify the severity and type of damage.*",
        'tl' => "**Pag-upload ng mga Larawan bilang Ebidensya** 📸\n\n" .
                "Sa Request Form, tinatanggap ng **Seksyon ng Ebidensya** ang hanggang **4 na larawan**.\n\n" .
                "**Paano mag-upload:**\n" .
                "• **Desktop:** I-click ang upload area o i-drag-and-drop ang mga larawan\n" .
                "• **Mobile:** I-tap ang 📷 camera button para kumuha ng larawan nang direkta, o i-tap ang upload area para pumili mula sa gallery\n\n" .
                "**Mga tinanggap na format:** JPEG, PNG, GIF, WEBP\n\n" .
                "**Mga tip para sa epektibong mga larawan:**\n" .
                "📌 Kunan ang larawan nang malapit sa pinsala\n" .
                "📌 Kumuha rin ng mas malawak na kontekstong larawan\n" .
                "📌 Mag-kuha sa magandang liwanag (mas mainam sa araw)\n" .
                "📌 Ipakita ang antas ng tubig, sirang bahagi, o mga hadlang nang malinaw\n\n" .
                "**Pagkatapos mag-upload:** Lalabas ang mga thumbnail na may ✕ para mag-alis. Kailangan ng hindi bababa sa 1 larawan.\n\n" .
                "💡 *Sinusuri ng AI sa sistema ang iyong mga larawan para makatulong sa pag-uuri ng kalubhaan ng pinsala.*",
    ],

    'urgency_severity' => [
        'keywords' => [
            'urgent','emergency','critical','severe','dangerous','hazard','immediate','asap',
            'priority','life','risk','accident','flood','collapse','fire','electricity','live wire',
            'urgente','emergency','kritikal','mapanganib','agarang','buhay','panganib','accident',
            'baha','guho','sunog','kuryente','live wire',
        ],
        'phrases' => [
            'very urgent','life threatening','dangerous situation','immediate action',
            'emergency situation','serious hazard','critical issue','need help now',
            'napaka-urgente','mapanganib na sitwasyon','agarang aksyon','seryosong panganib',
        ],
        'en' => "**Reporting Urgent / Emergency Infrastructure Issues** 🚨\n\n" .
                "For **immediate life-threatening hazards** (e.g., live electrical wires on the road, collapsed bridge, severe flooding blocking emergency vehicles), **contact emergency services first:**\n\n" .
                "📞 **Quezon City Emergency Hotline:** 122 or 8988-4242\n" .
                "🚒 **Fire:** 160 | 🚔 **Police:** 117 | 🚑 **Medical:** 119\n\n" .
                "**Then submit your report on InfraGovServices** to formally document the issue for LGU response.\n\n" .
                "**In the Issue Description field, clearly state:**\n" .
                "• ⚠️ 'URGENT' or 'EMERGENCY' at the beginning\n" .
                "• The exact nature of the hazard (who's at risk, what could happen)\n" .
                "• How many people are affected\n\n" .
                "**Upload photos of the danger** to support faster prioritization.\n\n" .
                "💡 *LGU staff monitor incoming reports and flag critical ones for immediate dispatch.*",
        'tl' => "**Pag-uulat ng Urgent / Emergency na Isyu** 🚨\n\n" .
                "Para sa **agarang banta sa buhay** (hal., live wire sa kalsada, guho ng tulay, matinding baha), **makipag-ugnayan muna sa emergency services:**\n\n" .
                "📞 **QC Emergency Hotline:** 122 o 8988-4242\n" .
                "🚒 **Sunog:** 160 | 🚔 **Pulis:** 117 | 🚑 **Medikal:** 119\n\n" .
                "**Pagkatapos, mag-submit ng ulat sa InfraGovServices** para pormal na idokumento ang isyu.\n\n" .
                "**Sa field ng Paglalarawan, malinaw na sabihin:**\n" .
                "• ⚠️ 'URGENT' o 'EMERGENCY' sa simula\n" .
                "• Ang eksaktong kalikasan ng panganib (sino ang nasa panganib, ano ang maaaring mangyari)\n\n" .
                "**Mag-upload ng mga larawan ng panganib** para mapabilis ang prioritisasyon.\n\n" .
                "💡 *Sinisigurado ng LGU staff na ang mga kritikal na ulat ay agad na tinutugunan.*",
    ],

    'infrastructure_types' => [
        'keywords' => [
            'type','roads','drainage','street lights','streetlight','sidewalk','bridge','water supply',
            'electrical','public facilities','pavement','pothole','canal','sewer','outage',
            'powerline','flood control','footpath','categories','classify','uri','kategorya',
            'kalsada','ilog','daluyan','bangketa','tulay','tubig','ilaw','kuryente','pasilidad',
        ],
        'phrases' => [
            'what types','what categories','what can i report','infrastructure types',
            'ano ang uri','ano ang kategorya','anong uri ng isyu','ano ang maaaring iulat',
        ],
        'en' => "**Infrastructure Types You Can Report** 📋\n\n" .
                "🛣️ **Roads** — potholes, cracks, road damage, sunken asphalt, no road markings\n\n" .
                "🌊 **Drainage** — clogged drains, overflowing canals, sewer backflow, flooding spots\n\n" .
                "💡 **Street Lights** — broken lampposts, non-functional lights, exposed/dangling wires\n\n" .
                "🚶 **Sidewalks / Footpaths** — damaged tiles, missing ramps, obstructions, sunken sections\n\n" .
                "🏛️ **Public Facilities** — damage to government buildings, parks, public markets, plazas\n\n" .
                "💧 **Water Supply** — leaking water mains, burst pipes, discolored water, no water supply\n\n" .
                "⚡ **Electrical** — power outages, exposed wires, illegal connections, transformer issues\n\n" .
                "📄 **Other** — any other infrastructure concern (selecting 'Other' reveals a text field for you to specify)\n\n" .
                "💡 *Choose the most specific category that matches your issue for fastest routing to the right team.*",
        'tl' => "**Mga Uri ng Imprastraktura na Maaaring Iulat** 📋\n\n" .
                "🛣️ **Mga Kalsada** — butas, bitak, sirang aspalto, walang road markings\n\n" .
                "🌊 **Drainage** — naharang na kanal, umaapaw na drainage, baha\n\n" .
                "💡 **Mga Ilaw sa Kalye** — sirang poste, hindi gumagana, nakalantad na wire\n\n" .
                "🚶 **Bangketa / Daanan** — sirang sahig, nawawalang ramp, mga hadlang\n\n" .
                "🏛️ **Mga Pampublikong Pasilidad** — pinsala sa mga gusali ng gobyerno, parke, palengke\n\n" .
                "💧 **Suplay ng Tubig** — tumatagasang tubo, putol na suplay, makulay na tubig\n\n" .
                "⚡ **Elektrikal** — pagkawala ng kuryente, nakalantad na wire, sirang transformer\n\n" .
                "📄 **Iba Pa** — anumang iba pang isyu (magpapakita ng text field para mas malinaw na ilarawan)\n\n" .
                "💡 *Piliin ang pinaka-angkop na kategorya para mas mabilis na mapunta sa tamang koponan.*",
    ],

    'maintenance_schedule' => [
        'keywords' => [
            'schedule','sched','maintenance','timeline','completion','start date','end date',
            'when','budget','assigned','crew','engineer','work order','iskedyul','katatapos',
            'kailan','badyet','assigned','trabaho','kailan matatapos',
        ],
        'phrases' => [
            'when will it be fixed','when will repairs start','what is the schedule',
            'how long will it take','estimated completion','maintenance timeline',
            'kailan maaayos','kailan magsisimula','ano ang iskedyul','gaano katagal',
        ],
        'en' => "**Understanding the Maintenance Schedule** 📅\n\n" .
                "On the **Reports page**, each record shows:\n\n" .
                "📌 **Sched #** — unique identifier (e.g., #SCH-042)\n" .
                "📅 **Start Date** — when work is scheduled to begin\n" .
                "🏁 **Estimated Completion Date** — projected finish date\n" .
                "💰 **Budget** — allocated budget for the repair (in ₱)\n" .
                "🏷️ **Category** — type of infrastructure\n\n" .
                "**Clicking [View]** opens the Schedule Detail modal showing all the above fields in full detail.\n\n" .
                "**Status progression:**\n" .
                "🟡 Pending → 🔵 In Progress → 🟢 Completed\n" .
                "(or → 🔴 Delayed if work is postponed)\n\n" .
                "💡 *Budgets and timelines are set by LGU engineers during the review and approval process.*",
        'tl' => "**Pag-unawa sa Maintenance Schedule** 📅\n\n" .
                "Sa **Reports page**, ang bawat rekord ay nagpapakita ng:\n\n" .
                "📌 **Sched #** — natatanging identifier (hal., #SCH-042)\n" .
                "📅 **Petsa ng Pagsisimula** — kailan magsisimula ang trabaho\n" .
                "🏁 **Tinatayang Petsa ng Pagkumpleto** — inaasahang tapusin\n" .
                "💰 **Badyet** — nakalaan na badyet para sa pag-aayos (sa ₱)\n" .
                "🏷️ **Kategorya** — uri ng imprastraktura\n\n" .
                "**Ang pag-click ng [View]** ay nagbubukas ng Schedule Detail modal.\n\n" .
                "**Pagsulong ng status:**\n" .
                "🟡 Nakabinbin → 🔵 Isinasagawa → 🟢 Natapos\n" .
                "(o → 🔴 Naantala kung napospone ang trabaho)\n\n" .
                "💡 *Ang mga badyet at timeline ay itinakda ng mga inhinyero ng LGU sa proseso ng pagsusuri.*",
    ],

    'navigation' => [
        'keywords' => [
            'navigate','menu','button','how to','where','find','go to','page','section',
            'link','click','tab','sidebar','nav','header','footer','back','home',
            'mag-navigate','menu','saan','pupunta','paano','hanapin','i-click',
        ],
        'phrases' => [
            'how do i go to','where do i find','where is','how to navigate',
            'where is the button','where is the menu','paano pumunta','nasaan ang',
            'paano ko mahahanap','saan ko makikita',
        ],
        'en' => "**Navigating InfraGovServices** 🧭\n\n" .
                "**Desktop (top navigation bar):**\n" .
                "• **Home** — return to the main dashboard\n" .
                "• **Reports** — browse all maintenance reports and schedules\n" .
                "• **Requests** — submit a new infrastructure issue\n" .
                "• **About** — learn about CIMMS and its mission\n" .
                "• **🌐 Language toggle** — switch between English and Filipino\n" .
                "• **🌙 Dark mode** — toggle dark/light theme\n\n" .
                "**Mobile (tap ☰ to open sidebar):**\n" .
                "The same links are in the slide-out sidebar menu.\n\n" .
                "**Quick tips:**\n" .
                "📌 The chatbot button (that's me! 💬) is always at the **bottom-right corner**\n" .
                "📌 The footer has Quick Links, Resources, and Legal links\n" .
                "📌 All pages are optimized for both desktop and mobile screens",
        'tl' => "**Pag-navigate sa InfraGovServices** 🧭\n\n" .
                "**Desktop (navigation bar sa itaas):**\n" .
                "• **Home** — bumalik sa pangunahing dashboard\n" .
                "• **Reports** — mag-browse ng lahat ng ulat at schedule\n" .
                "• **Requests** — mag-submit ng bagong isyu\n" .
                "• **About** — alamin ang tungkol sa CIMMS\n" .
                "• **🌐 Language toggle** — mag-switch sa English o Filipino\n" .
                "• **🌙 Dark mode** — mag-toggle ng tema\n\n" .
                "**Mobile (i-tap ang ☰ para buksan ang sidebar):**\n" .
                "Makikita ang parehong mga link sa slide-out sidebar menu.\n\n" .
                "**Mabilis na tips:**\n" .
                "📌 Ang chatbot button (ako iyon! 💬) ay laging nasa **ibaba-kanan na sulok**\n" .
                "📌 Ang footer ay may Quick Links, Resources, at Legal na mga link",
    ],

    'language_toggle' => [
        'keywords' => [
            'language','switch','english','filipino','tagalog','translate','wika','pagsalin',
            'mag-switch','pilipino','ingles','globe','translate button','language button',
        ],
        'en' => "**Switching Language** 🌐\n\n" .
                "InfraGovServices supports **English** and **Filipino (Tagalog)**.\n\n" .
                "• **Desktop:** Click the **🌐 globe icon** in the top navigation bar — it shows **EN** or **FIL**\n" .
                "• **Mobile:** Tap the **🌐 icon** in the top-right of the mobile nav bar\n\n" .
                "The switch applies to:\n" .
                "✅ All navigation labels\n" .
                "✅ Page content and headings\n" .
                "✅ Form field labels and placeholders\n" .
                "✅ Chatbot responses (I'll switch too!)\n" .
                "✅ Notifications and status messages\n\n" .
                "Your language preference is **saved automatically** via localStorage — it will be remembered the next time you visit! 🔄",
        'tl' => "**Pag-switch ng Wika** 🌐\n\n" .
                "Sinusuportahan ng InfraGovServices ang **English** at **Filipino (Tagalog)**.\n\n" .
                "• **Desktop:** I-click ang **🌐 globe icon** sa navigation bar — nagpapakita ng **EN** o **FIL**\n" .
                "• **Mobile:** I-tap ang **🌐 icon** sa mobile nav bar\n\n" .
                "Nalalapat ang pagpapalit sa:\n" .
                "✅ Lahat ng nav labels\n" .
                "✅ Nilalaman ng pahina\n" .
                "✅ Mga label at placeholder ng form\n" .
                "✅ Mga tugon ng chatbot (ako rin ay mag-switch!)\n" .
                "✅ Mga notification at status message\n\n" .
                "Ang iyong kagustuhan sa wika ay **awtomatikong nase-save** — maaalalang ito sa susunod na pagbisita! 🔄",
    ],

    'dark_mode' => [
        'keywords' => [
            'dark','light','mode','theme','night','moon','sun','brightness','madilim','maliwanag',
            'dark mode','light mode','tema','liwanag',
        ],
        'en' => "**Dark Mode / Light Mode** 🌙☀️\n\n" .
                "Click the **🌙 moon icon** (or ☀️ sun icon) in the top-right navigation area to toggle between dark and light mode.\n\n" .
                "• On **mobile**, the toggle is at the top-right of the mobile nav bar\n" .
                "• The theme applies instantly to the entire page\n" .
                "• Your preference is **automatically saved** and remembered on your next visit\n\n" .
                "💡 *Dark mode reduces eye strain, especially useful at night or in low-light environments.*",
        'tl' => "**Dark Mode / Light Mode** 🌙☀️\n\n" .
                "I-click ang **🌙 moon icon** (o ☀️ sun icon) sa navigation bar para mag-toggle.\n\n" .
                "• Sa **mobile**, ang toggle ay nasa itaas-kanan ng mobile nav bar\n" .
                "• Agad na nalalapat ang tema sa buong pahina\n" .
                "• Ang iyong kagustuhan ay **awtomatikong nase-save** at maalala sa susunod na pagbisita\n\n" .
                "💡 *Ang dark mode ay nakakabawas ng pagod sa mata, lalo na sa gabi.*",
    ],

    'account' => [
        'keywords' => [
            'login','logout','account','password','username','register','sign in','sign up',
            'forgot','reset','credentials','access','employee','admin','mag-login','mag-logout',
            'account ko','username','password','nakalimutan',
        ],
        'phrases' => [
            'how to login','forgot password','reset password','create account',
            'paano mag-login','nakalimutan ang password','gumawa ng account',
        ],
        'en' => "**Account & Login Help** 🔐\n\n" .
                "**For Citizens:**\n" .
                "• You do **NOT** need an account to submit a maintenance request or view reports\n" .
                "• The public forms (Reports, Submit Request) are fully accessible without logging in\n\n" .
                "**For LGU Employees / Admins:**\n" .
                "• Log in using your registered username and password on the **Login page**\n" .
                "• Click **Log in** in the top navigation bar\n" .
                "• Use 'Forgot Password?' on the login page if you need to reset your credentials\n\n" .
                "**New employee accounts:**\n" .
                "• Created by the System Administrator — contact your LGU supervisor\n" .
                "• Self-registration is not available for security reasons\n\n" .
                "📧 For account issues: **admin@infragovservices.com** | 📞 **(02) 8988-4242**",
        'tl' => "**Tulong sa Account at Login** 🔐\n\n" .
                "**Para sa mga Mamamayan:**\n" .
                "• **Hindi kailangan** ng account para mag-submit ng kahilingan o tingnan ang mga ulat\n" .
                "• Ang mga pampublikong form (Reports, Submit Request) ay accessible nang walang login\n\n" .
                "**Para sa mga Empleyado / Admin ng LGU:**\n" .
                "• Mag-login gamit ang iyong rehistradong username at password sa **Login page**\n" .
                "• I-click ang **Log in** sa navigation bar\n" .
                "• Gamitin ang 'Forgot Password?' kung kailangan mong i-reset ang iyong credentials\n\n" .
                "**Bagong account ng empleyado:**\n" .
                "• Nililikha ng System Administrator — makipag-ugnayan sa iyong supervisor sa LGU\n\n" .
                "📧 Para sa mga isyu: **admin@infragovservices.com** | 📞 **(02) 8988-4242**",
    ],

    'privacy' => [
        'keywords' => [
            'privacy','data','personal','information','collect','store','security','protect',
            'consent','rights','dpo','data privacy','ra 10173','law','confidential','sharing',
            'datos','personal na impormasyon','seguridad','karapatan','pahintulot','batas',
        ],
        'phrases' => [
            'data privacy','privacy policy','my data','is my data safe','data protection',
            'privacy rights','what data','how is data used','datos ko','ligtas ba ang datos',
        ],
        'en' => "**Privacy Policy & Data Protection** 🔒\n\n" .
                "InfraGovServices complies with **RA 10173 (Data Privacy Act of 2012)** of the Philippines.\n\n" .
                "**What data is collected:**\n" .
                "• Full name and contact information\n" .
                "• Login credentials (stored as encrypted hash)\n" .
                "• GPS location data from reports\n" .
                "• Device/browser activity logs\n" .
                "• Uploaded evidence images\n\n" .
                "**Your rights as a data subject:**\n" .
                "🔹 Right to be Informed — know what data is collected\n" .
                "🔹 Right to Access — view your personal data\n" .
                "🔹 Right to Correction — fix inaccurate data\n" .
                "🔹 Right to Object — object to certain data processing\n" .
                "🔹 Right to Erasure/Blocking — request data deletion\n\n" .
                "**Contact the Data Privacy Officer:**\n" .
                "📧 dpo@infragovservices.com | 📞 (02) 8988-4242\n\n" .
                "📄 Read the full policy: click **Privacy Policy** in the footer.",
        'tl' => "**Patakaran sa Privacy at Proteksyon ng Data** 🔒\n\n" .
                "Sumusunod ang InfraGovServices sa **RA 10173 (Data Privacy Act of 2012)**.\n\n" .
                "**Anong data ang kinokolekta:**\n" .
                "• Buong pangalan at contact info\n" .
                "• Login credentials (nakaimbak bilang encrypted hash)\n" .
                "• GPS location data mula sa mga ulat\n" .
                "• Mga log ng aktibidad ng device/browser\n" .
                "• Mga naka-upload na larawan ng ebidensya\n\n" .
                "**Ang iyong mga karapatan bilang data subject:**\n" .
                "🔹 Karapatan na Malaman — alamin kung anong data ang kinokolekta\n" .
                "🔹 Karapatan sa Pag-access — tingnan ang iyong personal na data\n" .
                "🔹 Karapatan sa Pagwawasto — baguhin ang hindi tamang data\n" .
                "🔹 Karapatan sa Pagtutol — tutulan ang ilang uri ng pagpoproseso\n" .
                "🔹 Karapatan sa Pagbubura — humiling ng pagbubura ng data\n\n" .
                "**Makipag-ugnayan sa Data Privacy Officer:**\n" .
                "📧 dpo@infragovservices.com | 📞 (02) 8988-4242\n\n" .
                "📄 Basahin ang buong patakaran: i-click ang **Privacy Policy** sa footer.",
    ],

    'terms' => [
        'keywords' => [
            'terms','conditions','agreement','accept','rules','use','ai disclaimer','policy',
            'tuntunin','kasunduan','alituntunin','sumasang-ayon','mga patakaran',
        ],
        'en' => "**Terms and Conditions** 📜\n\n" .
                "By using InfraGovServices, you agree to the Terms and Conditions.\n\n" .
                "**Key points:**\n" .
                "• Data you submit is used for infrastructure management, coordination, and AI-assisted analysis\n" .
                "• Data is stored securely and retained only as long as necessary\n" .
                "• AI recommendations are for **decision support only** — human LGU officials make final decisions\n" .
                "• Data is **not shared** without your consent, except when required by law\n\n" .
                "**Your rights are protected under RA 10173** — see the Privacy Policy for details.\n\n" .
                "📄 Read the full Terms: click **Terms of Service** in the footer.\n\n" .
                "📧 Questions: admin@infragovservices.com | dpo@infragovservices.com | 📞 (02) 8988-4242",
        'tl' => "**Mga Tuntunin at Kondisyon** 📜\n\n" .
                "Sa paggamit ng InfraGovServices, sumasang-ayon ka sa Mga Tuntunin at Kondisyon.\n\n" .
                "**Mga pangunahing punto:**\n" .
                "• Ang data na isusumite mo ay gagamitin para sa pamamahala ng imprastraktura at AI-assisted analysis\n" .
                "• Ang data ay ligtas na nakaimbak at pinananatili lamang hanggang kinakailangan\n" .
                "• Ang mga rekomendasyon ng AI ay para lamang sa **suporta sa desisyon** — ang mga opisyal ng LGU ang gumagawa ng pangwakas na desisyon\n" .
                "• Ang data ay **hindi ibinabahagi** nang walang pahintulot, maliban kung kinakailangan ng batas\n\n" .
                "📄 Basahin ang buong Tuntunin: i-click ang **Terms of Service** sa footer.\n\n" .
                "📧 Mga katanungan: admin@infragovservices.com | 📞 (02) 8988-4242",
    ],

    'about' => [
        'keywords' => [
            'about','system','infragovservices','lgu','quezon','city','mission','vision','purpose',
            'what is','who','cimms','goal','values','tungkol','layunin','misyon','bisyon',
            'sistema','lungsod','ginagawa','para saan','ano ang',
        ],
        'en' => "**About InfraGovServices (CIMMS)** ℹ️\n\n" .
                "**InfraGovServices** is the **Community Infrastructure Maintenance Management System (CIMMS)** for Quezon City, Philippines.\n\n" .
                "🎯 **Mission:** Efficient, transparent, and responsive infrastructure services for all QC residents.\n\n" .
                "🌟 **Vision:** A Quezon City where every infrastructure concern is addressed promptly, fairly, and transparently.\n\n" .
                "💎 **Core Values:**\n" .
                "• Efficiency — fast, systematic response to infrastructure issues\n" .
                "• Transparency — all reports and statuses are publicly visible\n" .
                "• Community First — citizens are the primary stakeholders\n" .
                "• Security — data is protected under Philippine law\n\n" .
                "🔧 **What the system does:**\n" .
                "• Centralizes citizen infrastructure reporting\n" .
                "• Uses AI to assist in damage classification\n" .
                "• Provides real-time GIS mapping of reported issues\n" .
                "• Coordinates LGU departments for faster resolution\n\n" .
                "📍 Quezon City Hall, Quezon City, Philippines",
        'tl' => "**Tungkol sa InfraGovServices (CIMMS)** ℹ️\n\n" .
                "Ang **InfraGovServices** ay ang **Community Infrastructure Maintenance Management System (CIMMS)** para sa Lungsod ng Quezon, Pilipinas.\n\n" .
                "🎯 **Misyon:** Mahusay, transparent, at maagap na serbisyo sa imprastraktura para sa lahat ng residente ng QC.\n\n" .
                "🌟 **Pananaw:** Isang Quezon City kung saan ang bawat alalahanin sa imprastraktura ay tinutugunan nang mabilis, makatarungan, at malinaw.\n\n" .
                "💎 **Mga Pangunahing Halaga:**\n" .
                "• Kahusayan | Transparency | Komunidad Muna | Seguridad\n\n" .
                "🔧 **Ginagawa ng sistema:**\n" .
                "• Sentralisasyon ng pag-uulat ng mamamayan\n" .
                "• Paggamit ng AI para sa pag-uuri ng pinsala\n" .
                "• Real-time GIS mapping ng mga iniulat na isyu\n" .
                "• Koordinasyon ng mga departamento ng LGU\n\n" .
                "📍 Quezon City Hall, Quezon City, Pilipinas",
    ],

    'contact' => [
        'keywords' => [
            'contact','email','phone','hotline','number','support','helpdesk','reach','office',
            'staff','lgu','administrator','makipag-ugnayan','email','telepono','numero',
            'suporta','opisina','saan','makakausap',
        ],
        'phrases' => [
            'how to contact','reach out','contact number','email address','phone number',
            'who do i call','paano makipag-ugnayan','numero ng telepono','email address',
            'sino ang tatawagan',
        ],
        'en' => "**Contact Information** 📞\n\n" .
                "**General Inquiries:**\n" .
                "📧 contact@infragovservices.com\n" .
                "📧 admin@infragovservices.com\n\n" .
                "**Data Privacy Officer:**\n" .
                "📧 dpo@infragovservices.com\n\n" .
                "**Phone:**\n" .
                "📞 (02) 8988-4242\n\n" .
                "**Emergency Hotlines:**\n" .
                "🆘 Quezon City Emergency: 122 or 8988-4242\n" .
                "🚒 Fire: 160 | 🚔 Police: 117 | 🚑 Medical: 119\n\n" .
                "**Office Address:**\n" .
                "📍 Quezon City Hall, Quezon City, Philippines\n\n" .
                "💡 *For the fastest response, use the InfraGovServices portal to submit a formal report.*",
        'tl' => "**Impormasyon sa Pakikipag-ugnayan** 📞\n\n" .
                "**Pangkalahatang Mga Katanungan:**\n" .
                "📧 contact@infragovservices.com\n" .
                "📧 admin@infragovservices.com\n\n" .
                "**Data Privacy Officer:**\n" .
                "📧 dpo@infragovservices.com\n\n" .
                "**Telepono:**\n" .
                "📞 (02) 8988-4242\n\n" .
                "**Emergency Hotlines:**\n" .
                "🆘 QC Emergency: 122 o 8988-4242\n" .
                "🚒 Sunog: 160 | 🚔 Pulis: 117 | 🚑 Medikal: 119\n\n" .
                "**Address:**\n" .
                "📍 Quezon City Hall, Quezon City, Pilipinas",
    ],

    'chatbot_help' => [
        'keywords' => [
            'chatbot','assistant','bot','chat','you','who are you','what can you do',
            'help me','capabilities','features','voice','mic','microphone','screenshot',
            'ikaw','sino ka','ano magagawa mo','tulong','boses','mikropono',
        ],
        'phrases' => [
            'what can you do','what do you do','how can you help','who are you',
            'are you an ai','chatbot features','voice input','how to use chatbot',
            'ano magagawa mo','paano ka gamitin','ikaw ba ay ai','features ng chatbot',
        ],
        'en' => "**About Me — InfraGovServices AI Assistant** 🤖\n\n" .
                "I'm an AI-powered chatbot built specifically for the InfraGovServices portal. Here's what I can do:\n\n" .
                "📋 **Answer questions** about the portal, forms, features, and navigation\n" .
                "📍 **Guide you** through submitting a maintenance report step by step\n" .
                "📊 **Explain** report statuses, infrastructure types, and maintenance schedules\n" .
                "🔒 **Clarify** Privacy Policy and Terms and Conditions\n" .
                "📸 **Analyze screenshots** — upload an image and I'll explain what I see\n" .
                "🎙️ **Voice input** — click the 🎙️ mic button to speak instead of type\n" .
                "🌐 **Bilingual** — I respond in English or Filipino based on your language setting\n\n" .
                "**How to clear chat history:** Click the 🗑️ trash icon in the chat header.\n\n" .
                "💡 *Just type your question naturally — I'm here to help!*",
        'tl' => "**Tungkol sa Akin — InfraGovServices AI Assistant** 🤖\n\n" .
                "Ako ay isang AI-powered chatbot para sa portal ng InfraGovServices. Narito ang aking mga kakayahan:\n\n" .
                "📋 **Sumagot sa mga tanong** tungkol sa portal, mga form, feature, at navigation\n" .
                "📍 **Gabayan ka** sa pag-submit ng ulat nang hakbang-hakbang\n" .
                "📊 **Ipaliwanag** ang mga status ng ulat, uri ng imprastraktura, at schedule\n" .
                "🔒 **Linawin** ang Privacy Policy at Terms and Conditions\n" .
                "📸 **Suriin ang mga screenshot** — mag-upload ng larawan at ipapaliwanag ko\n" .
                "🎙️ **Voice input** — i-click ang 🎙️ mic para magsalita kaysa mag-type\n" .
                "🌐 **Bilingual** — sumasagot ako sa English o Filipino\n\n" .
                "**Paano mag-clear ng chat history:** I-click ang 🗑️ trash icon sa chat header.\n\n" .
                "💡 *Mag-type lang ng iyong tanong — nandito ako para tumulong!*",
    ],

    'search_filter' => [
        'keywords' => [
            'search','filter','find','lookup','sort','browse','keyword','query',
            'maghanap','filter','hanapin','keyword',
        ],
        'phrases' => [
            'how to search','how to filter','find a report','search by date','filter by status',
            'paano maghanap','paano mag-filter','hanapin ang ulat','maghanap ayon sa petsa',
        ],
        'en' => "**Searching & Filtering Reports** 🔍\n\n" .
                "On the **Reports page**, use the **search bar** at the top of the table:\n\n" .
                "**You can search by:**\n" .
                "📅 Date (e.g., 'Jan 2026' or 'March')\n" .
                "🏷️ Task/Type (e.g., 'road repair', 'drainage')\n" .
                "📍 Location (e.g., barangay name or street)\n" .
                "💰 Budget (e.g., '50,000' or '₱')\n" .
                "🔘 Status (e.g., 'In Progress', 'Completed')\n\n" .
                "**How it works:**\n" .
                "• Matching text is **highlighted in yellow** in the results\n" .
                "• Matches float to the top of the table automatically\n" .
                "• Works on both desktop table AND mobile cards\n" .
                "• 'No matching data' appears if nothing matches\n\n" .
                "💡 *Try partial words — searching 'prog' will match 'In Progress'*",
        'tl' => "**Paghahanap at Pag-filter ng mga Ulat** 🔍\n\n" .
                "Sa **Reports page**, gamitin ang **search bar** sa itaas ng talahanayan:\n\n" .
                "**Maaari kang maghanap ayon sa:**\n" .
                "📅 Petsa (hal., 'Jan 2026' o 'Marso')\n" .
                "🏷️ Task/Uri (hal., 'kalsada', 'drainage')\n" .
                "📍 Lokasyon (hal., pangalan ng barangay o kalye)\n" .
                "💰 Badyet (hal., '50,000')\n" .
                "🔘 Status (hal., 'In Progress', 'Completed')\n\n" .
                "**Paano gumagana:**\n" .
                "• Ang nahanap na teksto ay **naka-highlight sa dilaw**\n" .
                "• Ang mga tugma ay lumilitaw sa itaas ng talahanayan\n" .
                "• Gumagana sa desktop table AT mobile cards\n\n" .
                "💡 *Subukan ang mga partial na salita — ang 'prog' ay makakahanap ng 'In Progress'*",
    ],

    'ai_features' => [
        'keywords' => [
            'ai','artificial intelligence','analysis','detect','classify','smart','tensorflow',
            'mobilenet','image analysis','damage detection','ai assistant','ml','machine learning',
            'artipisyal','intelihensiya','pagsusuri','pag-detect','matalino',
        ],
        'en' => "**AI Features in InfraGovServices** 🤖\n\n" .
                "The portal uses AI in two ways:\n\n" .
                "📸 **Image Analysis (TensorFlow.js):**\n" .
                "When you upload evidence photos in the Request Form, the system automatically:\n" .
                "• Detects the type of object or infrastructure in the image (MobileNet + COCO-SSD models)\n" .
                "• Classifies potential damage types\n" .
                "• Suggests an appropriate infrastructure category\n" .
                "• All analysis happens in-browser — your images are not sent to an external AI server\n\n" .
                "💬 **This Chatbot (Claude AI):**\n" .
                "• Powered by Anthropic's Claude language model\n" .
                "• Understands natural language questions in English and Filipino\n" .
                "• Can analyze screenshots you upload to explain what's on screen\n" .
                "• Provides accurate, context-aware guidance specific to the portal\n\n" .
                "⚠️ **Important:** AI recommendations are for **support only** — all final decisions are made by LGU staff.",
        'tl' => "**Mga Feature ng AI sa InfraGovServices** 🤖\n\n" .
                "Gumagamit ang portal ng AI sa dalawang paraan:\n\n" .
                "📸 **Pagsusuri ng Larawan (TensorFlow.js):**\n" .
                "Kapag nag-upload ka ng mga larawan sa Request Form, awtomatikong:\n" .
                "• Nide-detect ang uri ng bagay o imprastraktura sa larawan\n" .
                "• Ikinuklasipika ang mga posibleng uri ng pinsala\n" .
                "• Nagmumungkahi ng angkop na kategorya ng imprastraktura\n" .
                "• Lahat ng pagsusuri ay ginagawa sa browser — hindi ipinapadala ang iyong mga larawan sa external na AI server\n\n" .
                "💬 **Ang Chatbot na Ito (Claude AI):**\n" .
                "• Pinapagana ng language model ng Anthropic na si Claude\n" .
                "• Nauunawaan ang natural na wika sa English at Filipino\n" .
                "• Maaaring suriin ang mga screenshot na iyong ina-upload\n\n" .
                "⚠️ **Mahalaga:** Ang mga rekomendasyon ng AI ay para lamang sa **suporta** — ang mga pangwakas na desisyon ay ginagawa ng mga tauhan ng LGU.",
    ],

    'barangay_info' => [
        'keywords' => [
            'barangay','district','zone','area','qc','quezon city','neighborhood',
            'brgy','city boundary','location boundary','outside qc',
            'barangay','distrito','lugar','qc','quezon city','kapitbahayan','labas ng qc',
        ],
        'phrases' => [
            'my barangay','which barangay','barangay list','quezon city barangay',
            'outside quezon city','not in qc','my area','aking barangay','labas ng quezon city',
        ],
        'en' => "**Barangay & Location Info** 📍\n\n" .
                "InfraGovServices serves **all barangays of Quezon City** — the system covers the entire QC geographic area.\n\n" .
                "**When setting your location on the map:**\n" .
                "• Use the **Barangay dropdown** in the map modal to zoom to your barangay quickly\n" .
                "• Then drop the pin on the exact location of the issue\n" .
                "• The system will auto-fill the address via GPS reverse geocoding\n\n" .
                "**Location boundary:**\n" .
                "• The map is **locked to Quezon City bounds** — you cannot pin a location outside QC\n" .
                "• If your issue is outside QC (e.g., Marikina, Manila), you need to report to that city's LGU system instead\n\n" .
                "💡 *Quezon City has 142 barangays — the map dropdown lists all of them for easy navigation.*",
        'tl' => "**Impormasyon sa Barangay at Lokasyon** 📍\n\n" .
                "Ang InfraGovServices ay naglilingkod sa **lahat ng barangay ng Quezon City**.\n\n" .
                "**Kapag nagtatakda ng lokasyon sa mapa:**\n" .
                "• Gamitin ang **Barangay dropdown** sa map modal para mag-zoom sa iyong barangay\n" .
                "• Pagkatapos, mag-drop ng pin sa eksaktong lokasyon ng isyu\n" .
                "• Awtomatikong maglalagay ng address ang sistema sa pamamagitan ng reverse geocoding\n\n" .
                "**Hangganan ng lokasyon:**\n" .
                "• Ang mapa ay **nakakulong sa hangganan ng Quezon City** — hindi ka makapag-pin sa labas ng QC\n" .
                "• Kung ang iyong isyu ay nasa labas ng QC, kailangan mong mag-ulat sa sistema ng LGU ng lunsod na iyon\n\n" .
                "💡 *Ang Quezon City ay may 142 barangay — lahat ay nakalista sa dropdown ng mapa.*",
    ],

    'feedback' => [
        'keywords' => [
            'feedback','feedbacks','rate','rating','star','review','reviews','opinion','evaluate',
            'complaint','complaints','concern','concerns','suggestion','suggestions','improvement',
            'acknowledgement','acknowledge','commend','praise','comment','comments','experience',
            'satisfied','satisfaction','unsatisfied','dissatisfied','happy','unhappy','service quality',
            'leave a review','submit feedback','give feedback','write a review','share feedback',
            'mag-feedback','mag-rate','pumuri','magreklamo','magbigay ng opinyon',
            'karanasan','kasiyahan','hindi nasiyahan','magkomento','ibahagi','feedback form',
        ],
        'phrases' => [
            'how to submit feedback','how to give feedback','how to rate','how to leave a review',
            'submit my feedback','leave feedback','give a rating','star rating',
            'what is feedback','feedback form','feedback page','types of feedback',
            'reference a report','link a report','acknowledge a repair','completed report feedback',
            'paano mag-submit ng feedback','paano magbigay ng rating','mag-iwan ng review',
            'ano ang feedback','uri ng feedback','i-rate ang serbisyo',
        ],
        'en' => "**Submitting Citizen Feedback** 💬\n\n" .
                "Visit the **Feedback page** — click **Feedback** in the navigation menu.\n\n" .
                "**5 Types of Feedback you can submit:**\n\n" .
                "⚠️ **Concern** — raise a worry or issue you've noticed\n" .
                "👍 **Acknowledgement** — commend or appreciate good work done by the LGU\n" .
                "💡 **Improvement** — suggest ways to make services or infrastructure better\n" .
                "📢 **Complaint** — formally report dissatisfaction with a service or process\n" .
                "✏️ **Suggestion** — propose a new idea or recommendation\n\n" .
                "**Required fields (marked *):**\n" .
                "• **Feedback Title*** — brief summary of your feedback\n" .
                "• **Description*** — full details of your feedback\n" .
                "• **Star Rating*** — hover left/right half of a star for 0.5 increments (⭐ 0.5 – ⭐⭐⭐⭐⭐ 5.0)\n\n" .
                "**Optional fields:**\n" .
                "• 👤 Your name and contact info (name defaults to 'Citizen')\n" .
                "• 📧 Email — receive a notification update on your feedback status\n" .
                "• 🏗️ Infrastructure / Facility type (Roads, Drainage, Street Lights, etc.)\n" .
                "• 📋 Reference a completed report (#REP-XXX) — link your feedback to a specific finished repair\n" .
                "• 📍 Address / Location — use the interactive map picker or type manually\n" .
                "• 📸 Up to 5 photo attachments (JPG, PNG, WEBP, 5 MB each)\n\n" .
                "**After submitting:** You'll see a confirmation modal before the form is sent. The LGU will review your feedback.\n\n" .
                "💡 *Your draft is auto-saved — if you navigate away and return, your answers will be restored.*",
        'tl' => "**Pagsusumite ng Feedback ng Mamamayan** 💬\n\n" .
                "Pumunta sa **Feedback page** — i-click ang **Feedback** sa navigation menu.\n\n" .
                "**5 Uri ng Feedback na maaari mong isumite:**\n\n" .
                "⚠️ **Alalahanin (Concern)** — itaas ang isang isyu o pag-aalala\n" .
                "👍 **Pagkilala (Acknowledgement)** — purihin ang magandang ginagawa ng LGU\n" .
                "💡 **Pagpapabuti (Improvement)** — magmungkahi ng paraan para mapabuti ang serbisyo\n" .
                "📢 **Reklamo (Complaint)** — pormal na iulat ang hindi kasiya-siyang serbisyo\n" .
                "✏️ **Mungkahi (Suggestion)** — magpanukala ng bagong ideya o rekomendasyon\n\n" .
                "**Mga kinakailangang field (may *):**\n" .
                "• **Pamagat ng Feedback*** — maikling buod ng iyong feedback\n" .
                "• **Paglalarawan*** — kumpletong detalye ng iyong feedback\n" .
                "• **Star Rating*** — i-hover ang kaliwa/kanang kalahati ng bituin para sa 0.5 na hakbang\n\n" .
                "**Mga opsyonal na field:**\n" .
                "• 👤 Pangalan at contact info (default sa 'Citizen' kung walang pangalan)\n" .
                "• 📧 Email — makakatanggap ng notification sa status ng iyong feedback\n" .
                "• 🏗️ Uri ng Imprastraktura / Pasilidad\n" .
                "• 📋 Sangguniang natapos na ulat (#REP-XXX) — i-link ang feedback sa isang natapos na pag-aayos\n" .
                "• 📍 Address / Lokasyon — gamitin ang map picker o i-type nang mano-mano\n" .
                "• 📸 Hanggang 5 larawan (JPG, PNG, WEBP, 5 MB bawat isa)\n\n" .
                "**Pagkatapos mag-submit:** May lalabas na confirmation modal bago maipadala ang form.\n\n" .
                "💡 *Awtomatikong nase-save ang iyong draft — maibabalik ang iyong mga sagot kung lumayo ka sa pahina.*",
    ],

];

// ════════════════════════════════════════════════════════════
//  PAGE CONTEXT INFO
// ════════════════════════════════════════════════════════════

$PAGE_CONTEXT_INFO = [
    'home'    => [
        'en' => "You're on the **Home / Dashboard page** — an overview of InfraGovServices with live stats, quick access to reports and submissions, and a step-by-step guide on how the system works.",
        'tl' => "Nasa **Home / Dashboard page** ka — pangkalahatang-ideya ng InfraGovServices na may live stats, mabilis na access sa mga ulat at submission, at hakbang-hakbang na gabay kung paano gumagana ang sistema.",
    ],
    'reports' => [
        'en' => "You're on the **Reports page** — browse all submitted infrastructure maintenance reports with a search bar, status filters, and a [View] button that opens a detail modal for each record.",
        'tl' => "Nasa **Reports page** ka — mag-browse ng lahat ng isinumiteng ulat sa pamamagitan ng search bar, status filters, at [View] button na nagbubukas ng detail modal para sa bawat rekord.",
    ],
    'request' => [
        'en' => "You're on the **Request Form page** — submit a new infrastructure issue report by filling in the type, location (with interactive map), description, contact number, and uploading evidence photos.",
        'tl' => "Nasa **Request Form page** ka — mag-submit ng bagong isyu sa imprastraktura sa pamamagitan ng pagpuno ng uri, lokasyon (may interactive na mapa), paglalarawan, numero ng kontak, at pag-upload ng mga larawan.",
    ],
    'about'   => [
        'en' => "You're on the **About page** — learn about CIMMS (Community Infrastructure Maintenance Management System), its mission, vision, core values, and features for Quezon City residents.",
        'tl' => "Nasa **About page** ka — alamin ang tungkol sa CIMMS, ang misyon, pananaw, mga pangunahing halaga, at mga feature nito para sa mga residente ng Quezon City.",
    ],
    'privacy' => [
        'en' => "You're on the **Privacy Policy page** — details on how your personal data is collected, stored, and protected under RA 10173 (Data Privacy Act of 2012), and your rights as a data subject.",
        'tl' => "Nasa **Privacy Policy page** ka — mga detalye kung paano kinokolekta, iniimbak, at pinoprotektahan ang iyong personal na data sa ilalim ng RA 10173, at ang iyong mga karapatan bilang data subject.",
    ],
    'terms'   => [
        'en' => "You're on the **Terms and Conditions page** — the rules, data use agreements, AI disclaimer, and data subject rights for using InfraGovServices.",
        'tl' => "Nasa **Terms and Conditions page** ka — ang mga patakaran, kasunduan sa paggamit ng data, AI disclaimer, at mga karapatan ng data subject sa paggamit ng InfraGovServices.",
    ],
    'feedback' => [
        'en' => "You're on the **Feedback page** — submit your feedback about InfraGovServices or a completed repair. Choose from 5 feedback types (Concern, Acknowledgement, Improvement, Complaint, Suggestion), give a star rating (0.5–5.0), and optionally link to a specific completed report.",
        'tl' => "Nasa **Feedback page** ka — isumite ang iyong feedback tungkol sa InfraGovServices o isang natapos na pag-aayos. Pumili mula sa 5 uri ng feedback (Concern, Acknowledgement, Improvement, Complaint, Suggestion), magbigay ng star rating (0.5–5.0), at opsyonal na i-link sa isang partikular na natapos na ulat.",
    ],
    'general' => [
        'en' => "You're using **InfraGovServices** — the Community Infrastructure Maintenance Management System (CIMMS) for Quezon City, Philippines. Use the navigation bar to access Reports, submit a new Request, or learn more About the system.",
        'tl' => "Gumagamit ka ng **InfraGovServices** — ang Community Infrastructure Maintenance Management System (CIMMS) para sa Lungsod ng Quezon, Pilipinas. Gamitin ang navigation bar para ma-access ang Reports, mag-submit ng bagong Request, o matuto pa tungkol sa sistema.",
    ],
];

// ════════════════════════════════════════════════════════════
//  INTENT DETECTION — phrase + keyword weighted scoring
// ════════════════════════════════════════════════════════════

function detectIntent(string $message, array $kb): ?string {
    $lowerMsg  = mb_strtolower(trim($message));
    $scores    = [];

    foreach ($kb as $intent => $data) {
        $score = 0;

        // Phrase matching (higher weight — 4 pts each)
        if (!empty($data['phrases'])) {
            foreach ($data['phrases'] as $phrase) {
                if (mb_strpos($lowerMsg, mb_strtolower($phrase)) !== false) {
                    $score += 4;
                }
            }
        }

        // Keyword matching — longer keywords worth more
        foreach ($data['keywords'] as $keyword) {
            if (mb_strpos($lowerMsg, mb_strtolower($keyword)) !== false) {
                $len    = mb_strlen($keyword);
                $weight = $len >= 8 ? 3 : ($len >= 5 ? 2 : 1);
                $score += $weight;
            }
        }

        if ($score > 0) $scores[$intent] = $score;
    }

    if (empty($scores)) return null;
    arsort($scores);
    reset($scores);

    // Require minimum score of 2 to avoid weak matches
    return current($scores) >= 2 ? key($scores) : null;
}

// ════════════════════════════════════════════════════════════
//  GREETING DETECTION
// ════════════════════════════════════════════════════════════

function isGreeting(string $message): bool {
    $lower    = mb_strtolower(trim($message));
    $greetings = [
        'hello','hi','hey','good morning','good afternoon','good evening','howdy','greetings',
        'kumusta','kamusta','magandang','musta','yo','sup','hola','mabuhay','good day',
        'hi there','hey there','hello there',
    ];
    foreach ($greetings as $g) {
        if (mb_strpos($lower, $g) === 0 || $lower === $g) return true;
    }
    // Very short message (≤ 6 chars) is likely a greeting
    return mb_strlen($lower) <= 6 && mb_strlen($lower) > 0;
}

// ════════════════════════════════════════════════════════════
//  GREETING RESPONSE
// ════════════════════════════════════════════════════════════

function getGreetingResponse(string $context, bool $isTagalog, array $pageContextInfo): string {
    $hour = (int) date('H');
    if ($hour < 12)     $timeGreet = $isTagalog ? 'Magandang umaga' : 'Good morning';
    elseif ($hour < 17) $timeGreet = $isTagalog ? 'Magandang hapon' : 'Good afternoon';
    else                $timeGreet = $isTagalog ? 'Magandang gabi' : 'Good evening';

    $pageInfo = $pageContextInfo[$context] ?? $pageContextInfo['general'];
    $pageDesc = $pageInfo[$isTagalog ? 'tl' : 'en'];

    if ($isTagalog) {
        return "{$timeGreet}! 👋 Ako ang iyong **InfraGovServices AI Assistant**.\n\n{$pageDesc}\n\n" .
               "**Narito ako para tumulong sa:**\n" .
               "• 📋 Pag-uulat ng isyu sa imprastraktura (kalsada, drainage, ilaw, atbp.)\n" .
               "• 📍 Pagsubaybay ng mga ulat at pag-unawa sa mga status\n" .
               "• 💬 Pagsusumite ng feedback, rating, at mungkahi\n" .
               "• 🗺️ Paggamit ng mapa at pagtakda ng lokasyon\n" .
               "• 🔒 Mga tanong sa Privacy Policy at Terms\n" .
               "• 📸 Pagsusuri ng mga screenshot na iyong ia-upload\n" .
               "• 🔧 Pag-navigate at paggamit ng sistema\n\n" .
               "Paano kita matutulungan ngayon? 😊";
    } else {
        return "{$timeGreet}! 👋 I'm your **InfraGovServices AI Assistant**.\n\n{$pageDesc}\n\n" .
               "**I'm here to help you with:**\n" .
               "• 📋 Reporting infrastructure issues (roads, drainage, streetlights, etc.)\n" .
               "• 📍 Tracking reports and understanding status updates\n" .
               "• 💬 Submitting feedback, ratings, and suggestions\n" .
               "• 🗺️ Using the map and setting your location\n" .
               "• 🔒 Privacy Policy & Terms of Service questions\n" .
               "• 📸 Analyzing screenshots you upload\n" .
               "• 🔧 Navigating and using the system\n\n" .
               "How can I assist you today? 😊";
    }
}

// ════════════════════════════════════════════════════════════
//  FALLBACK RESPONSE — context-aware with topic hints
// ════════════════════════════════════════════════════════════

function getFallbackResponse(string $context, bool $isTagalog, array $pageContextInfo, string $recentTopic = ''): string {
    $pageInfo = $pageContextInfo[$context] ?? $pageContextInfo['general'];
    $pageDesc = $pageInfo[$isTagalog ? 'tl' : 'en'];

    $topicHint = '';
    if ($recentTopic) {
        $topicHint = $isTagalog
            ? "\n\n💭 *Mukhang pinag-uusapan natin ang **{$recentTopic}** — nais mo pa bang malaman ang tungkol diyan?*"
            : "\n\n💭 *It looks like we were discussing **{$recentTopic}** — would you like to know more about that?*";
    }

    if ($isTagalog) {
        return "Pasensya na, hindi ko ganap na naintindihan ang iyong tanong. 🙏\n\n{$pageDesc}\n\n" .
               "**Maaari akong tumulong sa mga sumusunod:**\n" .
               "• 📋 Paano mag-ulat ng isyu sa imprastraktura\n" .
               "• 📍 Pagsubaybay ng status ng iyong ulat\n" .
               "• 💬 Paano mag-submit ng feedback, rating, at mungkahi\n" .
               "• 🗺️ Pagtakda ng lokasyon sa mapa\n" .
               "• 📸 Pag-upload ng mga larawan bilang ebidensya\n" .
               "• 🔒 Privacy Policy at Terms and Conditions\n" .
               "• 🔧 Pag-navigate at paggamit ng sistema\n" .
               "• 📞 Impormasyon sa pakikipag-ugnayan at emergency\n" .
               "• 🏷️ Mga uri ng imprastraktura na maaaring iulat\n" .
               $topicHint .
               "\n\nSubukan mong i-rephrase ang iyong tanong, o piliin ang isa sa mga paksa sa itaas! 😊";
    } else {
        return "I'm not sure I fully understood your question. 🙏\n\n{$pageDesc}\n\n" .
               "**I can help you with:**\n" .
               "• 📋 How to report an infrastructure issue\n" .
               "• 📍 Tracking your request status\n" .
               "• 💬 How to submit feedback, ratings, and suggestions\n" .
               "• 🗺️ Setting your location on the map\n" .
               "• 📸 Uploading photos as evidence\n" .
               "• 🔒 Privacy Policy & Terms and Conditions\n" .
               "• 🔧 How to navigate and use the system\n" .
               "• 📞 Contact information and emergency hotlines\n" .
               "• 🏷️ Types of infrastructure issues you can report\n" .
               $topicHint .
               "\n\nTry rephrasing your question, or pick one of the topics above! 😊";
    }
}

// ════════════════════════════════════════════════════════════
//  CLAUDE API — TEXT  (with full conversation context)
// ════════════════════════════════════════════════════════════

function callClaudeText(
    string $apiKey,
    string $userMessage,
    string $context,
    string $lang,
    array  $history,
    array  $pageStructure,
    array  $pageContextInfo,
    string $conversationTopic = ''
): ?string {

    $pageStruct = $pageStructure[$context]  ?? $pageStructure['general'];
    $pageCtx    = $pageContextInfo[$context][$lang === 'tl' ? 'tl' : 'en'] ?? '';
    $topicHint  = $conversationTopic ? "\n- Ongoing conversation topic: {$conversationTopic}" : '';

    $systemPrompt = "You are **CIMMS Assistant** — the AI chatbot for InfraGovServices, the Community Infrastructure Maintenance Management System (CIMMS) of Quezon City, Philippines.

## Persona
- Friendly, professional, and concise
- Knowledgeable about every aspect of the InfraGovServices portal
- Always responds in the user's selected language
- Uses emojis, bullet points, and numbered steps to improve readability
- Never invents information — if unsure, say so and suggest contacting support

## Language
- Respond ONLY in: " . ($lang === 'tl' ? '**Filipino/Tagalog**. Mix in some English technical terms naturally where appropriate (e.g., button names, field labels).' : '**English**. Keep it clear and simple.') . "

## Current session context
- Active page: **{$context}**
- Page description: {$pageCtx}{$topicHint}

## Page structure reference (use to cite exact UI element names)
{$pageStruct}

## Scope — ONLY answer questions about:
1. InfraGovServices portal features (reporting, tracking, forms, navigation, search, filters, feedback)
2. Infrastructure types in Quezon City (roads, drainage, streetlights, sidewalks, water, electrical, public facilities)
3. Report status meanings: Pending → In Progress → Completed / Delayed
4. Evidence photo requirements and tips
5. Map usage, GPS location, barangay selection
6. Urgency levels and when to call emergency hotlines
7. Privacy Policy (RA 10173) and Terms & Conditions
8. AI features (TensorFlow image analysis, this chatbot)
9. Contact information: admin@infragovservices.com | dpo@infragovservices.com | (02) 8988-4242
10. How to navigate the portal, dark mode, language switching
11. Feedback form: 5 feedback types (Concern, Acknowledgement, Improvement, Complaint, Suggestion), star ratings (0.5–5.0 with half-star support), referencing completed reports (#REP-XXX), and the full feedback submission process

## Rules
1. If the user asks something outside scope (e.g., weather, unrelated topics), politely redirect: 'I'm specialized in InfraGovServices — let me know if you have any portal-related questions!'
2. Always cite **exact button names, field labels, and section headings** from the page structure reference above
3. For multi-step instructions, use numbered lists
4. Keep responses under 350 words unless a detailed technical explanation is genuinely needed
5. If the user's question is a follow-up, acknowledge context: 'Continuing from our earlier discussion about [topic]...'
6. End with a brief offer to help further or ask if the answer was clear — but keep this natural, not formulaic";

    // Build messages from history (last 8 turns for richer context)
    $messages = [];
    foreach (array_slice($history, -8) as $h) {
        if (!empty($h['text']) && !empty($h['type'])) {
            $messages[] = [
                'role'    => ($h['type'] === 'user') ? 'user' : 'assistant',
                'content' => mb_substr($h['text'], 0, 800), // cap each turn
            ];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return claudeRequest($apiKey, $systemPrompt, $messages, 700);
}

// ════════════════════════════════════════════════════════════
//  CLAUDE API — VISION  (screenshot / image analysis)
// ════════════════════════════════════════════════════════════

function callClaudeVision(
    string $apiKey,
    string $userMessage,
    string $context,
    string $lang,
    array  $history,
    string $imageBase64,
    array  $pageStructure,
    array  $pageContextInfo
): ?string {

    $pageStruct = $pageStructure[$context] ?? $pageStructure['general'];
    $pageCtx    = $pageContextInfo[$context][$lang === 'tl' ? 'tl' : 'en'] ?? '';

    $systemPrompt = "You are **CIMMS Assistant** — the AI chatbot for InfraGovServices (CIMMS) of Quezon City, Philippines.

## Your task for this message
The user has uploaded an image. Analyze it and respond helpfully.

**If it is a screenshot of the InfraGovServices portal:**
- Identify the exact page/section/element shown (use the page structure reference below)
- Start with: '📸 I can see [specific page/element]...'
- Describe what you see, then provide actionable guidance
- Mention specific button names, field labels, or status badges visible

**If it is a photo of an infrastructure issue (damage, road, drainage, etc.):**
- Analyze the visible damage or issue
- Identify the likely infrastructure type (road, drainage, electrical, etc.)
- Suggest the appropriate category in the Request Form
- Offer guidance on what to include in the Issue Description field
- Remind them to use this photo as evidence when submitting the report

**If it is unclear what the image shows:**
- Describe what you can see
- Ask the user to clarify what they need help with

## Language: " . ($lang === 'tl' ? 'Filipino/Tagalog' : 'English') . "

## Current page context
- Detected page: {$context}
- Context: {$pageCtx}

## Page structure reference
{$pageStruct}

## Rules
1. Be specific — mention actual visible text, colors, buttons, and UI components
2. Never guess — if you can't determine what something is, say so
3. Keep response under 300 words unless a detailed walkthrough is needed
4. Always end with an offer to help further";

    // Build history
    $messages = [];
    foreach (array_slice($history, -4) as $h) {
        if (!empty($h['text']) && !empty($h['type'])) {
            $messages[] = [
                'role'    => ($h['type'] === 'user') ? 'user' : 'assistant',
                'content' => mb_substr($h['text'], 0, 600),
            ];
        }
    }

    // Detect image media type
    $mediaType = 'image/jpeg';
    if (str_starts_with($imageBase64, 'data:image/png'))  $mediaType = 'image/png';
    if (str_starts_with($imageBase64, 'data:image/gif'))  $mediaType = 'image/gif';
    if (str_starts_with($imageBase64, 'data:image/webp')) $mediaType = 'image/webp';

    // Strip data URI prefix
    $base64Data = preg_replace('/^data:image\/[a-z]+;base64,/', '', $imageBase64);

    $userContent = [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64Data]],
    ];

    if (!empty($userMessage) && !isGenericQuestion($userMessage)) {
        $userContent[] = ['type' => 'text', 'text' => $userMessage];
    } else {
        $userContent[] = ['type' => 'text', 'text' => $lang === 'tl'
            ? 'Pakisuri ang larawang ito at ipaliwanag kung ano ang nakikita mo at kung paano ito naaayon sa InfraGovServices portal.'
            : 'Please analyze this image and explain what you see, relating it to the InfraGovServices portal where relevant.'];
    }

    $messages[] = ['role' => 'user', 'content' => $userContent];

    return claudeRequest($apiKey, $systemPrompt, $messages, 600);
}

// ════════════════════════════════════════════════════════════
//  CLAUDE HTTP REQUEST
// ════════════════════════════════════════════════════════════

function claudeRequest(string $apiKey, string $systemPrompt, array $messages, int $maxTokens = 700): ?string {
    $payload = [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => $maxTokens,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $decoded = json_decode($response, true);
        return $decoded['content'][0]['text'] ?? null;
    }
    return null;
}

// ════════════════════════════════════════════════════════════
//  FALLBACK IMAGE ANALYSIS (no Claude API key)
// ════════════════════════════════════════════════════════════

function fallbackImageAnalysis(string $context, bool $isTagalog, array $pageStructure, string $userMessage): string {

    $responses = [
        'home' => [
            'en' => "📸 I can see a screenshot of the **Home page** of InfraGovServices.\n\n" .
                    "**🔝 Navigation Bar (top):**\n" .
                    "• Links: Home | Reports | Requests | About | Log in\n" .
                    "• Right side: 🌐 Language toggle (EN/FIL) | 🌙 Dark mode | Digital clock\n\n" .
                    "**📊 Stats Section (3 cards):**\n" .
                    "• 🛠️ Completed Repairs | ⏳ On-Going Repairs | 📍 Pending Requests\n\n" .
                    "**🚀 Hero Section:**\n" .
                    "• 'Welcome to InfraGovServices' headline\n" .
                    "• [Submit a Report] and [Learn More] buttons\n\n" .
                    "**📋 How It Works (4 steps):**\n" .
                    "Report → Review → Maintenance Scheduled → Issue Resolved\n\n" .
                    "**Recent Maintenance Activity** — latest 10 records at the bottom\n\n" .
                    "💡 Click **Requests** to submit a new report, or **Reports** to browse existing ones.",
            'tl' => "📸 Nakikita ko ang screenshot ng **Home page** ng InfraGovServices.\n\n" .
                    "**🔝 Navigation Bar (sa itaas):**\n" .
                    "• Mga link: Home | Reports | Requests | About | Log in\n" .
                    "• Kanan: 🌐 Language toggle | 🌙 Dark mode | Digital na orasan\n\n" .
                    "**📊 Stats Section (3 cards):**\n" .
                    "• 🛠️ Natapos na Pag-aayos | ⏳ Kasalukuyang Pag-aayos | 📍 Mga Nakabinbing Kahilingan\n\n" .
                    "**🚀 Hero Section:**\n" .
                    "• 'Welcome sa InfraGovServices' headline\n" .
                    "• [Mag-ulat ng Isyu] at [Alamin Pa] buttons\n\n" .
                    "💡 I-click ang **Requests** para mag-submit ng ulat, o **Reports** para mag-browse.",
        ],
        'reports' => [
            'en' => "📸 I can see a screenshot of the **Reports page** of InfraGovServices.\n\n" .
                    "**📊 Stats Row (top):**\n" .
                    "• 🛠️ Repairs | ⏳ On-Going | 📍 Pending\n\n" .
                    "**🔍 Search Bar:**\n" .
                    "• Search by Date, Type, Location, Budget, or Status\n" .
                    "• Matching text is highlighted in yellow\n\n" .
                    "**📋 Reports Table (desktop):**\n" .
                    "Columns: Sched # | Date | Type | Location | Budget | Status | Action\n\n" .
                    "**Status badge colors:**\n" .
                    "• 🟡 Pending | 🔵 In Progress | 🟢 Completed | 🔴 Delayed\n\n" .
                    "**[View] button** — opens the Schedule Detail Modal with full record details\n\n" .
                    "💡 Use the search bar to find specific reports — partial words work too (e.g., 'prog' finds 'In Progress').",
            'tl' => "📸 Nakikita ko ang screenshot ng **Reports page** ng InfraGovServices.\n\n" .
                    "**📊 Stats Row (sa itaas):**\n" .
                    "• 🛠️ Mga Pag-aayos | ⏳ Kasalukuyan | 📍 Nakabinbin\n\n" .
                    "**🔍 Search Bar:**\n" .
                    "• Maghanap ayon sa Petsa, Uri, Lokasyon, Badyet, o Katayuan\n" .
                    "• Ang nahanap na teksto ay naka-highlight sa dilaw\n\n" .
                    "**📋 Talahanayan ng mga Ulat:**\n" .
                    "Kolum: Sched # | Petsa | Uri | Lokasyon | Badyet | Katayuan | Aksyon\n\n" .
                    "**[View] button** — nagbubukas ng Schedule Detail Modal na may kumpletong detalye\n\n" .
                    "💡 Gamitin ang search bar para mahanap ang mga partikular na ulat.",
        ],
        'request' => [
            'en' => "📸 I can see the **Request / Maintenance Request Form** page.\n\n" .
                    "**Form fields guide:**\n\n" .
                    "1️⃣ **Infrastructure Type *** — dropdown (Roads | Street Lights | Drainage | Public Facilities | Water Supply | Electrical | Other)\n" .
                    "2️⃣ **Location *** — click to open the interactive map; drop a pin or use 📍 GPS; select barangay to zoom\n" .
                    "3️⃣ **Name** (optional)\n" .
                    "4️⃣ **Contact Number *** — 09XX-XXX-XXXX (11 digits starting with 09)\n" .
                    "5️⃣ **Issue Description *** — describe the problem in detail\n" .
                    "6️⃣ **Upload Images** — up to 4 photos; tap 📷 to capture on mobile\n" .
                    "7️⃣ **Consent checkbox** — required\n" .
                    "8️⃣ **[Submit Request]** button\n\n" .
                    "⭐ Fields marked * are required. Need help with a specific field?",
            'tl' => "📸 Nakikita ko ang **Request Form** page.\n\n" .
                    "**Gabay sa mga field:**\n\n" .
                    "1️⃣ **Uri ng Imprastraktura *** — dropdown\n" .
                    "2️⃣ **Lokasyon *** — i-click para buksan ang mapa\n" .
                    "3️⃣ **Pangalan** (opsyonal)\n" .
                    "4️⃣ **Numero ng Kontak *** — 09XX-XXX-XXXX\n" .
                    "5️⃣ **Paglalarawan ng Isyu *** — ilarawan nang detalyado\n" .
                    "6️⃣ **Mag-upload ng Larawan** — hanggang 4, i-tap ang 📷 sa mobile\n" .
                    "7️⃣ **Consent checkbox** — kailangan\n" .
                    "8️⃣ **[Isumite ang Kahilingan]** button\n\n" .
                    "⭐ Ang mga field na may * ay kailangan.",
        ],
        'privacy' => [
            'en' => "📸 I can see the **Privacy Policy page** of InfraGovServices.\n\n" .
                    "**Summary of sections:**\n\n" .
                    "📋 **Data Collection** — names, credentials, location data, activity logs, images\n" .
                    "⚖️ **Lawful Processing** — data collected only for declared, legitimate purposes\n" .
                    "🔐 **Data Security** — encryption in transit and at rest\n" .
                    "🛡️ **Your Rights (RA 10173):** Informed | Access | Correction | Object | Erasure\n" .
                    "✅ **User Consent** — what you agree to by using the system\n" .
                    "📞 **Contact DPO:** dpo@infragovservices.com | (02) 8988-4242\n" .
                    "📅 Last Updated: February 2026\n\n" .
                    "Do you have a specific question about a section of the Privacy Policy?",
            'tl' => "📸 Nakikita ko ang **Privacy Policy page** ng InfraGovServices.\n\n" .
                    "**Buod ng mga seksyon:**\n\n" .
                    "📋 **Koleksyon ng Data** — mga pangalan, kredensyal, lokasyon, logs, mga larawan\n" .
                    "🔐 **Seguridad ng Data** — encryption sa transmission at storage\n" .
                    "🛡️ **Iyong Karapatan (RA 10173):** Malaman | Ma-access | Baguhin | Tumutol | Burahin\n" .
                    "📞 **DPO:** dpo@infragovservices.com | (02) 8988-4242\n\n" .
                    "May partikular ka bang tanong tungkol sa Privacy Policy?",
        ],
        'terms' => [
            'en' => "📸 I can see the **Terms and Conditions page** of InfraGovServices.\n\n" .
                    "**Summary of sections:**\n\n" .
                    "📁 **Information Collection** — types of data collected\n" .
                    "🎯 **Purpose** — operations, coordination, AI support, research\n" .
                    "🔐 **Processing & Storage** — secure storage, retained only as needed\n" .
                    "🚫 **Data Sharing** — not shared without consent (except when required by law)\n" .
                    "⚖️ **Data Subject Rights** — Informed · Access · Correction · Object · Erasure\n" .
                    "🤖 **AI Disclaimer** — AI recommendations are for decision support only, not replacing official authority\n" .
                    "📧 **Contact:** admin@infragovservices.com | dpo@infragovservices.com\n" .
                    "📅 Last Updated: February 2026\n\n" .
                    "Which section would you like me to explain in more detail?",
            'tl' => "📸 Nakikita ko ang **Terms and Conditions page** ng InfraGovServices.\n\n" .
                    "**Buod ng mga seksyon:**\n\n" .
                    "📁 **Koleksyon ng Impormasyon** — mga uri ng data\n" .
                    "🎯 **Layunin** — operasyon, koordinasyon, AI support, pananaliksik\n" .
                    "🚫 **Pagbabahagi ng Data** — hindi ibinabahagi nang walang pahintulot\n" .
                    "⚖️ **Mga Karapatan:** Malaman · Ma-access · Baguhin · Tumutol · Burahin\n" .
                    "🤖 **AI Disclaimer** — para sa suporta sa desisyon lamang\n\n" .
                    "Aling seksyon ang nais mong ipaliwanag nang mas detalyado?",
        ],
        'about' => [
            'en' => "📸 I can see the **About page** of InfraGovServices.\n\n" .
                    "**Page covers:**\n\n" .
                    "🏛️ **Transforming Infrastructure Management** — intro to CIMMS for Quezon City\n" .
                    "✨ **Highlights (4 cards):** Easy Reporting | GPS Tracking | Real-Time Updates | Transparent Tracking\n" .
                    "🎯 **Our Purpose** — 4 goals: efficiency, communication, faster response, transparency\n" .
                    "📦 **What CIMMS Offers** — 5 features: reporting, tracking, coordination, secure access, dashboards\n" .
                    "👥 **For QC Citizens** — exclusively for Quezon City residents\n" .
                    "🌟 **Vision & Mission** — service excellence statements\n" .
                    "💎 **Core Values:** Efficiency | Transparency | Community First | Security\n\n" .
                    "Is there something specific about the system you'd like to know more about?",
            'tl' => "📸 Nakikita ko ang **About page** ng InfraGovServices.\n\n" .
                    "**Saklaw ng pahina:**\n\n" .
                    "🏛️ **Pagbabago ng Pamamahala ng Imprastraktura** — intro sa CIMMS\n" .
                    "✨ **Mga Highlight (4 cards):** Madaling Pag-uulat | GPS | Real-Time | Transparent\n" .
                    "🎯 **Aming Layunin** — 4 layunin\n" .
                    "🌟 **Pananaw at Misyon** — mga pahayag ng kahusayan\n" .
                    "💎 **Mga Pangunahing Halaga:** Kahusayan | Transparency | Komunidad Muna | Seguridad\n\n" .
                    "May nais ka pa bang malaman tungkol sa sistema?",
        ],
        'feedback' => [
            'en' => "📸 I can see the **Feedback page** of InfraGovServices.\n\n" .
                    "**Form sections guide:**\n\n" .
                    "👤 **Your Information** (all optional):\n" .
                    "• Full Name, Contact Number (09XX-XXX-XXXX), Email\n" .
                    "• If you provide an email, the LGU will notify you on your feedback status\n\n" .
                    "💬 **Feedback Details:**\n" .
                    "• **Type** — ⚠️ Concern | 👍 Acknowledgement | 💡 Improvement | 📢 Complaint | ✏️ Suggestion\n" .
                    "• **Feedback Title*** — required brief summary\n" .
                    "• **Description*** — required full details\n" .
                    "• **Star Rating*** — hover left/right half of a star for 0.5 values (0.5–5.0)\n\n" .
                    "🏗️ **Infrastructure & Location** (optional):\n" .
                    "• Infrastructure type dropdown (Roads, Drainage, Street Lights, etc.)\n" .
                    "• Reference a completed report (#REP-XXX) — link to a finished repair\n" .
                    "• Address / Location with interactive map picker\n\n" .
                    "📸 **Photo Evidence** (optional, up to 5 photos, 5 MB each)\n\n" .
                    "**[Submit Feedback]** → opens a confirmation modal before sending.\n\n" .
                    "Would you like help filling in a specific section?",
            'tl' => "📸 Nakikita ko ang **Feedback page** ng InfraGovServices.\n\n" .
                    "**Gabay sa mga seksyon ng form:**\n\n" .
                    "👤 **Iyong Impormasyon** (lahat ay opsyonal):\n" .
                    "• Buong Pangalan, Numero ng Kontak, Email\n" .
                    "• Kung magbibigay ka ng email, aabisuhan ka ng LGU sa status ng iyong feedback\n\n" .
                    "💬 **Mga Detalye ng Feedback:**\n" .
                    "• **Uri** — ⚠️ Concern | 👍 Acknowledgement | 💡 Improvement | 📢 Complaint | ✏️ Suggestion\n" .
                    "• **Pamagat ng Feedback*** — kinakailangan\n" .
                    "• **Paglalarawan*** — kinakailangan\n" .
                    "• **Star Rating*** — i-hover ang kaliwa/kanang kalahati ng bituin para sa 0.5 na hakbang\n\n" .
                    "🏗️ **Imprastraktura at Lokasyon** (opsyonal):\n" .
                    "• Uri ng imprastraktura (Kalsada, Drainage, Mga Ilaw, atbp.)\n" .
                    "• Sangguniang natapos na ulat (#REP-XXX)\n" .
                    "• Address / Lokasyon gamit ang interactive na mapa\n\n" .
                    "📸 **Mga Larawan bilang Ebidensya** (opsyonal, hanggang 5, 5 MB bawat isa)\n\n" .
                    "**[Isumite ang Feedback]** → lalabas ang confirmation modal bago maipadala.\n\n" .
                    "Gusto mo bang tulungan kita sa isang partikular na seksyon?",
        ],
        'general' => [
            'en' => "📸 I can see a screenshot from the **InfraGovServices portal**.\n\n" .
                    "To help you best, here's what I can assist with:\n\n" .
                    "🔍 **If you see the Navigation Bar** — I can explain any menu item or button\n" .
                    "📋 **If you see a form** — I can guide you through filling it out correctly\n" .
                    "📊 **If you see reports/data** — I can explain what each field and status means\n" .
                    "❌ **If you see an error** — describe the error message and I'll help troubleshoot\n" .
                    "🗺️ **If you see the map** — I can help with location pinning and barangay selection\n\n" .
                    "Please describe what you're seeing or what you're trying to do, and I'll give specific guidance!",
            'tl' => "📸 Nakikita ko ang screenshot mula sa **portal ng InfraGovServices**.\n\n" .
                    "Para mas matulungan kita:\n\n" .
                    "🔍 **Kung nakikita mo ang Navigation Bar** — maaari kong ipaliwanag ang anumang item\n" .
                    "📋 **Kung nakikita mo ang isang form** — maaari kitang gabayan sa tamang pagpuno\n" .
                    "📊 **Kung nakikita mo ang mga ulat** — maaari kong ipaliwanag ang bawat field at status\n" .
                    "❌ **Kung nakikita mo ang isang error** — ilarawan at tutulungan kita\n" .
                    "🗺️ **Kung nakikita mo ang mapa** — maaari akong tumulong sa paglalagay ng pin\n\n" .
                    "Mangyaring ilarawan kung ano ang nakikita mo o sinusubukan mong gawin!",
        ],
    ];

    $base = ($responses[$context] ?? $responses['general'])[$isTagalog ? 'tl' : 'en'];

    // Bridge specific (non-generic) user questions to the image analysis response
    if (!empty($userMessage) && !isGenericQuestion($userMessage)) {
        $questionBridge = $isTagalog
            ? "\n\n---\n💬 **Tungkol sa iyong tanong:** \"" . htmlspecialchars($userMessage) . "\"\n\nMaaari kang mag-type ng mas detalyadong mensahe para masagot kita nang mas tumpak."
            : "\n\n---\n💬 **Regarding your question:** \"" . htmlspecialchars($userMessage) . "\"\n\nFeel free to type more details so I can give you a more precise answer.";
        $base .= $questionBridge;
    }

    return $base;
}

// ════════════════════════════════════════════════════════════
//  MAIN ROUTING LOGIC
// ════════════════════════════════════════════════════════════

// Extract conversation topic from history (used in Claude prompt + fallback)
$conversationTopic = extractConversationTopic($history);

// ── 1. IMAGE PATH ─────────────────────────────────────────────
if ($imageBase64 || !empty($images)) {
    $primaryImage = $imageBase64 ?: ($images[0] ?? null);

    if ($USE_CLAUDE_API && $primaryImage) {
        $claudeResp = callClaudeVision(
            $CLAUDE_API_KEY, $userMessage, $context, $lang,
            $history, $primaryImage, $PAGE_STRUCTURE, $PAGE_CONTEXT_INFO
        );
        if ($claudeResp) respond($claudeResp);
    }

    // Fallback
    respond(fallbackImageAnalysis($context, $isTagalog, $PAGE_STRUCTURE, $userMessage));
}

// ── 2. EMPTY MESSAGE ──────────────────────────────────────────
if (empty($userMessage)) {
    respond(bi(
        "Please type a message so I can help you! 😊",
        "Mangyaring mag-type ng mensahe para matulungan kita! 😊",
        $isTagalog
    ));
}

// ── 3. GREETING ───────────────────────────────────────────────
if (isGreeting($userMessage)) {
    respond(getGreetingResponse($context, $isTagalog, $PAGE_CONTEXT_INFO));
}

// ── 4. CLAUDE TEXT (if API key available) ─────────────────────
if ($USE_CLAUDE_API) {
    $claudeResp = callClaudeText(
        $CLAUDE_API_KEY, $userMessage, $context, $lang,
        $history, $PAGE_STRUCTURE, $PAGE_CONTEXT_INFO, $conversationTopic
    );
    if ($claudeResp) respond($claudeResp);
}

// ── 5. LOCAL INTENT DETECTION ─────────────────────────────────
$intent = detectIntent($userMessage, $KB);

// For follow-up messages without a detected intent, try to match against the ongoing topic
if (!$intent && isFollowUp($userMessage) && $conversationTopic) {
    // Map topic string back to a rough intent to surface related KB entry
    $topicIntentMap = [
        'reporting / submission'  => 'reporting',
        'tracking / status'       => 'tracking',
        'evidence / photos'       => 'evidence_photos',
        'location / map'          => 'location_map',
        'privacy policy'          => 'privacy',
        'terms and conditions'    => 'terms',
        'account / login'         => 'account',
        'navigation / how to use' => 'navigation',
        'infrastructure types'    => 'infrastructure_types',
        'maintenance schedules'   => 'maintenance_schedule',
        'contact / support'       => 'contact',
        'feedback / rating'       => 'feedback',
    ];
    $intent = $topicIntentMap[$conversationTopic] ?? null;
}

if ($intent && isset($KB[$intent])) {
    $kbText = $KB[$intent][$isTagalog ? 'tl' : 'en'];
    // Prepend page context for navigation intent
    if ($intent === 'navigation') {
        $pageInfo = $PAGE_CONTEXT_INFO[$context] ?? null;
        if ($pageInfo) {
            $kbText = "📍 " . $pageInfo[$isTagalog ? 'tl' : 'en'] . "\n\n" . $kbText;
        }
    }
    respond($kbText);
}

// ── 6. FALLBACK ────────────────────────────────────────────────
respond(getFallbackResponse($context, $isTagalog, $PAGE_CONTEXT_INFO, $conversationTopic));