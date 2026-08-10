<?php
/**
 * smtp_credentials.php — single source of truth for the Gmail SMTP account
 * PHPMailer uses across the whole app (OTP codes, forgot-password emails,
 * report status update emails, new-account invite emails, etc.)
 *
 * Before this, the same host/username/password/port were hardcoded
 * separately in 4 different files (report_email.php, login.php,
 * admin_create.php, user_management.php) — every one of them had to be
 * edited by hand whenever the Gmail App Password changed (e.g. after Google
 * revokes/rotates it), and it was easy for them to silently drift out of
 * sync. Now there's exactly one place to update.
 *
 * If mail suddenly stops sending with "534-5.7.9 Please log in with your
 * web browser and then try again" (Google's WebLoginRequired error), that
 * means Google has revoked this App Password — nothing in the code broke.
 * Generate a new one from the Gmail account's Google Account settings
 * (Security → 2-Step Verification → App passwords) and paste it below.
 */
if (!function_exists('cimm_smtp_credentials')) {
    function cimm_smtp_credentials(): array {
        return [
            'host'       => 'smtp.gmail.com',
            'username'   => 'lguportal2026@gmail.com',
            'password'   => 'iisp igik etma csma',
            'port'       => 587,
            'secure'     => 'tls', // PHPMailer::ENCRYPTION_STARTTLS
            'from_email' => 'lguportal2026@gmail.com',
            'from_name'  => 'LGU Portal',
        ];
    }
}
