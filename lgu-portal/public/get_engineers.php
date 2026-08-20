<?php
/**
 * Compatibility redirect — this page moved to functionality/get_engineers.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: functionality/get_engineers.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
