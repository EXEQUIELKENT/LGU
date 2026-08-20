<?php
/**
 * Shared report-workflow status logic — extracted from requests.php so the
 * new bounds-filtered public/api/requests-map.php endpoint (see
 * gis_map_loader.js) can compute the exact same "report_status" label for
 * a joined requests/request_resolutions/reports row without duplicating
 * this logic a second time.
 */
declare(strict_types=1);

if (!function_exists('statusDisplayLabel')) {
    // Maps the raw approval_status value to what should be shown to the user (Approved → Validated)
    function statusDisplayLabel(string $status): string {
        return $status === 'Approved' ? 'Validated' : $status;
    }
}

if (!function_exists('computeReportStatus')) {
    function computeReportStatus(array $row): string {
        $resSt       = $row['resolution_status'] ?? '';
        $engId       = (int)($row['engineer_id']       ?? 0);
        $engAccepted = (bool)($row['engineer_accepted'] ?? false);
        $endDate     = $row['estimated_end_date']       ?? '';

        if (!$resSt) return '';                                           // no report created yet
        if ($resSt === 'Pending Admin Approval') return 'Pending Approval';
        if ($resSt === 'Completed')   return 'Completed';
        if ($resSt === 'Cancelled')   return 'Cancelled';
        if ($resSt === 'Pending Completion') return 'Pending Completion';

        // Check for Delayed: past estimated end date and not yet completed/cancelled
        if ($endDate) {
            try {
                $today  = new DateTime('today', new DateTimeZone('Asia/Manila'));
                $endDt  = new DateTime($endDate, new DateTimeZone('Asia/Manila'));
                if ($today > $endDt) return 'Delayed';
            } catch (Exception $e) {}
        }

        if ($resSt === 'Scheduled')   return 'Scheduled';
        if (in_array($resSt, ['Approved', 'In Progress'])) {
            if (!$engId)       return 'Awaiting Engineer';
            if (!$engAccepted) return 'Pending Acceptance';
            return 'In Progress';
        }
        return $resSt;
    }
}
