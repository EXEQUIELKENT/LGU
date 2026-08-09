<?php
/**
 * Admin Chatbot Widget — CIMM Admin Assistant
 * ─────────────────────────────────────────────────────────
 * Role-aware workflow/decision-support chatbot for the admin portal.
 * UI/UX and functionality mirror the citizen chatbot widget
 * (includes/partials/chatbot-widget.php) — floating toggle, slide-up
 * panel, suggestion chips, voice input, clear-conversation confirm —
 * but re-themed for the admin side (sidebar's indigo/purple gradient
 * instead of citizen blue) and English-only (no admin page in this
 * codebase has a language toggle, so the i18n layer is dropped).
 *
 * Usage: <?php include __DIR__ . '/../../includes/partials/admin_chatbot_widget.php'; ?>
 * Talks to: public/functionality/admin_chatbot.php (role read server-side
 * from the session — never trust a client-sent role).
 */
?>

<!-- ═══════════════ ADMIN CHATBOT WIDGET ═══════════════ -->
<style>
.adm-chatbot-toggle {
    position: fixed; bottom: 24px; right: 24px;
    width: 60px; height: 60px; border-radius: 50%;
    background: linear-gradient(135deg, #2e54b8 0%, #7b5cfa 100%);
    color: #fff; border: none; cursor: pointer;
    box-shadow: 0 4px 22px rgba(46,84,184,.45);
    z-index: 9998;
    display: flex; align-items: center; justify-content: center;
    transition: all .3s cubic-bezier(.34,1.56,.64,1);
    overflow: visible;
}
.adm-chatbot-toggle::before {
    content:''; position:absolute; inset:-4px; border-radius:50%;
    border:2px solid rgba(123,92,250,.3);
    animation: admRingPulse 2.5s ease-out infinite;
}
@keyframes admRingPulse {
    0%   { transform:scale(1);    opacity:.8; }
    70%  { transform:scale(1.35); opacity:0;  }
    100% { transform:scale(1.35); opacity:0;  }
}
.adm-chatbot-toggle:hover  { transform:scale(1.1) translateY(-2px); box-shadow:0 8px 28px rgba(46,84,184,.55); }
.adm-chatbot-toggle:active { transform:scale(.95); }
.adm-chatbot-toggle.active { background:linear-gradient(135deg,#dc3545 0%,#c82333 100%); }
.adm-chatbot-toggle.active::before { border-color:rgba(220,53,69,.3); }
.adm-chatbot-icon { width:30px; height:30px; transition:all .3s ease; }
.adm-chatbot-toggle.active .adm-chatbot-icon { transform:rotate(180deg); }

.adm-chatbot-badge {
    position:absolute; top:-4px; right:-4px;
    background:linear-gradient(135deg,#f59e0b,#ef4444);
    color:#fff; border-radius:50%; width:22px; height:22px;
    font-size:11px; font-weight:700;
    display:none; align-items:center; justify-content:center;
    border:2px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,.2);
    animation:admBadgePop .4s cubic-bezier(.34,1.56,.64,1);
}
.adm-chatbot-badge.show { display:flex; }
@keyframes admBadgePop { from{transform:scale(0)} to{transform:scale(1)} }

.adm-chatbot-container {
    position:fixed; bottom:96px; right:24px;
    width:380px; height:560px;
    background:var(--card-bg,#ffffff);
    border-radius:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.18), 0 0 0 1px rgba(46,84,184,.08);
    display:none; flex-direction:column;
    z-index:9999; overflow:hidden;
    border:1px solid var(--border-color,rgba(0,0,0,.08));
    animation:admChatSlideUp .35s cubic-bezier(.34,1.56,.64,1);
}
@keyframes admChatSlideUp {
    from { opacity:0; transform:translateY(30px) scale(.95); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.adm-chatbot-container.active { display:flex; }
[data-theme="dark"] .adm-chatbot-container {
    background:rgba(24,24,28,.98);
    border-color:rgba(255,255,255,.08);
    box-shadow:0 20px 60px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.05);
}

.adm-chatbot-header {
    background:linear-gradient(135deg,#20336e 0%,#2e54b8 55%,#7b5cfa 100%);
    color:#fff; padding:0 18px; height:66px;
    display:flex; justify-content:space-between; align-items:center;
    flex-shrink:0; position:relative; overflow:hidden;
}
.adm-chatbot-header::after {
    content:''; position:absolute; top:-30px; right:-30px;
    width:120px; height:120px; background:rgba(255,255,255,.05); border-radius:50%;
}
.adm-chatbot-header-info { display:flex; align-items:center; gap:12px; z-index:1; }
.adm-chatbot-avatar {
    width:38px; height:38px; background:rgba(255,255,255,.2); border-radius:50%;
    display:flex; align-items:center; justify-content:center; font-size:19px;
    border:2px solid rgba(255,255,255,.3); flex-shrink:0;
}
.adm-chatbot-header-text h3 { margin:0 0 2px; font-size:14.5px; font-weight:700; letter-spacing:.02em; }
.adm-chatbot-status { display:flex; align-items:center; gap:5px; font-size:11px; opacity:.85; }
.adm-chatbot-status-dot {
    width:7px; height:7px; background:#4ade80; border-radius:50%;
    box-shadow:0 0 6px #4ade80; animation:admStatusPulse 2s ease-in-out infinite;
}
@keyframes admStatusPulse { 0%,100%{opacity:1} 50%{opacity:.5} }
.adm-chatbot-header-actions { display:flex; gap:6px; z-index:1; }
.adm-chatbot-header-btn {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.2); color:#fff;
    width:30px; height:30px; border-radius:10px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; font-size:13px; backdrop-filter:blur(4px);
}
.adm-chatbot-header-btn:hover { background:rgba(255,255,255,.28); transform:translateY(-1px); }
.adm-chatbot-header-btn:active { transform:scale(.92); }

.adm-chatbot-messages {
    flex:1; overflow-y:auto; padding:16px 16px 8px;
    display:flex; flex-direction:column; gap:10px;
    background:var(--bg-secondary,#f8fafd); scroll-behavior:smooth;
}
[data-theme="dark"] .adm-chatbot-messages { background:rgba(18,18,22,.95); }
.adm-chatbot-messages::-webkit-scrollbar { width:4px; }
.adm-chatbot-messages::-webkit-scrollbar-track { background:transparent; }
.adm-chatbot-messages::-webkit-scrollbar-thumb { background:rgba(46,84,184,.25); border-radius:2px; }

.adm-chatbot-message {
    max-width:84%; padding:10px 14px; border-radius:16px;
    font-size:13.5px; line-height:1.55; word-wrap:break-word;
    animation:admMsgFade .3s ease; position:relative;
}
@keyframes admMsgFade { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

.adm-chatbot-message.user {
    align-self:flex-end;
    background:linear-gradient(135deg,#2e54b8 0%,#7b5cfa 100%);
    color:#fff; border-bottom-right-radius:4px;
    box-shadow:0 2px 10px rgba(46,84,184,.25);
}
.adm-chatbot-message.bot {
    align-self:flex-start;
    background:var(--card-bg,#ffffff); color:var(--text-primary,#1a1a2e);
    border-bottom-left-radius:4px;
    border:1px solid var(--border-color,rgba(0,0,0,.07));
    box-shadow:0 2px 10px rgba(0,0,0,.06);
}
[data-theme="dark"] .adm-chatbot-message.bot {
    background:rgba(36,36,44,.95);
    color:var(--text-primary,#e2e8f0);
    border-color:rgba(255,255,255,.07);
}
.adm-chatbot-message .message-time { font-size:10px; opacity:.55; margin-top:5px; display:block; font-weight:500; }
.adm-chatbot-message.user .message-time { text-align:right; }
.adm-chatbot-message.bot::before { content:'🛠️'; position:absolute; left:-16px; top:8px; font-size:13px; line-height:1; }

.adm-chatbot-typing {
    align-self:flex-start;
    background:var(--card-bg,#ffffff);
    border:1px solid var(--border-color,rgba(0,0,0,.07));
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    padding:12px 16px; border-radius:16px; border-bottom-left-radius:4px;
    font-size:13px; display:none; align-items:center; gap:8px;
    color:var(--text-secondary,#666); animation:admMsgFade .3s ease;
}
[data-theme="dark"] .adm-chatbot-typing {
    background:rgba(36,36,44,.95); border-color:rgba(255,255,255,.07); color:var(--text-secondary,#94a3b8);
}
.adm-chatbot-typing.active { display:flex; }
.adm-typing-dots { display:inline-flex; gap:4px; }
.adm-typing-dots span {
    width:7px; height:7px; background:#2e54b8; border-radius:50%; opacity:.35;
    animation:admTypingDot 1.4s infinite;
}
.adm-typing-dots span:nth-child(2) { animation-delay:.2s; }
.adm-typing-dots span:nth-child(3) { animation-delay:.4s; }
@keyframes admTypingDot { 0%,60%,100%{opacity:.35;transform:scale(1)} 30%{opacity:1;transform:scale(1.3) translateY(-2px)} }

.adm-chatbot-suggestions {
    padding:8px 16px 10px; display:flex; flex-wrap:wrap; gap:7px;
    background:var(--bg-secondary,#f8fafd);
    flex-shrink:0; border-top:1px solid var(--border-color,rgba(0,0,0,.06));
}
[data-theme="dark"] .adm-chatbot-suggestions { background:rgba(18,18,22,.95); border-color:rgba(255,255,255,.06); }
.adm-suggestion-chip {
    background:var(--card-bg,#fff); border:1px solid var(--border-color,rgba(0,0,0,.1));
    color:var(--text-secondary,#4a5568); padding:5px 12px; border-radius:20px;
    font-size:11.5px; cursor:pointer; transition:all .2s ease;
    white-space:nowrap; font-weight:500; box-shadow:0 1px 4px rgba(0,0,0,.05);
}
[data-theme="dark"] .adm-suggestion-chip {
    background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-secondary,#94a3b8);
}
.adm-suggestion-chip:hover {
    background:#2e54b8; color:#fff; border-color:#2e54b8;
    transform:translateY(-2px); box-shadow:0 4px 12px rgba(46,84,184,.25);
}
.adm-suggestion-chip:active { transform:scale(.96); }

.adm-chatbot-input-wrapper {
    padding:12px 14px;
    border-top:1px solid var(--border-color,rgba(0,0,0,.07));
    background:var(--card-bg,#fff);
    display:flex; align-items:center; gap:8px; flex-shrink:0;
}
[data-theme="dark"] .adm-chatbot-input-wrapper { background:rgba(24,24,28,.98); border-top-color:rgba(255,255,255,.06); }
.adm-chatbot-input {
    flex:1; min-width:0;
    border:1.5px solid var(--border-color,#e2e8f0); border-radius:22px;
    padding:10px 16px; font-size:13.5px; outline:none;
    background:var(--input-bg,#f8fafd); color:var(--text-primary,#1a1a2e);
    transition:all .2s ease; font-family:inherit;
}
[data-theme="dark"] .adm-chatbot-input { background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-primary,#e2e8f0); }
.adm-chatbot-input:focus { border-color:#2e54b8; background:var(--card-bg,#fff); box-shadow:0 0 0 3px rgba(46,84,184,.1); }
[data-theme="dark"] .adm-chatbot-input:focus { background:rgba(40,40,50,.95); }
.adm-chatbot-input::placeholder { color:var(--input-placeholder,#a0aec0); font-size:13px; }

.adm-chatbot-icon-btn {
    background:var(--bg-secondary,#f1f5f9); border:1.5px solid var(--border-color,#e2e8f0);
    color:var(--text-secondary,#64748b); width:38px; height:38px; border-radius:50%;
    cursor:pointer; font-size:15px; display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; flex-shrink:0; padding:0; position:relative;
}
[data-theme="dark"] .adm-chatbot-icon-btn { background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-secondary,#94a3b8); }
.adm-chatbot-icon-btn:hover { background:#2e54b8; color:#fff; border-color:#2e54b8; transform:scale(1.1); box-shadow:0 4px 12px rgba(46,84,184,.3); }
.adm-chatbot-icon-btn:active { transform:scale(.92); }
.adm-chatbot-icon-btn:disabled { opacity:.38; cursor:not-allowed; transform:none !important; box-shadow:none !important; }
.adm-chatbot-icon-btn.mic-active {
    background:#ef4444 !important; border-color:#ef4444 !important; color:#fff !important;
    animation:admMicPulse 1s ease-in-out infinite;
}
@keyframes admMicPulse { 0%,100% { box-shadow:0 0 0 0 rgba(239,68,68,.5); } 50% { box-shadow:0 0 0 8px rgba(239,68,68,0); } }

.adm-chatbot-send {
    background:linear-gradient(135deg,#2e54b8 0%,#7b5cfa 100%);
    border:none; color:#fff; width:38px; height:38px; border-radius:50%;
    cursor:pointer; font-size:15px; display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; flex-shrink:0; box-shadow:0 3px 10px rgba(46,84,184,.3);
}
.adm-chatbot-send:hover { transform:scale(1.1) translateY(-1px); box-shadow:0 6px 16px rgba(46,84,184,.4); }
.adm-chatbot-send:active { transform:scale(.92); }
.adm-chatbot-send:disabled { opacity:.45; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

.adm-chatbot-toast {
    position:absolute; bottom:90px; left:50%; transform:translateX(-50%);
    background:rgba(15,23,42,.88); color:#fff;
    padding:8px 18px; border-radius:22px; font-size:12px; white-space:nowrap;
    z-index:10001; opacity:0; transition:opacity .25s ease; pointer-events:none;
    backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.1); font-weight:500;
}
.adm-chatbot-toast.show { opacity:1; }

@media (max-width:768px) {
    .adm-chatbot-container { bottom:88px; right:10px; left:10px; width:auto; height:500px; border-radius:18px; }
    .adm-chatbot-toggle    { bottom:16px; right:14px; width:56px; height:56px; }
    .adm-chatbot-icon      { width:26px; height:26px; }
}
@media (max-width:480px) { .adm-chatbot-container { height:450px; } }

.adm-chatbot-clear-backdrop {
    position:fixed; z-index:10000; inset:0;
    background:rgba(15,23,42,.4); backdrop-filter:blur(6px);
    display:none; align-items:center; justify-content:center;
}
.adm-chatbot-clear-backdrop.active { display:flex; }
.adm-chatbot-clear-modal {
    background:var(--card-bg,#fff); border-radius:20px;
    box-shadow:0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding:32px 26px 22px; width:320px; max-width:92vw;
    animation:admModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display:flex; flex-direction:column; align-items:center; text-align:center;
}
[data-theme="dark"] .adm-chatbot-clear-modal { background:rgba(24,24,30,.98); box-shadow:0 25px 50px rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.08); }
@keyframes admModalPop { from{transform:translateY(24px) scale(.93);opacity:0} to{transform:translateY(0) scale(1);opacity:1} }
.adm-chatbot-clear-modal .icon-wrap {
    width:60px; height:60px;
    background:linear-gradient(135deg,rgba(239,68,68,.12),rgba(239,68,68,.08));
    border-radius:50%; margin:0 auto 14px;
    display:flex; align-items:center; justify-content:center; font-size:26px;
    border:1px solid rgba(239,68,68,.2);
}
.adm-chatbot-clear-modal .modal-title { font-size:1.05rem; font-weight:700; color:var(--text-primary,#1a1a2e); margin-bottom:8px; }
[data-theme="dark"] .adm-chatbot-clear-modal .modal-title { color:#e2e8f0; }
.adm-chatbot-clear-modal .modal-desc  { color:var(--text-secondary,#64748b); font-size:.92rem; margin-bottom:22px; line-height:1.5; }
[data-theme="dark"] .adm-chatbot-clear-modal .modal-desc  { color:#94a3b8; }
.adm-chatbot-clear-modal .modal-btns  { display:flex; gap:10px; width:100%; }
.adm-chatbot-clear-modal .modal-btn   { flex:1; padding:10px 0; border-radius:10px; border:none; font-weight:600; font-size:14px; cursor:pointer; transition:all .18s ease; }
.adm-chatbot-clear-modal .modal-btn.cancel  { background:var(--bg-secondary,#f1f5f9); color:var(--text-primary,#374151); border:1px solid var(--border-color,#e2e8f0); }
[data-theme="dark"] .adm-chatbot-clear-modal .modal-btn.cancel { background:rgba(255,255,255,.06); color:#e2e8f0; border-color:rgba(255,255,255,.1); }
.adm-chatbot-clear-modal .modal-btn.cancel:hover  { background:var(--border-color,#e2e8f0); }
.adm-chatbot-clear-modal .modal-btn.confirm { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; box-shadow:0 4px 12px rgba(239,68,68,.3); }
.adm-chatbot-clear-modal .modal-btn.confirm:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(239,68,68,.4); }
</style>

<!-- ── Toggle button ─────────────────────────────── -->
<button class="adm-chatbot-toggle" id="admChatbotToggle" title="CIMM Admin Assistant" aria-label="Toggle admin assistant">
    <svg class="adm-chatbot-icon" viewBox="0 0 24 24" fill="none">
        <path d="M12 2C6.48 2 2 6.48 2 12c0 1.93.6 3.72 1.62 5.2L2.05 21.71c-.16.47.29.92.76.76L7.32 20.9C8.8 21.92 10.59 22.52 12.52 22.52 18.04 22.52 22.52 18.04 22.52 12.52 22.52 6.48 18.04 2 12 2z" fill="currentColor"/>
        <circle cx="8"  cy="12" r="1.5" fill="white"/>
        <circle cx="12" cy="12" r="1.5" fill="white"/>
        <circle cx="16" cy="12" r="1.5" fill="white"/>
    </svg>
    <span class="adm-chatbot-badge" id="admChatbotBadge">1</span>
</button>

<!-- ── Chat panel ───────────────────────────────── -->
<div class="adm-chatbot-container" id="admChatbotContainer">
    <div class="adm-chatbot-toast" id="admChatbotToast"></div>

    <div class="adm-chatbot-header">
        <div class="adm-chatbot-header-info">
            <div class="adm-chatbot-avatar">🛠️</div>
            <div class="adm-chatbot-header-text">
                <h3>CIMM Admin Assistant</h3>
                <div class="adm-chatbot-status">
                    <div class="adm-chatbot-status-dot"></div>
                    <span>Online · Ready to help</span>
                </div>
            </div>
        </div>
        <div class="adm-chatbot-header-actions">
            <button class="adm-chatbot-header-btn" id="admChatbotClear" title="Clear conversation">🗑️</button>
            <button class="adm-chatbot-header-btn" id="admChatbotClose" title="Close">✕</button>
        </div>
    </div>

    <div class="adm-chatbot-messages" id="admChatbotMessages"></div>

    <div class="adm-chatbot-suggestions" id="admChatbotSuggestions"></div>

    <div class="adm-chatbot-typing" id="admChatbotTyping">
        <span class="adm-typing-dots"><span></span><span></span><span></span></span>
        <span>Typing…</span>
    </div>

    <div class="adm-chatbot-input-wrapper">
        <button class="adm-chatbot-icon-btn" id="admChatbotMicBtn" title="Speak your message" aria-label="Voice input" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                <line x1="12" y1="19" x2="12" y2="23"/>
                <line x1="8"  y1="23" x2="16" y2="23"/>
            </svg>
        </button>

        <input type="text" class="adm-chatbot-input" id="admChatbotInput"
               placeholder="Ask about your workflow…" autocomplete="off" maxlength="600">

        <button class="adm-chatbot-send" id="admChatbotSend" title="Send" aria-label="Send">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>
</div>

<div class="adm-chatbot-clear-backdrop" id="admChatbotClearBackdrop">
    <div class="adm-chatbot-clear-modal">
        <div class="icon-wrap">🗑️</div>
        <div class="modal-title">Clear Conversation?</div>
        <div class="modal-desc">This will delete all messages. This action cannot be undone.</div>
        <div class="modal-btns">
            <button class="modal-btn cancel"  id="admChatbotClearCancel">Cancel</button>
            <button class="modal-btn confirm" id="admChatbotClearConfirm">Clear All</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CONFIG = {
        STORAGE_KEY:       'admin_chatbot_conversation_v1',
        STORAGE_STATE_KEY: 'admin_chatbot_state_v1',
        MAX_MESSAGES:      60,
        AUTO_HIDE_CHIPS:   7000,
        ENDPOINT: (window.ADMIN_CHATBOT_ENDPOINT || '../functionality/admin_chatbot.php')
    };

    var WELCOME_TEXT =
        "Hi! 👋 I'm your **CIMM Admin Assistant**. I can help with workflow questions — assigning engineers, " +
        "budgets & dates, report statuses, Road Monitoring verification, schedules, exporting reports, and more.\n\n" +
        "How can I help you today?";

    var el = {
        toggle:        document.getElementById('admChatbotToggle'),
        container:     document.getElementById('admChatbotContainer'),
        close:         document.getElementById('admChatbotClose'),
        clear:         document.getElementById('admChatbotClear'),
        messages:      document.getElementById('admChatbotMessages'),
        input:         document.getElementById('admChatbotInput'),
        send:          document.getElementById('admChatbotSend'),
        typing:        document.getElementById('admChatbotTyping'),
        suggestions:   document.getElementById('admChatbotSuggestions'),
        badge:         document.getElementById('admChatbotBadge'),
        clearBackdrop: document.getElementById('admChatbotClearBackdrop'),
        clearCancel:   document.getElementById('admChatbotClearCancel'),
        clearConfirm:  document.getElementById('admChatbotClearConfirm'),
        toast:         document.getElementById('admChatbotToast'),
        micBtn:        document.getElementById('admChatbotMicBtn'),
    };

    var conversationHistory  = [];
    var isWaitingForResponse = false;
    var suggestionTimeout    = null;
    var recognition          = null;
    var isRecording          = false;

    /* ── Page context (must match admin_chatbot.php's $allowedContexts) ── */
    function getCurrentPage() {
        var path = window.location.pathname.toLowerCase();
        if (path.includes('employee.php'))         return 'dashboard';
        if (path.includes('requests.php'))          return 'requests';
        if (path.includes('current_reports.php'))   return 'current_reports';
        if (path.includes('pending_reports.php'))   return 'pending_reports';
        if (path.includes('archive_reports.php'))   return 'archive_reports';
        if (path.includes('sched.php'))              return 'schedules';
        if (path.includes('case_management.php'))   return 'case_management';
        if (path.includes('road_monitoring.php'))   return 'road_monitoring';
        if (path.includes('emp_feedback.php'))       return 'feedback';
        if (path.includes('user_management.php'))   return 'user_management';
        if (path.includes('profile.php'))            return 'profile';
        return 'general';
    }

    var CHIP_SETS = {
        dashboard:        ['How do I assign an engineer?', 'What do the report statuses mean?', 'How do I export reports?'],
        requests:         ['How does AI image analysis work?', 'What happens after a request is approved?'],
        current_reports:  ['How do I assign an engineer?', 'How do I edit budget and dates?'],
        pending_reports:  ['What does "pending" mean?', 'When does this move to Current Reports?'],
        archive_reports:  ['How do I export archived reports?'],
        schedules:        ['What does the CPRF badge mean?', 'What does the Energy badge mean?'],
        case_management:  ['What is Case Management?', 'How do I find where a case currently sits?'],
        road_monitoring:  ['How do I verify a report?', 'What happens after I verify a report?'],
        feedback:         ['What feedback types are there?', 'How do I export feedback?'],
        user_management:  ['How do I create an account?', 'How do I deactivate an account?'],
        profile:          ['How do I export reports?', 'What do the report statuses mean?'],
        general:          ['How do I assign an engineer?', 'What is Case Management?', 'How do I export reports?'],
    };

    function renderContextChips() {
        if (!el.suggestions) return;
        var chips = CHIP_SETS[getCurrentPage()] || CHIP_SETS.general;
        el.suggestions.innerHTML = '';
        chips.forEach(function (msg) {
            var btn = document.createElement('button');
            btn.className       = 'adm-suggestion-chip';
            btn.textContent     = msg;
            btn.dataset.message = msg;
            el.suggestions.appendChild(btn);
        });
    }

    var toastTimer = null;
    function showToast(msg, ms) {
        if (!el.toast) return;
        clearTimeout(toastTimer);
        el.toast.textContent = msg;
        el.toast.classList.add('show');
        toastTimer = setTimeout(function () { el.toast.classList.remove('show'); }, ms || 2500);
    }

    function formatTime(date) {
        return date.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
    }
    function saveConversation() {
        try { sessionStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(conversationHistory)); } catch(e){}
    }
    function loadConversation() {
        try {
            var s = sessionStorage.getItem(CONFIG.STORAGE_KEY);
            if (s) {
                conversationHistory = JSON.parse(s);
                if (conversationHistory.length > CONFIG.MAX_MESSAGES)
                    conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
                return conversationHistory;
            }
        } catch(e){}
        return [];
    }
    function saveChatState(isOpen) {
        try {
            sessionStorage.setItem(CONFIG.STORAGE_STATE_KEY, JSON.stringify({
                isOpen:isOpen, timestamp:Date.now(), msgCount:conversationHistory.length
            }));
        } catch(e){}
    }
    function loadChatState() {
        try { var s = sessionStorage.getItem(CONFIG.STORAGE_STATE_KEY); if (s) return JSON.parse(s); } catch(e){}
        return null;
    }
    function scrollToBottom() { setTimeout(function(){ el.messages.scrollTop = el.messages.scrollHeight; }, 80); }
    function hideSuggestions() { if (el.suggestions && conversationHistory.length > 1) el.suggestions.style.display = 'none'; }
    function setInputsBusy(busy) {
        el.send.disabled = busy;
        if (el.micBtn)    el.micBtn.disabled = busy;
    }

    function addMessage(text, type) {
        if (!text) return;
        var message = { text:text, type:type, timestamp:new Date().toISOString() };
        conversationHistory.push(message);
        if (conversationHistory.length > CONFIG.MAX_MESSAGES)
            conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
        saveConversation();
        renderMessage(message);
        scrollToBottom();
    }

    function renderMessage(message) {
        var div = document.createElement('div');
        div.className = 'adm-chatbot-message ' + message.type;

        var textSpan = document.createElement('span');
        textSpan.innerHTML = message.text
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        div.appendChild(textSpan);

        var timeSpan = document.createElement('span');
        timeSpan.className   = 'message-time';
        timeSpan.textContent = formatTime(new Date(message.timestamp));
        div.appendChild(timeSpan);

        var anchor = (el.typing && el.typing.parentNode === el.messages ? el.typing : null);
        if (anchor) el.messages.insertBefore(div, anchor);
        else        el.messages.appendChild(div);
    }

    function renderMessages() {
        Array.prototype.slice.call(el.messages.children).forEach(function (k) {
            if (k !== el.typing) el.messages.removeChild(k);
        });
        conversationHistory.forEach(renderMessage);
        scrollToBottom();
    }

    function showWelcomeMessage() {
        var message = { text:WELCOME_TEXT, type:'bot', timestamp:new Date().toISOString(), isWelcome:true };
        conversationHistory.push(message);
        saveConversation();
        renderMessage(message);
        scrollToBottom();
        if (el.suggestions) el.suggestions.style.display = 'flex';
    }

    function openClearModal()  { el.clearBackdrop && el.clearBackdrop.classList.add('active'); }
    function closeClearModal() { el.clearBackdrop && el.clearBackdrop.classList.remove('active'); }
    function confirmClear() {
        conversationHistory = [];
        sessionStorage.removeItem(CONFIG.STORAGE_KEY);
        renderMessages();
        showWelcomeMessage();
        closeClearModal();
    }

    function sendMessage() {
        var text = (el.input ? el.input.value.trim() : '');
        if (!text || isWaitingForResponse) return;

        addMessage(text, 'user');
        if (el.input) el.input.value = '';
        hideSuggestions();

        var payload = {
            message: text,
            context: getCurrentPage(),
            history: conversationHistory.slice(-6).map(function (m) {
                return { text: m.text, type: m.type };
            }),
        };

        el.typing && el.typing.classList.add('active');
        isWaitingForResponse = true;
        setInputsBusy(true);
        scrollToBottom();

        fetch(CONFIG.ENDPOINT, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            el.typing && el.typing.classList.remove('active');
            addMessage(data.response || 'Sorry, I encountered an error. Please try again.', 'bot');
        })
        .catch(function () {
            el.typing && el.typing.classList.remove('active');
            addMessage("Sorry, I'm having trouble connecting. Please try again later.", 'bot');
        })
        .finally(function () {
            isWaitingForResponse = false;
            setInputsBusy(false);
            scrollToBottom();
        });
    }

    function initSpeechRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            if (el.micBtn) {
                el.micBtn.title    = 'Voice input not supported in this browser';
                el.micBtn.style.opacity = '0.35';
                el.micBtn.style.cursor  = 'not-allowed';
                el.micBtn.disabled = true;
            }
            return null;
        }
        var rec = new SR();
        rec.continuous    = true;
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        rec.lang = 'en-US';

        rec.onstart = function () {
            isRecording = true;
            if (el.micBtn) el.micBtn.classList.add('mic-active');
            showToast('🎙️ Listening… click mic again to stop', 60000);
        };
        rec.onresult = function (e) {
            var transcript = '';
            for (var i = e.resultIndex; i < e.results.length; i++) transcript += e.results[i][0].transcript;
            if (el.input) el.input.value = (el.input.value + ' ' + transcript).trim();
        };
        rec.onerror = function (e) {
            var msg = e.error === 'no-speech' ? 'No speech detected.'
                    : e.error === 'not-allowed' ? 'Microphone permission denied.'
                    : 'Could not hear you.';
            showToast('❌ ' + msg, 3000);
            stopMic();
        };
        rec.onend = function () {
            if (isRecording) { try { rec.start(); } catch(e) { stopMic(); } }
        };
        return rec;
    }

    function stopMic() {
        isRecording = false;
        if (el.micBtn) el.micBtn.classList.remove('mic-active');
        if (recognition) { try { recognition.stop(); } catch(e){} }
        if (el.toast) el.toast.classList.remove('show');
        showToast('🛑 Voice input stopped.', 1800);
    }
    function startMic() {
        if (!recognition) { showToast('❌ Voice input not supported.', 3000); return; }
        isRecording = true;
        try { recognition.start(); } catch(err) { stopMic(); }
    }
    function toggleMic() {
        if (isWaitingForResponse) return;
        if (isRecording) stopMic(); else startMic();
    }

    function toggleChat() {
        var isActive = el.container.classList.toggle('active');
        el.toggle.classList.toggle('active');
        if (isActive) {
            el.input && el.input.focus();
            if (el.badge) el.badge.classList.remove('show');
            clearTimeout(suggestionTimeout);
            suggestionTimeout = setTimeout(hideSuggestions, CONFIG.AUTO_HIDE_CHIPS);
        }
        saveChatState(isActive);
    }
    function closeChat() {
        el.container.classList.remove('active');
        el.toggle.classList.remove('active');
        if (isRecording) stopMic();
        saveChatState(false);
    }

    el.toggle       && el.toggle.addEventListener('click', toggleChat);
    el.close        && el.close.addEventListener('click', closeChat);
    el.clear        && el.clear.addEventListener('click', openClearModal);
    el.clearConfirm && el.clearConfirm.addEventListener('click', confirmClear);
    el.clearCancel  && el.clearCancel.addEventListener('click', closeClearModal);
    el.clearBackdrop && el.clearBackdrop.addEventListener('click', function (e) {
        if (e.target === el.clearBackdrop) closeClearModal();
    });
    el.send  && el.send.addEventListener('click', sendMessage);
    el.input && el.input.addEventListener('keypress', function (e) {
        if (e.key === 'Enter' && !isWaitingForResponse) sendMessage();
    });
    el.micBtn && el.micBtn.addEventListener('click', toggleMic);
    el.suggestions && el.suggestions.addEventListener('click', function (e) {
        if (e.target.classList.contains('adm-suggestion-chip')) {
            if (el.input) el.input.value = e.target.dataset.message || e.target.textContent.trim();
            sendMessage();
        }
    });

    function init() {
        recognition = initSpeechRecognition();
        loadConversation();
        renderContextChips();

        var state = loadChatState();

        if (conversationHistory.length > 0) {
            renderMessages();
            if (el.badge && !el.container.classList.contains('active')) {
                var lastCount = (state && state.msgCount) || 0;
                var newCount  = conversationHistory.length - lastCount;
                if (newCount > 0) {
                    el.badge.textContent = Math.min(newCount, 9);
                    el.badge.classList.add('show');
                }
            }
        } else {
            showWelcomeMessage();
        }

        if (state && state.isOpen && (Date.now() - state.timestamp) < 60000) {
            setTimeout(function () {
                el.container.classList.add('active');
                el.toggle.classList.add('active');
            }, 300);
        }
    }

    window.addEventListener('beforeunload', function () {
        if (isRecording) stopMic();
        saveChatState(el.container.classList.contains('active'));
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

})();
</script>
<!-- ═══════════════ ADMIN CHATBOT WIDGET END ═══════════════ -->
