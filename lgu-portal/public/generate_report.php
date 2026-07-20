<?php
/**
 * Compatibility redirect — this page moved to functionality/generate_report.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: functionality/generate_report.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
