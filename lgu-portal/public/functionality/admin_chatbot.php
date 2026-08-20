<?php
/**
 * CIMM Admin Assistant — Chatbot Backend
 * ─────────────────────────────────────────────────────────────
 * Role-aware workflow/decision-support chatbot for logged-in employees
 * (Admin, Super Admin, Office Staff, Engineer, Area Engineer). Mirrors the
 * citizen chatbot's architecture (functionality/chatbot.php) — Claude API
 * primary path, local keyword-matched KB fallback — but:
 *   • English-only (no admin page in this codebase has a language toggle)
 *   • Answers workflow/decision questions ("what does this status mean",
 *     "how do I assign an engineer") rather than "how do I fill this form"
 *   • The system prompt is built per-role from $ROLE_INFO so answers stay
 *     scoped to what the logged-in employee can actually see/do
 *   • Answers live-data questions ("what's the latest report", "requests
 *     from district 1", "which district has the most delayed reports",
 *     "which engineer should I assign this to", "what needs my attention
 *     today") via a whitelisted, read-only, role-scoped SQL layer (see
 *     tryAnswerDataQuestion()) — answered deterministically from the query
 *     result, never handed to Claude to phrase freely, since this app has
 *     no tool-calling loop and letting an LLM free-associate specific
 *     report numbers/names/counts risks hallucinating data that was never
 *     actually in the database. This layer also powers simple decision
 *     support (workload-aware engineer recommendations, a daily digest of
 *     delayed/unassigned/pending-approval items, at-risk-of-delay reports,
 *     and "why is this request stuck") by composing the same read-only
 *     queries rather than asking Claude to reason about live data it
 *     doesn't actually have.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../includes/core/roles.php';
require __DIR__ . '/../../includes/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Any logged-in employee may use this — no role restriction (the ask was
// "help different roles", not "help admins only").
if (empty($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['response' => 'Please log in to use the assistant.', 'aiCardHtml' => null]);
    exit;
}

// ─── API key (same source as the citizen chatbot) ─────────────
require_once __DIR__ . '/../../includes/config/claude_credentials.php';
$CLAUDE_API_KEY = cimm_claude_api_key();
$USE_CLAUDE_API = !empty($CLAUDE_API_KEY);

// ─── Parse request ────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['response' => 'Invalid request.', 'aiCardHtml' => null]);
    exit;
}

$userMessage = mb_substr(strip_tags(trim($data['message'] ?? '')), 0, 1200);
$context     = strtolower(trim($data['context'] ?? 'general'));
$history     = is_array($data['history'] ?? null) ? $data['history'] : [];

$allowedContexts = [
    'dashboard', 'requests', 'current_reports', 'pending_reports', 'archive_reports',
    'schedules', 'case_management', 'road_monitoring', 'feedback', 'user_management',
    'profile', 'general',
];
if (!in_array($context, $allowedContexts)) $context = 'general';

// ─── Role (server-derived — never trust a client-sent role) ───
$role = cimm_current_role(); // 'admin' | 'super admin' | 'office staff' | 'engineer' | 'area engineer'
if ($role === '') $role = 'employee';
$employeeFirstName = trim($_SESSION['employee_first_name'] ?? '') ?: 'there';
$employeeId = (int)($_SESSION['employee_id'] ?? 0);

// Area Engineers are scoped to their own district for every data lookup
// below, same as current_reports.php/sched.php's own district filtering.
$employeeDistrict = '';
if ($role === 'area engineer') {
    $stmt = $conn->prepare('SELECT district FROM engineer_profiles WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $employeeDistrict = trim($row['district'] ?? '');
}

// ─── RESPONSE BUILDER ─────────────────────────────────────────
function respond(string $text): void {
    echo json_encode(['response' => $text, 'aiCardHtml' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════
//  CONVERSATION ANALYSIS HELPERS (same approach as the citizen bot)
// ════════════════════════════════════════════════════════════

function extractConversationTopic(array $history): string {
    if (empty($history)) return '';
    $recent = array_slice($history, -6);
    $text   = mb_strtolower(implode(' ', array_column($recent, 'text')));

    $topicMap = [
        'engineer assignment'    => ['assign','engineer','accept','reassign'],
        'budget & timeline'      => ['budget','cost','starting date','end date','timeline','estimated'],
        'report status'          => ['status','pending','approved','rejected','in progress','delayed','completed'],
        'road monitoring'        => ['road monitoring','rgmap','verify','verified','severity'],
        'case management'        => ['case management','unified case','case stage'],
        'schedules / maintenance'=> ['schedule','maintenance','cprf','energy','capsule','calendar'],
        'exporting reports'      => ['export','csv','pdf','download','report generation'],
        'user management'        => ['user management','role','deactivate','revoke','resend invite'],
        'citizen feedback'       => ['feedback','rating','star','citizen'],
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

function isFollowUp(string $msg): bool {
    $lower = mb_strtolower(trim($msg));
    $phrases = [
        'what about', 'how about', 'and also', 'tell me more', 'explain more',
        'elaborate', 'can you explain', 'more details', 'what else', 'anything else',
        'follow up', 'related to that', 'in that case', 'so what', 'so how',
        'then what', 'what do i do next', 'next step', 'after that', 'and then',
    ];
    foreach ($phrases as $p) {
        if (mb_strpos($lower, $p) !== false) return true;
    }
    return false;
}

function isGreeting(string $message): bool {
    $lower = mb_strtolower(trim($message));
    $greetings = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'howdy', 'greetings', 'yo', 'sup', 'hi there', 'hey there'];
    foreach ($greetings as $g) {
        if (mb_strpos($lower, $g) === 0 || $lower === $g) return true;
    }
    return mb_strlen($lower) <= 6 && mb_strlen($lower) > 0;
}

// ════════════════════════════════════════════════════════════
//  ROLE KNOWLEDGE — what each role can actually see/do
// ════════════════════════════════════════════════════════════

$ROLE_INFO = [
    'super admin' => "**Super Admin** — full system access: all reports (Current/Pending/Archive), Requests (GIS + table), Case Management, Road Monitoring, Schedules, Citizen Feedback, User Management (create/deactivate/revoke/change roles), and CSV/PDF export on every page.",
    'admin'       => "**Admin** — same day-to-day access as Super Admin: all reports, Requests, Case Management, Road Monitoring, Schedules, Citizen Feedback, User Management, and CSV/PDF export on every page.",
    'office staff'=> "**Office Staff** — can view Requests, Current/Pending/Archive Reports, Case Management, Citizen Feedback, and Schedules, and can export CSV/PDF reports. Cannot access User Management, cannot assign engineers, and does not see Road Monitoring (Admin-only).",
    'engineer'    => "**Engineer** — sees only the reports assigned to them across Current/Pending/Archive Reports and Schedules; accepts or is assigned reports via **assign_engineer**, updates budget/starting/estimated-end dates on their own assigned reports. No access to User Management, Road Monitoring, or export tools.",
    'area engineer' => "**Area Engineer** — like Engineer, but scoped to reports and schedules within their assigned **district** only. No access to User Management, Road Monitoring, or export tools.",
    'employee'    => "Logged-in employee with standard CIMM access.",
];
$roleInfo = $ROLE_INFO[$role] ?? $ROLE_INFO['employee'];

// ════════════════════════════════════════════════════════════
//  PAGE STRUCTURE KNOWLEDGE — one entry per admin page
// ════════════════════════════════════════════════════════════

$PAGE_STRUCTURE = [
    'dashboard' => "
## Dashboard (employee.php)
Overview cards (Pending Requests, Active Reports, Completed Tasks, Active Users — the last links to User Management for Admins), Upcoming Maintenance (click a row to highlight it in place; entries pull from both Schedules and in-progress Reports, including CPRF- and Energy-linked schedules shown with 🔗 CPRF / ⚡ Energy badges), and preview cards for Current Reports, Pending Reports, Archive Reports, Recent Activity, Citizen Feedback, Case Management, and Road Monitoring — clicking any item highlights it in place rather than navigating away.",

    'requests' => "
## Requests (requests.php)
Two views: a **GIS map view** (pins per request, click to open detail) and a **table view**. New citizen-submitted requests start here with `approval_status = Pending`. Approving a request creates a resolution + report row that then appears on Current Reports. AI image analysis (TensorFlow.js, client-side) can be triggered per request to flag likely infrastructure issues from the uploaded evidence photos. Admin/Office Staff see an Export CSV/PDF button top-right.",

    'current_reports' => "
## Current Reports (current_reports.php)
Reports that are actively being worked (status: Scheduled / In Progress / Pending Completion). This is where an Admin/Office Staff **assigns an engineer**, sets/edits the **budget**, and sets/edits the **starting date** and **estimated end date**. Engineers see only reports assigned to them here. Reports that originated from a verified Road Monitoring submission carry a Road Monitoring badge.",

    'pending_reports' => "
## Pending Reports (pending_reports.php)
Reports awaiting the next lifecycle step (e.g. awaiting engineer acceptance or scheduling) before they move to Current Reports.",

    'archive_reports' => "
## Archive Reports (archive_reports.php)
Completed reports, kept for historical record and reporting/export.",

    'schedules' => "
## Schedules (sched.php)
Calendar / List / Capsule views of maintenance_schedule + in-progress report timelines. Rows linked to the CPRF integration show a 🔗 CPRF badge; rows imported from the Energy Management System show an ⚡ Energy badge (edits to those sync back automatically). Clicking a schedule highlights it across whichever view is currently active.",

    'case_management' => "
## Case Management (case_management.php)
A read-only, unified view of every case's full lifecycle in one row — from citizen submission through to Current/Pending/Archive Reports — so staff don't have to check four separate pages to find where a case currently sits. Clicking a case's Action link jumps to whichever page currently owns that case.",

    'road_monitoring' => "
## Road Monitoring (road_monitoring.php) — Admin only
Road-specific reports synced in from the separate RGMAP Road Monitoring system. An Admin **verifies** an incoming report here; once verified it also becomes a real CIMM report on Current Reports (assignable, budget/date-editable) while still showing as Verified on this page — the two stay in sync.",

    'feedback' => "
## Citizen Feedback (emp_feedback.php)
Citizen-submitted feedback (5 types: Concern, Acknowledgement, Improvement, Complaint, Suggestion) with star ratings, optionally referencing a specific completed report.",

    'user_management' => "
## User Management (user_management.php) — Admin/Super Admin only
Create employee accounts, assign roles (Area Engineer, Engineer, Office Staff, Admin, Super Admin), resend invites, deactivate accounts, and revoke access — all four of the last actions ask for confirmation first. CSV and PDF export available.",

    'profile' => "
## My Profile (profile.php)
Edit personal info, profile picture, and password/security settings for the logged-in account.",

    'general' => "
## General
The CIMM admin portal manages the full infrastructure-maintenance lifecycle for the LGU: citizen Requests → assignment/scheduling on Current Reports → Archive, plus Case Management (unified view), Road Monitoring (RGMAP integration), Schedules (with CPRF/Energy integrations), Citizen Feedback, and User Management.",
];

$PAGE_CONTEXT_INFO = [
    'dashboard'        => "You're on the **Dashboard** — an overview of pending work, upcoming maintenance, and recent activity across the system.",
    'requests'         => "You're on **Requests** — incoming citizen-submitted infrastructure issues (GIS map + table view).",
    'current_reports'  => "You're on **Current Reports** — actively-worked reports where engineers, budgets, and dates get set.",
    'pending_reports'  => "You're on **Pending Reports** — reports waiting on the next lifecycle step.",
    'archive_reports'  => "You're on **Archive Reports** — completed, historical reports.",
    'schedules'        => "You're on **Schedules** — calendar/list/capsule views of maintenance and report timelines.",
    'case_management'  => "You're on **Case Management** — a unified, read-only view of every case's current stage.",
    'road_monitoring'  => "You're on **Road Monitoring** — RGMAP road reports awaiting or already verified.",
    'feedback'         => "You're on **Citizen Feedback** — feedback and ratings submitted by citizens.",
    'user_management'  => "You're on **User Management** — employee accounts and roles.",
    'profile'          => "You're on **My Profile** — your account settings.",
    'general'          => "You're using the **CIMM Admin Portal**.",
];

// ════════════════════════════════════════════════════════════
//  LOCAL KNOWLEDGE BASE — workflow/decision-support intents
// ════════════════════════════════════════════════════════════

$KB = [
    'assign_engineer' => [
        'keywords' => ['assign', 'engineer', 'accept', 'reassign', 'assigned', 'assignment'],
        'phrases'  => ['how do i assign', 'assign an engineer', 'how to assign', 'who should i assign'],
        'response' =>
            "**Assigning an Engineer** 🛠️\n\n" .
            "1️⃣ Go to **Current Reports** and open the report that needs an engineer.\n" .
            "2️⃣ Pick an engineer from the assignment control — this sets `engineer_id` on the report.\n" .
            "3️⃣ The engineer sees the report appear in their own filtered Current/Pending Reports and Schedules views, and can accept it.\n\n" .
            "💡 *Prioritize by the report's priority level (Critical/High first) and by which engineer already has the lightest active load.*",
    ],

    'budget_dates' => [
        'keywords' => ['budget', 'cost', 'starting date', 'end date', 'estimated', 'timeline', 'due date'],
        'phrases'  => ['edit budget', 'set the budget', 'change the date', 'how do i set the budget'],
        'response' =>
            "**Editing Budget & Dates** 💰\n\n" .
            "On **Current Reports**, open the report and edit:\n" .
            "• **Budget** — the allocated cost for the fix\n" .
            "• **Starting Date** — when work begins\n" .
            "• **Estimated End Date** — target completion; if today passes this date without completion, the report shows as **Delayed** on Schedules and the Dashboard\n\n" .
            "💡 *Reports synced in from Road Monitoring keep the Road Monitoring system updated automatically whenever you change these here — no separate step needed.*",
    ],

    'report_lifecycle' => [
        'keywords' => ['status', 'pending', 'approved', 'rejected', 'in progress', 'delayed', 'completed', 'lifecycle', 'stage'],
        'phrases'  => ['what does status mean', 'what does pending mean', 'what happens after approval', 'report lifecycle'],
        'response' =>
            "**Report Lifecycle & Status Meanings** 📋\n\n" .
            "**Requests → Current Reports → Archive Reports:**\n" .
            "🟡 **Pending** — citizen request awaiting approval\n" .
            "🔵 **Scheduled / In Progress** — approved, engineer assigned, work underway (Current Reports)\n" .
            "🟠 **Pending Completion** — engineer marked work done, awaiting confirmation\n" .
            "🔴 **Delayed** — passed its estimated end date without completing\n" .
            "🟢 **Completed** — moved to Archive Reports\n\n" .
            "**Case Management** shows all of this as one row per case, so you don't need to check every page separately to see where a case currently sits.",
    ],

    'road_monitoring_verify' => [
        'keywords' => ['road monitoring', 'rgmap', 'verify', 'verified', 'verification'],
        'phrases'  => ['how do i verify', 'what does verify do', 'road monitoring badge'],
        'response' =>
            "**Verifying a Road Monitoring Report** 🛣️\n\n" .
            "1️⃣ On **Road Monitoring**, review the incoming RGMAP report's photos and details, then click **Verify**.\n" .
            "2️⃣ It's automatically turned into a real CIMM report on **Current Reports** — AI image analysis runs on its photos, and it's ready for you to assign an engineer, set budget, and set dates, just like any other report.\n" .
            "3️⃣ It keeps a Road Monitoring badge so you always know where it came from, and stays marked **Verified** on the Road Monitoring page — the two pages stay in sync as you update engineer/budget/dates on the CIMM side.",
    ],

    'case_management_overview' => [
        'keywords' => ['case management', 'unified', 'case stage', 'where is this case'],
        'phrases'  => ['what is case management', 'how does case management work'],
        'response' =>
            "**Case Management** 🗂️\n\n" .
            "A single, read-only table that shows every case's current lifecycle stage in one place — instead of checking Requests, Current Reports, Pending Reports, and Archive Reports separately to find where a case sits. Click a case's action link and it takes you straight to whichever page currently owns it.",
    ],

    'schedule_integration' => [
        'keywords' => ['cprf', 'energy', 'schedule integration', 'capsule', 'calendar view', 'maintenance schedule'],
        'phrases'  => ['what does cprf mean', 'what does the energy badge mean', 'shared schedule'],
        'response' =>
            "**Schedules & CPRF/Energy Integration** 📅\n\n" .
            "**Schedules** supports 3 views (Calendar / List / Capsule). Two integration badges appear on rows:\n" .
            "🔗 **CPRF** — this schedule is shared with the CPRF facility system\n" .
            "⚡ **Energy** — imported from the Energy Management System; edits here sync back automatically\n\n" .
            "Clicking a schedule (including from the Dashboard's Upcoming Maintenance) highlights the matching item in whichever view you currently have open.",
    ],

    'export_reports' => [
        'keywords' => ['export', 'csv', 'pdf', 'download report', 'generate report'],
        'phrases'  => ['how do i export', 'how to download', 'generate a report', 'export as pdf', 'export as csv'],
        'response' =>
            "**Exporting Reports** 📤\n\n" .
            "Admins and Office Staff see an **Export** button top-right on Requests, Current/Pending/Archive Reports, Citizen Feedback, Road Monitoring, Schedules, Case Management, and User Management.\n\n" .
            "1️⃣ Click **Export**, pick a **date range**.\n" .
            "2️⃣ Choose **CSV** or **PDF** (PDF opens a print-styled page — use your browser's print dialog to save it as a PDF).\n" .
            "3️⃣ Confirm your password when prompted (a one-time security check before the download starts).",
    ],

    'user_management' => [
        'keywords' => ['user management', 'role', 'deactivate', 'revoke', 'resend invite', 'create account', 'employee account'],
        'phrases'  => ['how do i create an account', 'how do i change someone\'s role', 'deactivate an account'],
        'response' => $role === 'admin' || $role === 'super admin'
            ? "**User Management** 👥\n\n" .
              "Create employee accounts, set a role (Area Engineer, Engineer, Office Staff, Admin, Super Admin), **resend invite** to a not-yet-activated account, **deactivate** an account, or **revoke** access — each of these asks you to confirm first. CSV and PDF export are both available here."
            : "User Management is restricted to Admin and Super Admin accounts, so it isn't available from your **" . ucwords($role) . "** role.",
    ],

    'ai_analysis' => [
        'keywords' => ['ai analysis', 'tensorflow', 'image analysis', 'analyze image', 'detect'],
        'phrases'  => ['how does the ai work', 'what does ai analysis do'],
        'response' =>
            "**AI Image Analysis** 🤖\n\n" .
            "On **Requests**, evidence photos can be run through a client-side TensorFlow.js model that flags likely infrastructure issue types from the image — this helps prioritize review, it doesn't auto-approve anything. The same analysis automatically runs on photos from a Road Monitoring report the moment it's verified.",
    ],
];

// ════════════════════════════════════════════════════════════
//  INTENT DETECTION — phrase + keyword weighted scoring
// ════════════════════════════════════════════════════════════

function detectIntent(string $message, array $kb): ?string {
    $lowerMsg = mb_strtolower(trim($message));
    $scores   = [];

    foreach ($kb as $intent => $entry) {
        $score = 0;
        foreach ($entry['phrases'] as $phrase) {
            if (mb_strpos($lowerMsg, mb_strtolower($phrase)) !== false) $score += 4;
        }
        foreach ($entry['keywords'] as $keyword) {
            if (mb_strpos($lowerMsg, mb_strtolower($keyword)) !== false) {
                $len = mb_strlen($keyword);
                $score += $len >= 8 ? 3 : ($len >= 5 ? 2 : 1);
            }
        }
        if ($score > 0) $scores[$intent] = $score;
    }

    if (empty($scores)) return null;
    arsort($scores);
    reset($scores);
    return current($scores) >= 2 ? key($scores) : null;
}

// ════════════════════════════════════════════════════════════
//  GREETING / FALLBACK
// ════════════════════════════════════════════════════════════

function getGreetingResponse(string $context, array $pageContextInfo, string $firstName, string $roleInfo): string {
    $hour = (int) date('H');
    $timeGreet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $pageDesc  = $pageContextInfo[$context] ?? $pageContextInfo['general'];

    return "{$timeGreet}, {$firstName}! 👋 I'm your **CIMM Admin Assistant**.\n\n{$pageDesc}\n\n" .
           "Your access level: {$roleInfo}\n\n" .
           "**I can help with:**\n" .
           "• 🛠️ Assigning engineers, budgets, and dates\n" .
           "• 📋 Understanding report statuses and the case lifecycle\n" .
           "• 🛣️ Road Monitoring verification & CIMM sync\n" .
           "• 📅 CPRF/Energy schedule integrations\n" .
           "• 📤 Exporting reports\n" .
           "• 👥 User Management (if your role has access)\n" .
           "• 📊 Live counts, trends, and workload comparisons\n" .
           "• 🎯 Decisions — \"who should I assign this to\", \"what needs my attention today\"\n\n" .
           "What would you like help with?";
}

function getFallbackResponse(string $context, array $pageContextInfo, string $recentTopic = ''): string {
    $pageDesc  = $pageContextInfo[$context] ?? $pageContextInfo['general'];
    $topicHint = $recentTopic
        ? "\n\n💭 *It looks like we were discussing **{$recentTopic}** — want to know more about that?*"
        : '';

    return "I'm not sure I fully understood that. 🙏\n\n{$pageDesc}\n\n" .
           "**I can help with:**\n" .
           "• Assigning engineers, budgets, and dates\n" .
           "• Report statuses and the case lifecycle\n" .
           "• Road Monitoring verification\n" .
           "• CPRF/Energy schedule integrations\n" .
           "• Exporting reports\n" .
           "• User Management\n" .
           "• Live counts, trends, and workload comparisons\n" .
           "• Decisions like \"who should I assign this to\"" .
           $topicHint .
           "\n\nTry rephrasing your question, or ask about one of the topics above.";
}

// ════════════════════════════════════════════════════════════
//  CLAUDE API — TEXT (with role-aware system prompt)
// ════════════════════════════════════════════════════════════

function callClaudeText(
    string $apiKey,
    string $userMessage,
    string $context,
    array  $history,
    array  $pageStructure,
    array  $pageContextInfo,
    string $roleInfo,
    string $firstName,
    string $conversationTopic = ''
): ?string {

    $pageStruct = $pageStructure[$context]     ?? $pageStructure['general'];
    $pageCtx    = $pageContextInfo[$context]   ?? $pageContextInfo['general'];
    $topicHint  = $conversationTopic ? "\n- Ongoing conversation topic: {$conversationTopic}" : '';

    $systemPrompt = "You are the **CIMM Admin Assistant** — an AI assistant embedded in the CIMM (Community Infrastructure Maintenance Management) admin portal, helping LGU employees make decisions and understand their workflow.

## Persona
- Friendly, professional, and concise
- Focused on WORKFLOW and DECISIONS (\"what should I do next\", \"what does this status mean\", \"who should I assign this to\") — NOT step-by-step form-filling tutorials
- Never invents information about specific database records — you have no live database access, so never claim to know the contents of a specific report, request, or account; instead explain the general process/UI
- Responds only in English

## Current user
- Name: {$firstName}
- Role: {$roleInfo}
- IMPORTANT: only describe features/pages this role actually has access to. If asked about something outside their role's access (e.g. User Management for an Engineer), say so plainly and don't explain how to use it.

## Current session context
- Active page: **{$context}**
- {$pageCtx}{$topicHint}

## Page structure reference
{$pageStruct}

## Scope — ONLY answer questions about:
1. CIMM admin workflow: requests → assignment → current reports → archive
2. Assigning engineers, setting budgets/dates, understanding report statuses
3. Case Management (unified case view) and Road Monitoring (RGMAP integration + verify-to-CIMM-report sync)
4. Schedules (Calendar/List/Capsule views) and the CPRF/Energy integration badges
5. Exporting reports (CSV/PDF)
6. User Management (roles, invites, deactivation) — Admin/Super Admin only
7. General navigation of the admin portal
8. Live counts, trends, and workload comparisons pulled from the database (handled deterministically before this prompt runs — see the data-question layer), and decision support like \"who should I assign this to\" or \"what needs my attention today\"

## Rules
1. If asked something outside scope (e.g. weather, unrelated topics, or a request to look up specific live data you don't have access to), politely redirect: 'I can help with CIMM workflow and decisions — let me know if you have a question about that!'
2. Cite exact page names and button/field labels from the page structure reference above
3. For multi-step guidance, use numbered lists
4. Keep responses under 300 words unless genuinely necessary
5. If this is a follow-up, acknowledge it naturally
6. Never fabricate specific report numbers, budgets, names, or dates — you don't have live data access";

    $messages = [];
    foreach (array_slice($history, -8) as $h) {
        if (!empty($h['text']) && !empty($h['type'])) {
            $messages[] = [
                'role'    => ($h['type'] === 'user') ? 'user' : 'assistant',
                'content' => mb_substr($h['text'], 0, 800),
            ];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return claudeRequest($apiKey, $systemPrompt, $messages, 700);
}

// ════════════════════════════════════════════════════════════
//  CLAUDE HTTP REQUEST (identical mechanics to the citizen chatbot)
// ════════════════════════════════════════════════════════════

function claudeRequest(string $apiKey, string $systemPrompt, array $messages, int $maxTokens = 700): ?string {
    $payload = [
        'model'      => 'claude-sonnet-5',
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
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $decoded = json_decode($response, true);
        return $decoded['content'][0]['text'] ?? null;
    }

    error_log('CIMM admin chatbot: Claude API call failed — HTTP ' . $httpCode
        . ($curlErr ? " (curl: {$curlErr})" : '')
        . ($response ? ' — ' . substr($response, 0, 300) : ''));
    return null;
}

// ════════════════════════════════════════════════════════════
//  LIVE DATA QUESTIONS — whitelisted, read-only, role-scoped lookups
// ════════════════════════════════════════════════════════════
// Answered deterministically straight from the query result (see the
// docblock at the top of this file for why this never goes through Claude).
// Each handler below applies the same visibility rules the actual admin
// pages use: Engineers see only their own assigned reports, Area Engineers
// only their own district, Office Staff/Engineers never see Road Monitoring
// or User Management data.

function dataq_reportLabel(array $r): string {
    return '#REP-' . str_pad((string)$r['rep_id'], 3, '0', STR_PAD_LEFT) . ' — ' . ($r['infrastructure'] ?: 'Infrastructure') . ' at ' . ($r['location'] ?: 'an unspecified location');
}
function dataq_requestLabel(array $r): string {
    return '#REQ-' . str_pad((string)$r['req_id'], 3, '0', STR_PAD_LEFT) . ' — ' . ($r['infrastructure'] ?: 'Infrastructure') . ' at ' . ($r['location'] ?: 'an unspecified location');
}

/**
 * Parses a relative date-range phrase out of a lowercased message and
 * returns a ready-to-append SQL condition on $column, plus a human label
 * for the response text. Returns ['sql' => '', 'label' => ''] when no
 * recognized date phrase is present (i.e. don't filter by date at all).
 */
function dataq_dateRangeFilter(string $lower, string $column): array {
    if (preg_match('/\btoday\b/i', $lower)) {
        return ['sql' => " AND DATE({$column}) = CURDATE()", 'label' => 'today'];
    }
    if (preg_match('/\bthis week\b/i', $lower)) {
        return ['sql' => " AND YEARWEEK({$column}, 1) = YEARWEEK(CURDATE(), 1)", 'label' => 'this week'];
    }
    if (preg_match('/\blast month\b/i', $lower)) {
        return ['sql' => " AND YEAR({$column}) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH({$column}) = MONTH(CURDATE() - INTERVAL 1 MONTH)", 'label' => 'last month'];
    }
    if (preg_match('/\bthis month\b/i', $lower)) {
        return ['sql' => " AND YEAR({$column}) = YEAR(CURDATE()) AND MONTH({$column}) = MONTH(CURDATE())", 'label' => 'this month'];
    }
    if (preg_match('/\blast (\d{1,3}) days?\b/i', $lower, $m)) {
        $n = (int)$m[1];
        return ['sql' => " AND {$column} >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY)", 'label' => "the last {$n} days"];
    }
    if (preg_match('/\bpast week\b/i', $lower)) {
        return ['sql' => " AND {$column} >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", 'label' => 'the past week'];
    }
    return ['sql' => '', 'label' => ''];
}

/**
 * @return string|null  A ready-to-send answer, or null if this message
 *                       doesn't match any recognized data question.
 */
function tryAnswerDataQuestion(mysqli $conn, string $message, string $role, int $employeeId, string $employeeDistrict): ?string {
    $lower = mb_strtolower(trim($message));
    $isEngineer     = ($role === 'engineer');
    $isAreaEngineer = ($role === 'area engineer');
    $canSeeRoadMonitoring = in_array($role, ['admin', 'super admin'], true);
    $canSeeUserManagement = in_array($role, ['admin', 'super admin'], true);

    // Engineers only ever see reports assigned to them; Area Engineers only
    // ever see requests/reports in their own district — same gates
    // current_reports.php/sched.php already enforce.
    $engFilter = $isEngineer ? ' AND rp.engineer_id = ' . $employeeId : '';
    $reqDistrictFilter = '';
    $repDistrictFilter = '';
    if ($isAreaEngineer) {
        if ($employeeDistrict === '') return "Your profile has no district set, so I can't look up district-scoped data for you yet — ask an Admin to set it on your profile.";
        $safeDistrict = $conn->real_escape_string($employeeDistrict);
        $reqDistrictFilter = " AND req.district = '{$safeDistrict}'";
        $repDistrictFilter = " AND COALESCE(req.district,'') = '{$safeDistrict}'";
    }

    // ── 1a. "What's blocking REQ-xxx?" — explain the current bottleneck.
    //    Checked BEFORE the generic REQ lookup below so a blocking-intent
    //    question about a specific request gets the bottleneck explanation
    //    rather than just the plain status card. ─────────────────────────────
    if ((str_contains($lower, 'blocking') || str_contains($lower, 'stuck') || str_contains($lower, 'why is'))
        && (preg_match('/\bREQ-?\s*0*(\d+)\b/i', $message, $m) || preg_match('/\brequest\s*#?\s*(\d+)\b/i', $lower, $m))) {
        $reqId = (int)$m[1];
        $stmt = $conn->prepare("SELECT req_id, infrastructure, location, approval_status, district FROM requests WHERE req_id = ?" . ($isAreaEngineer ? " AND district = '{$conn->real_escape_string($employeeDistrict)}'" : '') . " LIMIT 1");
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) return "I couldn't find Request #REQ-" . str_pad((string)$reqId, 3, '0', STR_PAD_LEFT) . " — either it doesn't exist, or it's outside what your role can see.";
        if ($req['approval_status'] === 'Pending') return "**" . dataq_requestLabel($req) . "** is still **awaiting approval** on Requests — that's the current blocker.";
        if ($req['approval_status'] === 'Rejected') return "**" . dataq_requestLabel($req) . "** was **rejected** — it won't move forward unless resubmitted.";

        $stmt = $conn->prepare("
            SELECT rp.rep_id, res.status AS resolution_status, rp.engineer_id, rp.estimated_end_date,
                   CONCAT(e.first_name,' ',e.last_name) AS engineer_name
            FROM request_resolutions res
            LEFT JOIN reports rp ON rp.res_id = res.res_id
            LEFT JOIN employees e ON e.user_id = rp.engineer_id
            WHERE res.req_id = ?
            ORDER BY res.res_id DESC LIMIT 1
        ");
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$res) return "**" . dataq_requestLabel($req) . "** was approved but doesn't have a report yet — that's the current blocker.";

        $status = $res['resolution_status'];
        $isDelayed = !empty($res['estimated_end_date']) && strtotime($res['estimated_end_date']) < strtotime('today') && !in_array($status, ['Completed', 'Cancelled', 'Pending Completion'], true);
        if ($isDelayed) return "**" . dataq_requestLabel($req) . "** is **Delayed** — it passed its estimated end date ({$res['estimated_end_date']}) without completing.";
        if (empty($res['engineer_id'])) return "**" . dataq_requestLabel($req) . "** is approved but has **no engineer assigned yet** — that's the current blocker.";
        if ($status === 'Pending Admin Approval') return "**" . dataq_requestLabel($req) . "** is done by the engineer and **awaiting admin approval** to be marked complete — that's the current blocker.";
        if (in_array($status, ['Completed', 'Cancelled'], true)) return "**" . dataq_requestLabel($req) . "** is already **{$status}** — nothing is blocking it.";
        $eng = trim($res['engineer_name'] ?? '');
        return "**" . dataq_requestLabel($req) . "** is **{$status}**, assigned to **" . ($eng !== '' ? $eng : 'an engineer') . "** — actively moving, no blocker right now.";
    }

    // ── 1. Specific #REQ-xxx / #REP-xxx / "report 12" / "request 12" lookup ──
    if (preg_match('/\bREP-?\s*0*(\d+)\b/i', $message, $m) || (preg_match('/\breport\s*#?\s*(\d+)\b/i', $lower, $m) && !preg_match('/\brequests?\b/i', $lower))) {
        $repId = (int)$m[1];
        $stmt = $conn->prepare("
            SELECT rp.rep_id, req.infrastructure, req.location, res.status AS resolution_status,
                   rp.priority_lvl, rp.budget, rp.starting_date, rp.estimated_end_date,
                   CONCAT(e.first_name,' ',e.last_name) AS engineer_name, rp.engineer_id
            FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            LEFT JOIN employees e ON e.user_id = rp.engineer_id
            WHERE rp.rep_id = ?{$engFilter}{$repDistrictFilter}
            LIMIT 1
        ");
        $stmt->bind_param('i', $repId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) return "I couldn't find Report #REP-" . str_pad((string)$repId, 3, '0', STR_PAD_LEFT) . " — either it doesn't exist, or it's outside what your role can see.";
        $eng = trim($r['engineer_name'] ?? '');
        return "**" . dataq_reportLabel($r) . "**\n"
            . "• Status: {$r['resolution_status']}\n"
            . "• Priority: " . ($r['priority_lvl'] ?: 'Low') . "\n"
            . "• Engineer: " . ($eng !== '' ? $eng : 'Unassigned') . "\n"
            . "• Budget: ₱" . number_format((float)$r['budget'], 2) . "\n"
            . "• Schedule: {$r['starting_date']} → {$r['estimated_end_date']}";
    }

    if (preg_match('/\bREQ-?\s*0*(\d+)\b/i', $message, $m) || preg_match('/\brequest\s*#?\s*(\d+)\b/i', $lower, $m)) {
        $reqId = (int)$m[1];
        $stmt = $conn->prepare("SELECT req_id, infrastructure, location, issue, approval_status, district, created_at FROM requests WHERE req_id = ?" . ($isAreaEngineer ? " AND district = '{$conn->real_escape_string($employeeDistrict)}'" : '') . " LIMIT 1");
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) return "I couldn't find Request #REQ-" . str_pad((string)$reqId, 3, '0', STR_PAD_LEFT) . " — either it doesn't exist, or it's outside what your role can see.";
        return "**" . dataq_requestLabel($r) . "**\n"
            . "• Status: {$r['approval_status']}\n"
            . "• Issue: " . ($r['issue'] ?: '—') . "\n"
            . "• District: " . ($r['district'] ?: '—') . "\n"
            . "• Submitted: " . date('M j, Y', strtotime($r['created_at']));
    }

    // ── 2. Latest / most recent report ──────────────────────────────────────
    if (preg_match('/\b(latest|newest|most recent|last)\b/i', $lower) && preg_match('/\breports?\b/i', $lower) && !preg_match('/\brequests?\b/i', $lower)) {
        $stmt = $conn->prepare("
            SELECT rp.rep_id, req.infrastructure, req.location, res.status AS resolution_status,
                   rp.priority_lvl, CONCAT(e.first_name,' ',e.last_name) AS engineer_name, rp.engineer_id
            FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            LEFT JOIN employees e ON e.user_id = rp.engineer_id
            WHERE 1=1{$engFilter}{$repDistrictFilter}
            ORDER BY rp.rep_id DESC LIMIT 1
        ");
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) return "There aren't any reports " . ($isEngineer ? 'assigned to you ' : '') . "yet.";
        $eng = trim($r['engineer_name'] ?? '');
        return "The latest report is **" . dataq_reportLabel($r) . "**, status **{$r['resolution_status']}**, priority **" . ($r['priority_lvl'] ?: 'Low') . "**, " . ($eng !== '' ? "assigned to **{$eng}**." : "not yet assigned to an engineer.");
    }

    // ── 3. Latest / most recent request ─────────────────────────────────────
    if (preg_match('/\b(latest|newest|most recent|last)\b/i', $lower) && preg_match('/\brequests?\b/i', $lower)) {
        $stmt = $conn->prepare("SELECT req_id, infrastructure, location, approval_status, district, created_at FROM requests WHERE 1=1{$reqDistrictFilter} ORDER BY req_id DESC LIMIT 1");
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) return "There aren't any requests yet.";
        return "The latest request is **" . dataq_requestLabel($r) . "**, currently **{$r['approval_status']}**, submitted " . date('M j, Y', strtotime($r['created_at'])) . ".";
    }

    // ── 4. Requests / reports from a specific district ─────────────────────
    if (preg_match('/district\s*(\d+)/i', $lower, $m)) {
        if ($isAreaEngineer && $employeeDistrict !== 'District ' . $m[1]) {
            return "You're scoped to **{$employeeDistrict}** — I can't look up other districts for you.";
        }
        $district = 'District ' . $m[1];
        $wantReports = preg_match('/\breports?\b/i', $lower) && !preg_match('/\brequests?\b/i', $lower);
        if ($wantReports) {
            $stmt = $conn->prepare("
                SELECT rp.rep_id, req.infrastructure, req.location, res.status AS resolution_status
                FROM reports rp
                LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
                LEFT JOIN requests req ON req.req_id = res.req_id
                WHERE req.district = ?{$engFilter}
                ORDER BY rp.rep_id DESC LIMIT 8
            ");
            $stmt->bind_param('s', $district);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($rows)) return "No reports found in **{$district}**" . ($isEngineer ? ' assigned to you' : '') . ".";
            $lines = array_map(fn($r) => '• ' . dataq_reportLabel($r) . " — {$r['resolution_status']}", $rows);
            return "Reports in **{$district}** (" . count($rows) . " shown):\n" . implode("\n", $lines);
        } else {
            $stmt = $conn->prepare("SELECT req_id, infrastructure, location, approval_status FROM requests WHERE district = ? ORDER BY req_id DESC LIMIT 8");
            $stmt->bind_param('s', $district);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($rows)) return "No requests found in **{$district}**.";
            $lines = array_map(fn($r) => '• ' . dataq_requestLabel($r) . " — {$r['approval_status']}", $rows);
            return "Requests in **{$district}** (" . count($rows) . " shown):\n" . implode("\n", $lines);
        }
    }

    // ── 5. Counts by status ("how many pending requests", "how many
    //    current/pending/archived reports", etc.) — Road Monitoring and
    //    User Management are checked FIRST since their trigger phrases
    //    ("road monitoring report", "engineer account") also contain the
    //    generic word "report"/substrings the request/report branches below
    //    would otherwise swallow first. ───────────────────────────────────
    if (preg_match('/\bhow many\b/i', $lower) || preg_match('/\bcount\b/i', $lower)) {
        if ($canSeeRoadMonitoring && (str_contains($lower, 'road monitoring') || str_contains($lower, 'rgmap'))) {
            require_once __DIR__ . '/../../includes/api/rgmap_road_reports.php';
            rgmap_road_reports_ensure_schema($conn);
            $c = (int)$conn->query("SELECT COUNT(*) c FROM rgmap_road_reports WHERE verification_status != 'Verified'")->fetch_assoc()['c'];
            return "There " . ($c === 1 ? 'is' : 'are') . " **{$c}** Road Monitoring report" . ($c === 1 ? '' : 's') . " awaiting verification.";
        }
        if ($canSeeUserManagement && (str_contains($lower, 'engineer') || str_contains($lower, 'employee') || str_contains($lower, 'account') || str_contains($lower, 'staff') || str_contains($lower, 'user'))) {
            $roleFilter = '';
            foreach (['area engineer' => 'Area Engineer', 'engineer' => 'Engineer', 'office staff' => 'Office Staff', 'super admin' => 'Super Admin', 'admin' => 'Admin'] as $kw => $val) {
                if (str_contains($lower, $kw)) { $roleFilter = $val; break; }
            }
            if ($roleFilter) {
                $stmt = $conn->prepare('SELECT COUNT(*) c FROM employees WHERE role = ?');
                $stmt->bind_param('s', $roleFilter);
                $stmt->execute();
                $c = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                return "There " . ($c === 1 ? 'is' : 'are') . " **{$c}** {$roleFilter} account" . ($c === 1 ? '' : 's') . ".";
            }
            $c = (int)$conn->query('SELECT COUNT(*) c FROM employees')->fetch_assoc()['c'];
            return "There are **{$c}** employee accounts in total.";
        }
        if (preg_match('/\brequest/i', $lower)) {
            $status = null;
            foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $kw => $val) {
                if (str_contains($lower, $kw)) { $status = $val; break; }
            }
            $dateRange = dataq_dateRangeFilter($lower, 'created_at');
            if ($status) {
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM requests WHERE approval_status = ?" . $reqDistrictFilter . $dateRange['sql']);
                $stmt->bind_param('s', $status);
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM requests WHERE 1=1" . $reqDistrictFilter . $dateRange['sql']);
            }
            $stmt->execute();
            $c = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            return "There " . ($c === 1 ? 'is' : 'are') . " **{$c}** " . ($status ? strtolower($status) . ' ' : '') . "request" . ($c === 1 ? '' : 's') . ($isAreaEngineer ? " in {$employeeDistrict}" : '') . ($dateRange['label'] ? " from {$dateRange['label']}" : '') . ".";
        }
        if (preg_match('/\breport/i', $lower)) {
            $statusFilter = '';
            $label = '';
            if (str_contains($lower, 'current') || str_contains($lower, 'progress')) { $statusFilter = " AND res.status IN ('Approved','Pending Admin Approval')"; $label = 'current '; }
            elseif (str_contains($lower, 'pending')) { $statusFilter = " AND res.status IN ('Scheduled','In Progress','Pending','','Pending Completion')"; $label = 'pending '; }
            elseif (str_contains($lower, 'archiv') || str_contains($lower, 'complet')) { $statusFilter = " AND res.status IN ('Completed','Cancelled')"; $label = 'archived '; }
            $dateRange = dataq_dateRangeFilter($lower, 'rp.created_at');
            $stmt = $conn->prepare("
                SELECT COUNT(*) c FROM reports rp
                LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
                LEFT JOIN requests req ON req.req_id = res.req_id
                WHERE 1=1{$statusFilter}{$engFilter}{$repDistrictFilter}{$dateRange['sql']}
            ");
            $stmt->execute();
            $c = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            return "There " . ($c === 1 ? 'is' : 'are') . " **{$c}** {$label}report" . ($c === 1 ? '' : 's') . ($isEngineer ? ' assigned to you' : '') . ($isAreaEngineer ? " in {$employeeDistrict}" : '') . ($dateRange['label'] ? " from {$dateRange['label']}" : '') . ".";
        }
    }

    // ── 6. Most common infrastructure type ──────────────────────────────────
    if ((str_contains($lower, 'most common') || str_contains($lower, 'top infrastructure') || str_contains($lower, 'top issue'))
        && (str_contains($lower, 'infrastructure') || str_contains($lower, 'issue') || str_contains($lower, 'type'))) {
        $stmt = $conn->prepare("SELECT infrastructure, COUNT(*) c FROM requests WHERE 1=1{$reqDistrictFilter} GROUP BY infrastructure ORDER BY c DESC LIMIT 5");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return "There's no request data yet to find a most-common infrastructure type.";
        $lines = array_map(fn($r) => "• {$r['infrastructure']} — {$r['c']} request" . ($r['c'] == 1 ? '' : 's'), $rows);
        return "**Most common infrastructure types**" . ($isAreaEngineer ? " in {$employeeDistrict}" : '') . ":\n" . implode("\n", $lines);
    }

    // ── 7. District with the most delayed reports (Admin/Super Admin/Office
    //    Staff see all districts; Area Engineers/Engineers naturally get
    //    results scoped to what they can already see via the existing
    //    $engFilter/$repDistrictFilter). ─────────────────────────────────────
    if (str_contains($lower, 'district') && (str_contains($lower, 'delayed') || str_contains($lower, 'most delay'))) {
        $stmt = $conn->prepare("
            SELECT req.district AS district, COUNT(*) c
            FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            WHERE rp.estimated_end_date IS NOT NULL
              AND rp.estimated_end_date < CURDATE()
              AND res.status NOT IN ('Completed','Cancelled','Pending Completion')
              {$engFilter}{$repDistrictFilter}
            GROUP BY req.district
            ORDER BY c DESC
            LIMIT 5
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return "No districts currently have delayed reports — nice! 🎉";
        $lines = array_map(fn($r) => '• ' . ($r['district'] ?: 'Unspecified') . " — {$r['c']} delayed", $rows);
        return "**Delayed reports by district:**\n" . implode("\n", $lines);
    }

    // ── 8. Engineer workload comparison (busiest / lightest load) — decision
    //    support for who to assign next. Admin/Super Admin/Office Staff only
    //    (Engineers/Area Engineers only ever see their own single workload). ──
    if (str_contains($lower, 'engineer')
        && (str_contains($lower, 'most reports') || str_contains($lower, 'busiest') || str_contains($lower, 'workload')
            || str_contains($lower, 'lightest') || str_contains($lower, 'least load') || str_contains($lower, 'capacity'))) {
        if (!in_array($role, ['admin', 'super admin', 'office staff'], true)) {
            return "Comparing engineer workloads is available to Admin, Super Admin, and Office Staff roles.";
        }
        $ascending = str_contains($lower, 'lightest') || str_contains($lower, 'least load') || str_contains($lower, 'capacity');
        $stmt = $conn->prepare("
            SELECT e.user_id, CONCAT(e.first_name,' ',e.last_name) AS name,
                (SELECT COUNT(*) FROM reports rp2
                   LEFT JOIN request_resolutions res2 ON res2.res_id = rp2.res_id
                   WHERE rp2.engineer_id = e.user_id AND res2.status NOT IN ('Completed','Cancelled')
                ) AS active_count
            FROM employees e
            WHERE e.role IN ('Engineer','Area Engineer')
            ORDER BY active_count " . ($ascending ? 'ASC' : 'DESC') . "
            LIMIT 5
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return "There are no Engineer or Area Engineer accounts yet.";
        $lines = array_map(fn($r) => "• {$r['name']} — {$r['active_count']} active report" . ($r['active_count'] == 1 ? '' : 's'), $rows);
        $heading = $ascending ? '**Engineers with the most capacity right now:**' : '**Busiest engineers right now:**';
        return "{$heading}\n" . implode("\n", $lines);
    }

    // ── 9. Citizen Feedback aggregates ──────────────────────────────────────
    if (str_contains($lower, 'feedback') && (str_contains($lower, 'average') || str_contains($lower, 'rating') || str_contains($lower, 'breakdown') || str_contains($lower, 'star'))) {
        $dateRange = dataq_dateRangeFilter($lower, 'created_at');
        if (str_contains($lower, 'breakdown') || str_contains($lower, 'type')) {
            $stmt = $conn->prepare("SELECT feedback_type, COUNT(*) c FROM citizen_feedback WHERE 1=1{$dateRange['sql']} GROUP BY feedback_type ORDER BY c DESC");
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($rows)) return "No feedback submitted yet" . ($dateRange['label'] ? " for {$dateRange['label']}" : '') . ".";
            $lines = array_map(fn($r) => "• {$r['feedback_type']} — {$r['c']}", $rows);
            return "**Feedback breakdown by type" . ($dateRange['label'] ? " ({$dateRange['label']})" : '') . ":**\n" . implode("\n", $lines);
        }
        $stmt = $conn->prepare("SELECT AVG(rating) avg_rating, COUNT(*) c FROM citizen_feedback WHERE 1=1{$dateRange['sql']}");
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$r['c'] === 0) return "No feedback submitted yet" . ($dateRange['label'] ? " for {$dateRange['label']}" : '') . ".";
        return "Average feedback rating" . ($dateRange['label'] ? " for {$dateRange['label']}" : '') . " is **" . number_format((float)$r['avg_rating'], 1) . "/5** across **{$r['c']}** submission" . ((int)$r['c'] === 1 ? '' : 's') . ".";
    }

    // ── 10. Decision support: who should I assign this to? ─────────────────
    if ((str_contains($lower, 'who should i assign') || str_contains($lower, 'which engineer should i assign')
        || str_contains($lower, 'best engineer') || str_contains($lower, 'recommend an engineer') || str_contains($lower, 'who has capacity'))) {
        if (!in_array($role, ['admin', 'super admin', 'office staff'], true)) {
            return "Assignment recommendations are available to Admin, Super Admin, and Office Staff roles.";
        }
        $districtFilter = '';
        if (preg_match('/district\s*(\d+)/i', $lower, $m)) {
            $wantDistrict = 'District ' . $m[1];
            $safe = $conn->real_escape_string($wantDistrict);
            $districtFilter = " AND (ep.district = '{$safe}' OR e.role = 'Engineer')";
        }
        $stmt = $conn->prepare("
            SELECT e.user_id, CONCAT(e.first_name,' ',e.last_name) AS name, e.role,
                (SELECT COUNT(*) FROM reports rp2
                   LEFT JOIN request_resolutions res2 ON res2.res_id = rp2.res_id
                   WHERE rp2.engineer_id = e.user_id AND res2.status NOT IN ('Completed','Cancelled')
                ) AS active_count
            FROM employees e
            LEFT JOIN engineer_profiles ep ON ep.user_id = e.user_id
            WHERE e.role IN ('Engineer','Area Engineer'){$districtFilter}
            ORDER BY active_count ASC
            LIMIT 3
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return "I couldn't find any engineers to recommend" . ($districtFilter ? ' for that district' : '') . " — check User Management.";
        $top = $rows[0];
        $others = array_slice($rows, 1);
        $answer = "**{$top['name']}** ({$top['role']}) currently has the lightest load — **{$top['active_count']}** active report" . ($top['active_count'] == 1 ? '' : 's') . ". Recommend assigning to them.";
        if (!empty($others)) {
            $lines = array_map(fn($r) => "• {$r['name']} — {$r['active_count']} active", $others);
            $answer .= "\n\nOther options:\n" . implode("\n", $lines);
        }
        return $answer;
    }

    // ── 11. Daily digest / "what needs my attention" ────────────────────────
    if (str_contains($lower, 'need my attention') || str_contains($lower, 'needs attention') || str_contains($lower, 'daily digest')
        || str_contains($lower, 'anything urgent') || str_contains($lower, 'focus on today') || str_contains($lower, 'what should i focus')) {
        $delayed = $conn->query("
            SELECT COUNT(*) c FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            WHERE rp.estimated_end_date IS NOT NULL AND rp.estimated_end_date < CURDATE()
              AND res.status NOT IN ('Completed','Cancelled','Pending Completion'){$engFilter}{$repDistrictFilter}
        ")->fetch_assoc()['c'];
        $pendingApproval = $conn->query("
            SELECT COUNT(*) c FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            WHERE res.status = 'Pending Admin Approval'{$engFilter}{$repDistrictFilter}
        ")->fetch_assoc()['c'];
        $lines = [];
        if ((int)$delayed > 0)          $lines[] = "🔴 **{$delayed}** delayed report" . ((int)$delayed == 1 ? '' : 's') . " past their estimated end date";
        if ((int)$pendingApproval > 0)  $lines[] = "🟣 **{$pendingApproval}** report" . ((int)$pendingApproval == 1 ? '' : 's') . " awaiting your admin approval to schedule";
        if (in_array($role, ['admin', 'super admin', 'office staff'], true)) {
            $unassigned = $conn->query("
                SELECT COUNT(*) c FROM reports rp
                LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
                WHERE rp.engineer_id IS NULL AND rp.priority_lvl = 'High' AND res.status NOT IN ('Completed','Cancelled')
            ")->fetch_assoc()['c'];
            if ((int)$unassigned > 0) $lines[] = "🟠 **{$unassigned}** high-priority report" . ((int)$unassigned == 1 ? '' : 's') . " with no engineer assigned yet";
            $underReview = $conn->query("SELECT COUNT(*) c FROM citizen_feedback WHERE status = 'Under Review'")->fetch_assoc()['c'];
            if ((int)$underReview > 0) $lines[] = "💬 **{$underReview}** citizen feedback item" . ((int)$underReview == 1 ? '' : 's') . " awaiting review";
        }
        if (empty($lines)) return "You're all caught up — nothing urgent needs your attention right now. ✅";
        return "**Here's what needs your attention:**\n" . implode("\n", $lines);
    }

    // ── 12. Reports at risk of becoming delayed (due within 3 days) ─────────
    if (str_contains($lower, 'at risk') || str_contains($lower, 'about to be late') || str_contains($lower, 'approaching due')
        || str_contains($lower, 'close to due') || str_contains($lower, 'soon due') || str_contains($lower, 'about to be delayed')) {
        $stmt = $conn->prepare("
            SELECT rp.rep_id, req.infrastructure, req.location, rp.estimated_end_date
            FROM reports rp
            LEFT JOIN request_resolutions res ON res.res_id = rp.res_id
            LEFT JOIN requests req ON req.req_id = res.req_id
            WHERE rp.estimated_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
              AND res.status NOT IN ('Completed','Cancelled','Pending Completion'){$engFilter}{$repDistrictFilter}
            ORDER BY rp.estimated_end_date ASC
            LIMIT 8
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return "No reports are close to their due date right now.";
        $lines = array_map(fn($r) => '• ' . dataq_reportLabel($r) . " — due " . date('M j', strtotime($r['estimated_end_date'])), $rows);
        return "**Reports due within 3 days:**\n" . implode("\n", $lines);
    }

    return null;
}

// ════════════════════════════════════════════════════════════
//  MAIN ROUTING LOGIC
// ════════════════════════════════════════════════════════════

$conversationTopic = extractConversationTopic($history);

// ── 1. EMPTY MESSAGE ──────────────────────────────────────────
if (empty($userMessage)) {
    respond("Please type a message so I can help you! 😊");
}

// ── 2. GREETING ───────────────────────────────────────────────
if (isGreeting($userMessage)) {
    respond(getGreetingResponse($context, $PAGE_CONTEXT_INFO, $employeeFirstName, $roleInfo));
}

// ── 2.5. LIVE DATA QUESTION — checked before Claude so real database
//    answers are always deterministic, never Claude-phrased/hallucinated ──
$dataAnswer = tryAnswerDataQuestion($conn, $userMessage, $role, $employeeId, $employeeDistrict);
if ($dataAnswer !== null) {
    respond($dataAnswer);
}

// ── 3. CLAUDE TEXT (if API key available) ─────────────────────
if ($USE_CLAUDE_API) {
    $claudeResp = callClaudeText(
        $CLAUDE_API_KEY, $userMessage, $context, $history,
        $PAGE_STRUCTURE, $PAGE_CONTEXT_INFO, $roleInfo, $employeeFirstName, $conversationTopic
    );
    if ($claudeResp) respond($claudeResp);
}

// ── 4. LOCAL INTENT DETECTION ─────────────────────────────────
$intent = detectIntent($userMessage, $KB);

if (!$intent && isFollowUp($userMessage) && $conversationTopic) {
    $topicIntentMap = [
        'engineer assignment'     => 'assign_engineer',
        'budget & timeline'       => 'budget_dates',
        'report status'           => 'report_lifecycle',
        'road monitoring'         => 'road_monitoring_verify',
        'case management'         => 'case_management_overview',
        'schedules / maintenance' => 'schedule_integration',
        'exporting reports'       => 'export_reports',
        'user management'         => 'user_management',
    ];
    $intent = $topicIntentMap[$conversationTopic] ?? null;
}

if ($intent && isset($KB[$intent])) {
    respond($KB[$intent]['response']);
}

// ── 5. FALLBACK ────────────────────────────────────────────────
respond(getFallbackResponse($context, $PAGE_CONTEXT_INFO, $conversationTopic));