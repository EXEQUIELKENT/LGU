<?php
/**
 * Chatbot Widget v4
 * ─────────────────────────────────────────────────────────
 * Changes from v3:
 *  1. Gallery icon (not camera) — visible on DESKTOP + mobile
 *  2. Mic: manual toggle off — click again to stop recording
 *  3. Image upload → queued (never auto-sends); user types a
 *     message first, then presses Send to dispatch together
 *  4. Multiple images supported per send (up to 5)
 *  5. Image preview tray above the input row
 *  6. Better AI intent + image-analysis responses (via chatbot.php v3)
 *
 * Usage: <?php include 'chatbot-widget.php'; ?>
 */
?>

<!-- ═══════════════ CHATBOT WIDGET v4 ═══════════════ -->
<style>
/* ────────────────────────────────────────────────────────
   TOGGLE BUTTON
──────────────────────────────────────────────────────── */
.chatbot-toggle {
    position: fixed; bottom: 75px; right: 24px;
    width: 62px; height: 62px; border-radius: 50%;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff; border: none; cursor: pointer;
    box-shadow: 0 4px 22px rgba(43,108,176,.45), 0 0 0 0 rgba(43,108,176,.3);
    z-index: 9998;
    display: flex; align-items: center; justify-content: center;
    transition: all .3s cubic-bezier(.34,1.56,.64,1);
    overflow: visible;
}
.chatbot-toggle::before {
    content:''; position:absolute; inset:-4px; border-radius:50%;
    border:2px solid rgba(43,108,176,.3);
    animation: ringPulse 2.5s ease-out infinite;
}
@keyframes ringPulse {
    0%   { transform:scale(1);    opacity:.8; }
    70%  { transform:scale(1.35); opacity:0;  }
    100% { transform:scale(1.35); opacity:0;  }
}
.chatbot-toggle:hover  { transform:scale(1.1) translateY(-2px); box-shadow:0 8px 28px rgba(43,108,176,.55); }
.chatbot-toggle:active { transform:scale(.95); }
.chatbot-toggle.active { background:linear-gradient(135deg,#dc3545 0%,#c82333 100%); }
.chatbot-toggle.active::before { border-color:rgba(220,53,69,.3); }
.chatbot-icon { width:32px; height:32px; transition:all .3s ease; }
.chatbot-toggle.active .chatbot-icon { transform:rotate(180deg); }

.chatbot-badge {
    position:absolute; top:-4px; right:-4px;
    background:linear-gradient(135deg,#f59e0b,#ef4444);
    color:#fff; border-radius:50%; width:22px; height:22px;
    font-size:11px; font-weight:700;
    display:none; align-items:center; justify-content:center;
    border:2px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,.2);
    animation:badgePop .4s cubic-bezier(.34,1.56,.64,1);
}
.chatbot-badge.show { display:flex; }
@keyframes badgePop { from{transform:scale(0)} to{transform:scale(1)} }

/* ────────────────────────────────────────────────────────
   CONTAINER
──────────────────────────────────────────────────────── */
.chatbot-container {
    position:fixed; bottom:100px; right:24px;
    width:400px; height:590px;
    background:var(--card-bg,#ffffff);
    border-radius:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.18), 0 0 0 1px rgba(43,108,176,.08);
    display:none; flex-direction:column;
    z-index:9999; overflow:hidden;
    border:1px solid var(--border-color,rgba(0,0,0,.08));
    transition:all .3s ease;
    animation:chatSlideUp .35s cubic-bezier(.34,1.56,.64,1);
}
@keyframes chatSlideUp {
    from { opacity:0; transform:translateY(30px) scale(.95); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.chatbot-container.active { display:flex; }
[data-theme="dark"] .chatbot-container {
    background:rgba(24,24,28,.98);
    border-color:rgba(255,255,255,.08);
    box-shadow:0 20px 60px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.05);
}

/* ────────────────────────────────────────────────────────
   HEADER
──────────────────────────────────────────────────────── */
.chatbot-header {
    background:linear-gradient(135deg,#1e4d8c 0%,#2563eb 60%,#3b82f6 100%);
    color:#fff; padding:0 18px; height:68px;
    display:flex; justify-content:space-between; align-items:center;
    flex-shrink:0; position:relative; overflow:hidden;
}
.chatbot-header::after {
    content:''; position:absolute; top:-30px; right:-30px;
    width:120px; height:120px; background:rgba(255,255,255,.05); border-radius:50%;
}
.chatbot-header::before {
    content:''; position:absolute; bottom:-40px; left:20px;
    width:80px; height:80px; background:rgba(255,255,255,.04); border-radius:50%;
}
.chatbot-header-info { display:flex; align-items:center; gap:12px; z-index:1; }
.chatbot-avatar {
    width:40px; height:40px; background:rgba(255,255,255,.2); border-radius:50%;
    display:flex; align-items:center; justify-content:center; font-size:20px;
    border:2px solid rgba(255,255,255,.3); flex-shrink:0;
}
.chatbot-header-text h3 { margin:0 0 2px; font-size:15px; font-weight:700; letter-spacing:.02em; }
.chatbot-status { display:flex; align-items:center; gap:5px; font-size:11px; opacity:.85; }
.chatbot-status-dot {
    width:7px; height:7px; background:#4ade80; border-radius:50%;
    box-shadow:0 0 6px #4ade80; animation:statusPulse 2s ease-in-out infinite;
}
@keyframes statusPulse { 0%,100%{opacity:1} 50%{opacity:.5} }
.chatbot-header-actions { display:flex; gap:6px; z-index:1; }
.chatbot-header-btn {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.2); color:#fff;
    width:32px; height:32px; border-radius:10px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; font-size:14px; backdrop-filter:blur(4px);
}
.chatbot-header-btn:hover { background:rgba(255,255,255,.28); transform:translateY(-1px); }
.chatbot-header-btn:active { transform:scale(.92); }

/* ────────────────────────────────────────────────────────
   MESSAGES
──────────────────────────────────────────────────────── */
.chatbot-messages {
    flex:1; overflow-y:auto; padding:16px 16px 8px;
    display:flex; flex-direction:column; gap:10px;
    background:var(--bg-secondary,#f8fafd); scroll-behavior:smooth;
}
[data-theme="dark"] .chatbot-messages { background:rgba(18,18,22,.95); }
.chatbot-messages::-webkit-scrollbar { width:4px; }
.chatbot-messages::-webkit-scrollbar-track { background:transparent; }
.chatbot-messages::-webkit-scrollbar-thumb { background:rgba(43,108,176,.25); border-radius:2px; }
.chatbot-messages::-webkit-scrollbar-thumb:hover { background:rgba(43,108,176,.45); }

.chat-date-divider {
    display:flex; align-items:center; gap:10px; margin:4px 0;
    font-size:11px; color:var(--text-secondary,#999); font-weight:600;
    letter-spacing:.05em; text-transform:uppercase;
}
.chat-date-divider::before, .chat-date-divider::after {
    content:''; flex:1; height:1px; background:var(--border-color,rgba(0,0,0,.08));
}

.chatbot-message {
    max-width:82%; padding:10px 14px; border-radius:16px;
    font-size:13.5px; line-height:1.55; word-wrap:break-word;
    animation:msgFade .3s ease; position:relative;
}
@keyframes msgFade { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

.chatbot-message.user {
    align-self:flex-end;
    background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);
    color:#fff; border-bottom-right-radius:4px;
    box-shadow:0 2px 10px rgba(37,99,235,.25);
}
.chatbot-message.bot {
    align-self:flex-start;
    background:var(--card-bg,#ffffff); color:var(--text-primary,#1a1a2e);
    border-bottom-left-radius:4px;
    border:1px solid var(--border-color,rgba(0,0,0,.07));
    box-shadow:0 2px 10px rgba(0,0,0,.06);
}
[data-theme="dark"] .chatbot-message.bot {
    background:rgba(36,36,44,.95);
    color:var(--text-primary,#e2e8f0);
    border-color:rgba(255,255,255,.07);
}
.chatbot-message .message-time {
    font-size:10px; opacity:.55; margin-top:5px; display:block; font-weight:500;
}
.chatbot-message.user .message-time { text-align:right; }

/* Image grid in message */
.msg-image-grid {
    display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;
}
.msg-image-grid img {
    max-width:calc(50% - 3px); max-height:120px; object-fit:cover;
    border-radius:8px; cursor:zoom-in; border:1px solid rgba(255,255,255,.2);
    transition:opacity .15s;
}
.msg-image-grid img:only-child  { max-width:100%; max-height:150px; }
.msg-image-grid img:hover { opacity:.88; }
.chatbot-message.bot .msg-image-grid img { border-color:var(--border-color,rgba(0,0,0,.1)); }

/* AI bot prefix dot */
.chatbot-message.bot::before {
    content:'🤖'; position:absolute; left:-14px; top:8px; font-size:14px; line-height:1;
}

/* ────────────────────────────────────────────────────────
   AI ANALYSIS CARD
──────────────────────────────────────────────────────── */
.ai-analysis-card {
    background:linear-gradient(135deg,rgba(37,99,235,.08) 0%,rgba(99,102,241,.08) 100%);
    border:1px solid rgba(37,99,235,.2); border-radius:12px;
    padding:12px 14px; margin-top:8px; font-size:12.5px; line-height:1.6;
}
[data-theme="dark"] .ai-analysis-card {
    background:linear-gradient(135deg,rgba(37,99,235,.15) 0%,rgba(99,102,241,.12) 100%);
    border-color:rgba(37,99,235,.3);
}
.ai-analysis-card .ai-label {
    font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
    color:#2563eb; margin-bottom:6px;
    display:flex; align-items:center; gap:5px;
}
.ai-analysis-card .ai-confidence {
    display:inline-block; background:rgba(37,99,235,.15); color:#2563eb;
    border-radius:20px; padding:2px 8px; font-size:10px; font-weight:700; margin-left:auto;
}

/* ────────────────────────────────────────────────────────
   TYPING + ANALYZING INDICATORS
──────────────────────────────────────────────────────── */
.chatbot-typing,
.chatbot-img-uploading {
    align-self:flex-start;
    background:var(--card-bg,#ffffff);
    border:1px solid var(--border-color,rgba(0,0,0,.07));
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    padding:12px 16px; border-radius:16px; border-bottom-left-radius:4px;
    font-size:13px; display:none; align-items:center; gap:8px;
    color:var(--text-secondary,#666); animation:msgFade .3s ease; position:relative;
}
.chatbot-typing::before,
.chatbot-img-uploading::before {
    content:'🤖'; font-size:14px; position:absolute; left:-14px; top:8px;
}
[data-theme="dark"] .chatbot-typing,
[data-theme="dark"] .chatbot-img-uploading {
    background:rgba(36,36,44,.95);
    border-color:rgba(255,255,255,.07);
    color:var(--text-secondary,#94a3b8);
}
.chatbot-typing.active,
.chatbot-img-uploading.active { display:flex; }

.typing-dots { display:inline-flex; gap:4px; }
.typing-dots span {
    width:7px; height:7px; background:#2563eb; border-radius:50%; opacity:.35;
    animation:typingDot 1.4s infinite;
}
.typing-dots span:nth-child(2) { animation-delay:.2s; }
.typing-dots span:nth-child(3) { animation-delay:.4s; }
@keyframes typingDot { 0%,60%,100%{opacity:.35;transform:scale(1)} 30%{opacity:1;transform:scale(1.3) translateY(-2px)} }

.chatbot-img-uploading .spin {
    width:18px; height:18px;
    border:2.5px solid rgba(37,99,235,.2); border-top-color:#2563eb;
    border-radius:50%; animation:spinAnim .7s linear infinite; flex-shrink:0;
}
@keyframes spinAnim { to{transform:rotate(360deg)} }

/* ────────────────────────────────────────────────────────
   SUGGESTION CHIPS
──────────────────────────────────────────────────────── */
.chatbot-suggestions {
    padding:8px 16px 10px; display:flex; flex-wrap:wrap; gap:7px;
    overflow-x:hidden; background:var(--bg-secondary,#f8fafd);
    flex-shrink:0; border-top:1px solid var(--border-color,rgba(0,0,0,.06));
}
.chatbot-suggestions.lang-tl {
    flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch;
    scrollbar-width:thin; scrollbar-color:rgba(43,108,176,.2) transparent;
}
.chatbot-suggestions.lang-tl::-webkit-scrollbar { height:2px; }
.chatbot-suggestions.lang-tl::-webkit-scrollbar-thumb { background:rgba(43,108,176,.2); border-radius:1px; }
[data-theme="dark"] .chatbot-suggestions { background:rgba(18,18,22,.95); border-color:rgba(255,255,255,.06); }

.suggestion-chip {
    background:var(--card-bg,#fff); border:1px solid var(--border-color,rgba(0,0,0,.1));
    color:var(--text-secondary,#4a5568); padding:5px 12px; border-radius:20px;
    font-size:11.5px; cursor:pointer; transition:all .2s ease;
    white-space:nowrap; font-weight:500; box-shadow:0 1px 4px rgba(0,0,0,.05);
}
[data-theme="dark"] .suggestion-chip {
    background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-secondary,#94a3b8);
}
.suggestion-chip:hover {
    background:#2563eb; color:#fff; border-color:#2563eb;
    transform:translateY(-2px); box-shadow:0 4px 12px rgba(37,99,235,.25);
}
.suggestion-chip:active { transform:scale(.96); }

/* ────────────────────────────────────────────────────────
   IMAGE PREVIEW TRAY (above input row)
──────────────────────────────────────────────────────── */
.chatbot-image-tray {
    display:none; flex-wrap:wrap; gap:8px;
    padding:10px 14px 2px;
    background:var(--bg-secondary,#f8fafd);
    border-top:1px solid var(--border-color,rgba(0,0,0,.06));
    flex-shrink:0;
    max-height:130px; overflow-y:auto;
}
.chatbot-image-tray.has-images { display:flex; }
[data-theme="dark"] .chatbot-image-tray {
    background:rgba(20,20,26,.95); border-color:rgba(255,255,255,.06);
}

.tray-item {
    position:relative; width:72px; height:72px; flex-shrink:0;
}
.tray-item img {
    width:100%; height:100%; object-fit:cover; border-radius:10px;
    border:2px solid rgba(37,99,235,.3);
}
.tray-item .tray-remove {
    position:absolute; top:-6px; right:-6px;
    width:20px; height:20px; border-radius:50%;
    background:#ef4444; color:#fff; border:none;
    font-size:11px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 2px 6px rgba(0,0,0,.2); line-height:1;
    transition:transform .15s;
}
.tray-item .tray-remove:hover { transform:scale(1.2); }

.tray-count-label {
    width:100%; font-size:10.5px; font-weight:600; padding:0 2px 6px;
    color:var(--text-secondary,#64748b);
    display:flex; align-items:center; justify-content:space-between;
}
.tray-count-label span { color:#2563eb; }

/* ────────────────────────────────────────────────────────
   INPUT ROW
   Desktop:  [🎙 Mic] [🖼 Gallery] [── Input ──] [Send]
   Mobile:   same layout (gallery always visible)
──────────────────────────────────────────────────────── */
.chatbot-input-wrapper {
    padding:12px 14px;
    border-top:1px solid var(--border-color,rgba(0,0,0,.07));
    background:var(--card-bg,#fff);
    display:flex; align-items:center; gap:8px; flex-shrink:0;
}
[data-theme="dark"] .chatbot-input-wrapper {
    background:rgba(24,24,28,.98); border-top-color:rgba(255,255,255,.06);
}

/* Text field */
.chatbot-input {
    flex:1; min-width:0;
    border:1.5px solid var(--border-color,#e2e8f0); border-radius:22px;
    padding:10px 16px; font-size:13.5px; outline:none;
    background:var(--input-bg,#f8fafd); color:var(--text-primary,#1a1a2e);
    transition:all .2s ease; font-family:inherit;
}
[data-theme="dark"] .chatbot-input {
    background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-primary,#e2e8f0);
}
.chatbot-input:focus {
    border-color:#2563eb; background:var(--card-bg,#fff); box-shadow:0 0 0 3px rgba(37,99,235,.1);
}
[data-theme="dark"] .chatbot-input:focus { background:rgba(40,40,50,.95); }
.chatbot-input::placeholder { color:var(--input-placeholder,#a0aec0); font-size:13px; }

/* Shared icon button */
.chatbot-icon-btn {
    background:var(--bg-secondary,#f1f5f9); border:1.5px solid var(--border-color,#e2e8f0);
    color:var(--text-secondary,#64748b); width:40px; height:40px; border-radius:50%;
    cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; flex-shrink:0; padding:0; position:relative;
}
[data-theme="dark"] .chatbot-icon-btn {
    background:rgba(36,36,44,.9); border-color:rgba(255,255,255,.1); color:var(--text-secondary,#94a3b8);
}
.chatbot-icon-btn:hover {
    background:#2563eb; color:#fff; border-color:#2563eb;
    transform:scale(1.1); box-shadow:0 4px 12px rgba(37,99,235,.3);
}
.chatbot-icon-btn:active { transform:scale(.92); }
.chatbot-icon-btn:disabled { opacity:.38; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

/* Mic active / recording state */
.chatbot-icon-btn.mic-active {
    background:#ef4444 !important; border-color:#ef4444 !important; color:#fff !important;
    animation:micPulse 1s ease-in-out infinite;
}
@keyframes micPulse {
    0%,100% { box-shadow:0 0 0 0 rgba(239,68,68,.5); }
    50%      { box-shadow:0 0 0 8px rgba(239,68,68,0); }
}

/* Gallery button image-count badge */
.chatbot-icon-btn .img-count-dot {
    position:absolute; top:-5px; right:-5px;
    width:18px; height:18px; border-radius:50%;
    background:#ef4444; color:#fff; font-size:9px; font-weight:700;
    display:none; align-items:center; justify-content:center;
    border:2px solid var(--card-bg,#fff);
}
.chatbot-icon-btn .img-count-dot.show { display:flex; }

/* Send button */
.chatbot-send {
    background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);
    border:none; color:#fff; width:40px; height:40px; border-radius:50%;
    cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center;
    transition:all .2s ease; flex-shrink:0; box-shadow:0 3px 10px rgba(37,99,235,.3);
}
.chatbot-send:hover { transform:scale(1.1) translateY(-1px); box-shadow:0 6px 16px rgba(37,99,235,.4); }
.chatbot-send:active { transform:scale(.92); }
.chatbot-send:disabled { opacity:.45; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

/* ────────────────────────────────────────────────────────
   TOAST
──────────────────────────────────────────────────────── */
.chatbot-toast {
    position:absolute; bottom:90px; left:50%; transform:translateX(-50%);
    background:rgba(15,23,42,.88); color:#fff;
    padding:8px 18px; border-radius:22px; font-size:12px; white-space:nowrap;
    z-index:10001; opacity:0; transition:opacity .25s ease; pointer-events:none;
    backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.1); font-weight:500;
}
.chatbot-toast.show { opacity:1; }

@media (max-height: 800px) and (min-width: 769px) {

/* Shrink the panel so it never clips under the navbar */
.chatbot-container {
    height: 500px;          /* was 590px */
    bottom: 76px;           /* was 100px — keeps clear of toggle */
}

/* Drop the toggle button slightly so it doesn't crowd the panel */
.chatbot-toggle {
    bottom: 18px;           /* was 75px */
}

/* Slim the header */
.chatbot-header {
    height: 56px;           /* was 68px */
    padding: 0 14px;
}

.chatbot-header-text h3  { font-size: 13px; }
.chatbot-status          { font-size: 10px; }
.chatbot-avatar          { width: 34px; height: 34px; font-size: 17px; }

/* Tighten message bubbles */
.chatbot-messages {
    padding: 10px 12px 6px;
    gap: 7px;
}
.chatbot-message {
    padding: 8px 11px;
    font-size: 12.5px;
}
.chatbot-message .message-time { font-size: 9.5px; margin-top: 3px; }

/* Tighten suggestion chips row */
.chatbot-suggestions {
    padding: 5px 12px 7px;
    gap: 5px;
}
.suggestion-chip {
    padding: 4px 10px;
    font-size: 11px;
}

/* Shrink image tray */
.chatbot-image-tray {
    max-height: 96px;
    padding: 6px 10px 2px;
}
.tray-item             { width: 58px; height: 58px; }
.tray-count-label      { font-size: 9.5px; }

/* Tighten input row */
.chatbot-input-wrapper {
    padding: 8px 10px;
    gap: 6px;
}
.chatbot-input {
    padding: 8px 13px;
    font-size: 12.5px;
}
.chatbot-icon-btn,
.chatbot-send {
    width: 36px;
    height: 36px;
}
.chatbot-icon-btn svg,
.chatbot-send svg      { width: 15px; height: 15px; }

/* Typing / uploading indicators */
.chatbot-typing,
.chatbot-img-uploading {
    padding: 8px 12px;
    font-size: 12px;
}
}

/* ────────────────────────────────────────────────────────
   RESPONSIVE
──────────────────────────────────────────────────────── */
@media (max-width:768px) {
    .chatbot-container { bottom:90px; right:10px; left:10px; width:auto; height:520px; border-radius:18px; }
    .chatbot-toggle    { bottom:40px; right:14px; width:58px; height:58px; }
    .chatbot-icon      { width:28px; height:28px; }
}
@media (max-width:480px) { .chatbot-container { height:470px; } }

/* ────────────────────────────────────────────────────────
   CLEAR CONFIRMATION MODAL
──────────────────────────────────────────────────────── */
.chatbot-clear-backdrop {
    position:fixed; z-index:10000; inset:0;
    background:rgba(15,23,42,.4); backdrop-filter:blur(6px);
    display:none; align-items:center; justify-content:center;
}
.chatbot-clear-backdrop.active { display:flex; }
.chatbot-clear-modal {
    background:var(--card-bg,#fff); border-radius:20px;
    box-shadow:0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding:32px 26px 22px; width:320px; max-width:92vw;
    animation:modalPop .28s cubic-bezier(.34,1.56,.64,1);
    display:flex; flex-direction:column; align-items:center; text-align:center;
}
[data-theme="dark"] .chatbot-clear-modal {
    background:rgba(24,24,30,.98);
    box-shadow:0 25px 50px rgba(0,0,0,.5);
    border:1px solid rgba(255,255,255,.08);
}
@keyframes modalPop {
    from{transform:translateY(24px) scale(.93);opacity:0}
    to  {transform:translateY(0)    scale(1);  opacity:1}
}
.chatbot-clear-modal .icon-wrap {
    width:60px; height:60px;
    background:linear-gradient(135deg,rgba(239,68,68,.12),rgba(239,68,68,.08));
    border-radius:50%; margin:0 auto 14px;
    display:flex; align-items:center; justify-content:center; font-size:26px;
    border:1px solid rgba(239,68,68,.2);
}
.chatbot-clear-modal .modal-title { font-size:1.05rem; font-weight:700; color:var(--text-primary,#1a1a2e); margin-bottom:8px; }
[data-theme="dark"] .chatbot-clear-modal .modal-title { color:#e2e8f0; }
.chatbot-clear-modal .modal-desc  { color:var(--text-secondary,#64748b); font-size:.92rem; margin-bottom:22px; line-height:1.5; }
[data-theme="dark"] .chatbot-clear-modal .modal-desc  { color:#94a3b8; }
.chatbot-clear-modal .modal-btns  { display:flex; gap:10px; width:100%; }
.chatbot-clear-modal .modal-btn   { flex:1; padding:10px 0; border-radius:10px; border:none; font-weight:600; font-size:14px; cursor:pointer; transition:all .18s ease; }
.chatbot-clear-modal .modal-btn.cancel  { background:var(--bg-secondary,#f1f5f9); color:var(--text-primary,#374151); border:1px solid var(--border-color,#e2e8f0); }
[data-theme="dark"] .chatbot-clear-modal .modal-btn.cancel { background:rgba(255,255,255,.06); color:#e2e8f0; border-color:rgba(255,255,255,.1); }
.chatbot-clear-modal .modal-btn.cancel:hover  { background:var(--border-color,#e2e8f0); }
.chatbot-clear-modal .modal-btn.confirm { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; box-shadow:0 4px 12px rgba(239,68,68,.3); }
.chatbot-clear-modal .modal-btn.confirm:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(239,68,68,.4); }

/* ────────────────────────────────────────────────────────
   LIGHTBOX (image zoom)
──────────────────────────────────────────────────────── */
.chatbot-lightbox {
    position:fixed; inset:0; z-index:10002;
    background:rgba(0,0,0,.85); backdrop-filter:blur(8px);
    display:none; align-items:center; justify-content:center;
    cursor:zoom-out;
}
.chatbot-lightbox.active { display:flex; }
.chatbot-lightbox img { max-width:90vw; max-height:88vh; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,.6); }
.chatbot-lightbox-close {
    position:absolute; top:16px; right:20px; color:#fff; font-size:28px;
    cursor:pointer; line-height:1; opacity:.8; background:none; border:none;
    transition:opacity .2s;
}
.chatbot-lightbox-close:hover { opacity:1; }
</style>

<!-- ── Toggle button ─────────────────────────────── -->
<button class="chatbot-toggle" id="chatbotToggle"
        data-i18n-title="chatbot_toggle_title" title="Chat with us" aria-label="Toggle chat">
    <svg class="chatbot-icon" viewBox="0 0 24 24" fill="none">
        <path d="M12 2C6.48 2 2 6.48 2 12c0 1.93.6 3.72 1.62 5.2L2.05 21.71c-.16.47.29.92.76.76L7.32 20.9C8.8 21.92 10.59 22.52 12.52 22.52 18.04 22.52 22.52 18.04 22.52 12.52 22.52 6.48 18.04 2 12 2z" fill="currentColor"/>
        <circle cx="8"  cy="12" r="1.5" fill="white"/>
        <circle cx="12" cy="12" r="1.5" fill="white"/>
        <circle cx="16" cy="12" r="1.5" fill="white"/>
    </svg>
    <span class="chatbot-badge" id="chatbotBadge">1</span>
</button>

<!-- ── Chat panel ───────────────────────────────── -->
<div class="chatbot-container" id="chatbotContainer">

    <!-- Toast -->
    <div class="chatbot-toast" id="chatbotToast"></div>

    <!-- Header -->
    <div class="chatbot-header">
        <div class="chatbot-header-info">
            <div class="chatbot-avatar">🤖</div>
            <div class="chatbot-header-text">
                <h3 data-i18n="chatbot_header_title">InfraGov Assistant</h3>
                <div class="chatbot-status">
                    <div class="chatbot-status-dot"></div>
                    <span data-i18n="chatbot_status_online">Online · Ready to help</span>
                </div>
            </div>
        </div>
        <div class="chatbot-header-actions">
            <button class="chatbot-header-btn" id="chatbotClear"
                    data-i18n-title="chatbot_clear_title" title="Clear conversation">🗑️</button>
            <button class="chatbot-header-btn" id="chatbotClose"
                    data-i18n-title="chatbot_close_title" title="Close chat">✕</button>
        </div>
    </div>

    <!-- Messages -->
    <div class="chatbot-messages" id="chatbotMessages">
        <div class="chatbot-img-uploading" id="chatbotImgUploading">
            <div class="spin"></div>
            <span data-i18n="chatbot_analyzing_image">Analyzing your screenshot…</span>
        </div>
    </div>

    <!-- Suggestion chips -->
    <div class="chatbot-suggestions" id="chatbotSuggestions">
        <button class="suggestion-chip" data-i18n="chip_how_report" data-i18n-msg="chip_how_report_msg">How to report?</button>
        <button class="suggestion-chip" data-i18n="chip_upload"     data-i18n-msg="chip_upload_msg">Upload photos</button>
        <button class="suggestion-chip" data-i18n="chip_track"      data-i18n-msg="chip_track_msg">Track my request</button>
        <button class="suggestion-chip" data-i18n="chip_contact"    data-i18n-msg="chip_contact_msg">Contact support</button>
    </div>

    <!-- Typing indicator -->
    <div class="chatbot-typing" id="chatbotTyping">
        <span class="typing-dots"><span></span><span></span><span></span></span>
        <span data-i18n="chatbot_typing_label">Typing…</span>
    </div>

    <!-- ── Image preview tray (shows queued images before send) -->
    <div class="chatbot-image-tray" id="chatbotImageTray">
        <div class="tray-count-label" id="chatbotTrayLabel">
            <span id="chatbotTrayCount">0</span> image(s) queued — type your message then Send
        </div>
    </div>

    <!-- ── Input row: [Mic] [Gallery] [Input] [Send] ───────── -->
    <div class="chatbot-input-wrapper">

        <!-- Mic button -->
        <button class="chatbot-icon-btn chatbot-mic-btn" id="chatbotMicBtn"
                data-i18n-title="chatbot_mic_btn_title"
                title="Speak your message" aria-label="Voice input" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                <line x1="12" y1="19" x2="12" y2="23"/>
                <line x1="8"  y1="23" x2="16" y2="23"/>
            </svg>
        </button>

        <!-- Gallery button — visible on ALL screen sizes -->
        <button class="chatbot-icon-btn chatbot-gallery-btn" id="chatbotGalleryBtn"
                data-i18n-title="chatbot_img_btn_title"
                title="Attach screenshots (up to 5)" aria-label="Attach screenshots" type="button">
            <!-- Gallery / image stack icon -->
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
            </svg>
            <!-- Badge shows how many images are queued -->
            <span class="img-count-dot" id="chatbotImgCountDot">0</span>
        </button>
        <input type="file" id="chatbotImgInput" accept="image/*" multiple style="display:none" aria-hidden="true">

        <!-- Text input -->
        <input type="text" class="chatbot-input" id="chatbotInput"
               data-i18n-placeholder="chatbot_input_placeholder"
               placeholder="Type your message…"
               autocomplete="off" maxlength="600">

        <!-- Send button -->
        <button class="chatbot-send" id="chatbotSend"
                data-i18n-title="chatbot_send_title"
                title="Send message" aria-label="Send">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>
</div>

<!-- Clear confirmation modal -->
<div class="chatbot-clear-backdrop" id="chatbotClearBackdrop">
    <div class="chatbot-clear-modal">
        <div class="icon-wrap">🗑️</div>
        <div class="modal-title" data-i18n="chatbot_modal_title">Clear Conversation?</div>
        <div class="modal-desc"  data-i18n="chatbot_modal_desc">This will delete all messages. This action cannot be undone.</div>
        <div class="modal-btns">
            <button class="modal-btn cancel"  id="chatbotClearCancel"  data-i18n="chatbot_modal_cancel">Cancel</button>
            <button class="modal-btn confirm" id="chatbotClearConfirm" data-i18n="chatbot_modal_confirm">Clear All</button>
        </div>
    </div>
</div>

<!-- Lightbox for zooming images -->
<div class="chatbot-lightbox" id="chatbotLightbox" role="dialog" aria-modal="true">
    <button class="chatbot-lightbox-close" id="chatbotLightboxClose" aria-label="Close">✕</button>
    <img id="chatbotLightboxImg" src="" alt="Full size preview">
</div>

<script>
(function () {
    'use strict';

    /* ══════════════════════════════════════════════════════
       CONFIG
    ══════════════════════════════════════════════════════ */
    var CONFIG = {
        STORAGE_KEY:       'chatbot_conversation_v4',
        STORAGE_STATE_KEY: 'chatbot_state_v4',
        MAX_MESSAGES:      60,
        MAX_IMAGES:        5,          // images per send
        MAX_IMAGE_BYTES:   5242880,    // 5 MB
        AUTO_HIDE_CHIPS:   7000,
        ENDPOINT: (window.CHATBOT_ENDPOINT || 'chatbot.php')
    };

    /* ══════════════════════════════════════════════════════
       i18n HELPER
    ══════════════════════════════════════════════════════ */
    var FALLBACKS = {
        chatbot_welcome:
            "Hello! 👋 I'm your InfraGovServices assistant. I can help you with:\n" +
            "• Reporting infrastructure issues\n• Understanding system features\n" +
            "• Tracking your requests\n• Privacy Policy & Terms questions\n• Navigation help\n\n" +
            "How can I assist you today?",
        chatbot_error_generic:  'Sorry, I encountered an error. Please try again.',
        chatbot_error_connect:  "Sorry, I'm having trouble connecting. Please try again later.",
        chatbot_analyzing_image:'Analyzing your screenshot…',
        chatbot_img_btn_title:  'Attach screenshots (up to 5)',
        chatbot_mic_btn_title:  'Click to speak · Click again to stop',
        chatbot_mic_listening:  'Listening… click mic again to stop',
        chatbot_mic_no_support: 'Voice input is not supported in your browser.',
        chatbot_mic_error:      'Could not hear you. Please try again.',
        chatbot_img_error:      'Could not read the image. Please try a different file.',
        chatbot_status_online:  'Online · Ready to help',
        chip_how_report_msg:    'How do I report an infrastructure issue?',
        chip_upload_msg:        'How do I upload photo evidence?',
        chip_track_msg:         'How can I track the status of my request?',
        chip_contact_msg:       'How do I contact support?',
        chip_privacy_msg:       'Tell me about the Privacy Policy.',
        chip_terms_msg:         'What are the Terms and Conditions?',
        chip_language_msg:      'How do I switch the language?',
        chip_report_types_msg:  'What types of issues can I report?',
        chip_feedback_submit_msg:  'How do I submit feedback on this system?',
        chip_feedback_types_msg:   'What types of feedback can I submit?',
        chip_feedback_rating_msg:  'How does the star rating work on the feedback form?',
        chip_feedback_ref_msg:     'What is the reference completed report field in feedback?'
    };

    function t(key) {
        var lang  = getLang();
        var trans = window.__preloadedTranslations;
        if (trans && trans[lang]  && trans[lang][key]  !== undefined) return trans[lang][key];
        if (trans && trans['en']  && trans['en'][key]  !== undefined) return trans['en'][key];
        return FALLBACKS[key] || '';
    }

    /* ══════════════════════════════════════════════════════
       DOM REFS
    ══════════════════════════════════════════════════════ */
    var el = {
        toggle:        document.getElementById('chatbotToggle'),
        container:     document.getElementById('chatbotContainer'),
        close:         document.getElementById('chatbotClose'),
        clear:         document.getElementById('chatbotClear'),
        messages:      document.getElementById('chatbotMessages'),
        input:         document.getElementById('chatbotInput'),
        send:          document.getElementById('chatbotSend'),
        typing:        document.getElementById('chatbotTyping'),
        suggestions:   document.getElementById('chatbotSuggestions'),
        badge:         document.getElementById('chatbotBadge'),
        clearBackdrop: document.getElementById('chatbotClearBackdrop'),
        clearCancel:   document.getElementById('chatbotClearCancel'),
        clearConfirm:  document.getElementById('chatbotClearConfirm'),
        toast:         document.getElementById('chatbotToast'),
        galleryBtn:    document.getElementById('chatbotGalleryBtn'),
        imgInput:      document.getElementById('chatbotImgInput'),
        imgCountDot:   document.getElementById('chatbotImgCountDot'),
        micBtn:        document.getElementById('chatbotMicBtn'),
        imgUploading:  document.getElementById('chatbotImgUploading'),
        imageTray:     document.getElementById('chatbotImageTray'),
        trayCount:     document.getElementById('chatbotTrayCount'),
        trayLabel:     document.getElementById('chatbotTrayLabel'),
        lightbox:      document.getElementById('chatbotLightbox'),
        lightboxImg:   document.getElementById('chatbotLightboxImg'),
        lightboxClose: document.getElementById('chatbotLightboxClose'),
    };

    /* ══════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════ */
    var conversationHistory  = [];
    var isWaitingForResponse = false;
    var suggestionTimeout    = null;
    var recognition          = null;
    var isRecording          = false;

    /** Queued images: array of { dataUrl: string, preview: string } */
    var queuedImages = [];

    /* ══════════════════════════════════════════════════════
       LANGUAGE
    ══════════════════════════════════════════════════════ */
    function getLang() { return localStorage.getItem('lang') || 'en'; }

    function refreshLanguage() {
        var roots = [el.container, el.toggle, el.clearBackdrop].filter(Boolean);
        roots.forEach(function (root) {
            root.querySelectorAll('[data-i18n]').forEach(function (e) {
                var v = t(e.getAttribute('data-i18n')); if (v) e.textContent = v;
            });
            root.querySelectorAll('[data-i18n-placeholder]').forEach(function (e) {
                var v = t(e.getAttribute('data-i18n-placeholder')); if (v) e.placeholder = v;
            });
            root.querySelectorAll('[data-i18n-title]').forEach(function (e) {
                var v = t(e.getAttribute('data-i18n-title')); if (v) e.title = v;
            });
        });
        renderContextChips();
        updateSuggestionsLayout();
        updateTrayLabel();

        // Refresh welcome message if present
        var hasWelcome = conversationHistory.some(function (m) { return m.isWelcome; });
        if (hasWelcome) {
            var nw = t('chatbot_welcome');
            conversationHistory.forEach(function (m) { if (m.isWelcome) m.text = nw; });
            saveConversation();
            renderMessages();
        }
    }
    window.__chatbotRefreshLang = refreshLanguage;

    /* ══════════════════════════════════════════════════════
       PAGE CONTEXT
    ══════════════════════════════════════════════════════ */
    function getCurrentPage() {
        var path = window.location.pathname.toLowerCase();
        if (path.includes('citizencimm'))       return 'home';
        if (path.includes('citizenreports'))    return 'reports';
        if (path.includes('citizenrepform'))    return 'request';
        if (path.includes('citizen_feedback'))  return 'feedback';
        if (path.includes('about'))             return 'about';
        if (path.includes('privacy'))           return 'privacy';
        if (path.includes('termcon'))           return 'terms';
        return 'general';
    }

    /* ══════════════════════════════════════════════════════
       CHIPS
    ══════════════════════════════════════════════════════ */
    var CHIP_SETS = {
        home:     [['chip_how_report','chip_how_report_msg'],['chip_track','chip_track_msg'],['chip_language','chip_language_msg'],['chip_contact','chip_contact_msg']],
        reports:  [['chip_track','chip_track_msg'],['chip_how_report','chip_how_report_msg'],['chip_report_types','chip_report_types_msg'],['chip_contact','chip_contact_msg']],
        request:  [['chip_how_report','chip_how_report_msg'],['chip_upload','chip_upload_msg'],['chip_report_types','chip_report_types_msg'],['chip_terms','chip_terms_msg']],
        feedback: [['chip_feedback_submit','chip_feedback_submit_msg'],['chip_feedback_types','chip_feedback_types_msg'],['chip_feedback_rating','chip_feedback_rating_msg'],['chip_feedback_ref','chip_feedback_ref_msg']],
        about:    [['chip_how_report','chip_how_report_msg'],['chip_contact','chip_contact_msg'],['chip_privacy','chip_privacy_msg'],['chip_language','chip_language_msg']],
        privacy:  [['chip_privacy','chip_privacy_msg'],['chip_terms','chip_terms_msg'],['chip_contact','chip_contact_msg'],['chip_how_report','chip_how_report_msg']],
        terms:    [['chip_terms','chip_terms_msg'],['chip_privacy','chip_privacy_msg'],['chip_how_report','chip_how_report_msg'],['chip_contact','chip_contact_msg']],
        general:  [['chip_how_report','chip_how_report_msg'],['chip_upload','chip_upload_msg'],['chip_track','chip_track_msg'],['chip_contact','chip_contact_msg']]
    };
    var CHIP_FALLBACK = {
        chip_how_report:'How to report?',       chip_upload:'Upload photos',
        chip_track:'Track my request',          chip_contact:'Contact support',
        chip_privacy:'Privacy Policy',          chip_terms:'Terms & Conditions',
        chip_language:'Switch language',        chip_report_types:'What can I report?',
        chip_feedback_submit:'How to give feedback?',
        chip_feedback_types:'Feedback types',
        chip_feedback_rating:'Star rating',
        chip_feedback_ref:'Reference report'
    };

    function renderContextChips() {
        if (!el.suggestions) return;
        var page  = getCurrentPage();
        var chips = CHIP_SETS[page] || CHIP_SETS['general'];
        el.suggestions.innerHTML = '';
        chips.forEach(function (chip) {
            var label   = t(chip[0]) || CHIP_FALLBACK[chip[0]] || chip[0];
            var message = t(chip[1]) || FALLBACKS[chip[1]]     || label;
            var btn = document.createElement('button');
            btn.className       = 'suggestion-chip';
            btn.textContent     = label;
            btn.dataset.message = message;
            el.suggestions.appendChild(btn);
        });
        updateSuggestionsLayout();
    }

    function updateSuggestionsLayout() {
        if (!el.suggestions) return;
        el.suggestions.classList.toggle('lang-tl', getLang() === 'tl');
    }

    /* ══════════════════════════════════════════════════════
       TOAST
    ══════════════════════════════════════════════════════ */
    var toastTimer = null;
    function showToast(msg, ms) {
        if (!el.toast) return;
        clearTimeout(toastTimer);
        el.toast.textContent = msg;
        el.toast.classList.add('show');
        toastTimer = setTimeout(function () { el.toast.classList.remove('show'); }, ms || 2500);
    }

    /* ══════════════════════════════════════════════════════
       UTILITIES
    ══════════════════════════════════════════════════════ */
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
                saveConversation();
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
        if (el.micBtn)    el.micBtn.disabled    = busy;
        if (el.galleryBtn) el.galleryBtn.disabled = busy;
    }

    /* ══════════════════════════════════════════════════════
       IMAGE QUEUE / TRAY
    ══════════════════════════════════════════════════════ */
    function updateTrayLabel() {
        var tl = getLang() === 'tl';
        if (el.trayCount) el.trayCount.textContent = queuedImages.length;
        if (el.trayLabel) {
            el.trayLabel.innerHTML = '<span id="chatbotTrayCount">' + queuedImages.length + '</span> ' +
                (tl ? 'larawan ang naka-queue — mag-type ng mensahe at pindutin ang Send'
                    : 'image(s) queued — type your message then Send');
        }
    }

    function renderTray() {
        if (!el.imageTray) return;

        // Remove old tray items (keep the label)
        var items = el.imageTray.querySelectorAll('.tray-item');
        items.forEach(function (i) { i.parentNode.removeChild(i); });

        if (queuedImages.length === 0) {
            el.imageTray.classList.remove('has-images');
            el.imgCountDot && el.imgCountDot.classList.remove('show');
            return;
        }

        el.imageTray.classList.add('has-images');
        if (el.imgCountDot) {
            el.imgCountDot.textContent = queuedImages.length;
            el.imgCountDot.classList.add('show');
        }

        queuedImages.forEach(function (img, idx) {
            var item = document.createElement('div');
            item.className = 'tray-item';

            var imgEl = document.createElement('img');
            imgEl.src = img.preview;
            imgEl.alt = 'Queued image ' + (idx + 1);
            imgEl.title = 'Click to zoom';
            imgEl.addEventListener('click', function () { openLightbox(img.preview); });

            var removeBtn = document.createElement('button');
            removeBtn.className = 'tray-remove';
            removeBtn.textContent = '✕';
            removeBtn.title = 'Remove image';
            removeBtn.addEventListener('click', function () { removeQueuedImage(idx); });

            item.appendChild(imgEl);
            item.appendChild(removeBtn);
            el.imageTray.appendChild(item);
        });

        updateTrayLabel();
    }

    function removeQueuedImage(idx) {
        queuedImages.splice(idx, 1);
        renderTray();
        if (queuedImages.length === 0) el.imgInput && (el.imgInput.value = '');
    }

    function addImagesToQueue(files) {
        var allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
        var added   = 0;

        for (var i = 0; i < files.length; i++) {
            if (queuedImages.length >= CONFIG.MAX_IMAGES) {
                showToast('⚠️ Max ' + CONFIG.MAX_IMAGES + ' images per message', 2500);
                break;
            }
            var file = files[i];
            if (allowed.indexOf(file.type) === -1) {
                showToast('⚠️ Only JPG, PNG, WEBP, GIF allowed', 2500); continue;
            }
            if (file.size > CONFIG.MAX_IMAGE_BYTES) {
                showToast('⚠️ ' + file.name + ' is too large (max 5 MB)', 2500); continue;
            }
            (function (f) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    queuedImages.push({ dataUrl: e.target.result, preview: e.target.result });
                    renderTray();
                };
                reader.onerror = function () { showToast('❌ ' + t('chatbot_img_error'), 2500); };
                reader.readAsDataURL(f);
            })(file);
            added++;
        }
        if (added > 0) {
            var tl = getLang() === 'tl';
            showToast(
                (tl ? '✅ ' + added + ' larawang naka-queue. Mag-type at pindutin ang Send.'
                    : '✅ ' + added + ' image(s) queued. Type your message and press Send.'),
                3000
            );
            // Focus text input so user types their message
            setTimeout(function () { el.input && el.input.focus(); }, 100);
        }
    }

    /* ══════════════════════════════════════════════════════
       LIGHTBOX
    ══════════════════════════════════════════════════════ */
    function openLightbox(src) {
        if (!el.lightbox || !el.lightboxImg) return;
        el.lightboxImg.src = src;
        el.lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeLightbox() {
        if (!el.lightbox) return;
        el.lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }
    el.lightbox      && el.lightbox.addEventListener('click', function (e) { if (e.target === el.lightbox) closeLightbox(); });
    el.lightboxClose && el.lightboxClose.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLightbox(); });

    /* ══════════════════════════════════════════════════════
       MESSAGE RENDERING
    ══════════════════════════════════════════════════════ */
    function addMessage(text, type, imageDataUrls, aiCardHtml) {
        if (!text && (!imageDataUrls || imageDataUrls.length === 0)) return;
        var message = {
            text:          text || '',
            type:          type,
            timestamp:     new Date().toISOString(),
            page:          getCurrentPage(),
            imageDataUrls: imageDataUrls || [],
            aiCard:        aiCardHtml || null
        };
        conversationHistory.push(message);
        if (conversationHistory.length > CONFIG.MAX_MESSAGES)
            conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
        saveConversation();
        renderMessage(message);
        scrollToBottom();
    }

    function renderMessage(message) {
        var div = document.createElement('div');
        div.className = 'chatbot-message ' + message.type;

        // Image grid
        var imgs = message.imageDataUrls || (message.imageDataUrl ? [message.imageDataUrl] : []);
        if (imgs.length > 0) {
            var grid = document.createElement('div');
            grid.className = 'msg-image-grid';
            imgs.forEach(function (src) {
                var img = document.createElement('img');
                img.src = src;
                img.alt = 'Attached image';
                img.addEventListener('click', function () { openLightbox(src); });
                grid.appendChild(img);
            });
            div.appendChild(grid);
        }

        // Text
        if (message.text) {
            var textSpan = document.createElement('span');
            textSpan.innerHTML = message.text
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>');
            div.appendChild(textSpan);
        }

        // AI card
        if (message.aiCard) {
            var card = document.createElement('div');
            card.className = 'ai-analysis-card';
            card.innerHTML = message.aiCard;
            div.appendChild(card);
        }

        // Time
        var timeSpan = document.createElement('span');
        timeSpan.className   = 'message-time';
        timeSpan.textContent = formatTime(new Date(message.timestamp));
        div.appendChild(timeSpan);

        // Insert before typing/uploading indicators
        var anchor = (el.typing && el.typing.parentNode === el.messages ? el.typing : null)
                  || (el.imgUploading && el.imgUploading.parentNode === el.messages ? el.imgUploading : null);
        if (anchor) el.messages.insertBefore(div, anchor);
        else        el.messages.appendChild(div);
    }

    function renderMessages() {
        Array.prototype.slice.call(el.messages.children).forEach(function (k) {
            if (k !== el.imgUploading && k !== el.typing) el.messages.removeChild(k);
        });
        conversationHistory.forEach(renderMessage);
        scrollToBottom();
    }

    function showWelcomeMessage() {
        var welcome = t('chatbot_welcome') || FALLBACKS.chatbot_welcome;
        var message = {
            text: welcome, type: 'bot',
            timestamp: new Date().toISOString(),
            page: getCurrentPage(),
            isWelcome: true,
            imageDataUrls: [], aiCard: null
        };
        conversationHistory.push(message);
        if (conversationHistory.length > CONFIG.MAX_MESSAGES)
            conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
        saveConversation();
        renderMessage(message);
        scrollToBottom();
        if (el.suggestions) el.suggestions.style.display = 'flex';
    }

    /* ══════════════════════════════════════════════════════
       CLEAR MODAL
    ══════════════════════════════════════════════════════ */
    function openClearModal()  { el.clearBackdrop && el.clearBackdrop.classList.add('active'); }
    function closeClearModal() { el.clearBackdrop && el.clearBackdrop.classList.remove('active'); }
    function confirmClear() {
        conversationHistory = [];
        queuedImages = [];
        sessionStorage.removeItem(CONFIG.STORAGE_KEY);
        renderTray();
        renderMessages();
        showWelcomeMessage();
        closeClearModal();
    }

    /* ══════════════════════════════════════════════════════
       SEND MESSAGE (text + optional queued images)
    ══════════════════════════════════════════════════════ */
    function sendMessage() {
        var text     = (el.input ? el.input.value.trim() : '');
        var hasText  = text.length > 0;
        var hasImgs  = queuedImages.length > 0;

        if ((!hasText && !hasImgs) || isWaitingForResponse) return;

        // Show user message bubble
        var userImgUrls = queuedImages.map(function (q) { return q.preview; });
        addMessage(hasText ? text : '', 'user', userImgUrls, null);

        // Clear input + queue
        if (el.input) el.input.value = '';
        var imagesToSend = queuedImages.slice();    // copy before clearing
        queuedImages = [];
        renderTray();
        if (el.imgInput) el.imgInput.value = '';

        hideSuggestions();

        // Build API payload
        var payload = {
            message: hasText ? text : (getLang() === 'tl'
                ? 'Nagsumite ako ng mga screenshot ng website para sa pagsusuri.'
                : 'I submitted screenshots of the website for analysis.'),
            context: getCurrentPage(),
            history: conversationHistory.slice(-6).map(function (m) {
                return { text: m.text, type: m.type };
            }),
            lang:    getLang(),
        };

        if (hasImgs) {
            // Primary image (for vision APIs that support single-image)
            payload.image = imagesToSend[0].dataUrl;
            // All images as array (for future multi-image support)
            payload.images = imagesToSend.map(function (q) { return q.dataUrl; });
            // Show analyzing indicator
            el.imgUploading && el.imgUploading.classList.add('active');
        } else {
            el.typing && el.typing.classList.add('active');
        }

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
            el.imgUploading && el.imgUploading.classList.remove('active');
            el.typing       && el.typing.classList.remove('active');
            var resp = data.response || t('chatbot_error_generic');
            var card = data.aiCardHtml || null;
            addMessage(resp, 'bot', [], card);
        })
        .catch(function () {
            el.imgUploading && el.imgUploading.classList.remove('active');
            el.typing       && el.typing.classList.remove('active');
            addMessage(t('chatbot_error_connect') || FALLBACKS.chatbot_error_connect, 'bot', [], null);
        })
        .finally(function () {
            isWaitingForResponse = false;
            setInputsBusy(false);
            scrollToBottom();
        });
    }

    /* ══════════════════════════════════════════════════════
       VOICE / MIC — Manual toggle on/off
    ══════════════════════════════════════════════════════ */
    function initSpeechRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            if (el.micBtn) {
                var noMsg = getLang() === 'tl'
                    ? 'Hindi sinusuportahan ng browser ang voice input'
                    : 'Voice input not supported in this browser';
                el.micBtn.title          = noMsg;
                el.micBtn.style.opacity  = '0.35';
                el.micBtn.style.cursor   = 'not-allowed';
                el.micBtn.disabled       = true;
            }
            return null;
        }

        var rec          = new SR();
        rec.continuous   = true;     // keep listening until user stops manually
        rec.interimResults= false;
        rec.maxAlternatives = 1;

        rec.onstart = function () {
            isRecording = true;
            if (el.micBtn) el.micBtn.classList.add('mic-active');
            showToast('🎙️ ' + (t('chatbot_mic_listening') || 'Listening… click mic again to stop'), 60000);
        };

        rec.onresult = function (e) {
            // Accumulate all results (continuous mode returns multiple)
            var transcript = '';
            for (var i = e.resultIndex; i < e.results.length; i++) {
                transcript += e.results[i][0].transcript;
            }
            if (el.input) {
                el.input.value = (el.input.value + ' ' + transcript).trim();
            }
        };

        rec.onerror = function (e) {
            var msg = e.error === 'no-speech'
                ? (getLang()==='tl' ? 'Walang narinig.' : 'No speech detected.')
                : e.error === 'not-allowed'
                    ? (getLang()==='tl' ? 'Tinanggihan ang mikropono.' : 'Microphone permission denied.')
                    : (t('chatbot_mic_error') || 'Could not hear you.');
            showToast('❌ ' + msg, 3000);
            stopMic();
        };

        rec.onend = function () {
            // If still supposed to be recording (user hasn't clicked stop), restart
            if (isRecording) {
                try { rec.start(); } catch(e) { stopMic(); }
            }
        };

        return rec;
    }

    function stopMic() {
        isRecording = false;
        if (el.micBtn) el.micBtn.classList.remove('mic-active');
        if (recognition) { try { recognition.stop(); } catch(e){} }
        // Dismiss the listening toast
        if (el.toast) el.toast.classList.remove('show');
        var tl = getLang() === 'tl';
        showToast(tl ? '🛑 Huminto na ang voice input.' : '🛑 Voice input stopped.', 1800);
    }

    function startMic() {
        if (!recognition) {
            showToast('❌ ' + (t('chatbot_mic_no_support') || 'Voice input not supported.'), 3000);
            return;
        }
        recognition.lang = getLang() === 'tl' ? 'fil-PH' : 'en-US';
        isRecording = true;
        try { recognition.start(); }
        catch(err) {
            // Already running — stop instead
            stopMic();
        }
    }

    function toggleMic() {
        if (isWaitingForResponse) return;
        if (isRecording) stopMic();
        else             startMic();
    }

    /* ══════════════════════════════════════════════════════
       TOGGLE / CLOSE
    ══════════════════════════════════════════════════════ */
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

    /* ══════════════════════════════════════════════════════
       EVENT WIRING
    ══════════════════════════════════════════════════════ */
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

    // Gallery button — opens multi-file picker (NO auto-send)
    el.galleryBtn && el.galleryBtn.addEventListener('click', function () {
        if (!isWaitingForResponse && el.imgInput) el.imgInput.click();
    });
    el.imgInput && el.imgInput.addEventListener('change', function (e) {
        if (e.target.files && e.target.files.length > 0) {
            addImagesToQueue(Array.prototype.slice.call(e.target.files));
            e.target.value = ''; // reset so same files can be re-added
        }
    });

    // Mic
    el.micBtn && el.micBtn.addEventListener('click', toggleMic);

    // Suggestion chips
    el.suggestions && el.suggestions.addEventListener('click', function (e) {
        if (e.target.classList.contains('suggestion-chip')) {
            if (el.input) el.input.value = e.target.dataset.message || e.target.textContent.trim();
            sendMessage();
        }
    });

    /* ══════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════ */
    function init() {
        recognition = initSpeechRecognition();
        loadConversation();
        renderContextChips();
        renderTray();

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
            var delay = (localStorage.getItem('lang') === 'tl' && !window.__preloadedTranslations) ? 650 : 0;
            setTimeout(showWelcomeMessage, delay);
        }

        // Restore open state if last closed within 60 s
        if (state && state.isOpen && (Date.now() - state.timestamp) < 60000) {
            setTimeout(function () {
                el.container.classList.add('active');
                el.toggle.classList.add('active');
            }, 300);
        }

        updateSuggestionsLayout();
    }

    window.addEventListener('beforeunload', function () {
        if (isRecording) stopMic();
        saveChatState(el.container.classList.contains('active'));
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

})();
</script>
<!-- ═══════════════ CHATBOT WIDGET v4 END ═══════════════ -->