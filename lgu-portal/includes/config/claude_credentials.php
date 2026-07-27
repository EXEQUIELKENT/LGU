<?php
/**
 * Claude API key for the citizen-portal chatbot (functionality/chatbot.php).
 *
 * Unlike sso_credentials.php / db_credentials.php, this one is deliberately
 * NOT hardcoded here — an Anthropic API key is billed per call, so it must
 * not sit in a committed file. Set it as a real environment variable on the
 * server (Apache SetEnv / php-fpm pool env / system env), e.g.:
 *
 *   SetEnv CLAUDE_API_KEY "sk-ant-..."
 *
 * Without it, the chatbot automatically and safely falls back to the local
 * bilingual rule-based responder — see chatbot.php's $USE_CLAUDE_API check.
 */
if (!function_exists('cimm_claude_api_key')) {
    function cimm_claude_api_key(): string {
        return getenv('CLAUDE_API_KEY') ?: '';
    }
}
