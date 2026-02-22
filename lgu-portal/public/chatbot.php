<?php
/**
 * InfraGovServices Chatbot Backend v3.1
 * ─────────────────────────────────────────────────────────────
 * Fixes from v3:
 *  • Dual-response bug — image analysis no longer also runs intent matching
 *  • Generic question detection ("what is this", "ano ito") skips intent lookup
 *  • Claude vision system prompt now includes full page structure knowledge
 *  • Improved fallback image analysis ties into actual page HTML structure
 *  • Single, clean response for every code path
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ─── Optional: Claude API key from environment ─────────────
$CLAUDE_API_KEY = getenv('CLAUDE_API_KEY') ?: (defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '');
$USE_CLAUDE_API = !empty($CLAUDE_API_KEY);

// ─── Parse Request ──────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['response' => 'Invalid request.', 'aiCardHtml' => null]);
    exit;
}

$userMessage = trim($data['message'] ?? '');
$context     = strtolower(trim($data['context']  ?? 'general'));
$lang        = strtolower(trim($data['lang']      ?? 'en'));
$history     = is_array($data['history'] ?? null) ? $data['history'] : [];
$imageBase64 = $data['image']    ?? null;
$aiResult    = $data['aiResult'] ?? null;
$images      = is_array($data['images'] ?? null) ? $data['images'] : [];

$isTagalog = ($lang === 'tl');

// Sanitize
$userMessage = mb_substr(strip_tags($userMessage), 0, 1000);

// ─── RESPONSE BUILDER ────────────────────────────────────────
function respond(string $text, ?string $aiCardHtml = null): void {
    echo json_encode([
        'response'   => $text,
        'aiCardHtml' => $aiCardHtml,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── BILINGUAL HELPER ────────────────────────────────────────
function bi(string $en, string $tl, bool $isTagalog): string {
    return $isTagalog ? $tl : $en;
}

// ─── GENERIC QUESTION DETECTOR ───────────────────────────────
// Returns true for vague questions that should NOT trigger intent lookup
// when they accompany an image (e.g. "what is this", "ano ito", "explain")
function isGenericQuestion(string $msg): bool {
    $lower = mb_strtolower(trim($msg));
    $genericPhrases = [
        'what is this', 'what\'s this', 'what is it', 'what is that',
        'what am i looking at', 'explain this', 'explain', 'describe this',
        'can you explain', 'tell me about this', 'what does this show',
        'what does this mean', 'what do i see', 'whats this', 'what\'s here',
        // Tagalog
        'ano ito', 'ano ba ito', 'ano ang nakikita ko', 'ipaliwanag',
        'ipaliwanag mo', 'ano ang ibig sabihin', 'narito', 'ano na ito',
        'paano ito', 'ano ang pagpapaliwanag', 'i-explain', 'ano dito',
        // Generic auto-generated messages (from widget when no text typed)
        'i submitted screenshots of the website for analysis.',
        'nagsumite ako ng mga screenshot ng website para sa pagsusuri.',
        'nagsumite ako ng screenshot ng website para sa pagsusuri.',
    ];
    foreach ($genericPhrases as $phrase) {
        if (mb_strpos($lower, mb_strtolower($phrase)) !== false) {
            return true;
        }
    }
    // Very short messages alongside an image are treated as generic
    if (mb_strlen($lower) <= 15) {
        return true;
    }
    return false;
}

// ════════════════════════════════════════════════════════════
//  PAGE STRUCTURE KNOWLEDGE
//  Detailed descriptions of each page's actual HTML structure.
//  This is what gets sent to Claude so it can give accurate guidance.
// ════════════════════════════════════════════════════════════

$PAGE_STRUCTURE = [

    'home' => "
## Home Page (citizencimm) — Structure

**Navigation Bar (top):**
- Logo: 'InfraGovServices' on the left
- Nav links: Home | Reports | Requests | About | Log in
- Right side: 🌐 language toggle (EN/FIL) | 🌙 dark mode toggle

**Hero Section:**
- Headline: 'Welcome to InfraGovServices'
- Subtitle: 'Community Infrastructure Maintenance Management System'
- Two CTA buttons: [Submit a Report] [Learn More]

**Stats Bar (3 cards):**
- Completed Repairs (green badge)
- Ongoing Repairs (orange badge)
- Pending Requests (yellow badge)

**Trust Strip (4 icons):**
Secure & Private | Fast Response | Verified Reports | Service Excellence

**How It Works (4 steps):**
1. Report the Issue → 2. Review & Verification → 3. Maintenance Scheduled → 4. Issue Resolved

**Our Services (5 feature cards):**
Submit Requests | Track Maintenance | Location-Based Reporting | Real-Time Updates | Community Engagement
Each card has a CTA link.

**Recent Maintenance Activity section** — table/list of latest reports

**About section** — brief system overview with highlights

**Footer:** Quick Links | Resources | Legal | Contact info
",

    'reports' => "
## Reports Page (citizenreports) — Structure

**Page Header:** 'Recent Maintenance Reports'

**Stats Row (3 cards):**
- Repairs (total completed) | On-Going Repairs | Pending

**Search & Filter Bar:**
- Search input: placeholder 'Search by Date, Type, Location, Budget, or Status…'
- Filter dropdown buttons (Status, Type, Date)

**Reports Table (desktop):**
Columns: Sched # | Date | Type | Location | Budget | Status | Action
- Status badges: Pending (yellow) | In Progress (orange) | Completed (green) | Delayed (red)
- [View] button per row opens a detail modal

**Reports Cards (mobile):**
Each card shows: Schedule ID | Category | Task | Location | Start Date | Budget | Status

**Empty State:** 'No maintenance schedules available' or 'No matching data'
",

    'request' => "
## Request / Submit Form Page (citizenrepform) — Structure

**Page title:** 'Maintenance Request'

**Form fields (in order):**
1. Infrastructure Type * — dropdown: Roads | Street Lights | Drainage | Public Facilities | Water Supply | Electrical | Other (with 'Specify' text field)
2. Location * — text input with map pin button; clicking opens a map modal
3. Name (Optional) — text input
4. Contact Number * — text input, format: 09XX-XXX-XXXX
5. Issue / Damage Description * — textarea
6. Evidence - Upload Images — file input, up to 4 images; shows thumbnail previews; has 📷 camera capture button on mobile
7. Consent checkbox — 'I agree to Terms and Conditions and Privacy Policy'

**Submit button:** 'Submit Request'

**Map Modal (when location is clicked):**
- Interactive Leaflet map centered on Quezon City
- Barangay dropdown to filter map to a barangay
- Address field (auto-detected via reverse geocoding)
- GPS button to use current location
- Satellite / Street layer toggle
- [Cancel] [Save Location] buttons

**Validation alerts:**
- Contact must be 11 digits starting with 09
- Location must be within Quezon City bounds
- At least 1 image required
- Consent checkbox required before submit

**Confirm modal:** 'Are you sure you want to submit this maintenance request?' → [Cancel] [Submit]
",

    'about' => "
## About Page — Structure

**Hero section:** 'About CIMMS – Quezon City' with subtitle

**Main content sections:**
1. Transforming Infrastructure Management — intro text about the platform
2. Highlights grid (4 cards): Easy Reporting | GPS Tracking | Real-Time Updates | Transparent Tracking
3. About CIMMS intro paragraphs
4. Our Purpose — bulleted list of 4 goals
5. What CIMMS Offers — 5 feature descriptions
6. For Quezon City Citizens — eligibility note
7. Our Vision — vision statement
8. Our Mission — mission statement
9. Our Core Values — 4 values: Efficiency | Transparency | Community First | Security
10. CTA: 'Submit a Report' button
",

    'privacy' => "
## Privacy Policy Page — Structure

**Page title:** 'Privacy Policy'

**Sections (in order):**
1. Intro — compliance with RA 10173, periodic updates notice
2. Data Collection and Processing — commitment statement
3. Lawful Processing Principles
4. Types of Information Collected — 5 bullet items: names, credentials, contact info, location data, activity logs
5. Data Security and Protection — encryption, technical/organizational measures
6. Your Rights as a Data Subject — 5 rights: Informed, Access, Correction, Object, Erasure
7. User Consent and Agreement — consent declaration text + 3 consent items
8. Contact Information — DPO details: dpo@infragovservices.com | (02) 8988-4242
9. Policy Updates — last updated February 2026

**Back button:** 'Back to Home'
",

    'terms' => "
## Terms and Conditions Page — Structure

**Page title:** 'Terms and Conditions'

**Sections (in order):**
1. Intro — RA 10173 compliance statement
2. Information Collection — 5 bullet items
3. Purpose of Data Collection — 5 purposes + disclaimer
4. Data Processing and Storage — 4 processing rules
5. Data Sharing and Disclosure — 3 exception cases
6. Data Subject Rights — 5 rights with title + description each:
   Right to be Informed | Right to Access | Right to Correction | Right to Object | Right to Erasure or Blocking
7. AI-Assisted Decision Support — 4 disclaimer items
8. Contact and Data Privacy Concerns — admin@infragovservices.com | dpo@infragovservices.com | (02) 8988-4242
9. Acceptance of Terms — agreement statement
10. Last Updated: February 2026

**Back button:** 'Back to Home'
",

    'general' => "
## InfraGovServices Portal — General

This is the InfraGovServices (CIMMS) portal for Quezon City, Philippines.
The system allows citizens to report, track, and monitor infrastructure maintenance issues.

Pages available:
- Home (citizencimm)
- Reports list (citizenreports)
- Submit request form (citizenrepform)
- About (about)
- Privacy Policy (privacy)
- Terms and Conditions (termcon)
- Login page

Common UI elements on all pages:
- Top navigation bar with logo, nav links, language toggle (🌐 EN/FIL), dark mode (🌙)
- Footer with Quick Links, Resources, Legal sections
- Chatbot widget (bottom-right floating button)
",
];

// ════════════════════════════════════════════════════════════
//  KNOWLEDGE BASE
// ════════════════════════════════════════════════════════════

$KB = [

    'reporting' => [
        'keywords' => ['report','issue','problem','concern','submit','request','complaint','broken',
                       'damage','pothole','flood','road','sidewalk','streetlight','drainage',
                       'ulat','isyu','problema','reklamo','sira','baha','kalsada','ipaalam',
                       'mag-report','i-report','ipag-ulat'],
        'en' => "**How to Report an Infrastructure Issue** 🏗️\n\n" .
                "Follow these steps to submit a report:\n\n" .
                "1️⃣ **Go to the Request Form** — Click **Requests** in the navigation menu or visit the Submit Request page.\n\n" .
                "2️⃣ **Fill in the details:**\n" .
                "   • Select the **type of issue** (road damage, flooding, streetlight, etc.)\n" .
                "   • Enter the **exact location** of the problem\n" .
                "   • Write a **description** of what you observed\n" .
                "   • Set the **urgency level** (Low, Medium, High, Critical)\n\n" .
                "3️⃣ **Upload evidence** — Attach photos or screenshots of the issue (up to 4 images).\n\n" .
                "4️⃣ **Submit** — Click the Submit button. You'll receive a reference number to track your request.\n\n" .
                "💡 *Tip: The more detailed your description, the faster the response from our team!*",
        'tl' => "**Paano Mag-ulat ng Isyu sa Imprastraktura** 🏗️\n\n" .
                "Sundin ang mga hakbang na ito:\n\n" .
                "1️⃣ **Pumunta sa Request Form** — I-click ang **Requests** sa navigation menu.\n\n" .
                "2️⃣ **Punan ang mga detalye:**\n" .
                "   • Piliin ang **uri ng isyu** (sira na kalsada, baha, streetlight, atbp.)\n" .
                "   • Ilagay ang **eksaktong lokasyon** ng problema\n" .
                "   • Isulat ang **detalyadong paglalarawan** ng nakita mo\n" .
                "   • Itakda ang **antas ng urgensi** (Mababa, Katamtaman, Mataas, Kritikal)\n\n" .
                "3️⃣ **Mag-upload ng larawan** — Mag-attach ng mga litrato bilang ebidensya (hanggang 4 na larawan).\n\n" .
                "4️⃣ **I-submit** — I-click ang Submit. Makakatanggap ka ng reference number para subaybayan ang iyong kahilingan.\n\n" .
                "💡 *Tip: Mas detalyado ang iyong paglalarawan, mas mabilis ang tugon ng aming koponan!*",
    ],

    'tracking' => [
        'keywords' => ['track','status','follow','update','monitor','progress','reference','number',
                       'subaybayan','katayuan','progreso','numero','reference','update','balita'],
        'en' => "**Tracking Your Request** 📍\n\n" .
                "Here's how to check the status of your submitted report:\n\n" .
                "1️⃣ **Go to Reports** — Click **Reports** in the navigation menu.\n\n" .
                "2️⃣ **Find your submission** — Use the search bar or filter by date/type to locate your report.\n\n" .
                "3️⃣ **Status meanings:**\n" .
                "   🟡 **Pending** — Report received, awaiting review\n" .
                "   🟠 **In Progress** — Work has started\n" .
                "   🟢 **Completed** — Issue has been addressed\n" .
                "   🔴 **Delayed** — Timeline extended\n\n" .
                "📌 *Keep your reference number (Sched #) for faster tracking!*",
        'tl' => "**Subaybayan ang Iyong Kahilingan** 📍\n\n" .
                "Paano tingnan ang katayuan ng iyong isinumiteng ulat:\n\n" .
                "1️⃣ **Pumunta sa Reports** — I-click ang **Reports** sa navigation menu.\n\n" .
                "2️⃣ **Hanapin ang iyong ulat** — Gamitin ang search bar o i-filter ayon sa petsa/uri.\n\n" .
                "3️⃣ **Kahulugan ng katayuan:**\n" .
                "   🟡 **Nakabinbin** — Natanggap na ang ulat\n" .
                "   🟠 **Isinasagawa** — Nagsimula na ang trabaho\n" .
                "   🟢 **Natapos** — Natugunan na ang isyu\n" .
                "   🔴 **Naantala** — Pinalawak ang timeline\n\n" .
                "📌 *Itago ang iyong Sched # para sa mas mabilis na pagsubaybay!*",
    ],

    'upload' => [
        'keywords' => ['upload','photo','image','picture','attach','evidence','file','camera','screenshot',
                       'litrato','larawan','mag-upload','i-attach','ebidensya','kuha'],
        'en' => "**Uploading Photos as Evidence** 📸\n\n" .
                "**In the Request Form:**\n" .
                "• Click the **Upload Images** area or tap 📷 to capture on mobile\n" .
                "• You can attach **up to 4 images** per report\n" .
                "• Supported formats: **JPG, PNG, WEBP** (max 5 MB each)\n" .
                "• Photos should clearly show the infrastructure issue\n\n" .
                "**In this Chatbot:**\n" .
                "• Click the 🖼️ **Gallery** icon to upload screenshots\n" .
                "• You can queue up to 5 images, then type your question and press Send\n" .
                "• The AI will analyze your screenshot and provide contextual help\n\n" .
                "💡 *Good photos greatly speed up assessment and response time!*",
        'tl' => "**Pag-upload ng Mga Larawan bilang Ebidensya** 📸\n\n" .
                "**Sa Request Form:**\n" .
                "• I-click ang **Upload Images** o i-tap ang 📷 para kumuha sa mobile\n" .
                "• Maaari kang mag-attach ng **hanggang 4 na larawan** bawat ulat\n" .
                "• Mga format: **JPG, PNG, WEBP** (max 5 MB bawat isa)\n\n" .
                "**Sa Chatbot na ito:**\n" .
                "• I-click ang 🖼️ **Gallery** icon para mag-queue ng mga screenshot\n" .
                "• Hanggang 5 larawan, pagkatapos mag-type ng mensahe at pindutin ang Send\n\n" .
                "💡 *Ang magagandang larawan ay nagpapabilis ng pagsusuri!*",
    ],

    'contact' => [
        'keywords' => ['contact','support','help','hotline','phone','email','call','reach','address',
                       'makipag-ugnayan','tulong','telepono','email','tawag','saan'],
        'en' => "**Contact & Support Information** 📞\n\n" .
                "📧 **Email:** contact@infragovservices.com\n" .
                "📞 **Phone:** (02) 8988-4242\n" .
                "📍 **Office:** Quezon City Hall, Quezon City\n\n" .
                "**Data Privacy Concerns:**\n" .
                "• System Admin: admin@infragovservices.com\n" .
                "• Data Protection Officer: dpo@infragovservices.com\n\n" .
                "**Operating Hours:**\n" .
                "🕗 Monday – Friday: 8:00 AM – 5:00 PM\n" .
                "🚨 Emergency infrastructure issues: available 24/7 via phone",
        'tl' => "**Impormasyon sa Pakikipag-ugnayan** 📞\n\n" .
                "📧 **Email:** contact@infragovservices.com\n" .
                "📞 **Telepono:** (02) 8988-4242\n" .
                "📍 **Opisina:** Quezon City Hall, Quezon City\n\n" .
                "**Para sa Data Privacy:**\n" .
                "• Admin: admin@infragovservices.com\n" .
                "• DPO: dpo@infragovservices.com\n\n" .
                "**Oras ng Opisina:**\n" .
                "🕗 Lunes – Biyernes: 8:00 AM – 5:00 PM\n" .
                "🚨 Emergency: available 24/7 sa telepono",
    ],

    'privacy' => [
        'keywords' => ['privacy','personal','data','information','collect','store','protect','dpa','ra 10173',
                       'private','confidential','npc','kalihim','personal na impormasyon','proteksyon'],
        'en' => "**Privacy Policy — Summary** 🔒\n\n" .
                "InfraGovServices complies with **RA 10173 (Data Privacy Act of 2012)**:\n\n" .
                "📋 **What we collect:** Names, credentials, contact info, location data, activity logs\n\n" .
                "🎯 **Why:** Authentication, reporting coordination, AI decision support, academic research\n\n" .
                "🛡️ **Your rights:** Informed · Access · Correction · Object · Erasure\n\n" .
                "Data is **never sold** to third parties.\n" .
                "📧 DPO: dpo@infragovservices.com | 📞 (02) 8988-4242",
        'tl' => "**Patakaran sa Privacy — Buod** 🔒\n\n" .
                "Sumusunod ang InfraGovServices sa **RA 10173 (Data Privacy Act of 2012)**:\n\n" .
                "📋 **Kinokolekta:** Mga pangalan, kredensyal, contact info, lokasyon, activity logs\n\n" .
                "🛡️ **Iyong karapatan:** Malaman · Ma-access · Baguhin · Tumutol · Burahin\n\n" .
                "Ang iyong data ay **hindi ibinebenta** sa mga third party.\n" .
                "📧 DPO: dpo@infragovservices.com",
    ],

    'terms' => [
        'keywords' => ['terms','conditions','agreement','accept','policy','rules','service','toc',
                       'mga tuntunin','kondisyon','kasunduan','patakaran','serbisyo'],
        'en' => "**Terms and Conditions — Key Points** 📄\n\n" .
                "✅ **Data Use** — Personal data used only for system operations and academic research.\n\n" .
                "✅ **AI Assistance** — AI recommendations are for support only, not replacing official decisions.\n\n" .
                "✅ **Data Security** — All data stored securely with organizational and technical safeguards.\n\n" .
                "✅ **Data Sharing** — Not shared without consent, except when required by law.\n\n" .
                "✅ **Retention** — Retained only as needed, then securely deleted or anonymized.\n\n" .
                "📅 *Last Updated: February 2026*",
        'tl' => "**Mga Tuntunin at Kondisyon — Mahahalagang Punto** 📄\n\n" .
                "✅ **Paggamit ng Data** — Para lamang sa mga operasyon ng sistema at academic research.\n\n" .
                "✅ **Tulong ng AI** — Para sa suporta lamang, hindi pinapalitan ang opisyal na desisyon.\n\n" .
                "✅ **Seguridad ng Data** — Lahat ng data ay ligtas na nakaimbak.\n\n" .
                "✅ **Pagbabahagi** — Hindi ibinabahagi nang walang pahintulot.\n\n" .
                "📅 *Huling Na-update: Pebrero 2026*",
    ],

    'ai_system' => [
        'keywords' => ['ai','artificial intelligence','machine learning','decision','algorithm','model',
                       'predict','analyze','analyse','recommendation','automated','artipisyal','intelihensya'],
        'en' => "**AI-Assisted Decision Support** 🤖\n\n" .
                "InfraGovServices uses AI to enhance infrastructure coordination:\n\n" .
                "🔍 **What the AI does:**\n" .
                "• Analyzes submitted reports to detect patterns\n" .
                "• Prioritizes issues based on severity, frequency, and location\n" .
                "• Suggests resource allocation for repair crews\n" .
                "• Powers this chatbot to answer your questions\n\n" .
                "⚠️ **Important:** AI recommendations are **decision support only** and do not replace official LGU authority.",
        'tl' => "**AI-Assisted Decision Support** 🤖\n\n" .
                "Gumagamit ng AI ang InfraGovServices para mapabuti ang koordinasyon:\n\n" .
                "🔍 **Ginagawa ng AI:**\n" .
                "• Nag-aanalisa ng mga ulat para mahanap ang mga pattern\n" .
                "• Nagbibigay ng priyoridad sa mga isyu\n" .
                "• Nagmumungkahi ng paglalaan ng mga mapagkukunan\n\n" .
                "⚠️ Ang mga rekomendasyon ng AI ay **para sa suporta sa desisyon lamang**.",
    ],

    'navigation' => [
        'keywords' => ['navigate','menu','page','home','go to','where','find','access','login','logout',
                       'account','register','sign','how to use','tutorial','saan','pumunta','paano gamitin'],
        'en' => "**Navigating InfraGovServices** 🗺️\n\n" .
                "🏠 **Home** — Overview, stats, recent activity, quick access buttons\n" .
                "📊 **Reports** — Browse all submissions, filter by status/type/date, view details\n" .
                "📝 **Requests** — Fill out the form to report a new infrastructure issue\n" .
                "ℹ️ **About** — Learn about the system, mission, and team\n" .
                "🔐 **Login** — Access your account to manage personal submissions\n" .
                "🌐 **Language** — Toggle EN/FIL via the globe icon in the navbar\n" .
                "🌙 **Dark Mode** — Toggle via the moon/sun icon in the navbar",
        'tl' => "**Pag-navigate sa InfraGovServices** 🗺️\n\n" .
                "🏠 **Home** — Pangkalahatang-ideya, stats, at mabilis na access\n" .
                "📊 **Reports** — Mag-browse ng lahat ng ulat, i-filter ayon sa katayuan/uri\n" .
                "📝 **Requests** — Punan ang form para iulat ang bagong isyu\n" .
                "ℹ️ **About** — Alamin ang tungkol sa sistema at koponan\n" .
                "🌐 **Wika** — I-toggle ang EN/FIL gamit ang globe icon\n" .
                "🌙 **Dark Mode** — I-toggle gamit ang moon icon",
    ],

    'issue_types' => [
        'keywords' => ['type','kind','category','what kind','what type','classify','road','pothole','flood',
                       'drainage','streetlight','sidewalk','bridge','building','electrical','water','sewer',
                       'uri','klase','kategorya','kalsada','baha','drenahe','tulay','gusali','kuryente','tubig'],
        'en' => "**Types of Infrastructure Issues You Can Report** 📋\n\n" .
                "🛣️ **Roads** — Potholes, cracks, damaged surfaces\n" .
                "💧 **Drainage** — Clogged drains, waterlogging, blocked canals\n" .
                "💡 **Street Lights** — Broken lights, exposed wiring\n" .
                "🚶 **Sidewalks** — Damaged footpaths, missing ramps\n" .
                "🏗️ **Public Facilities** — Government building damage\n" .
                "💧 **Water Supply** — Leaking pipes, supply interruptions\n" .
                "⚡ **Electrical** — Power outages, electrical hazards\n" .
                "🗑️ **Other** — Any other infrastructure concern\n\n" .
                "Select the most appropriate category in the Request Form!",
        'tl' => "**Mga Uri ng Isyu na Maaaring Iulat** 📋\n\n" .
                "🛣️ **Mga Kalsada** — Butas, bitak, sirang sahig\n" .
                "💧 **Drainage** — Napalapag na drain, baha, naharang na kanal\n" .
                "💡 **Mga Ilaw sa Kalye** — Sirang ilaw, nakalantad na wire\n" .
                "🚶 **Bangketa** — Sirang bangketa, nawawalang ramp\n" .
                "🏗️ **Mga Pampublikong Pasilidad** — Pinsala sa gusali ng gobyerno\n" .
                "💧 **Suplay ng Tubig** — Tumatagasang tubo\n" .
                "⚡ **Elektrikal** — Pagkawala ng kuryente\n\n" .
                "Piliin ang pinaka-angkop na kategorya sa Request Form!",
    ],

    'language' => [
        'keywords' => ['language','switch','english','filipino','tagalog','translate','wika','pagsalin',
                       'mag-switch','pilipino','ingles'],
        'en' => "**Switching Language** 🌐\n\n" .
                "InfraGovServices supports **English** and **Filipino (Tagalog)**:\n\n" .
                "🖥️ **Desktop & Mobile:** Click the **🌐 globe icon** in the top navigation bar.\n" .
                "It displays **EN** or **FIL** depending on the current language.\n\n" .
                "The switch applies to all navigation labels, page content, chatbot responses, and notifications.\n" .
                "Your preference is saved automatically! 🔄",
        'tl' => "**Pag-switch ng Wika** 🌐\n\n" .
                "Sinusuportahan ng InfraGovServices ang **English** at **Filipino**:\n\n" .
                "🖥️ **Desktop at Mobile:** I-click ang **🌐 globe icon** sa navigation bar.\n" .
                "Ipapakita nito ang **EN** o **FIL**.\n\n" .
                "Ang iyong kagustuhan ay awtomatikong nase-save! 🔄",
    ],

    'dark_mode' => [
        'keywords' => ['dark','light','mode','theme','night','moon','sun','brightness','madilim','maliwanag'],
        'en' => "**Dark Mode / Light Mode** 🌙\n\n" .
                "Click the **🌙 moon icon** (or ☀️ sun icon) in the top-right navigation area to toggle between dark and light mode.\n\n" .
                "Your theme preference is automatically saved for your next visit.",
        'tl' => "**Dark Mode / Light Mode** 🌙\n\n" .
                "I-click ang **🌙 moon icon** (o ☀️ sun icon) sa navigation bar para mag-toggle.\n\n" .
                "Ang iyong kagustuhan ay awtomatikong nase-save.",
    ],

    'account' => [
        'keywords' => ['login','logout','account','password','username','register','sign in','sign up',
                       'forgot','reset','credentials','mag-login','mag-logout'],
        'en' => "**Account & Login Help** 🔐\n\n" .
                "🔑 **Login** — Use your registered username and password on the Login page.\n\n" .
                "👤 **Citizens** — Can submit reports without an account using the public form.\n\n" .
                "🆕 **New Account** — Contact the system administrator (LGU staff only).\n\n" .
                "🔒 **Forgot Password** — Click 'Forgot Password' on the login page.\n\n" .
                "📧 Account issues: admin@infragovservices.com | 📞 (02) 8988-4242",
        'tl' => "**Tulong sa Account at Login** 🔐\n\n" .
                "🔑 **Login** — Gamitin ang iyong rehistradong username at password.\n\n" .
                "👤 **Mga Mamamayan** — Maaaring mag-submit nang walang account.\n\n" .
                "🔒 **Nakalimutang Password** — I-click ang 'Forgot Password'.\n\n" .
                "📧 admin@infragovservices.com | 📞 (02) 8988-4242",
    ],

    'about' => [
        'keywords' => ['about','system','infragovservices','lgu','quezon','city','mission','vision','purpose',
                       'what is','who','tungkol','layunin','misyon','bisyon','sistema','lungsod'],
        'en' => "**About InfraGovServices** ℹ️\n\n" .
                "InfraGovServices is the **Community Infrastructure Maintenance Management System (CIMMS)** for Quezon City.\n\n" .
                "🎯 **Mission:** Efficient, transparent, and responsive infrastructure services for all Quezon City residents.\n\n" .
                "🔧 **What we do:**\n" .
                "• Centralize infrastructure issue reporting from citizens\n" .
                "• Coordinate between LGU departments for faster response\n" .
                "• Use AI-assisted tools for data-driven infrastructure planning\n" .
                "• Maintain transparency through public status tracking\n\n" .
                "📍 Quezon City Hall, Quezon City, Philippines",
        'tl' => "**Tungkol sa InfraGovServices** ℹ️\n\n" .
                "Ang InfraGovServices ay ang **Community Infrastructure Maintenance Management System (CIMMS)** para sa Lungsod ng Quezon.\n\n" .
                "🎯 **Misyon:** Mahusay, transparent, at maagap na serbisyo sa imprastraktura.\n\n" .
                "🔧 **Ginagawa namin:**\n" .
                "• Sentralisasyon ng pag-uulat mula sa mga mamamayan\n" .
                "• Koordinasyon sa pagitan ng mga departamento ng LGU\n" .
                "• Paggamit ng AI-assisted tools para sa data-driven na pagpaplano\n\n" .
                "📍 Quezon City Hall, Quezon City, Pilipinas",
    ],
];

// ════════════════════════════════════════════════════════════
//  PAGE CONTEXT INFO (short descriptions for greetings/fallback)
// ════════════════════════════════════════════════════════════

$PAGE_CONTEXT_INFO = [
    'home'    => ['en' => "You're on the **Home page** — overview of InfraGovServices with stats and quick access to reports and submissions.",
                  'tl' => "Nasa **Home page** ka — pangkalahatang-ideya ng InfraGovServices na may stats at mabilis na access sa mga ulat at submission."],
    'reports' => ['en' => "You're on the **Reports page** — browse all submitted infrastructure reports with filtering and search.",
                  'tl' => "Nasa **Reports page** ka — mag-browse ng lahat ng isinumiteng ulat sa imprastraktura na may filter at search."],
    'request' => ['en' => "You're on the **Request Form page** — submit a new infrastructure issue report here.",
                  'tl' => "Nasa **Request Form page** ka — dito mag-submit ng bagong ulat sa isyu ng imprastraktura."],
    'about'   => ['en' => "You're on the **About page** — learn about InfraGovServices, its mission, vision, and core values.",
                  'tl' => "Nasa **About page** ka — alamin ang tungkol sa InfraGovServices, misyon, pananaw, at mga pangunahing halaga."],
    'privacy' => ['en' => "You're on the **Privacy Policy page** — details on how your personal data is collected, used, and protected under RA 10173.",
                  'tl' => "Nasa **Privacy Policy page** ka — mga detalye kung paano kinokolekta, ginagamit, at pinoprotektahan ang iyong data sa ilalim ng RA 10173."],
    'terms'   => ['en' => "You're on the **Terms and Conditions page** — the rules and agreements for using InfraGovServices.",
                  'tl' => "Nasa **Terms and Conditions page** ka — mga patakaran at kasunduan sa paggamit ng InfraGovServices."],
    'general' => ['en' => "You're using **InfraGovServices** — the Community Infrastructure Maintenance Management System for Quezon City.",
                  'tl' => "Gumagamit ka ng **InfraGovServices** — ang Community Infrastructure Maintenance Management System para sa Lungsod ng Quezon."],
];

// ════════════════════════════════════════════════════════════
//  INTENT DETECTION
// ════════════════════════════════════════════════════════════

function detectIntent(string $message, array $kb): ?string {
    $lowerMsg = mb_strtolower($message);
    $scores   = [];
    foreach ($kb as $intent => $data) {
        $score = 0;
        foreach ($data['keywords'] as $keyword) {
            if (mb_strpos($lowerMsg, mb_strtolower($keyword)) !== false) {
                $weight = mb_strlen($keyword) >= 6 ? 2 : 1;
                $score += $weight;
            }
        }
        if ($score > 0) $scores[$intent] = $score;
    }
    if (empty($scores)) return null;
    arsort($scores);
    reset($scores);
    return current($scores) >= 1 ? key($scores) : null;
}

// ════════════════════════════════════════════════════════════
//  GREETING DETECTION
// ════════════════════════════════════════════════════════════

function isGreeting(string $message): bool {
    $greetings = ['hello','hi','hey','good morning','good afternoon','good evening','howdy','greetings',
                  'kumusta','kamusta','magandang','musta','yo','sup','hola','mabuhay'];
    $lower = mb_strtolower(trim($message));
    foreach ($greetings as $g) {
        if (mb_strpos($lower, $g) === 0 || $lower === $g) return true;
    }
    return false;
}

// ════════════════════════════════════════════════════════════
//  GREETING RESPONSE
// ════════════════════════════════════════════════════════════

function getGreetingResponse(string $context, bool $isTagalog, array $pageContextInfo): string {
    $hour = (int) date('H');
    if ($hour < 12)     $timeGreet = $isTagalog ? 'Magandang umaga' : 'Good morning';
    elseif ($hour < 17) $timeGreet = $isTagalog ? 'Magandang tanghali' : 'Good afternoon';
    else                $timeGreet = $isTagalog ? 'Magandang gabi' : 'Good evening';

    $pageInfo = $pageContextInfo[$context] ?? $pageContextInfo['general'];
    $pageDesc = $pageInfo[$isTagalog ? 'tl' : 'en'];

    if ($isTagalog) {
        return "{$timeGreet}! 👋 Ako ang iyong InfraGovServices Assistant.\n\n{$pageDesc}\n\n" .
               "Narito ako para tumulong sa:\n" .
               "• 📋 Pag-uulat ng isyu sa imprastraktura\n" .
               "• 📍 Pagsubaybay ng iyong mga kahilingan\n" .
               "• 🔒 Mga katanungan sa Privacy at Terms\n" .
               "• 🗺️ Pag-navigate sa sistema\n" .
               "• 📸 Pagsusuri ng mga screenshot\n\nPaano kita matutulungan ngayon?";
    } else {
        return "{$timeGreet}! 👋 I'm your InfraGovServices Assistant.\n\n{$pageDesc}\n\n" .
               "I'm here to help you with:\n" .
               "• 📋 Reporting infrastructure issues\n" .
               "• 📍 Tracking your requests\n" .
               "• 🔒 Privacy Policy & Terms questions\n" .
               "• 🗺️ Navigating the system\n" .
               "• 📸 Analyzing screenshots\n\nHow can I assist you today?";
    }
}

// ════════════════════════════════════════════════════════════
//  FALLBACK RESPONSE
// ════════════════════════════════════════════════════════════

function getFallbackResponse(string $context, bool $isTagalog, array $pageContextInfo): string {
    $pageInfo = $pageContextInfo[$context] ?? $pageContextInfo['general'];
    $pageDesc = $pageInfo[$isTagalog ? 'tl' : 'en'];

    if ($isTagalog) {
        return "Pasensya na, hindi ko ganap na naintindihan ang iyong tanong. 🙏\n\n{$pageDesc}\n\n" .
               "**Maaari akong tumulong sa mga sumusunod:**\n" .
               "• Paano mag-ulat ng isyu sa imprastraktura\n" .
               "• Pagsubaybay ng iyong kahilingan\n" .
               "• Pag-upload ng mga larawan bilang ebidensya\n" .
               "• Privacy Policy at Terms and Conditions\n" .
               "• Paano gamitin ang sistema\n" .
               "• Mga uri ng isyu na maaaring iulat\n" .
               "• Impormasyon sa pakikipag-ugnayan\n\n" .
               "Subukan mong i-rephrase ang iyong tanong! 😊";
    } else {
        return "I'm not sure I fully understood your question. 🙏\n\n{$pageDesc}\n\n" .
               "**I can help you with:**\n" .
               "• How to report an infrastructure issue\n" .
               "• Tracking your request status\n" .
               "• Uploading photos as evidence\n" .
               "• Privacy Policy & Terms and Conditions\n" .
               "• How to navigate and use the system\n" .
               "• Types of issues you can report\n" .
               "• Contact information and support\n\n" .
               "Try rephrasing your question, or pick one of the topics above! 😊";
    }
}

// ════════════════════════════════════════════════════════════
//  CLAUDE API — TEXT
// ════════════════════════════════════════════════════════════

function callClaudeText(string $apiKey, string $userMessage, string $context, string $lang,
                        array $history, array $pageStructure, array $pageContextInfo): ?string {

    $pageStruct = $pageStructure[$context] ?? $pageStructure['general'];
    $pageCtx    = $pageContextInfo[$context][$lang === 'tl' ? 'tl' : 'en'] ?? '';

    $systemPrompt = "You are InfraGovServices AI Assistant — a helpful chatbot for the Community Infrastructure Maintenance Management System (CIMMS) of Quezon City, Philippines.

## Your scope
You ONLY answer questions related to:
- InfraGovServices portal features (reporting, tracking, navigation, forms)
- Infrastructure issues in Quezon City (roads, drainage, streetlights, sidewalks, bridges, etc.)
- Privacy Policy (RA 10173 / Data Privacy Act 2012) and Terms & Conditions
- AI-assisted decision support features
- Contact info: admin@infragovservices.com | dpo@infragovservices.com | (02) 8988-4242

## Current context
- Page: {$context}
- User's location context: {$pageCtx}
- Language: " . ($lang === 'tl' ? 'Filipino/Tagalog — respond in Tagalog' : 'English — respond in English') . "

## Page structure reference
{$pageStruct}

## Rules
1. Respond in the user's language (" . ($lang === 'tl' ? 'Tagalog/Filipino' : 'English') . ")
2. Use emojis, bullet points, numbered steps for clarity
3. Keep responses focused and actionable
4. If asked something unrelated, politely redirect to InfraGovServices topics
5. Reference specific UI elements (button names, field labels, section names) from the page structure above";

    $messages = [];
    foreach (array_slice($history, -4) as $h) {
        if (!empty($h['text']) && !empty($h['type'])) {
            $messages[] = ['role' => ($h['type'] === 'user') ? 'user' : 'assistant', 'content' => $h['text']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return claudeRequest($apiKey, $systemPrompt, $messages);
}

// ════════════════════════════════════════════════════════════
//  CLAUDE API — VISION (image + optional text)
// ════════════════════════════════════════════════════════════

function callClaudeVision(string $apiKey, string $userMessage, string $context, string $lang,
                          array $history, string $imageBase64, array $pageStructure, array $pageContextInfo): ?string {

    $pageStruct = $pageStructure[$context] ?? $pageStructure['general'];
    $pageCtx    = $pageContextInfo[$context][$lang === 'tl' ? 'tl' : 'en'] ?? '';

    $systemPrompt = "You are InfraGovServices AI Assistant — a helpful chatbot for the Community Infrastructure Maintenance Management System (CIMMS) of Quezon City, Philippines.

## Your task for this message
The user has uploaded a screenshot from the InfraGovServices portal. You must:
1. LOOK at the screenshot and identify exactly what page/section/element is shown
2. Describe what you see in the screenshot specifically
3. Provide helpful, actionable guidance based on what is visible

## Current context
- Detected page context: {$context}
- User location context: {$pageCtx}
- Language: " . ($lang === 'tl' ? 'Filipino/Tagalog — respond in Tagalog' : 'English — respond in English') . "

## Full page structure reference (use this to identify UI elements)
{$pageStruct}

## Rules
1. Always start by acknowledging what you see in the screenshot (e.g., '📸 I can see the Reports page showing...')
2. Be specific about visible elements — mention actual button names, field labels, status badges, section headings
3. If the user asked a specific question alongside the image, answer it directly after describing what you see
4. Respond in " . ($lang === 'tl' ? 'Tagalog/Filipino' : 'English') . "
5. Use emojis and clear formatting";

    $messages = [];
    foreach (array_slice($history, -3) as $h) {
        if (!empty($h['text']) && !empty($h['type'])) {
            $messages[] = ['role' => ($h['type'] === 'user') ? 'user' : 'assistant', 'content' => $h['text']];
        }
    }

    // Extract base64 data and MIME type
    $imgData = $imageBase64;
    if (strpos($imgData, 'base64,') !== false) {
        $imgData = substr($imgData, strpos($imgData, 'base64,') + 7);
    }
    preg_match('/data:([^;]+)/', $imageBase64, $mimeMatch);
    $mimeType = $mimeMatch[1] ?? 'image/jpeg';

    $prompt = empty($userMessage) || isGenericQuestion($userMessage)
        ? "Please analyze this screenshot from the InfraGovServices portal and describe what you see, then explain what the user can do on this page."
        : "The user uploaded this screenshot and asked: \"{$userMessage}\"\n\nFirst describe what you see in the screenshot, then answer their question.";

    $messages[] = [
        'role' => 'user',
        'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $imgData]],
            ['type' => 'text',  'text'   => $prompt],
        ]
    ];

    return claudeRequest($apiKey, $systemPrompt, $messages);
}

// ════════════════════════════════════════════════════════════
//  CLAUDE HTTP REQUEST (shared)
// ════════════════════════════════════════════════════════════

function claudeRequest(string $apiKey, string $systemPrompt, array $messages): ?string {
    $payload = [
        'model'      => 'claude-opus-4-6',
        'max_tokens' => 900,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 28,
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
//  Gives a single focused response — NO second intent pass.
// ════════════════════════════════════════════════════════════

function fallbackImageAnalysis(string $context, bool $isTagalog, array $pageStructure, string $userMessage): string {

    $pageName = [
        'home'    => ['en' => 'Home Dashboard', 'tl' => 'Home Dashboard'],
        'reports' => ['en' => 'Reports List Page', 'tl' => 'Pahina ng Listahan ng mga Ulat'],
        'request' => ['en' => 'Request Submission Form', 'tl' => 'Form sa Pagsumite ng Kahilingan'],
        'about'   => ['en' => 'About Page', 'tl' => 'Pahina ng Tungkol Sa'],
        'privacy' => ['en' => 'Privacy Policy Page', 'tl' => 'Pahina ng Patakaran sa Privacy'],
        'terms'   => ['en' => 'Terms & Conditions Page', 'tl' => 'Pahina ng Mga Tuntunin'],
        'general' => ['en' => 'InfraGovServices Portal', 'tl' => 'Portal ng InfraGovServices'],
    ];

    $name = ($pageName[$context] ?? $pageName['general'])[$isTagalog ? 'tl' : 'en'];

    // Per-page focused response (single, clean)
    $responses = [
        'home' => [
            'en' => "📸 I can see a screenshot of the **Home page** of InfraGovServices.\n\n" .
                    "Here's what this page contains:\n\n" .
                    "**🔝 Navigation Bar (top):**\n" .
                    "• Logo and links: Home | Reports | Requests | About | Log in\n" .
                    "• Right side: 🌐 Language toggle (EN/FIL) and 🌙 dark mode toggle\n\n" .
                    "**📊 Stats Section:**\n" .
                    "• Completed Repairs | On-Going Repairs | Pending Requests\n\n" .
                    "**🚀 Hero Section:**\n" .
                    "• 'Welcome to InfraGovServices' headline\n" .
                    "• [Submit a Report] and [Learn More] buttons\n\n" .
                    "**📋 How It Works (4 steps):**\n" .
                    "Report → Review → Maintenance Scheduled → Issue Resolved\n\n" .
                    "**Recent Maintenance Activity** — latest reports list at the bottom\n\n" .
                    "💡 To get started, click **Requests** to submit a report, or **Reports** to browse existing ones.",
            'tl' => "📸 Nakikita ko ang screenshot ng **Home page** ng InfraGovServices.\n\n" .
                    "Narito ang nilalaman ng pahinang ito:\n\n" .
                    "**🔝 Navigation Bar (sa itaas):**\n" .
                    "• Logo at mga link: Home | Reports | Requests | About | Log in\n" .
                    "• Kanan: 🌐 Language toggle (EN/FIL) at 🌙 dark mode\n\n" .
                    "**📊 Stats Section:**\n" .
                    "• Natapos na Pag-aayos | Kasalukuyang Pag-aayos | Mga Nakabinbing Kahilingan\n\n" .
                    "**🚀 Hero Section:**\n" .
                    "• 'Welcome sa InfraGovServices' headline\n" .
                    "• [Mag-ulat ng Isyu] at [Alamin Pa] buttons\n\n" .
                    "**📋 Paano Gumagana (4 hakbang):**\n" .
                    "Iulat → Suriin → Naka-iskedyul → Nalutas\n\n" .
                    "💡 I-click ang **Requests** para mag-submit ng ulat, o **Reports** para mag-browse.",
        ],
        'reports' => [
            'en' => "📸 I can see a screenshot of the **Reports page** of InfraGovServices.\n\n" .
                    "Here's what you're looking at:\n\n" .
                    "**📊 Stats Row (top):**\n" .
                    "• Repairs (total) | On-Going Repairs | Pending\n\n" .
                    "**🔍 Search & Filter Bar:**\n" .
                    "• Search by Date, Type, Location, Budget, or Status\n" .
                    "• Filter dropdown buttons\n\n" .
                    "**📋 Reports Table:**\n" .
                    "Columns: Sched # | Date | Type | Location | Budget | Status | Action\n\n" .
                    "**Status badge colors:**\n" .
                    "• 🟡 Pending → 🟠 In Progress → 🟢 Completed → 🔴 Delayed\n\n" .
                    "**[View] button** — click to open full report details\n\n" .
                    "💡 Use the search bar or filters to find your specific report quickly.",
            'tl' => "📸 Nakikita ko ang screenshot ng **Reports page** ng InfraGovServices.\n\n" .
                    "Narito ang nakikita mo:\n\n" .
                    "**📊 Stats Row (sa itaas):**\n" .
                    "• Mga Pag-aayos | Kasalukuyang Pag-aayos | Nakabinbin\n\n" .
                    "**🔍 Search at Filter Bar:**\n" .
                    "• Maghanap ayon sa Petsa, Uri, Lokasyon, Badyet, o Katayuan\n\n" .
                    "**📋 Talahanayan ng mga Ulat:**\n" .
                    "Mga Kolum: Sched # | Petsa | Uri | Lokasyon | Badyet | Katayuan | Aksyon\n\n" .
                    "**Mga kulay ng status badge:**\n" .
                    "• 🟡 Nakabinbin → 🟠 Isinasagawa → 🟢 Natapos → 🔴 Naantala\n\n" .
                    "💡 Gamitin ang search bar o mga filter para mabilis na mahanap ang iyong ulat.",
        ],
        'request' => [
            'en' => "📸 I can see the **Request / Maintenance Request Form** page.\n\n" .
                    "Here's a guide to every field:\n\n" .
                    "**Form fields (top to bottom):**\n\n" .
                    "1️⃣ **Infrastructure Type *** — dropdown: Roads | Street Lights | Drainage | Public Facilities | Water Supply | Electrical | Other\n\n" .
                    "2️⃣ **Location *** — click the field to open the interactive map modal\n" .
                    "   • Pin the location on the map or use 📍 GPS\n" .
                    "   • Select your barangay from the dropdown\n\n" .
                    "3️⃣ **Name** (optional)\n\n" .
                    "4️⃣ **Contact Number *** — must be 11 digits starting with 09\n\n" .
                    "5️⃣ **Issue / Damage Description *** — describe the problem in detail\n\n" .
                    "6️⃣ **Upload Images** — up to 4 photos; tap 📷 to capture on mobile\n\n" .
                    "7️⃣ **Consent checkbox** — agree to Terms and Privacy Policy\n\n" .
                    "8️⃣ **[Submit Request]** button\n\n" .
                    "⭐ Fields marked with * are required. Need help with a specific field?",
            'tl' => "📸 Nakikita ko ang **Request / Maintenance Request Form** page.\n\n" .
                    "Narito ang gabay sa bawat field:\n\n" .
                    "**Mga field ng form (mula itaas pababa):**\n\n" .
                    "1️⃣ **Uri ng Imprastraktura *** — dropdown: Mga Kalsada | Mga Ilaw | Drainage | Mga Pasilidad | Tubig | Elektrikal | Iba Pa\n\n" .
                    "2️⃣ **Lokasyon *** — i-click para buksan ang interactive map modal\n" .
                    "   • I-pin ang lokasyon sa mapa o gamitin ang 📍 GPS\n\n" .
                    "3️⃣ **Pangalan** (opsyonal)\n\n" .
                    "4️⃣ **Numero ng Kontak *** — 11 digits, nagsisimula sa 09\n\n" .
                    "5️⃣ **Paglalarawan ng Isyu *** — ilarawan ang problema nang detalyado\n\n" .
                    "6️⃣ **Mag-upload ng Larawan** — hanggang 4 na larawan; i-tap ang 📷 sa mobile\n\n" .
                    "7️⃣ **Consent checkbox** — sumang-ayon sa Terms at Privacy Policy\n\n" .
                    "8️⃣ **[Isumite ang Kahilingan]** button\n\n" .
                    "⭐ Ang mga field na may * ay kailangan. Kailangan mo ng tulong sa isang partikular na field?",
        ],
        'privacy' => [
            'en' => "📸 I can see the **Privacy Policy page** of InfraGovServices.\n\n" .
                    "Here's a summary of the sections visible on this page:\n\n" .
                    "📋 **Data Collection** — What info we collect (names, credentials, location data, logs)\n\n" .
                    "⚖️ **Lawful Processing** — Data collected only for legitimate, declared purposes\n\n" .
                    "🔐 **Data Security** — Encryption during transmission and storage\n\n" .
                    "🛡️ **Your Rights (RA 10173):**\n" .
                    "• Informed | Access | Correction | Object | Erasure\n\n" .
                    "✅ **User Consent** — What you agree to by using the system\n\n" .
                    "📞 **Contact the DPO:** dpo@infragovservices.com | (02) 8988-4242\n\n" .
                    "📅 Last Updated: February 2026\n\n" .
                    "Do you have a specific question about a section of the Privacy Policy?",
            'tl' => "📸 Nakikita ko ang **Privacy Policy page** ng InfraGovServices.\n\n" .
                    "Narito ang buod ng mga seksyon:\n\n" .
                    "📋 **Koleksyon ng Data** — Ano ang kinokolekta (mga pangalan, kredensyal, lokasyon, logs)\n\n" .
                    "🔐 **Seguridad ng Data** — Encryption sa panahon ng transmission at storage\n\n" .
                    "🛡️ **Iyong Karapatan (RA 10173):**\n" .
                    "• Malaman | Ma-access | Baguhin | Tumutol | Burahin\n\n" .
                    "📞 **Makipag-ugnayan sa DPO:** dpo@infragovservices.com | (02) 8988-4242\n\n" .
                    "May partikular ka bang tanong tungkol sa isang seksyon ng Privacy Policy?",
        ],
        'terms' => [
            'en' => "📸 I can see the **Terms and Conditions page** of InfraGovServices.\n\n" .
                    "Here's a summary of the sections on this page:\n\n" .
                    "📁 **Information Collection** — Types of data collected\n\n" .
                    "🎯 **Purpose** — System operations, coordination, AI support, academic research\n\n" .
                    "🔐 **Processing & Storage** — Secure storage, retained only as needed\n\n" .
                    "🚫 **Data Sharing** — Not shared without consent (except when required by law)\n\n" .
                    "⚖️ **Data Subject Rights** — Informed · Access · Correction · Object · Erasure or Blocking\n\n" .
                    "🤖 **AI Disclaimer** — AI recommendations are for decision support only, not replacing official authority\n\n" .
                    "📧 **Contact:** admin@infragovservices.com | dpo@infragovservices.com\n\n" .
                    "📅 Last Updated: February 2026\n\n" .
                    "Which section would you like me to explain in more detail?",
            'tl' => "📸 Nakikita ko ang **Terms and Conditions page** ng InfraGovServices.\n\n" .
                    "Narito ang buod ng mga seksyon:\n\n" .
                    "📁 **Koleksyon ng Impormasyon** — Mga uri ng data na kinokolekta\n\n" .
                    "🎯 **Layunin** — Operasyon ng sistema, koordinasyon, AI support, academic research\n\n" .
                    "🚫 **Pagbabahagi ng Data** — Hindi ibinabahagi nang walang pahintulot\n\n" .
                    "⚖️ **Mga Karapatan:** Malaman · Ma-access · Baguhin · Tumutol · Burahin\n\n" .
                    "🤖 **AI Disclaimer** — Para sa suporta sa desisyon lamang\n\n" .
                    "📅 Huling Na-update: Pebrero 2026\n\n" .
                    "Aling seksyon ang nais mong ipaliwanag?",
        ],
        'about' => [
            'en' => "📸 I can see the **About page** of InfraGovServices.\n\n" .
                    "Here's what this page covers:\n\n" .
                    "🏛️ **Transforming Infrastructure Management** — Intro to CIMMS for Quezon City\n\n" .
                    "✨ **Highlights (4 cards):** Easy Reporting | GPS Tracking | Real-Time Updates | Transparent Tracking\n\n" .
                    "🎯 **Our Purpose** — 4 goals: efficiency, communication, faster response, transparency\n\n" .
                    "📦 **What CIMMS Offers** — 5 features: reporting, tracking, coordination, secure access, dashboards\n\n" .
                    "👥 **For Quezon City Citizens** — exclusively for QC residents\n\n" .
                    "🌟 **Vision & Mission** — service excellence statements\n\n" .
                    "💎 **Core Values:** Efficiency | Transparency | Community First | Security\n\n" .
                    "Is there something specific about the system you'd like to know more about?",
            'tl' => "📸 Nakikita ko ang **About page** ng InfraGovServices.\n\n" .
                    "Narito ang saklaw ng pahinang ito:\n\n" .
                    "🏛️ **Pagbabago ng Pamamahala ng Imprastraktura** — Intro sa CIMMS para sa Quezon City\n\n" .
                    "✨ **Mga Highlight (4 cards):** Madaling Pag-uulat | GPS Tracking | Real-Time Updates | Malinaw na Pagsubaybay\n\n" .
                    "🎯 **Aming Layunin** — 4 layunin: kahusayan, komunikasyon, mas mabilis na tugon, transparency\n\n" .
                    "🌟 **Pananaw at Misyon** — mga pahayag ng kahusayan sa serbisyo\n\n" .
                    "May nais ka pa bang malaman tungkol sa sistema?",
        ],
        'general' => [
            'en' => "📸 I can see a screenshot from the **InfraGovServices portal**.\n\n" .
                    "To help you better, here's what I can assist with based on what you might be seeing:\n\n" .
                    "🔍 **If you see the Navigation Bar** — I can explain any menu item or button\n" .
                    "📋 **If you see a form** — I can guide you through filling it out correctly\n" .
                    "📊 **If you see reports/data** — I can explain what each field and status means\n" .
                    "❌ **If you see an error** — Describe the error message and I'll help troubleshoot\n\n" .
                    "Please describe what you're seeing or what you're trying to do, and I'll provide specific guidance!",
            'tl' => "📸 Nakikita ko ang screenshot mula sa **portal ng InfraGovServices**.\n\n" .
                    "Para mas matulungan kita:\n\n" .
                    "🔍 **Kung nakikita mo ang Navigation Bar** — Maaari kong ipaliwanag ang anumang item\n" .
                    "📋 **Kung nakikita mo ang isang form** — Maaari kitang gabayan sa tamang pagpuno\n" .
                    "📊 **Kung nakikita mo ang mga ulat** — Maaari kong ipaliwanag ang bawat field at status\n" .
                    "❌ **Kung nakikita mo ang isang error** — Ilarawan ang error at tutulungan kita\n\n" .
                    "Mangyaring ilarawan kung ano ang nakikita mo o sinusubukan mong gawin!",
        ],
    ];

    $base = ($responses[$context] ?? $responses['general'])[$isTagalog ? 'tl' : 'en'];

    // If user has a specific (non-generic) question, append a direct bridge
    if (!empty($userMessage) && !isGenericQuestion($userMessage)) {
        $questionBridge = $isTagalog
            ? "\n\n---\n💬 **Tungkol sa iyong tanong:** \"" . htmlspecialchars($userMessage) . "\"\n\n" .
              "Batay sa screenshot na iyon at sa iyong tanong, maaari kang mag-type ng mas detalyadong mensahe para masagot kita nang mas tumpak."
            : "\n\n---\n💬 **Regarding your question:** \"" . htmlspecialchars($userMessage) . "\"\n\n" .
              "Based on the screenshot and your question, feel free to type more details so I can give you a more precise answer.";
        $base .= $questionBridge;
    }

    return $base;
}

// ════════════════════════════════════════════════════════════
//  MAIN ROUTING LOGIC
// ════════════════════════════════════════════════════════════

// ── 1. IMAGE PATH ────────────────────────────────────────────
if ($imageBase64 || !empty($images)) {

    $primaryImage = $imageBase64 ?: ($images[0] ?? null);

    if ($USE_CLAUDE_API && $primaryImage) {
        // Use Claude vision — it will actually look at the image
        $claudeResp = callClaudeVision(
            $CLAUDE_API_KEY, $userMessage, $context, $lang,
            $history, $primaryImage, $PAGE_STRUCTURE, $PAGE_CONTEXT_INFO
        );
        if ($claudeResp) {
            respond($claudeResp);
        }
    }

    // Fallback: context-aware structured response (single, no intent doubling)
    $text = fallbackImageAnalysis($context, $isTagalog, $PAGE_STRUCTURE, $userMessage);
    respond($text);
}

// ── 2. EMPTY MESSAGE ─────────────────────────────────────────
if (empty($userMessage)) {
    respond(bi(
        "Please type a message so I can help you! 😊",
        "Mangyaring mag-type ng mensahe para matulungan kita! 😊",
        $isTagalog
    ));
}

// ── 3. GREETING ──────────────────────────────────────────────
if (isGreeting($userMessage)) {
    respond(getGreetingResponse($context, $isTagalog, $PAGE_CONTEXT_INFO));
}

// ── 4. CLAUDE TEXT (if API key available) ────────────────────
if ($USE_CLAUDE_API) {
    $claudeResp = callClaudeText(
        $CLAUDE_API_KEY, $userMessage, $context, $lang,
        $history, $PAGE_STRUCTURE, $PAGE_CONTEXT_INFO
    );
    if ($claudeResp) {
        respond($claudeResp);
    }
}

// ── 5. LOCAL INTENT DETECTION ────────────────────────────────
$intent = detectIntent($userMessage, $KB);
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

// ── 6. FALLBACK ───────────────────────────────────────────────
respond(getFallbackResponse($context, $isTagalog, $PAGE_CONTEXT_INFO));