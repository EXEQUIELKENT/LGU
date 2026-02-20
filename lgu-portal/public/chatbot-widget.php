<?php
/**
 * Chatbot Widget - Standalone Include File (i18n-enabled)
 * v3 – redesigned UI + input reorder (Mic | Input | Send on desktop, Mic | Image | Input | Send on mobile)
 * + image recognition via InfraAI TFJS + voice recognition improvements
 *
 * Usage: <?php include 'chatbot-widget.php'; ?>
 */
?>

<!-- CHATBOT WIDGET - START -->
<style>
/* ═══════════════════════════════════════════════════════════
   CHATBOT WIDGET STYLES v3
═══════════════════════════════════════════════════════════ */

/* ── Toggle Button ─────────────────────────────────────── */
.chatbot-toggle {
    position: fixed;
    bottom: 75px;
    right: 24px;
    width: 62px;
    height: 62px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 22px rgba(43, 108, 176, 0.45), 0 0 0 0 rgba(43, 108, 176, 0.3);
    z-index: 9998;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    overflow: visible;
}
.chatbot-toggle::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    background: transparent;
    border: 2px solid rgba(43, 108, 176, 0.3);
    animation: ringPulse 2.5s ease-out infinite;
}
@keyframes ringPulse {
    0%   { transform: scale(1);    opacity: 0.8; }
    70%  { transform: scale(1.35); opacity: 0; }
    100% { transform: scale(1.35); opacity: 0; }
}
.chatbot-toggle:hover  { transform: scale(1.1) translateY(-2px); box-shadow: 0 8px 28px rgba(43,108,176,.55); }
.chatbot-toggle:active { transform: scale(0.95); }
.chatbot-toggle.active { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
.chatbot-toggle.active::before { border-color: rgba(220, 53, 69, 0.3); }
.chatbot-icon { width: 32px; height: 32px; transition: all 0.3s ease; }
.chatbot-toggle.active .chatbot-icon { transform: rotate(180deg); }

.chatbot-badge {
    position: absolute; top: -4px; right: -4px;
    background: linear-gradient(135deg, #f59e0b, #ef4444);
    color: #fff; border-radius: 50%;
    width: 22px; height: 22px; font-size: 11px; font-weight: 700;
    display: none; align-items: center; justify-content: center;
    border: 2px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    animation: badgePop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.chatbot-badge.show { display: flex; }
@keyframes badgePop {
    from { transform: scale(0); }
    to   { transform: scale(1); }
}

/* ── Container ─────────────────────────────────────────── */
.chatbot-container {
    position: fixed; bottom: 100px; right: 24px;
    width: 390px;
    height: 570px;
    background: var(--card-bg, #ffffff);
    border-radius: 22px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 0 0 1px rgba(43,108,176,.08);
    display: none; flex-direction: column;
    z-index: 9999; overflow: hidden;
    border: 1px solid var(--border-color, rgba(0,0,0,.08));
    transition: all 0.3s ease;
    animation: chatSlideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes chatSlideUp {
    from { opacity: 0; transform: translateY(30px) scale(0.95); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.chatbot-container.active { display: flex; }
[data-theme="dark"] .chatbot-container {
    background: rgba(24,24,28,.98);
    border-color: rgba(255,255,255,.08);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.05);
}

/* ── Header ────────────────────────────────────────────── */
.chatbot-header {
    background: linear-gradient(135deg, #1e4d8c 0%, #2563eb 60%, #3b82f6 100%);
    color: #fff; padding: 0 18px;
    height: 68px;
    display: flex; justify-content: space-between; align-items: center;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}
.chatbot-header::after {
    content: '';
    position: absolute;
    top: -30px; right: -30px;
    width: 120px; height: 120px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}
.chatbot-header::before {
    content: '';
    position: absolute;
    bottom: -40px; left: 20px;
    width: 80px; height: 80px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
}
.chatbot-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 1;
}
.chatbot-avatar {
    width: 40px; height: 40px;
    background: rgba(255,255,255,.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    border: 2px solid rgba(255,255,255,.3);
    flex-shrink: 0;
}
.chatbot-header-text h3 {
    margin: 0 0 2px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.chatbot-status {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px; opacity: 0.85;
}
.chatbot-status-dot {
    width: 7px; height: 7px;
    background: #4ade80;
    border-radius: 50%;
    box-shadow: 0 0 6px #4ade80;
    animation: statusPulse 2s ease-in-out infinite;
}
@keyframes statusPulse {
    0%,100% { opacity: 1; }
    50%      { opacity: 0.5; }
}
.chatbot-header-actions { display: flex; gap: 6px; z-index: 1; }
.chatbot-header-btn {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
    width: 32px; height: 32px;
    border-radius: 10px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
    font-size: 14px;
    backdrop-filter: blur(4px);
}
.chatbot-header-btn:hover { background: rgba(255,255,255,.28); transform: translateY(-1px); }
.chatbot-header-btn:active { transform: scale(0.92); }

/* ── Messages ──────────────────────────────────────────── */
.chatbot-messages {
    flex: 1; overflow-y: auto; padding: 16px 16px 8px;
    display: flex; flex-direction: column; gap: 10px;
    background: var(--bg-secondary, #f8fafd);
    scroll-behavior: smooth;
}
[data-theme="dark"] .chatbot-messages { background: rgba(18,18,22,.95); }
.chatbot-messages::-webkit-scrollbar { width: 4px; }
.chatbot-messages::-webkit-scrollbar-track { background: transparent; }
.chatbot-messages::-webkit-scrollbar-thumb { background: rgba(43,108,176,.25); border-radius: 2px; }
.chatbot-messages::-webkit-scrollbar-thumb:hover { background: rgba(43,108,176,.45); }

/* Date divider */
.chat-date-divider {
    display: flex; align-items: center; gap: 10px;
    margin: 4px 0;
    font-size: 11px;
    color: var(--text-secondary, #999);
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.chat-date-divider::before,
.chat-date-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-color, rgba(0,0,0,.08));
}

.chatbot-message {
    max-width: 82%; padding: 10px 14px;
    border-radius: 16px; font-size: 13.5px; line-height: 1.55;
    word-wrap: break-word;
    animation: msgFade 0.3s ease;
    position: relative;
}
@keyframes msgFade {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.chatbot-message.user {
    align-self: flex-end;
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    color: #fff;
    border-bottom-right-radius: 4px;
    box-shadow: 0 2px 10px rgba(37,99,235,.25);
}
.chatbot-message.bot {
    align-self: flex-start;
    background: var(--card-bg, #ffffff);
    color: var(--text-primary, #1a1a2e);
    border-bottom-left-radius: 4px;
    border: 1px solid var(--border-color, rgba(0,0,0,.07));
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
}
[data-theme="dark"] .chatbot-message.bot {
    background: rgba(36,36,44,.95);
    color: var(--text-primary, #e2e8f0);
    border-color: rgba(255,255,255,.07);
}
.chatbot-message .message-time {
    font-size: 10px; opacity: .55; margin-top: 5px; display: block;
    font-weight: 500;
}
.chatbot-message.user .message-time { text-align: right; }

/* Image preview in message */
.chatbot-message .msg-image-preview {
    max-width: 100%; max-height: 160px; object-fit: cover;
    border-radius: 10px; margin-bottom: 8px; display: block;
    border: 1px solid rgba(255,255,255,.2);
}
.chatbot-message.bot .msg-image-preview {
    border: 1px solid var(--border-color, rgba(0,0,0,.1));
}

/* AI badge on bot message */
.chatbot-message.bot::before {
    content: '🤖';
    position: absolute;
    left: -14px; top: 8px;
    font-size: 14px;
    line-height: 1;
}

/* ── Typing Indicator ──────────────────────────────────── */
.chatbot-typing {
    align-self: flex-start;
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--border-color, rgba(0,0,0,.07));
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    padding: 12px 16px;
    border-radius: 16px; border-bottom-left-radius: 4px;
    font-size: 13px; display: none;
    align-items: center; gap: 8px;
    color: var(--text-secondary, #666);
    animation: msgFade 0.3s ease;
    position: relative;
}
.chatbot-typing::before {
    content: '🤖';
    font-size: 14px;
    position: absolute;
    left: -14px; top: 8px;
}
[data-theme="dark"] .chatbot-typing {
    background: rgba(36,36,44,.95);
    border-color: rgba(255,255,255,.07);
    color: var(--text-secondary, #94a3b8);
}
.chatbot-typing.active { display: flex; }
.typing-dots { display: inline-flex; gap: 4px; }
.typing-dots span {
    width: 7px; height: 7px;
    background: #2563eb;
    border-radius: 50%; opacity: .35;
    animation: typingDot 1.4s infinite;
}
.typing-dots span:nth-child(2) { animation-delay: .2s; }
.typing-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes typingDot {
    0%,60%,100% { opacity: .35; transform: scale(1); }
    30%          { opacity: 1;   transform: scale(1.3) translateY(-2px); }
}

/* ── Image Analyzing ───────────────────────────────────── */
.chatbot-img-uploading {
    align-self: flex-start;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, rgba(0,0,0,.07));
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    padding: 12px 16px;
    border-radius: 16px; border-bottom-left-radius: 4px;
    font-size: 13px; display: none;
    align-items: center; gap: 10px;
    animation: msgFade 0.3s ease;
    position: relative;
}
.chatbot-img-uploading::before {
    content: '🤖';
    font-size: 14px;
    position: absolute;
    left: -14px; top: 8px;
}
[data-theme="dark"] .chatbot-img-uploading {
    background: rgba(36,36,44,.95);
    color: var(--text-secondary, #94a3b8);
    border-color: rgba(255,255,255,.07);
}
.chatbot-img-uploading.active { display: flex; }
.chatbot-img-uploading .spin {
    width: 18px; height: 18px;
    border: 2.5px solid rgba(37,99,235,.2);
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: spinAnim 0.7s linear infinite; flex-shrink: 0;
}
@keyframes spinAnim { to { transform: rotate(360deg); } }

/* ── AI Analysis Result Card ───────────────────────────── */
.ai-analysis-card {
    background: linear-gradient(135deg, rgba(37,99,235,.08) 0%, rgba(99,102,241,.08) 100%);
    border: 1px solid rgba(37,99,235,.2);
    border-radius: 12px;
    padding: 12px 14px;
    margin-top: 8px;
    font-size: 12.5px;
    line-height: 1.6;
}
[data-theme="dark"] .ai-analysis-card {
    background: linear-gradient(135deg, rgba(37,99,235,.15) 0%, rgba(99,102,241,.12) 100%);
    border-color: rgba(37,99,235,.3);
}
.ai-analysis-card .ai-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #2563eb;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.ai-analysis-card .ai-confidence {
    display: inline-block;
    background: rgba(37,99,235,.15);
    color: #2563eb;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 10px;
    font-weight: 700;
    margin-left: auto;
}

/* ── Suggestions ───────────────────────────────────────── */
.chatbot-suggestions {
    padding: 8px 16px 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    overflow-x: hidden;
    background: var(--bg-secondary, #f8fafd);
    flex-shrink: 0;
    border-top: 1px solid var(--border-color, rgba(0,0,0,.06));
}
.chatbot-suggestions.lang-tl {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(43,108,176,.2) transparent;
}
.chatbot-suggestions.lang-tl::-webkit-scrollbar { height: 2px; }
.chatbot-suggestions.lang-tl::-webkit-scrollbar-track { background: transparent; }
.chatbot-suggestions.lang-tl::-webkit-scrollbar-thumb { background: rgba(43,108,176,.2); border-radius: 1px; }
[data-theme="dark"] .chatbot-suggestions { background: rgba(18,18,22,.95); border-color: rgba(255,255,255,.06); }
.suggestion-chip {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, rgba(0,0,0,.1));
    color: var(--text-secondary, #4a5568);
    padding: 5px 12px; border-radius: 20px; font-size: 11.5px;
    cursor: pointer; transition: all 0.2s ease;
    white-space: nowrap;
    font-weight: 500;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
[data-theme="dark"] .suggestion-chip {
    background: rgba(36,36,44,.9);
    border-color: rgba(255,255,255,.1);
    color: var(--text-secondary, #94a3b8);
}
.suggestion-chip:hover {
    background: #2563eb; color: #fff;
    border-color: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37,99,235,.25);
}
.suggestion-chip:active { transform: scale(0.96); }

/* ═══════════════════════════════════════════════════════
   INPUT AREA — DESKTOP: Mic | Input | Send
                MOBILE:  Mic | Image | Input | Send
═══════════════════════════════════════════════════════ */
.chatbot-input-wrapper {
    padding: 12px 14px;
    border-top: 1px solid var(--border-color, rgba(0,0,0,.07));
    background: var(--card-bg, #fff);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
[data-theme="dark"] .chatbot-input-wrapper {
    background: rgba(24,24,28,.98);
    border-top-color: rgba(255,255,255,.06);
}

/* ── Text Input ─────────────────────────────────────────── */
.chatbot-input {
    flex: 1;
    min-width: 0;
    border: 1.5px solid var(--border-color, #e2e8f0);
    border-radius: 22px;
    padding: 10px 16px;
    font-size: 13.5px;
    outline: none;
    background: var(--input-bg, #f8fafd);
    color: var(--text-primary, #1a1a2e);
    transition: all 0.2s ease;
    font-family: inherit;
}
[data-theme="dark"] .chatbot-input {
    background: rgba(36,36,44,.9);
    border-color: rgba(255,255,255,.1);
    color: var(--text-primary, #e2e8f0);
}
.chatbot-input:focus {
    border-color: #2563eb;
    background: var(--card-bg, #fff);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
[data-theme="dark"] .chatbot-input:focus { background: rgba(40,40,50,.95); }
.chatbot-input::placeholder { color: var(--input-placeholder, #a0aec0); font-size: 13px; }

/* ── Shared Icon Button ──────────────────────────────────── */
.chatbot-icon-btn {
    background: var(--bg-secondary, #f1f5f9);
    border: 1.5px solid var(--border-color, #e2e8f0);
    color: var(--text-secondary, #64748b);
    width: 40px; height: 40px;
    border-radius: 50%;
    cursor: pointer; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
}
[data-theme="dark"] .chatbot-icon-btn {
    background: rgba(36,36,44,.9);
    border-color: rgba(255,255,255,.1);
    color: var(--text-secondary, #94a3b8);
}
.chatbot-icon-btn:hover {
    background: #2563eb; color: #fff;
    border-color: #2563eb;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(37,99,235,.3);
}
.chatbot-icon-btn:active { transform: scale(0.92); }
.chatbot-icon-btn:disabled { opacity: .38; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

/* Mic recording state */
.chatbot-icon-btn.mic-active {
    background: #ef4444 !important;
    border-color: #ef4444 !important;
    color: #fff !important;
    animation: micPulse 1s ease-in-out infinite;
}
@keyframes micPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
    50%      { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
}

/* ── Image Button: HIDDEN on desktop, VISIBLE on mobile ── */
.chatbot-img-btn { display: none; }

/* ── Send Button ─────────────────────────────────────────── */
.chatbot-send {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    border: none; color: #fff;
    width: 40px; height: 40px;
    border-radius: 50%;
    cursor: pointer; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(37,99,235,.3);
}
.chatbot-send:hover { transform: scale(1.1) translateY(-1px); box-shadow: 0 6px 16px rgba(37,99,235,.4); }
.chatbot-send:active { transform: scale(0.92); }
.chatbot-send:disabled { opacity: .45; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

/* ── Toast ───────────────────────────────────────────────── */
.chatbot-toast {
    position: absolute; bottom: 90px; left: 50%; transform: translateX(-50%);
    background: rgba(15,23,42,.88);
    color: #fff;
    padding: 8px 18px; border-radius: 22px; font-size: 12px;
    white-space: nowrap; z-index: 10001; opacity: 0;
    transition: opacity 0.25s ease;
    pointer-events: none;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,.1);
    font-weight: 500;
}
.chatbot-toast.show { opacity: 1; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
    .chatbot-container {
        bottom: 90px; right: 10px; left: 10px;
        width: auto; height: 500px;
        border-radius: 18px;
    }
    .chatbot-toggle { bottom: 40px; right: 14px; width: 58px; height: 58px; }
    .chatbot-icon { width: 28px; height: 28px; }
    /* Show image button on mobile */
    .chatbot-img-btn { display: flex; }
}
@media (max-width: 480px) {
    .chatbot-container { height: 460px; }
}

/* ── Clear Modal ─────────────────────────────────────────── */
.chatbot-clear-backdrop {
    position: fixed; z-index: 10000; inset: 0;
    background: rgba(15,23,42,.4);
    backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
.chatbot-clear-backdrop.active { display: flex; }
.chatbot-clear-modal {
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 22px;
    width: 320px; max-width: 92vw;
    animation: modalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex; flex-direction: column; align-items: center;
    text-align: center;
}
[data-theme="dark"] .chatbot-clear-modal {
    background: rgba(24,24,30,.98);
    box-shadow: 0 25px 50px rgba(0,0,0,.5);
    border: 1px solid rgba(255,255,255,.08);
}
@keyframes modalPop {
    from { transform: translateY(24px) scale(.93); opacity: 0; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}
.chatbot-clear-modal .icon-wrap {
    width: 60px; height: 60px;
    background: linear-gradient(135deg, rgba(239,68,68,.12), rgba(239,68,68,.08));
    border-radius: 50%; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    border: 1px solid rgba(239,68,68,.2);
}
.chatbot-clear-modal .modal-title {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    margin-bottom: 8px;
}
[data-theme="dark"] .chatbot-clear-modal .modal-title { color: #e2e8f0; }
.chatbot-clear-modal .modal-desc {
    color: var(--text-secondary, #64748b);
    font-size: .92rem; margin-bottom: 22px;
    line-height: 1.5;
}
[data-theme="dark"] .chatbot-clear-modal .modal-desc { color: #94a3b8; }
.chatbot-clear-modal .modal-btns { display: flex; gap: 10px; width: 100%; }
.chatbot-clear-modal .modal-btn {
    flex: 1; padding: 10px 0;
    border-radius: 10px; border: none;
    font-weight: 600; font-size: 14px; cursor: pointer;
    transition: all .18s ease;
}
.chatbot-clear-modal .modal-btn.cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #374151);
    border: 1px solid var(--border-color, #e2e8f0);
}
[data-theme="dark"] .chatbot-clear-modal .modal-btn.cancel {
    background: rgba(255,255,255,.06);
    color: #e2e8f0;
    border-color: rgba(255,255,255,.1);
}
.chatbot-clear-modal .modal-btn.cancel:hover { background: var(--border-color, #e2e8f0); }
.chatbot-clear-modal .modal-btn.confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    box-shadow: 0 4px 12px rgba(239,68,68,.3);
}
.chatbot-clear-modal .modal-btn.confirm:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(239,68,68,.4);
}
</style>

<!-- Toggle button -->
<button class="chatbot-toggle" id="chatbotToggle"
        data-i18n-title="chatbot_toggle_title"
        title="Chat with us" aria-label="Toggle chat">
    <svg class="chatbot-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 2C6.48 2 2 6.48 2 12C2 13.93 2.6 15.72 3.62 17.2L2.05 21.71C1.89 22.18 2.34 22.63 2.81 22.47L7.32 20.9C8.8 21.92 10.59 22.52 12.52 22.52C18.04 22.52 22.52 18.04 22.52 12.52C22.52 6.48 18.04 2 12 2Z"
              fill="currentColor"/>
        <circle cx="8"  cy="12" r="1.5" fill="white"/>
        <circle cx="12" cy="12" r="1.5" fill="white"/>
        <circle cx="16" cy="12" r="1.5" fill="white"/>
    </svg>
    <span class="chatbot-badge" id="chatbotBadge">1</span>
</button>

<!-- Chat panel -->
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
                    data-i18n-title="chatbot_clear_title"
                    title="Clear conversation">🗑️</button>
            <button class="chatbot-header-btn" id="chatbotClose"
                    data-i18n-title="chatbot_close_title"
                    title="Close chat">✕</button>
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

    <!-- Typing -->
    <div class="chatbot-typing" id="chatbotTyping">
        <span class="typing-dots"><span></span><span></span><span></span></span>
        <span data-i18n="chatbot_typing_label">Typing…</span>
    </div>

    <!-- ═══════════════════════════════════════════════════
         INPUT ROW
         Desktop: [Mic] [────── Input ──────] [Send]
         Mobile:  [Mic] [Img] [── Input ──] [Send]
    ══════════════════════════════════════════════════════ -->
    <div class="chatbot-input-wrapper">

        <!-- Mic: always first -->
        <button class="chatbot-icon-btn chatbot-mic-btn" id="chatbotMicBtn"
                data-i18n-title="chatbot_mic_btn_title"
                title="Speak your message" aria-label="Voice input" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                <line x1="12" y1="19" x2="12" y2="23"/>
                <line x1="8"  y1="23" x2="16" y2="23"/>
            </svg>
        </button>

        <!-- Image upload: mobile only (CSS controls visibility) -->
        <button class="chatbot-icon-btn chatbot-img-btn" id="chatbotImgBtn"
                data-i18n-title="chatbot_img_btn_title"
                title="Upload a screenshot" aria-label="Upload screenshot" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                <path d="M16 3l-4 4-4-4"/>
                <circle cx="12" cy="14" r="3"/>
            </svg>
        </button>
        <input type="file" id="chatbotImgInput" accept="image/*" style="display:none" aria-hidden="true">

        <!-- Text input -->
        <input type="text" class="chatbot-input" id="chatbotInput"
               data-i18n-placeholder="chatbot_input_placeholder"
               placeholder="Type your message…"
               autocomplete="off" maxlength="500">

        <!-- Send: always last -->
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

<script>
(function () {
    'use strict';

    /* ── CONFIG ──────────────────────────────────────────── */
    var CONFIG = {
        STORAGE_KEY:           'chatbot_conversation',
        STORAGE_STATE_KEY:     'chatbot_state',
        MAX_MESSAGES:          50,
        AUTO_HIDE_SUGGESTIONS: 6000,
        ENDPOINT:              'chatbot.php'
    };

    /* ── i18n ────────────────────────────────────────────── */
    var FALLBACKS = {
        chatbot_welcome:
            "Hello! 👋 I'm your InfraGovServices assistant. I can help you with:\n" +
            "• Reporting infrastructure issues\n• Understanding system features\n" +
            "• Tracking your requests\n• Privacy Policy & Terms questions\n• Navigation help\n\n" +
            "How can I assist you today?",
        chatbot_error_generic:  'Sorry, I encountered an error. Please try again.',
        chatbot_error_connect:  "Sorry, I'm having trouble connecting. Please try again later.",
        chatbot_analyzing_image:'Analyzing your screenshot…',
        chatbot_img_btn_title:  'Upload a screenshot',
        chatbot_mic_btn_title:  'Speak your message',
        chatbot_mic_listening:  'Listening… speak now',
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
        chip_report_types_msg:  'What types of issues can I report?'
    };

    function t(key) {
        var lang  = getLang();
        var trans = window.__preloadedTranslations;
        if (trans && trans[lang] && trans[lang][key] !== undefined) return trans[lang][key];
        if (trans && trans['en'] && trans['en'][key] !== undefined) return trans['en'][key];
        return FALLBACKS[key] || '';
    }

    /* ── DOM ─────────────────────────────────────────────── */
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
        imgBtn:        document.getElementById('chatbotImgBtn'),
        imgInput:      document.getElementById('chatbotImgInput'),
        micBtn:        document.getElementById('chatbotMicBtn'),
        imgUploading:  document.getElementById('chatbotImgUploading')
    };

    /* ── STATE ───────────────────────────────────────────── */
    var conversationHistory  = [];
    var isWaitingForResponse = false;
    var suggestionTimeout    = null;
    var recognition          = null;
    var isRecording          = false;

    /* ── LANGUAGE ────────────────────────────────────────── */
    function getLang() { return localStorage.getItem('lang') || 'en'; }

    function refreshLanguage() {
        var roots = [
            document.getElementById('chatbotContainer'),
            document.getElementById('chatbotToggle'),
            document.getElementById('chatbotClearBackdrop')
        ].filter(Boolean);

        roots.forEach(function (root) {
            root.querySelectorAll('[data-i18n]').forEach(function (elem) {
                var val = t(elem.getAttribute('data-i18n'));
                if (val) elem.textContent = val;
            });
            root.querySelectorAll('[data-i18n-placeholder]').forEach(function (elem) {
                var val = t(elem.getAttribute('data-i18n-placeholder'));
                if (val) elem.placeholder = val;
            });
            root.querySelectorAll('[data-i18n-title]').forEach(function (elem) {
                var val = t(elem.getAttribute('data-i18n-title'));
                if (val) elem.title = val;
            });
        });

        renderContextChips();
        updateSuggestionsLayout();

        var hasWelcome = conversationHistory.some(function (m) { return m.isWelcome; });
        if (hasWelcome) {
            var newWelcome = t('chatbot_welcome');
            conversationHistory.forEach(function (m) { if (m.isWelcome) m.text = newWelcome; });
            saveConversation();
            renderMessages();
        }
    }

    window.__chatbotRefreshLang = refreshLanguage;

    /* ── PAGE CONTEXT ────────────────────────────────────── */
    function getCurrentPage() {
        var path = window.location.pathname.toLowerCase();
        if (path.includes('citizencimm'))    return 'home';
        if (path.includes('citizenreports')) return 'reports';
        if (path.includes('citizenrepform')) return 'request';
        if (path.includes('about'))          return 'about';
        if (path.includes('privacy'))        return 'privacy';
        if (path.includes('termcon'))        return 'terms';
        return 'general';
    }

    /* ── CHIPS ───────────────────────────────────────────── */
    var CHIP_SETS = {
        home:    [{ i18n:'chip_how_report',   msg:'chip_how_report_msg'   },{ i18n:'chip_track',        msg:'chip_track_msg'        },{ i18n:'chip_language',   msg:'chip_language_msg'   },{ i18n:'chip_contact',    msg:'chip_contact_msg'    }],
        reports: [{ i18n:'chip_track',        msg:'chip_track_msg'        },{ i18n:'chip_how_report',   msg:'chip_how_report_msg'   },{ i18n:'chip_report_types',msg:'chip_report_types_msg'},{ i18n:'chip_contact',    msg:'chip_contact_msg'    }],
        request: [{ i18n:'chip_how_report',   msg:'chip_how_report_msg'   },{ i18n:'chip_upload',       msg:'chip_upload_msg'       },{ i18n:'chip_report_types',msg:'chip_report_types_msg'},{ i18n:'chip_terms',      msg:'chip_terms_msg'      }],
        about:   [{ i18n:'chip_how_report',   msg:'chip_how_report_msg'   },{ i18n:'chip_contact',      msg:'chip_contact_msg'      },{ i18n:'chip_privacy',    msg:'chip_privacy_msg'    },{ i18n:'chip_language',   msg:'chip_language_msg'   }],
        privacy: [{ i18n:'chip_privacy',      msg:'chip_privacy_msg'      },{ i18n:'chip_terms',        msg:'chip_terms_msg'        },{ i18n:'chip_contact',    msg:'chip_contact_msg'    },{ i18n:'chip_how_report', msg:'chip_how_report_msg' }],
        terms:   [{ i18n:'chip_terms',        msg:'chip_terms_msg'        },{ i18n:'chip_privacy',      msg:'chip_privacy_msg'      },{ i18n:'chip_how_report', msg:'chip_how_report_msg' },{ i18n:'chip_contact',    msg:'chip_contact_msg'    }],
        general: [{ i18n:'chip_how_report',   msg:'chip_how_report_msg'   },{ i18n:'chip_upload',       msg:'chip_upload_msg'       },{ i18n:'chip_track',      msg:'chip_track_msg'      },{ i18n:'chip_contact',    msg:'chip_contact_msg'    }]
    };
    var CHIP_LABEL_FALLBACKS = { chip_how_report:'How to report?', chip_upload:'Upload photos', chip_track:'Track my request', chip_contact:'Contact support', chip_privacy:'Privacy Policy', chip_terms:'Terms & Conditions', chip_language:'Switch language', chip_report_types:'What can I report?' };

    function renderContextChips() {
        if (!el.suggestions) return;
        var page  = getCurrentPage();
        var chips = CHIP_SETS[page] || CHIP_SETS['general'];
        el.suggestions.innerHTML = '';
        chips.forEach(function (chip) {
            var label   = t(chip.i18n) || CHIP_LABEL_FALLBACKS[chip.i18n] || chip.i18n;
            var message = t(chip.msg)  || FALLBACKS[chip.msg]              || label;
            var btn = document.createElement('button');
            btn.className = 'suggestion-chip';
            btn.textContent = label;
            btn.setAttribute('data-i18n', chip.i18n);
            btn.setAttribute('data-i18n-msg', chip.msg);
            btn.setAttribute('data-message', message);
            el.suggestions.appendChild(btn);
        });
        updateSuggestionsLayout();
    }

    function updateSuggestionsLayout() {
        if (!el.suggestions) return;
        el.suggestions.classList.toggle('lang-tl', getLang() === 'tl');
    }

    /* ── TOAST ───────────────────────────────────────────── */
    var toastTimer = null;
    function showToast(msg, ms) {
        if (!el.toast) return;
        clearTimeout(toastTimer);
        el.toast.textContent = msg;
        el.toast.classList.add('show');
        toastTimer = setTimeout(function () { el.toast.classList.remove('show'); }, ms || 2500);
    }

    /* ── UTILITIES ───────────────────────────────────────── */
    function formatTime(date) {
        return date.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
    }
    function saveConversation() {
        try { sessionStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(conversationHistory)); } catch(e){}
    }
    function loadConversation() {
        try {
            var saved = sessionStorage.getItem(CONFIG.STORAGE_KEY);
            if (saved) {
                conversationHistory = JSON.parse(saved);
                if (conversationHistory.length > CONFIG.MAX_MESSAGES) {
                    conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
                    saveConversation();
                }
                return conversationHistory;
            }
        } catch(e){}
        return [];
    }
    function saveChatState(isOpen) {
        try { sessionStorage.setItem(CONFIG.STORAGE_STATE_KEY, JSON.stringify({ isOpen:isOpen, timestamp:Date.now(), lastViewedMessageCount:conversationHistory.length })); } catch(e){}
    }
    function loadChatState() {
        try { var s = sessionStorage.getItem(CONFIG.STORAGE_STATE_KEY); if (s) return JSON.parse(s); } catch(e){}
        return null;
    }
    function scrollToBottom() { setTimeout(function(){ el.messages.scrollTop = el.messages.scrollHeight; }, 80); }
    function hideSuggestions() { if (el.suggestions && conversationHistory.length > 1) el.suggestions.style.display = 'none'; }
    function setInputsBusy(busy) {
        el.send.disabled = busy;
        if (el.micBtn) el.micBtn.disabled = busy;
        if (el.imgBtn) el.imgBtn.disabled = busy;
    }

    /* ── MESSAGES ────────────────────────────────────────── */
    function addMessage(text, type, saveToHistory, imageDataUrl, aiCard) {
        if (saveToHistory === undefined) saveToHistory = true;
        var message = { text:text, type:type, timestamp:new Date().toISOString(), page:getCurrentPage(), imageDataUrl:imageDataUrl||null, aiCard:aiCard||null };
        if (saveToHistory) {
            conversationHistory.push(message);
            if (conversationHistory.length > CONFIG.MAX_MESSAGES) conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
            saveConversation();
        }
        renderMessage(message);
        scrollToBottom();
    }

    function renderMessage(message) {
        var div = document.createElement('div');
        div.className = 'chatbot-message ' + message.type;

        if (message.imageDataUrl) {
            var img = document.createElement('img');
            img.className = 'msg-image-preview';
            img.src = message.imageDataUrl;
            img.alt = 'Uploaded image';
            div.appendChild(img);
        }

        var textSpan = document.createElement('span');
        textSpan.innerHTML = message.text.replace(/\n/g,'<br>');
        div.appendChild(textSpan);

        // AI analysis card
        if (message.aiCard) {
            var card = document.createElement('div');
            card.className = 'ai-analysis-card';
            card.innerHTML = message.aiCard;
            div.appendChild(card);
        }

        var timeSpan = document.createElement('span');
        timeSpan.className = 'message-time';
        timeSpan.textContent = formatTime(new Date(message.timestamp));
        div.appendChild(timeSpan);

        var anchor = el.typing && el.typing.parentNode === el.messages ? el.typing : null;
        if (!anchor && el.imgUploading && el.imgUploading.parentNode === el.messages) anchor = el.imgUploading;
        if (anchor) el.messages.insertBefore(div, anchor);
        else el.messages.appendChild(div);
    }

    function renderMessages() {
        var kids = Array.prototype.slice.call(el.messages.children);
        kids.forEach(function(k){ if (k !== el.imgUploading && k !== el.typing) el.messages.removeChild(k); });
        conversationHistory.forEach(renderMessage);
        scrollToBottom();
    }

    function showWelcomeMessage() {
        var message = { text:t('chatbot_welcome'), type:'bot', timestamp:new Date().toISOString(), page:getCurrentPage(), isWelcome:true };
        conversationHistory.push(message);
        if (conversationHistory.length > CONFIG.MAX_MESSAGES) conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
        saveConversation();
        renderMessage(message);
        scrollToBottom();
        if (el.suggestions) el.suggestions.style.display = 'flex';
    }

    /* ── CLEAR MODAL ─────────────────────────────────────── */
    function openClearModal()  { if (el.clearBackdrop) el.clearBackdrop.classList.add('active'); }
    function closeClearModal() { if (el.clearBackdrop) el.clearBackdrop.classList.remove('active'); }
    function confirmClear() {
        conversationHistory = [];
        sessionStorage.removeItem(CONFIG.STORAGE_KEY);
        renderMessages();
        showWelcomeMessage();
        closeClearModal();
    }

    /* ── TEXT API ────────────────────────────────────────── */
    function sendMessage() {
        var message = el.input.value.trim();
        if (!message || isWaitingForResponse) return;
        addMessage(message, 'user');
        el.input.value = '';
        hideSuggestions();
        if (el.typing) el.typing.classList.add('active');
        isWaitingForResponse = true;
        setInputsBusy(true);

        fetch(CONFIG.ENDPOINT, {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ message:message, context:getCurrentPage(), history:conversationHistory.slice(-5), lang:getLang() })
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (el.typing) el.typing.classList.remove('active');
            addMessage(data.response || t('chatbot_error_generic'), 'bot');
        })
        .catch(function(){
            if (el.typing) el.typing.classList.remove('active');
            addMessage(t('chatbot_error_connect'), 'bot');
        })
        .finally(function(){
            isWaitingForResponse = false;
            setInputsBusy(false);
            scrollToBottom();
        });
    }

    /* ══════════════════════════════════════════════════════
       IMAGE API — runs InfraAI TFJS analysis if available,
       then sends base64 + AI results to chatbot.php
    ══════════════════════════════════════════════════════ */
    function sendImageMessage(base64DataUrl, previewUrl) {
        hideSuggestions();
        var captionText = getLang() === 'tl'
            ? '📸 Screenshot na-upload para sa pagsusuri…'
            : '📸 Screenshot uploaded for analysis…';
        addMessage(captionText, 'user', true, previewUrl || base64DataUrl);

        if (el.imgUploading) el.imgUploading.classList.add('active');
        isWaitingForResponse = true;
        setInputsBusy(true);
        scrollToBottom();

        // Run local TFJS analysis if InfraAI is available
        var aiPromise;
        if (typeof InfraAI !== 'undefined') {
            // Build a temporary File from the base64 so InfraAI can analyse it
            try {
                var arr = base64DataUrl.split(',');
                var mime = arr[0].match(/:(.*?);/)[1];
                var bstr = atob(arr[1]);
                var n = bstr.length;
                var u8arr = new Uint8Array(n);
                while (n--) u8arr[n] = bstr.charCodeAt(n);
                var file = new File([u8arr], 'chatbot_upload.' + mime.split('/')[1], { type: mime });
                aiPromise = InfraAI.analyzeImages([file], 'General', function(){})
                    .catch(function(){ return null; });
            } catch(e) {
                aiPromise = Promise.resolve(null);
            }
        } else {
            aiPromise = Promise.resolve(null);
        }

        aiPromise.then(function(aiResult) {
            var userMsg = getLang() === 'tl'
                ? 'Nagsumite ako ng screenshot ng website para sa pagsusuri.'
                : 'I submitted a screenshot of the website for analysis.';

            return fetch(CONFIG.ENDPOINT, {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({
                    message:  userMsg,
                    context:  getCurrentPage(),
                    history:  conversationHistory.slice(-5),
                    lang:     getLang(),
                    image:    base64DataUrl,
                    aiResult: aiResult || null      // ← TFJS result forwarded
                })
            });
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (el.imgUploading) el.imgUploading.classList.remove('active');
            var aiCardHtml = data.aiCardHtml || null;
            addMessage(data.response || t('chatbot_error_generic'), 'bot', true, null, aiCardHtml);
        })
        .catch(function(){
            if (el.imgUploading) el.imgUploading.classList.remove('active');
            addMessage(t('chatbot_error_connect'), 'bot');
        })
        .finally(function(){
            isWaitingForResponse = false;
            setInputsBusy(false);
            if (el.imgInput) el.imgInput.value = '';
            scrollToBottom();
        });
    }

    /* ── IMAGE UPLOAD HANDLER ────────────────────────────── */
    function handleImageUpload(file) {
        if (!file) return;
        var allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
        if (allowed.indexOf(file.type) === -1) {
            showToast('⚠️ ' + (getLang()==='tl' ? 'JPG, PNG, o WEBP lamang.' : 'Please upload JPG, PNG, or WEBP.'), 3000);
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            showToast('⚠️ ' + (getLang()==='tl' ? 'Masyadong malaki (max 5 MB).' : 'Image too large (max 5 MB).'), 3000);
            return;
        }
        var reader = new FileReader();
        reader.onerror = function(){ showToast('❌ ' + t('chatbot_img_error'), 2500); };
        reader.onload  = function(e){ sendImageMessage(e.target.result, e.target.result); };
        reader.readAsDataURL(file);
    }

    /* ══════════════════════════════════════════════════════
       VOICE / MIC — Web Speech API
    ══════════════════════════════════════════════════════ */
    function initSpeechRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            if (el.micBtn) {
                el.micBtn.title = getLang()==='tl'
                    ? 'Hindi sinusuportahan ng browser ang voice input'
                    : 'Voice input not supported in this browser';
                el.micBtn.style.opacity = '0.35';
                el.micBtn.style.cursor  = 'not-allowed';
                el.micBtn.disabled = true;
            }
            return null;
        }
        var rec = new SR();
        rec.continuous = false; rec.interimResults = false; rec.maxAlternatives = 1;
        rec.onstart = function(){
            isRecording = true;
            if (el.micBtn) el.micBtn.classList.add('mic-active');
            showToast('🎙️ ' + (t('chatbot_mic_listening') || 'Listening… speak now'), 8000);
        };
        rec.onresult = function(e){
            var transcript = e.results[0][0].transcript;
            el.input.value = transcript;
            el.input.focus();
            showToast('✅ ' + (getLang()==='tl' ? 'Narinig!' : 'Got it!'), 1500);
        };
        rec.onerror = function(e){
            var msg = e.error === 'no-speech'   ? (getLang()==='tl' ? 'Walang narinig.' : 'No speech detected.')
                    : e.error === 'not-allowed' ? (getLang()==='tl' ? 'Tinanggihan ang mikropono.' : 'Microphone permission denied.')
                    : (t('chatbot_mic_error') || 'Could not hear you.');
            showToast('❌ ' + msg, 3000);
        };
        rec.onend = function(){
            isRecording = false;
            if (el.micBtn) el.micBtn.classList.remove('mic-active');
        };
        return rec;
    }

    function toggleMic() {
        if (!recognition) { showToast('❌ ' + (t('chatbot_mic_no_support') || 'Voice input not supported.'), 3000); return; }
        if (isRecording) {
            recognition.stop();
        } else {
            recognition.lang = getLang() === 'tl' ? 'fil-PH' : 'en-US';
            try { recognition.start(); }
            catch(err) { recognition.stop(); setTimeout(function(){ try{ recognition.start(); }catch(e){} }, 300); }
        }
    }

    /* ── TOGGLE / CLOSE ──────────────────────────────────── */
    function toggleChat() {
        var isActive = el.container.classList.toggle('active');
        el.toggle.classList.toggle('active');
        if (isActive) {
            el.input.focus();
            if (el.badge) el.badge.classList.remove('show');
            clearTimeout(suggestionTimeout);
            suggestionTimeout = setTimeout(hideSuggestions, CONFIG.AUTO_HIDE_SUGGESTIONS);
        }
        saveChatState(isActive);
    }
    function closeChat() {
        el.container.classList.remove('active');
        el.toggle.classList.remove('active');
        if (isRecording && recognition) recognition.stop();
        saveChatState(false);
    }

    /* ── EVENTS ──────────────────────────────────────────── */
    if (el.toggle)       el.toggle.addEventListener('click', toggleChat);
    if (el.close)        el.close.addEventListener('click', closeChat);
    if (el.clear)        el.clear.addEventListener('click', openClearModal);
    if (el.clearConfirm) el.clearConfirm.addEventListener('click', confirmClear);
    if (el.clearCancel)  el.clearCancel.addEventListener('click', closeClearModal);
    if (el.clearBackdrop) {
        el.clearBackdrop.addEventListener('click', function(e){ if (e.target === el.clearBackdrop) closeClearModal(); });
    }
    if (el.send)  el.send.addEventListener('click', sendMessage);
    if (el.input) {
        el.input.addEventListener('keypress', function(e){ if (e.key === 'Enter' && !isWaitingForResponse) sendMessage(); });
    }
    if (el.suggestions) {
        el.suggestions.addEventListener('click', function(e){
            if (e.target.classList.contains('suggestion-chip')) {
                el.input.value = e.target.getAttribute('data-message') || e.target.textContent.trim();
                sendMessage();
            }
        });
    }
    if (el.micBtn)  el.micBtn.addEventListener('click', function(){ if (!isWaitingForResponse) toggleMic(); });
    if (el.imgBtn && el.imgInput) {
        el.imgBtn.addEventListener('click', function(){ if (!isWaitingForResponse) el.imgInput.click(); });
        el.imgInput.addEventListener('change', function(e){ var f = e.target.files && e.target.files[0]; if (f) handleImageUpload(f); });
    }

    /* ── INIT ────────────────────────────────────────────── */
    function init() {
        recognition = initSpeechRecognition();
        loadConversation();
        var state = loadChatState();
        renderContextChips();

        if (conversationHistory.length > 0) {
            renderMessages();
            if (el.badge && !el.container.classList.contains('active')) {
                var lastViewed = (state && state.lastViewedMessageCount) || 0;
                var newCount   = conversationHistory.length - lastViewed;
                if (newCount > 0) { el.badge.textContent = newCount; el.badge.classList.add('show'); }
            }
        } else {
            var delay = (localStorage.getItem('lang') === 'tl' && !window.__preloadedTranslations) ? 600 : 0;
            setTimeout(showWelcomeMessage, delay);
        }

        if (state && state.isOpen && (Date.now() - state.timestamp) < 60000) {
            setTimeout(function(){ el.container.classList.add('active'); el.toggle.classList.add('active'); }, 300);
        }
        updateSuggestionsLayout();
    }

    window.addEventListener('beforeunload', function(){
        if (isRecording && recognition) recognition.stop();
        saveChatState(el.container.classList.contains('active'));
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
<!-- CHATBOT WIDGET - END -->