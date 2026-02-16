<?php
/**
 * Chatbot Widget - Standalone Include File (i18n-enabled)
 * 
 * Usage: Simply include this file in any PHP page where you want the chatbot to appear
 * <?php include 'chatbot-widget.php'; ?>
 * 
 * All text elements carry data-i18n attributes so the parent page's
 * translation engine (translations.json) can swap them to Tagalog.
 */
?>

<!-- CHATBOT WIDGET - START -->
<style>
/* Chatbot Widget Styles */
.chatbot-toggle {
    position: fixed;
    bottom: 75px;
    right: 24px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(43, 108, 176, 0.4);
    z-index: 9998;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    overflow: hidden;
}

.chatbot-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 25px rgba(43, 108, 176, 0.5);
}

.chatbot-toggle:active { transform: scale(0.95); }

.chatbot-toggle.active {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
}

.chatbot-icon {
    width: 32px;
    height: 32px;
    transition: all 0.3s ease;
}

.chatbot-toggle.active .chatbot-icon {
    transform: rotate(180deg);
}

.chatbot-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: #dc3545;
    color: #fff;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 11px;
    font-weight: 700;
    display: none;
    align-items: center;
    justify-content: center;
    animation: pulse 2s infinite;
}

.chatbot-badge.show { display: flex; }

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50%       { transform: scale(1.1); opacity: 0.8; }
}

.chatbot-container {
    position: fixed;
    bottom: 100px;
    right: 24px;
    width: 380px;
    height: 550px;
    background: var(--card-bg, #ffffff);
    border-radius: 18px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    display: none;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.1));
    transition: all 0.3s ease;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.chatbot-container.active { display: flex; }

[data-theme="dark"] .chatbot-container {
    background: rgba(30, 30, 30, 0.98);
    border-color: rgba(255, 255, 255, 0.1);
}

.chatbot-header {
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff;
    padding: 18px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    flex-shrink: 0;
}

.chatbot-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chatbot-header-actions { display: flex; gap: 8px; }

.chatbot-clear {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #fff;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.chatbot-clear:hover { background: rgba(255, 255, 255, 0.3); }

.chatbot-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #fff;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.chatbot-close:hover { background: rgba(255, 255, 255, 0.3); }

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: var(--bg-secondary, rgba(255, 255, 255, 0.95));
}

[data-theme="dark"] .chatbot-messages { background: rgba(26, 26, 26, 0.95); }

.chatbot-messages::-webkit-scrollbar { width: 6px; }
.chatbot-messages::-webkit-scrollbar-track { background: transparent; }
.chatbot-messages::-webkit-scrollbar-thumb { background: rgba(43, 108, 176, 0.3); border-radius: 3px; }

.chatbot-message {
    max-width: 80%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.5;
    word-wrap: break-word;
    animation: fadeInMessage 0.3s ease;
}

@keyframes fadeInMessage {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.chatbot-message.user {
    align-self: flex-end;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.chatbot-message.bot {
    align-self: flex-start;
    background: var(--card-bg, #f0f4f8);
    color: var(--text-primary, #333);
    border-bottom-left-radius: 4px;
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.1));
}

[data-theme="dark"] .chatbot-message.bot {
    background: rgba(40, 40, 40, 0.9);
    color: var(--text-primary, #e0e0e0);
}

.chatbot-message .message-time {
    font-size: 10px;
    opacity: 0.6;
    margin-top: 4px;
    display: block;
}

.chatbot-typing {
    align-self: flex-start;
    background: var(--card-bg, #f0f4f8);
    color: var(--text-primary, #666);
    padding: 10px 14px;
    border-radius: 12px;
    border-bottom-left-radius: 4px;
    font-size: 14px;
    display: none;
}

[data-theme="dark"] .chatbot-typing {
    background: rgba(40, 40, 40, 0.9);
    color: var(--text-secondary, #999);
}

.chatbot-typing.active { display: block; }

.typing-dots { display: inline-flex; gap: 4px; margin-left: 4px; }

.typing-dots span {
    width: 6px;
    height: 6px;
    background: currentColor;
    border-radius: 50%;
    opacity: 0.4;
    animation: typingDot 1.4s infinite;
}

.typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.typing-dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typingDot {
    0%, 60%, 100% { opacity: 0.4; transform: scale(1); }
    30%           { opacity: 1; transform: scale(1.2); }
}

.chatbot-input-wrapper {
    padding: 14px 16px;
    border-top: 1px solid var(--border-color, rgba(0, 0, 0, 0.1));
    background: var(--card-bg, #fff);
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}

[data-theme="dark"] .chatbot-input-wrapper { background: rgba(30, 30, 30, 0.98); }

.chatbot-input {
    flex: 1;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 14px;
    outline: none;
    background: var(--input-bg, #fff);
    color: var(--text-primary, #333);
    transition: all 0.2s ease;
}

[data-theme="dark"] .chatbot-input {
    background: rgba(40, 40, 40, 0.9);
    border-color: rgba(255, 255, 255, 0.2);
    color: var(--text-primary, #e0e0e0);
}

.chatbot-input:focus {
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1);
}

.chatbot-input::placeholder { color: var(--input-placeholder, #999); }

.chatbot-send {
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    border: none;
    color: #fff;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.chatbot-send:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(43, 108, 176, 0.3);
}

.chatbot-send:active { transform: scale(0.95); }
.chatbot-send:disabled { opacity: 0.5; cursor: not-allowed; }

.chatbot-suggestions {
    padding: 0 16px 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    background: var(--bg-secondary, rgba(255, 255, 255, 0.95));
    flex-shrink: 0;
}

[data-theme="dark"] .chatbot-suggestions { background: rgba(26, 26, 26, 0.95); }

.suggestion-chip {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #ddd);
    color: var(--text-primary, #333);
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

[data-theme="dark"] .suggestion-chip {
    background: rgba(40, 40, 40, 0.9);
    border-color: rgba(255, 255, 255, 0.2);
    color: var(--text-primary, #e0e0e0);
}

.suggestion-chip:hover {
    background: #2b6cb0;
    color: #fff;
    border-color: #2b6cb0;
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .chatbot-container {
        bottom: 90px;
        right: 12px;
        left: 12px;
        width: auto;
        height: 500px;
    }
    
    .chatbot-toggle {
        bottom: 40px;
        right: 16px;
        width: 56px;
        height: 56px;
    }
    
    .chatbot-icon { width: 28px; height: 28px; }
}

@media (max-width: 480px) {
    .chatbot-container { height: 450px; }
}

/* Clear Conversation Modal */
.chatbot-clear-backdrop {
    position: fixed;
    z-index: 10000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}

.chatbot-clear-backdrop.active { display: flex; }

[data-theme="dark"] .chatbot-clear-backdrop { background: rgba(0, 0, 0, 0.50); }

.chatbot-clear-modal {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 8px 42px rgba(17, 39, 77, 0.15);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeInModal 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

[data-theme="dark"] .chatbot-clear-modal {
    background: #1e1e1e;
    box-shadow: 0 8px 42px rgba(0, 0, 0, 0.5);
}

@keyframes fadeInModal {
    from { transform: translateY(34px) scale(.95); opacity: .24; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}

.chatbot-clear-modal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #fdeeed;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.11);
}

[data-theme="dark"] .chatbot-clear-modal .icon-wrap {
    background: rgba(233, 68, 68, 0.15);
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.2);
}

.chatbot-clear-modal .icon-wrap .icon { color: #e94444; font-size: 2.1rem; line-height: 1; }

.chatbot-clear-modal .modal-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}

[data-theme="dark"] .chatbot-clear-modal .modal-title { color: #e0e0e0; }

.chatbot-clear-modal .modal-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}

[data-theme="dark"] .chatbot-clear-modal .modal-desc { color: #b0b0b0; }

.chatbot-clear-modal .modal-btns { display: flex; gap: 15px; justify-content: center; }

.chatbot-clear-modal .modal-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
}

.chatbot-clear-modal .modal-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}

[data-theme="dark"] .chatbot-clear-modal .modal-btn.cancel {
    background: rgba(255, 255, 255, 0.08);
    color: #e0e0e0;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.chatbot-clear-modal .modal-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}

[data-theme="dark"] .chatbot-clear-modal .modal-btn.cancel:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #6b93ff;
    border-color: rgba(255, 255, 255, 0.25);
}

.chatbot-clear-modal .modal-btn.confirm {
    color: #fff;
    background: #e94444;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(236,82,82,0.08);
}

.chatbot-clear-modal .modal-btn.confirm:hover { background: #c82d2d; }
[data-theme="dark"] .chatbot-clear-modal .modal-btn.confirm:hover { background: #d63939; }
</style>

<button class="chatbot-toggle" id="chatbotToggle"
        data-i18n-title="chatbot_toggle_title"
        title="Chat with us" aria-label="Toggle chat">
    <svg class="chatbot-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 2C6.48 2 2 6.48 2 12C2 13.93 2.6 15.72 3.62 17.2L2.05 21.71C1.89 22.18 2.34 22.63 2.81 22.47L7.32 20.9C8.8 21.92 10.59 22.52 12.52 22.52C18.04 22.52 22.52 18.04 22.52 12.52C22.52 6.48 18.04 2 12 2Z" 
              fill="currentColor" opacity="0.9"/>
        <circle cx="8" cy="12" r="1.5" fill="white"/>
        <circle cx="12" cy="12" r="1.5" fill="white"/>
        <circle cx="16" cy="12" r="1.5" fill="white"/>
    </svg>
    <span class="chatbot-badge" id="chatbotBadge">1</span>
</button>

<div class="chatbot-container" id="chatbotContainer">
    <div class="chatbot-header">
        <h3>
            <span>🤖</span>
            <span data-i18n="chatbot_header_title">InfraGov Assistant</span>
        </h3>
        <div class="chatbot-header-actions">
            <button class="chatbot-clear" id="chatbotClear"
                    data-i18n-title="chatbot_clear_title"
                    title="Clear conversation">🗑️</button>
            <button class="chatbot-close" id="chatbotClose"
                    data-i18n-title="chatbot_close_title"
                    title="Close chat">&times;</button>
        </div>
    </div>
    
    <div class="chatbot-messages" id="chatbotMessages">
        <!-- Messages will be loaded here -->
    </div>
    
    <div class="chatbot-suggestions" id="chatbotSuggestions">
        <button class="suggestion-chip" data-i18n="chip_how_report" data-message-key="chip_how_report_msg">How to report an issue?</button>
        <button class="suggestion-chip" data-i18n="chip_upload"     data-message-key="chip_upload_msg">Upload photos</button>
        <button class="suggestion-chip" data-i18n="chip_track"      data-message-key="chip_track_msg">Track my request</button>
        <button class="suggestion-chip" data-i18n="chip_contact"    data-message-key="chip_contact_msg">Contact support</button>
    </div>
    
    <div class="chatbot-typing" id="chatbotTyping">
        <span data-i18n="chatbot_typing_label">Typing</span><span class="typing-dots"><span></span><span></span><span></span></span>
    </div>
    
    <div class="chatbot-input-wrapper">
        <input 
            type="text" 
            class="chatbot-input" 
            id="chatbotInput" 
            data-i18n-placeholder="chatbot_input_placeholder"
            placeholder="Type your message..."
            autocomplete="off"
            maxlength="500"
        >
        <button class="chatbot-send" id="chatbotSend"
                data-i18n-title="chatbot_send_title"
                title="Send message" aria-label="Send">
            ➤
        </button>
    </div>
</div>

<!-- Clear Conversation Confirmation Modal -->
<div class="chatbot-clear-backdrop" id="chatbotClearBackdrop">
    <div class="chatbot-clear-modal">
        <div class="icon-wrap">
            <span class="icon">🗑️</span>
        </div>
        <div class="modal-title" data-i18n="chatbot_modal_title">Clear Conversation?</div>
        <div class="modal-desc" data-i18n="chatbot_modal_desc">This will delete all conversation history. This action cannot be undone.</div>
        <div class="modal-btns">
            <button class="modal-btn cancel" id="chatbotClearCancel" data-i18n="chatbot_modal_cancel">Cancel</button>
            <button class="modal-btn confirm" id="chatbotClearConfirm" data-i18n="chatbot_modal_confirm">Clear All</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        STORAGE_KEY: 'chatbot_conversation',
        STORAGE_STATE_KEY: 'chatbot_state',
        MAX_MESSAGES: 50,
        AUTO_HIDE_SUGGESTIONS: 5000,
        ENDPOINT: 'chatbot.php'
    };
    
    // DOM Elements
    const elements = {
        toggle: document.getElementById('chatbotToggle'),
        container: document.getElementById('chatbotContainer'),
        close: document.getElementById('chatbotClose'),
        clear: document.getElementById('chatbotClear'),
        messages: document.getElementById('chatbotMessages'),
        input: document.getElementById('chatbotInput'),
        send: document.getElementById('chatbotSend'),
        typing: document.getElementById('chatbotTyping'),
        suggestions: document.getElementById('chatbotSuggestions'),
        badge: document.getElementById('chatbotBadge'),
        clearBackdrop: document.getElementById('chatbotClearBackdrop'),
        clearCancel: document.getElementById('chatbotClearCancel'),
        clearConfirm: document.getElementById('chatbotClearConfirm')
    };
    
    // State
    let conversationHistory = [];
    let isWaitingForResponse = false;
    let suggestionTimeout = null;
    
    // ------------------------------------------------
    // Language helpers — reads the same localStorage
    // key set by the parent page's i18n engine
    // ------------------------------------------------
    function getLang() {
        return localStorage.getItem('lang') || 'en';
    }

    // Welcome message text per language
    const WELCOME = {
        en: `Hello! 👋 I'm your InfraGovServices assistant. I can help you with:\n• Reporting infrastructure issues\n• Understanding the system features\n• Tracking your requests\n• Navigation help\n\nHow can I assist you today?`,
        tl: `Kumusta! 👋 Ako ang iyong InfraGovServices assistant. Maaari kitang tulungan sa:\n• Pag-uulat ng mga isyu sa imprastraktura\n• Pag-unawa sa mga tampok ng sistema\n• Pagsubaybay sa iyong mga kahilingan\n• Tulong sa nabigasyon\n\nPaano kita matutulungan ngayon?`
    };

    // Error / fallback messages per language
    const MSGS = {
        en: {
            error_generic: 'Sorry, I encountered an error. Please try again.',
            error_connect: "Sorry, I'm having trouble connecting. Please try again later."
        },
        tl: {
            error_generic: 'Paumanhin, nagkaroon ng error. Pakisubukang muli.',
            error_connect: 'Paumanhin, nagkakaproblema sa koneksyon. Pakisubukang muli mamaya.'
        }
    };

    function msg(key) {
        const lang = getLang();
        return (MSGS[lang] && MSGS[lang][key]) || MSGS['en'][key] || '';
    }

    // ------------------------------------------------
    // Suggestion chips: update data-message attribute
    // when language changes so the sent text matches
    // ------------------------------------------------
    function updateSuggestionMessages() {
        const lang = getLang();
        // translations object may or may not be loaded yet — fetch from DOM data-i18n text
        const chips = document.querySelectorAll('.suggestion-chip[data-message-key]');
        chips.forEach(chip => {
            const key = chip.getAttribute('data-message-key');
            // The i18n engine already set textContent via data-i18n;
            // use that as the message to send so it matches the displayed label
            chip.setAttribute('data-message', chip.textContent.trim());
        });
    }

    // Expose hook so the parent i18n engine can call this after translation
    window.__chatbotRefreshLang = updateSuggestionMessages;

    // ------------------------------------------------
    // Utility Functions
    // ------------------------------------------------
    function getCurrentPage() {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('citizencimm')) return 'home';
        if (path.includes('citizenreports')) return 'reports';
        if (path.includes('citizenrepform')) return 'request';
        if (path.includes('about')) return 'about';
        return 'general';
    }
    
    function formatTime(date) {
        return date.toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit',
            hour12: true 
        });
    }
    
    function saveConversation() {
        try {
            sessionStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(conversationHistory));
        } catch (e) {}
    }
    
    function loadConversation() {
        try {
            const saved = sessionStorage.getItem(CONFIG.STORAGE_KEY);
            if (saved) {
                conversationHistory = JSON.parse(saved);
                if (conversationHistory.length > CONFIG.MAX_MESSAGES) {
                    conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
                    saveConversation();
                }
                return conversationHistory;
            }
        } catch (e) {}
        return [];
    }
    
    function saveChatState(isOpen) {
        try {
            sessionStorage.setItem(CONFIG.STORAGE_STATE_KEY, JSON.stringify({
                isOpen: isOpen,
                timestamp: Date.now(),
                lastViewedMessageCount: conversationHistory.length
            }));
        } catch (e) {}
    }
    
    function loadChatState() {
        try {
            const saved = sessionStorage.getItem(CONFIG.STORAGE_STATE_KEY);
            if (saved) return JSON.parse(saved);
        } catch (e) {}
        return null;
    }
    
    function clearConversation() {
        if (elements.clearBackdrop) elements.clearBackdrop.classList.add('active');
    }
    
    function confirmClearConversation() {
        conversationHistory = [];
        sessionStorage.removeItem(CONFIG.STORAGE_KEY);
        renderMessages();
        showWelcomeMessage();
        if (elements.clearBackdrop) elements.clearBackdrop.classList.remove('active');
    }
    
    function cancelClearConversation() {
        if (elements.clearBackdrop) elements.clearBackdrop.classList.remove('active');
    }
    
    // ------------------------------------------------
    // Message Functions
    // ------------------------------------------------
    function addMessage(text, type, saveToHistory = true) {
        const message = {
            text: text,
            type: type,
            timestamp: new Date().toISOString(),
            page: getCurrentPage()
        };
        
        if (saveToHistory) {
            conversationHistory.push(message);
            if (conversationHistory.length > CONFIG.MAX_MESSAGES) {
                conversationHistory = conversationHistory.slice(-CONFIG.MAX_MESSAGES);
            }
            saveConversation();
        }
        
        renderMessage(message);
        scrollToBottom();
    }
    
    function renderMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chatbot-message ${message.type}`;
        
        const textSpan = document.createElement('span');
        textSpan.innerHTML = message.text
            .replace(/\n/g, '<br>')
            .replace(/•/g, '•');
        messageDiv.appendChild(textSpan);
        
        const timeSpan = document.createElement('span');
        timeSpan.className = 'message-time';
        timeSpan.textContent = formatTime(new Date(message.timestamp));
        messageDiv.appendChild(timeSpan);
        
        if (elements.typing && elements.typing.parentNode === elements.messages) {
            elements.messages.insertBefore(messageDiv, elements.typing);
        } else {
            elements.messages.appendChild(messageDiv);
        }
    }
    
    function renderMessages() {
        elements.messages.innerHTML = '';
        if (conversationHistory.length === 0) return;
        conversationHistory.forEach(message => renderMessage(message));
        scrollToBottom();
    }
    
    function showWelcomeMessage() {
        const lang = getLang();
        const welcomeText = WELCOME[lang] || WELCOME['en'];
        addMessage(welcomeText, 'bot');
        if (elements.suggestions) elements.suggestions.style.display = 'flex';
    }
    
    function scrollToBottom() {
        setTimeout(() => {
            elements.messages.scrollTop = elements.messages.scrollHeight;
        }, 100);
    }
    
    function hideSuggestions() {
        if (elements.suggestions && conversationHistory.length > 1) {
            elements.suggestions.style.display = 'none';
        }
    }
    
    // ------------------------------------------------
    // API Functions
    // ------------------------------------------------
    function sendMessage() {
        const message = elements.input.value.trim();
        if (!message || isWaitingForResponse) return;
        
        addMessage(message, 'user');
        elements.input.value = '';
        hideSuggestions();
        
        if (elements.typing) elements.typing.classList.add('active');
        
        isWaitingForResponse = true;
        elements.send.disabled = true;
        
        fetch(CONFIG.ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: message,
                context: getCurrentPage(),
                history: conversationHistory.slice(-5),
                lang: getLang()
            })
        })
        .then(response => response.json())
        .then(data => {
            if (elements.typing) elements.typing.classList.remove('active');
            if (data.response) {
                addMessage(data.response, 'bot');
            } else {
                addMessage(msg('error_generic'), 'bot');
            }
        })
        .catch(error => {
            console.error('Chatbot error:', error);
            if (elements.typing) elements.typing.classList.remove('active');
            addMessage(msg('error_connect'), 'bot');
        })
        .finally(() => {
            isWaitingForResponse = false;
            elements.send.disabled = false;
            scrollToBottom();
        });
    }
    
    // ------------------------------------------------
    // UI Functions
    // ------------------------------------------------
    function toggleChat() {
        const isActive = elements.container.classList.toggle('active');
        elements.toggle.classList.toggle('active');
        
        if (isActive) {
            elements.input.focus();
            if (elements.badge) elements.badge.classList.remove('show');
            if (suggestionTimeout) clearTimeout(suggestionTimeout);
            suggestionTimeout = setTimeout(() => hideSuggestions(), CONFIG.AUTO_HIDE_SUGGESTIONS);
        }
        
        saveChatState(isActive);
    }
    
    function closeChat() {
        elements.container.classList.remove('active');
        elements.toggle.classList.remove('active');
        saveChatState(false);
    }
    
    // ------------------------------------------------
    // Event Listeners
    // ------------------------------------------------
    if (elements.toggle)       elements.toggle.addEventListener('click', toggleChat);
    if (elements.close)        elements.close.addEventListener('click', closeChat);
    if (elements.clear)        elements.clear.addEventListener('click', clearConversation);
    if (elements.clearConfirm) elements.clearConfirm.addEventListener('click', confirmClearConversation);
    if (elements.clearCancel)  elements.clearCancel.addEventListener('click', cancelClearConversation);
    
    if (elements.clearBackdrop) {
        elements.clearBackdrop.addEventListener('click', (e) => {
            if (e.target === elements.clearBackdrop) cancelClearConversation();
        });
    }
    
    if (elements.send) elements.send.addEventListener('click', sendMessage);
    
    if (elements.input) {
        elements.input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !isWaitingForResponse) sendMessage();
        });
    }
    
    if (elements.suggestions) {
        elements.suggestions.addEventListener('click', (e) => {
            if (e.target.classList.contains('suggestion-chip')) {
                // Use data-message if set, otherwise textContent
                elements.input.value = e.target.getAttribute('data-message') || e.target.textContent.trim();
                sendMessage();
            }
        });
    }
    
    // ------------------------------------------------
    // Initialization
    // ------------------------------------------------
    function init() {
        const history = loadConversation();
        const state   = loadChatState();
        
        if (history.length > 0) {
            renderMessages();
            if (elements.badge && !elements.container.classList.contains('active')) {
                const lastViewedCount = state?.lastViewedMessageCount || 0;
                const newMessageCount = history.length - lastViewedCount;
                if (newMessageCount > 0) {
                    elements.badge.textContent = newMessageCount;
                    elements.badge.classList.add('show');
                }
            }
        } else {
            showWelcomeMessage();
        }
        
        if (state && state.isOpen) {
            const timeSinceClose = Date.now() - state.timestamp;
            if (timeSinceClose < 60000) {
                setTimeout(() => {
                    elements.container.classList.add('active');
                    elements.toggle.classList.add('active');
                }, 300);
            }
        }

        // Init suggestion data-message values
        updateSuggestionMessages();
    }
    
    window.addEventListener('beforeunload', () => {
        saveChatState(elements.container.classList.contains('active'));
    });
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
</script>
<!-- CHATBOT WIDGET - END -->