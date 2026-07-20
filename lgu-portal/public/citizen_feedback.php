<?php
/**
 * Compatibility redirect — this page moved to citizen/citizen_feedback.php during the
 * admin/citizen/functionality reorg. Kept here so old bookmarks and
 * already-sent email links (see report_email.php, admin_create.php) don't 404.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: citizen/citizen_feedback.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
