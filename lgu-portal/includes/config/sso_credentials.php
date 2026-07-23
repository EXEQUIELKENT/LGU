<?php
/**
 * Shared secret used to verify SSO tokens issued by Main LGU
 * (infragovservices.com hub). Must match SSO_SECRET_CIMM in Main LGU's .env.
 * No .env mechanism exists in this codebase (see db_credentials.php for the
 * same hardcoded-per-environment convention), so this follows that pattern.
 */
if (!function_exists('cimm_sso_shared_secret')) {
    function cimm_sso_shared_secret(): string {
        return '4b846cf9286c6a7d2dbd099b4033552ed162b08559f93624f69d48e1029092a6';
    }
}
