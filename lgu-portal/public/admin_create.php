<?php
/**
 * Compatibility redirect — this page moved to admin/admin_create.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: admin/admin_create.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
