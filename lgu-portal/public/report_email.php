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
    <p style="color:#9ca3af;font-size:12px;margin-top:28px;border-top:1px solid #f1f5f9;padding-top:18px;text-align:center;">This is an automated message. Please do not reply to this email.</p>
    <p style="color:#9ca3af;font-size:11px;text-align:center;margin-top:8px;">&copy; ' . date('Y') . ' LGU Portal &mdash; City Infrastructure Management &amp; Monitoring</p>
</div>
</body></html>';

        $mail->Body    = $htmlBody;
        $mail->AltBody = "LGU Portal — Report Update\n\nHello {$name},\n\n"
            . ($isComplete ? "Your report #REP-{$repId} has been COMPLETED.\n\n" : "Progress update on your report #REP-{$repId}.\n\n")
            . "Infrastructure: {$infra}\nLocation: {$location}\nIssue: {$issue}\nEngineer: {$engineer}\n"
            . ($desc ? "\nEngineer Notes:\n{$descRaw}\n" : '')
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
        SELECT req.email, req.name,
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