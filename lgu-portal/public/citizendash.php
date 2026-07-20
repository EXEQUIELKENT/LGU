<?php
/**
 * Compatibility redirect — this page moved to citizen/citizendash.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: citizen/citizendash.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
