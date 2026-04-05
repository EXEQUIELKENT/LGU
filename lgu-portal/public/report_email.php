<?php
/**
 * report_email.php — Send report status update emails to requesters.
 * Same PHPMailer config as login.php.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require_once __DIR__ . '/../vendor/PHPMailer/Exception.php';

/**
 * Build the absolute base URL, matching login.php logic.
 */
function getBaseUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocalhost = in_array(explode(':', $host)[0], ['localhost', '127.0.0.1', '::1']);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (strpos($host, 'infragovservices.com') !== false) {
        return 'https://' . $host . '/lgu-portal/public';
    }
    if ($isLocalhost) {
        return $protocol . '://' . $host . '/LGU/lgu-portal/public';
    }
    return $protocol . '://' . $host . '/lgu-portal/public';
}

/**
 * Send a report progress/completion email to the requester.
 *
 * @param string $toEmail
 * @param string $requesterName
 * @param int    $repId
 * @param string $eventType      'saved' | 'completed'
 * @param array  $reportData     Keys: infrastructure, location, issue, engineer_name, description
 * @param array  $imageUrls      Absolute image URLs
 */
function sendReportUpdateEmail(
    string $toEmail,
    string $requesterName,
    int    $repId,
    string $eventType,
    array  $reportData,
    array  $imageUrls = []
): bool {
    if (empty($toEmail)) return false;

    $infra    = htmlspecialchars($reportData['infrastructure'] ?? 'Report');
    $location = htmlspecialchars($reportData['location']      ?? '—');
    $issue    = htmlspecialchars($reportData['issue']         ?? '—');
    $engineer = htmlspecialchars($reportData['engineer_name'] ?? '—');
    $descRaw  = $reportData['description'] ?? '';
    $desc     = htmlspecialchars(trim($descRaw));
    $name     = htmlspecialchars(trim($requesterName) ?: 'Citizen');
    $dayNum   = (int)($reportData['day_number'] ?? 0);
    $dayLabel = $dayNum > 0 ? "Day {$dayNum}" : '';

    $isComplete  = ($eventType === 'completed');
    $accentColor = $isComplete ? '#16a34a' : '#2563eb';
    $statusLabel = $isComplete ? 'Completed ✅' : ('Progress Update' . ($dayLabel ? " — {$dayLabel}" : ''));
    $subjectLine = $isComplete
        ? "Your Report #REP-{$repId} Has Been Completed — LGU Portal"
        : "Progress Update" . ($dayLabel ? " ({$dayLabel})" : '') . " on Your Report #REP-{$repId} — LGU Portal";

    $statusBanner = $isComplete
        ? '<div style="background:#dcfce7;border-left:4px solid #16a34a;padding:14px 18px;border-radius:6px;margin:18px 0;">
               <p style="color:#15803d;font-size:14px;font-weight:700;margin:0;">✅ Your report has been marked as COMPLETED.</p>
               <p style="color:#166534;font-size:13px;margin:6px 0 0;">The assigned engineer has finished all work on this report. Thank you for your patience.</p>
           </div>'
        : '<div style="background:#dbeafe;border-left:4px solid #2563eb;padding:14px 18px;border-radius:6px;margin:18px 0;">'
          . ($dayLabel ? '<p style="color:#1d4ed8;font-size:13px;font-weight:700;margin:0 0 4px;">📅 ' . $dayLabel . '</p>' : '')
          . '<p style="color:#1d4ed8;font-size:14px;font-weight:700;margin:0;">🔧 Work is actively in progress on your report.</p>
               <p style="color:#1e40af;font-size:13px;margin:6px 0 0;">The engineer has submitted a progress update. We will notify you when all work is complete.</p>
           </div>';

    // Description section (only if present)
    $descSection = '';
    if ($desc !== '') {
        $label = $isComplete ? '📋 Final Engineer Report:' : '📋 Engineer\'s Progress Notes:';
        $descSection = '<div style="margin:20px 0;background:#f0f4f8;border-radius:8px;padding:16px 20px;">
            <p style="color:#374151;font-size:13px;font-weight:700;margin:0 0 8px;">' . $label . '</p>
            <p style="color:#374151;font-size:13px;line-height:1.7;margin:0;white-space:pre-wrap;">' . $desc . '</p>
        </div>';
    }

    // Image section - styled cards with fallback visible even when images blocked
    $imageSection = '';
    if (!empty($imageUrls)) {
        $imgCards = '';
        $i = 1;
        foreach ($imageUrls as $url) {
            $esc = htmlspecialchars($url);
            $imgCards .= '
            <div style="display:inline-block;margin:6px;vertical-align:top;">
                <a href="' . $esc . '" target="_blank" style="text-decoration:none;">
                    <div style="width:155px;border-radius:10px;overflow:hidden;border:1.5px solid #dde4ed;background:#f0f4f8;box-shadow:0 2px 6px rgba(0,0,0,.08);">
                        <img src="' . $esc . '" alt="Report Image ' . $i . '"
                             width="155" height="115"
                             style="width:155px;height:115px;object-fit:cover;display:block;border-bottom:1.5px solid #dde4ed;" />
                        <div style="padding:6px 0;text-align:center;background:#fff;">
                            <span style="font-size:11px;font-weight:600;color:#3762c8;">📷 Image ' . $i . ' — View</span>
                        </div>
                    </div>
                </a>
            </div>';
            $i++;
        }
        $label = $isComplete ? '📷 Report Progress Images (All Days):' : '📷 Today\'s Report Images:';
        $imageSection = '
        <div style="margin:20px 0;">
            <p style="color:#374151;font-size:14px;font-weight:700;margin:0 0 12px;">' . $label . '</p>
            <div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;border:1px solid #e8eef5;">'
            . $imgCards . '
            </div>
            <p style="color:#94a3b8;font-size:11px;text-align:center;margin:8px 0 0;">
                If images do not load, click each one to view in your browser.
            </p>
        </div>';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = 0;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable';
        $mail->Timeout    = 30;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]];
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;
        $mail->WordWrap      = 0;

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
        $mail->addAddress($toEmail, $name);
        $mail->isHTML(true);
        $mail->Subject = $subjectLine;

        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:36px 32px;box-shadow:0 2px 10px rgba(0,0,0,0.1);border-top:4px solid ' . $accentColor . '">
    <h1 style="color:#27417b;margin:0 0 4px 0;font-size:26px;text-align:center;">LGU Portal</h1>
    <p style="color:#64748b;font-size:13px;text-align:center;margin:0 0 24px;">City Infrastructure Management &amp; Monitoring</p>
    <h2 style="color:' . $accentColor . ';margin:0 0 6px;font-size:17px;font-weight:700;text-align:center;">' . $statusLabel . '</h2>
    <p style="color:#374151;font-size:14px;text-align:center;margin:0 0 20px;">Report <strong style="color:#27417b">#REP-' . $repId . '</strong></p>
    <p style="color:#374151;font-size:14px;margin:0 0 16px;">Hello <strong>' . $name . '</strong>,</p>
    <p style="color:#4b5563;font-size:14px;line-height:1.6;margin:0 0 16px;">We are writing to update you on the status of your submitted infrastructure report.</p>
    ' . $statusBanner . '
    <div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 10px;text-transform:uppercase;letter-spacing:.05em;">Report Details</p>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tr><td style="color:#64748b;padding:5px 0;width:40%;">Report ID</td><td style="color:#1e293b;font-weight:600;">#REP-' . $repId . '</td></tr>
            ' . ($dayLabel && !$isComplete ? '<tr><td style="color:#64748b;padding:5px 0;">Progress Day</td><td style="color:#1e293b;font-weight:600;">' . htmlspecialchars($dayLabel) . '</td></tr>' : '') . '
            <tr><td style="color:#64748b;padding:5px 0;">Infrastructure</td><td style="color:#1e293b;">' . $infra . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Location</td><td style="color:#1e293b;">' . $location . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Issue</td><td style="color:#1e293b;">' . $issue . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Assigned Engineer</td><td style="color:#1e293b;">' . $engineer . '</td></tr>
        </table>
    </div>
    ' . $descSection . '
    ' . $imageSection . '
    <p style="color:#6b7280;font-size:13px;line-height:1.6;margin:20px 0 0;">If you have any concerns or questions, please contact your local LGU office.</p>
    <div style="margin:24px 0;padding:18px 20px;background:#f0f4ff;border-radius:10px;border:1.5px solid #c7d4f7;text-align:center;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 6px;">&#128172; Share Your Feedback</p>
        <p style="color:#4b5563;font-size:12px;margin:0 0 12px;line-height:1.6;">We value your experience. Let us know how we\'re doing by submitting a feedback, suggestion, or concern about our services.</p>
        <a href="' . getBaseUrl() . '/citizen_feedback.php" target="_blank"
           style="display:inline-block;background:#27417b;color:#ffffff;font-size:13px;font-weight:700;
                  padding:10px 24px;border-radius:6px;text-decoration:none;letter-spacing:.03em;">
            &#128203; Submit Feedback — CIMM LGU
        </a>
    </div>
    <p style="color:#9ca3af;font-size:12px;margin-top:28px;border-top:1px solid #f1f5f9;padding-top:18px;text-align:center;">This is an automated message. Please do not reply to this email.</p>
    <p style="color:#9ca3af;font-size:11px;text-align:center;margin-top:8px;">&copy; ' . date('Y') . ' LGU Portal &mdash; City Infrastructure Management &amp; Monitoring</p>
</div>
</body></html>';

        $mail->Body    = $htmlBody;
        $mail->AltBody = "LGU Portal — Report Update\n\nHello {$name},\n\n"
            . ($isComplete ? "Your report #REP-{$repId} has been COMPLETED.\n\n" : "Progress update on your report #REP-{$repId}.\n\n")
            . "Infrastructure: {$infra}\nLocation: {$location}\nIssue: {$issue}\nEngineer: {$engineer}\n"
            . ($desc ? "\nEngineer Notes:\n{$descRaw}\n" : '')
            . "\n---\nShare your feedback, suggestions, or concerns about our services:\n" . getBaseUrl() . "/citizen_feedback.php\n"
            . "\n© " . date('Y') . " LGU Portal";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Report update email error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all progress image URLs. Uses correct base URL matching login.php path logic.
 */
function getReportImageUrls(mysqli $conn, int $repId, string $logDate = ''): array {
    $base = getBaseUrl();

    if ($logDate) {
        $stmt = $conn->prepare(
            "SELECT img_path FROM report_daily_images WHERE rep_id = ? AND log_date = ? ORDER BY uploaded_at ASC"
        );
        $stmt->bind_param("is", $repId, $logDate);
    } else {
        $stmt = $conn->prepare(
            "SELECT img_path FROM report_daily_images WHERE rep_id = ? ORDER BY uploaded_at ASC LIMIT 20"
        );
        $stmt->bind_param("i", $repId);
    }
    $stmt->execute();
    $res  = $stmt->get_result();
    $urls = [];
    while ($row = $res->fetch_assoc()) {
        $urls[] = $base . '/' . ltrim($row['img_path'], '/');
    }
    $stmt->close();
    return $urls;
}

/**
 * Get requester email + report metadata for a rep_id.
 * Also fetches the latest daily log description.
 */
function getRequesterEmailData(mysqli $conn, int $repId, string $logDate = ''): array {
    $stmt = $conn->prepare("
        SELECT req.email, req.name, req.contact_number,
               req.infrastructure, req.location, req.issue,
               CONCAT(e.first_name,' ',e.last_name) AS engineer_name
        FROM reports r
        LEFT JOIN request_resolutions rr ON r.res_id  = rr.res_id
        LEFT JOIN requests             req ON rr.req_id = req.req_id
        LEFT JOIN employees            e   ON r.engineer_id = e.user_id
        WHERE r.rep_id = ? LIMIT 1
    ");
    if (!$stmt) return [];
    $stmt->bind_param("i", $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return [];

    // Fetch description: specific day if given, otherwise most recent
    $desc = '';
    if ($logDate) {
        $ds = $conn->prepare("SELECT description FROM report_daily_logs WHERE rep_id = ? AND log_date = ? LIMIT 1");
        $ds->bind_param("is", $repId, $logDate);
    } else {
        $ds = $conn->prepare("SELECT description FROM report_daily_logs WHERE rep_id = ? ORDER BY log_date DESC LIMIT 1");
        $ds->bind_param("i", $repId);
    }
    if ($ds) {
        $ds->execute();
        $dr = $ds->get_result()->fetch_assoc();
        $ds->close();
        $desc = $dr['description'] ?? '';
    }
    $row['description'] = $desc;
    return $row;
}

/**
 * Send a rejection notification email to the requester.
 *
 * @param string $toEmail
 * @param string $requesterName
 * @param int    $reqId
 * @param string $reason        Admin's rejection reason (may be empty)
 * @param array  $requestData   Keys: infrastructure, location, issue
 */
function sendRejectionEmail(
    string $toEmail,
    string $requesterName,
    int    $reqId,
    string $reason,
    array  $requestData
): bool {
    if (empty($toEmail)) return false;

    $infra    = htmlspecialchars($requestData['infrastructure'] ?? 'Report');
    $location = htmlspecialchars($requestData['location']      ?? '—');
    $issue    = htmlspecialchars($requestData['issue']         ?? '—');
    $name     = htmlspecialchars(trim($requesterName) ?: 'Citizen');
    $reqLabel = '#REQ-' . str_pad($reqId, 3, '0', STR_PAD_LEFT);

    $reasonSection = '';
    if (!empty($reason)) {
        $reasonSection = '<div style="background:#fef2f2;border-left:4px solid #ef4444;padding:14px 18px;border-radius:6px;margin:18px 0;">
            <p style="color:#b91c1c;font-size:13px;font-weight:700;margin:0 0 6px;">📋 Reason for Rejection:</p>
            <p style="color:#7f1d1d;font-size:13px;line-height:1.6;margin:0;">' . htmlspecialchars($reason) . '</p>
        </div>';
    } else {
        $reasonSection = '<div style="background:#fef2f2;border-left:4px solid #ef4444;padding:14px 18px;border-radius:6px;margin:18px 0;">
            <p style="color:#b91c1c;font-size:13px;font-weight:700;margin:0;">❌ Your request did not meet the requirements for LGU action.</p>
            <p style="color:#7f1d1d;font-size:13px;margin:6px 0 0;">Please contact your local LGU office for further assistance.</p>
        </div>';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = 0;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable';
        $mail->Timeout    = 30;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]];
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
        $mail->addAddress($toEmail, $name);
        $mail->isHTML(true);
        $mail->Subject = "Your Request {$reqLabel} Has Been Rejected — LGU Portal";

        $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:36px 32px;
            box-shadow:0 2px 10px rgba(0,0,0,0.1);border-top:4px solid #ef4444;">
    <h1 style="color:#27417b;margin:0 0 4px;font-size:26px;text-align:center;">LGU Portal</h1>
    <p style="color:#64748b;font-size:13px;text-align:center;margin:0 0 24px;">City Infrastructure Management &amp; Monitoring</p>
    <h2 style="color:#ef4444;margin:0 0 6px;font-size:17px;font-weight:700;text-align:center;">Request Rejected ❌</h2>
    <p style="color:#374151;font-size:14px;text-align:center;margin:0 0 20px;">Request <strong style="color:#27417b">' . $reqLabel . '</strong></p>
    <p style="color:#374151;font-size:14px;margin:0 0 16px;">Hello <strong>' . $name . '</strong>,</p>
    <p style="color:#4b5563;font-size:14px;line-height:1.6;margin:0 0 16px;">We regret to inform you that your infrastructure request has been reviewed and <strong style="color:#ef4444">rejected</strong> by the LGU.</p>
    ' . $reasonSection . '
    <div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 10px;text-transform:uppercase;letter-spacing:.05em;">Request Details</p>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tr><td style="color:#64748b;padding:5px 0;width:40%;">Request ID</td><td style="color:#1e293b;font-weight:600;">' . $reqLabel . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Infrastructure</td><td style="color:#1e293b;">' . $infra . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Location</td><td style="color:#1e293b;">' . $location . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Issue</td><td style="color:#1e293b;">' . $issue . '</td></tr>
        </table>
    </div>
    <p style="color:#6b7280;font-size:13px;line-height:1.6;margin:20px 0 0;">If you believe this decision is incorrect, you may visit your local LGU office or submit a new request with additional supporting information.</p>
    <p style="color:#9ca3af;font-size:12px;margin-top:28px;border-top:1px solid #f1f5f9;padding-top:18px;text-align:center;">This is an automated message. Please do not reply to this email.</p>
    <p style="color:#9ca3af;font-size:11px;text-align:center;margin-top:8px;">&copy; ' . date('Y') . ' LGU Portal &mdash; City Infrastructure Management &amp; Monitoring</p>
</div>
</body></html>';

        $mail->AltBody = "LGU Portal — Request Rejected\n\nHello {$name},\n\n"
            . "Your request {$reqLabel} ({$infra} at {$location}) has been REJECTED.\n"
            . ($reason ? "\nReason: {$reason}\n" : "\nPlease contact your LGU office for more information.\n")
            . "\n© " . date('Y') . " LGU Portal";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('Rejection email error: ' . $e->getMessage());
        return false;
    }
}


/**
 * Send a feedback status notification email (Valid or Dismissed) to the citizen.
 *
 * @param string $toEmail
 * @param string $citizenName
 * @param int    $feedbackId
 * @param string $status          'Valid' | 'Dismissed'
 * @param string $adminReply      Admin's reply / employee_notes (required)
 * @param string $feedbackTitle
 * @param string $feedbackType
 * @param float  $rating          Star rating 0–5 (optional)
 * @param string $description     Citizen's own description (optional)
 * @param string $createdAt       Submitted datetime string (optional)
 * @param int    $refRepId        Referenced report ID, 0 = none (optional)
 * @param string $refInfra        Infrastructure from referenced report (optional)
 * @param string $engineerName    Engineer assigned to referenced report (optional)
 */
function sendFeedbackStatusEmail(
    string $toEmail,
    string $citizenName,
    int    $feedbackId,
    string $status,
    string $adminReply,
    string $feedbackTitle,
    string $feedbackType,
    float  $rating       = 0.0,
    string $description  = '',
    string $createdAt    = '',
    int    $refRepId     = 0,
    string $refInfra     = '',
    string $engineerName = ''
): bool {
    if (empty($toEmail)) return false;

    $isValid     = ($status === 'Valid');
    $accentColor = $isValid ? '#16a34a' : '#64748b';
    $statusIcon  = $isValid ? '✅' : '❌';
    $statusLabel = $isValid ? 'Feedback Validated' : 'Feedback Dismissed';
    $name        = htmlspecialchars(trim($citizenName) ?: 'Citizen');
    $title       = htmlspecialchars($feedbackTitle);
    $type        = htmlspecialchars($feedbackType);
    $reply       = htmlspecialchars($adminReply);
    $fbkId       = '#FBK-' . str_pad($feedbackId, 3, '0', STR_PAD_LEFT);

    // ── Star rating HTML (inline, email-safe) ─────────────────────────────
    $starsHtml = '';
    if ($rating > 0) {
        $starsHtml = '<tr><td style="color:#64748b;padding:5px 0;width:40%;vertical-align:middle;">Rating</td>'
            . '<td style="vertical-align:middle;">'
            . '<span style="color:#f59e0b;font-size:14px;font-weight:700;">&#9733; ' . number_format($rating, 1) . ' / 5 stars</span>'
            . '</td></tr>';
    }

    // ── Submitted date ────────────────────────────────────────────────────
    $dateHtml = '';
    if (!empty($createdAt)) {
        $ts = strtotime($createdAt);
        $formatted = $ts ? date('F j, Y \a\t g:i A', $ts) : htmlspecialchars($createdAt);
        $dateHtml = '<tr><td style="color:#64748b;padding:5px 0;width:40%;">Submitted</td>'
            . '<td style="color:#1e293b;">' . $formatted . '</td></tr>';
    }

    // ── Reference report ─────────────────────────────────────────────────
    $refRepHtml = '';
    if ($refRepId > 0) {
        $repLabel = '#REP-' . str_pad($refRepId, 3, '0', STR_PAD_LEFT);
        $refRepHtml = '<tr><td style="color:#64748b;padding:5px 0;width:40%;">Reference Report</td>'
            . '<td style="color:#27417b;font-weight:600;">' . htmlspecialchars($repLabel) . '</td></tr>';
    }

    // ── Infrastructure ────────────────────────────────────────────────────
    $infraHtml = '';
    if (!empty($refInfra)) {
        $infraHtml = '<tr><td style="color:#64748b;padding:5px 0;width:40%;">Infrastructure</td>'
            . '<td style="color:#1e293b;">' . htmlspecialchars($refInfra) . '</td></tr>';
    }

    // ── Engineer ──────────────────────────────────────────────────────────
    $engHtml = '';
    $engTrimmed = trim($engineerName);
    if (!empty($engTrimmed) && $engTrimmed !== ' ') {
        $engHtml = '<tr><td style="color:#64748b;padding:5px 0;width:40%;">Assigned Engineer</td>'
            . '<td style="color:#1e293b;">' . htmlspecialchars($engTrimmed) . '</td></tr>';
    }

    // ── Description ───────────────────────────────────────────────────────
    $descSection = '';
    $descTrimmed = trim($description);
    if (!empty($descTrimmed)) {
        $descSection = '<div style="margin:20px 0;background:#f0f4f8;border-radius:8px;padding:16px 20px;">
            <p style="color:#374151;font-size:13px;font-weight:700;margin:0 0 8px;">&#128172; Your Feedback Description:</p>
            <p style="color:#374151;font-size:13px;line-height:1.7;margin:0;white-space:pre-wrap;">' . htmlspecialchars($descTrimmed) . '</p>
        </div>';
    }

    $bannerHtml = $isValid
        ? '<div style="background:#dcfce7;border-left:4px solid #16a34a;padding:14px 18px;border-radius:6px;margin:18px 0;">
               <p style="color:#15803d;font-size:14px;font-weight:700;margin:0;">&#9989; Your feedback has been reviewed and marked as <strong>VALID</strong>.</p>
               <p style="color:#166534;font-size:13px;margin:6px 0 0;">Thank you for your valuable input. Your concern has been acknowledged and recorded.</p>
           </div>'
        : '<div style="background:#f1f5f9;border-left:4px solid #64748b;padding:14px 18px;border-radius:6px;margin:18px 0;">
               <p style="color:#334155;font-size:14px;font-weight:700;margin:0;">&#10060; Your feedback has been reviewed and marked as <strong>DISMISSED</strong>.</p>
               <p style="color:#475569;font-size:13px;margin:6px 0 0;">After careful review, the LGU has decided not to act on this feedback at this time.</p>
           </div>';

    $replySection = '<div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:20px 0;border:1px solid #e2e8f0;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 8px;text-transform:uppercase;letter-spacing:.05em;">&#128221; CIMM LGU replied:</p>
        <p style="color:#374151;font-size:13px;line-height:1.7;margin:0;white-space:pre-wrap;">' . $reply . '</p>
    </div>';

    // Build details table rows — only include rows that have data
    $detailRows = '';
    $detailRows .= '<tr><td style="color:#64748b;padding:5px 0;width:40%;">Feedback ID</td><td style="color:#1e293b;font-weight:600;">' . $fbkId . '</td></tr>';
    $detailRows .= '<tr><td style="color:#64748b;padding:5px 0;">Title</td><td style="color:#1e293b;">' . $title . '</td></tr>';
    $detailRows .= '<tr><td style="color:#64748b;padding:5px 0;">Type</td><td style="color:#1e293b;">' . $type . '</td></tr>';
    $detailRows .= $dateHtml;
    $detailRows .= $starsHtml;
    $detailRows .= $refRepHtml;
    $detailRows .= $infraHtml;
    $detailRows .= $engHtml;
    $detailRows .= '<tr><td style="color:#64748b;padding:5px 0;">Status</td><td style="color:' . $accentColor . ';font-weight:700;">' . $status . '</td></tr>';

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = 0;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable';
        $mail->Timeout    = 30;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]];
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;
        $mail->WordWrap      = 0;

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
        $mail->addAddress($toEmail, $name);
        $mail->isHTML(true);
        $mail->Subject = "Your Feedback {$fbkId} Has Been {$status} — LGU Portal";

        $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:36px 32px;
            box-shadow:0 2px 10px rgba(0,0,0,0.1);border-top:4px solid ' . $accentColor . ';">
    <h1 style="color:#27417b;margin:0 0 4px;font-size:26px;text-align:center;">LGU Portal</h1>
    <p style="color:#64748b;font-size:13px;text-align:center;margin:0 0 24px;">City Infrastructure Management &amp; Monitoring</p>
    <h2 style="color:' . $accentColor . ';margin:0 0 6px;font-size:17px;font-weight:700;text-align:center;">' . $statusIcon . ' ' . $statusLabel . '</h2>
    <p style="color:#374151;font-size:14px;text-align:center;margin:0 0 20px;">Feedback <strong style="color:#27417b">' . $fbkId . '</strong></p>
    <p style="color:#374151;font-size:14px;margin:0 0 16px;">Hello <strong>' . $name . '</strong>,</p>
    <p style="color:#4b5563;font-size:14px;line-height:1.6;margin:0 0 16px;">
        We would like to inform you that your feedback has been reviewed by our team.
    </p>
    ' . $bannerHtml . '
    <div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 10px;text-transform:uppercase;letter-spacing:.05em;">Feedback Details</p>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">'
            . $detailRows .
        '</table>
    </div>
    ' . $descSection . '
    ' . $replySection . '
    <p style="color:#6b7280;font-size:13px;line-height:1.6;margin:20px 0 0;">If you have any further concerns, please contact your local LGU office.</p>
    <div style="margin:24px 0;padding:18px 20px;background:#f0f4ff;border-radius:10px;border:1.5px solid #c7d4f7;text-align:center;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 6px;">&#128172; Submit Another Feedback</p>
        <p style="color:#4b5563;font-size:12px;margin:0 0 12px;line-height:1.6;">Have more suggestions or concerns? We always welcome your input to help us improve our services.</p>
        <a href="' . getBaseUrl() . '/citizen_feedback.php" target="_blank"
           style="display:inline-block;background:#27417b;color:#ffffff;font-size:13px;font-weight:700;
                  padding:10px 24px;border-radius:6px;text-decoration:none;letter-spacing:.03em;">
            &#128203; Submit Feedback — CIMM LGU
        </a>
    </div>
    <p style="color:#9ca3af;font-size:12px;margin-top:28px;border-top:1px solid #f1f5f9;padding-top:18px;text-align:center;">
        This is an automated message. Please do not reply to this email.
    </p>
    <p style="color:#9ca3af;font-size:11px;text-align:center;margin-top:8px;">
        &copy; ' . date('Y') . ' LGU Portal &mdash; City Infrastructure Management &amp; Monitoring
    </p>
</div>
</body></html>';

        // Plain-text fallback
        $plainParts = ["LGU Portal — Feedback Update\n\nHello {$name},\n"];
        $plainParts[] = "Your feedback {$fbkId} (\"{$feedbackTitle}\") has been {$status}.\n";
        if ($rating > 0)          $plainParts[] = "Rating: " . number_format($rating, 1) . " / 5";
        if (!empty($createdAt))   $plainParts[] = "Submitted: " . (($ts = strtotime($createdAt)) ? date('F j, Y g:i A', $ts) : $createdAt);
        if ($refRepId > 0)        $plainParts[] = "Reference Report: #REP-" . str_pad($refRepId, 3, '0', STR_PAD_LEFT);
        if (!empty($refInfra))    $plainParts[] = "Infrastructure: {$refInfra}";
        if (!empty($engTrimmed) && $engTrimmed !== ' ') $plainParts[] = "Assigned Engineer: {$engTrimmed}";
        if (!empty($descTrimmed)) $plainParts[] = "\nYour Description:\n{$descTrimmed}";
        $plainParts[] = "\nCIMM LGU replied:\n{$adminReply}";
        $plainParts[] = "\n---\nHave more suggestions or concerns? Submit feedback here:\n" . getBaseUrl() . "/citizen_feedback.php";
        $plainParts[] = "\n© " . date('Y') . " LGU Portal";
        $mail->AltBody = implode("\n", $plainParts);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Feedback status email error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send an approval/validation notification email to the requester.
 *
 * @param string $toEmail
 * @param string $requesterName
 * @param int    $reqId
 * @param int    $repId
 * @param array  $requestData   Keys: infrastructure, location, issue, engineer_name (optional)
 */
function sendValidationEmail(
    string $toEmail,
    string $requesterName,
    int    $reqId,
    int    $repId,
    array  $requestData
): bool {
    if (empty($toEmail)) return false;

    $infra    = htmlspecialchars($requestData['infrastructure'] ?? 'Report');
    $location = htmlspecialchars($requestData['location']      ?? '—');
    $issue    = htmlspecialchars($requestData['issue']         ?? '—');
    $engineer = htmlspecialchars($requestData['engineer_name'] ?? 'To be assigned');
    $name     = htmlspecialchars(trim($requesterName) ?: 'Citizen');
    $reqLabel = '#REQ-' . str_pad($reqId, 3, '0', STR_PAD_LEFT);
    $repLabel = '#REP-' . str_pad($repId, 3, '0', STR_PAD_LEFT);

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = 0;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable';
        $mail->Timeout    = 30;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]];
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
        $mail->addAddress($toEmail, $name);
        $mail->isHTML(true);
        $mail->Subject = "Your Request {$reqLabel} Has Been Approved — LGU Portal";

        $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:36px 32px;
            box-shadow:0 2px 10px rgba(0,0,0,0.1);border-top:4px solid #16a34a;">
    <h1 style="color:#27417b;margin:0 0 4px;font-size:26px;text-align:center;">LGU Portal</h1>
    <p style="color:#64748b;font-size:13px;text-align:center;margin:0 0 24px;">City Infrastructure Management &amp; Monitoring</p>
    <h2 style="color:#16a34a;margin:0 0 6px;font-size:17px;font-weight:700;text-align:center;">Request Approved ✅</h2>
    <p style="color:#374151;font-size:14px;text-align:center;margin:0 0 20px;">
        Request <strong style="color:#27417b">' . $reqLabel . '</strong>
        &rarr; Report <strong style="color:#27417b">' . $repLabel . '</strong>
    </p>
    <p style="color:#374151;font-size:14px;margin:0 0 16px;">Hello <strong>' . $name . '</strong>,</p>
    <p style="color:#4b5563;font-size:14px;line-height:1.6;margin:0 0 16px;">
        Great news! Your infrastructure request has been <strong style="color:#16a34a">approved</strong>
        by the LGU and a repair report has been created.
    </p>
    <div style="background:#dcfce7;border-left:4px solid #16a34a;padding:14px 18px;border-radius:6px;margin:18px 0;">
        <p style="color:#15803d;font-size:14px;font-weight:700;margin:0;">✅ Your request is now being processed.</p>
        <p style="color:#166534;font-size:13px;margin:6px 0 0;">
            An engineer has been assigned and work will begin shortly.
            You will receive progress updates as repairs are carried out.
        </p>
    </div>
    <div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 10px;text-transform:uppercase;letter-spacing:.05em;">Details</p>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tr><td style="color:#64748b;padding:5px 0;width:40%;">Request ID</td><td style="color:#1e293b;font-weight:600;">' . $reqLabel . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Report ID</td><td style="color:#1e293b;font-weight:600;">' . $repLabel . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Infrastructure</td><td style="color:#1e293b;">' . $infra . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Location</td><td style="color:#1e293b;">' . $location . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Issue</td><td style="color:#1e293b;">' . $issue . '</td></tr>
            <tr><td style="color:#64748b;padding:5px 0;">Assigned Engineer</td><td style="color:#1e293b;">' . $engineer . '</td></tr>
        </table>
    </div>
    <p style="color:#6b7280;font-size:13px;line-height:1.6;margin:20px 0 0;">
        If you have any concerns, please contact your local LGU office.
    </p>
    <div style="margin:24px 0;padding:18px 20px;background:#f0f4ff;border-radius:10px;border:1.5px solid #c7d4f7;text-align:center;">
        <p style="color:#27417b;font-size:13px;font-weight:700;margin:0 0 6px;">&#128172; Share Your Feedback</p>
        <p style="color:#4b5563;font-size:12px;margin:0 0 12px;line-height:1.6;">We value your experience. Let us know how we\'re doing by submitting a feedback, suggestion, or concern about our services.</p>
        <a href="' . getBaseUrl() . '/citizen_feedback.php" target="_blank"
           style="display:inline-block;background:#27417b;color:#ffffff;font-size:13px;font-weight:700;
                  padding:10px 24px;border-radius:6px;text-decoration:none;letter-spacing:.03em;">
            &#128203; Submit Feedback — CIMM LGU
        </a>
    </div>
    <p style="color:#9ca3af;font-size:12px;margin-top:28px;border-top:1px solid #f1f5f9;padding-top:18px;text-align:center;">
        This is an automated message. Please do not reply to this email.
    </p>
    <p style="color:#9ca3af;font-size:11px;text-align:center;margin-top:8px;">
        &copy; ' . date('Y') . ' LGU Portal &mdash; City Infrastructure Management &amp; Monitoring
    </p>
</div>
</body></html>';

        $mail->AltBody = "LGU Portal — Request Approved\n\nHello {$name},\n\n"
            . "Your request {$reqLabel} has been APPROVED. Report {$repLabel} has been created.\n\n"
            . "Infrastructure: {$infra}\nLocation: {$location}\nIssue: {$issue}\nEngineer: {$engineer}\n"
            . "\nYou will receive progress updates as work proceeds.\n"
            . "\n---\nShare your feedback, suggestions, or concerns about our services:\n" . getBaseUrl() . "/citizen_feedback.php\n"
            . "\n© " . date('Y') . " LGU Portal";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('Validation email error: ' . $e->getMessage());
        return false;
    }
}