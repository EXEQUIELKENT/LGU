<?php
/**
 * Compatibility redirect — this page moved to functionality/reject_request.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: functionality/reject_request.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
