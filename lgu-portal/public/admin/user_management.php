<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

ob_start();
require_once __DIR__ . '/../../includes/core/session_guard.php';

$serverTimestamp = time();

// 🔐 Role guard — Admin and Super Admin only
if (!in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['admin', 'super admin'])) {
    header("Location: employee.php");
    exit;
}

require __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/core/activity_log.php';
require_once __DIR__ . '/../../includes/core/notif_helper.php';
require_once __DIR__ . '/../../includes/core/xlsx_builder.php';
require __DIR__ . '/../../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../../vendor/PHPMailer/Exception.php';

// Keep the live-status column available regardless of whether session_guard.php's
// heartbeat has run yet on a fresh install.
$conn->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL DEFAULT NULL");

$currentUserId = (int)($_SESSION['employee_id'] ?? 0);
$currentRole   = strtolower(trim($_SESSION['employee_role'] ?? ''));
$isSuperAdmin  = $currentRole === 'super admin';

const UM_VALID_ROLES = ['Area Engineer', 'Engineer', 'Office Staff', 'Admin', 'Super Admin'];
const UM_ROLE_ICONS = [
    'Area Engineer' => 'fa-user-tie',
    'Engineer'      => 'fa-hard-hat',
    'Office Staff'  => 'fa-user-clock',
    'Admin'         => 'fa-user-shield',
    'Super Admin'   => 'fa-crown',
];

function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
    $stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $profilePath = $row['profile_picture'] ?? null;
        if ($profilePath && file_exists(__DIR__ . '/../' . $profilePath)) {
            $stmt->close();
            return '../' . $profilePath;
        }
    }
    $stmt->close();
    return 'profile.png';
}

// Google-account-style fallback avatar: initials + a deterministic color from the palette.
function um_avatar_meta(string $name): array {
    $palette = ['#1a73e8', '#d93025', '#188038', '#f9ab00', '#8430ce', '#12a4af', '#e8710a', '#3949ab'];
    $name = trim($name);
    $parts = preg_split('/\s+/', $name);
    $initials = '';
    if (count($parts) >= 2) {
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    } elseif ($name !== '') {
        $initials = mb_strtoupper(mb_substr($name, 0, 2));
    } else {
        $initials = '?';
    }
    $color = $palette[crc32($name) % count($palette)];
    return ['initials' => $initials, 'color' => $color];
}

// Live status: "Active now" within the heartbeat window, else "Active X ago"
// relative to the last known activity, else "Never active" / "Locked".
function um_relative_time(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    $mins = intdiv($diff, 60);
    if ($mins < 60) return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    $hours = intdiv($mins, 60);
    if ($hours < 24) return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    $days = intdiv($hours, 24);
    if ($days < 30) return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    $months = intdiv($days, 30);
    if ($months < 12) return $months . ' month' . ($months === 1 ? '' : 's') . ' ago';
    $years = intdiv($months, 12);
    return $years . ' year' . ($years === 1 ? '' : 's') . ' ago';
}

// ── Account-creation helpers (mirrors admin_create.php) ───────────────────────
function um_generate_temp_password($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function um_generate_verification_token() {
    return bin2hex(random_bytes(32));
}

function um_validate_email($email) {
    $emailNormalized = strtolower(trim($email));
    if (!filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'message' => 'Invalid email format. Please enter a valid email address.'];
    }
    $parts = explode('@', $emailNormalized);
    if (count($parts) !== 2) {
        return ['valid' => false, 'message' => 'Invalid email format.'];
    }
    $domain = strtolower($parts[1]);
    $hasMX = @checkdnsrr($domain, 'MX');
    $hasA  = @checkdnsrr($domain, 'A');
    if (!$hasMX && !$hasA) {
        return ['valid' => false, 'message' => 'Email domain does not exist or cannot receive emails. The domain "' . htmlspecialchars($domain) . '" is not configured to receive emails.'];
    }
    if (preg_match('/\.{2,}/', $emailNormalized) || preg_match('/@{2,}/', $emailNormalized)) {
        return ['valid' => false, 'message' => 'Invalid email format.'];
    }
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
        return ['valid' => false, 'message' => 'Invalid email domain format.'];
    }
    return ['valid' => true, 'message' => ''];
}

function getDisplayName() {
    $n    = trim($_SESSION['employee_first_name'] ?? '') ?: 'User';
    $role = $_SESSION['employee_role'] ?? '';
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $n;
    if (strcasecmp($role, 'Admin') === 0)       return 'Admin - ' . $n;
    return $role ? $role . ' - ' . $n : $n;
}

function setNotification($type, $message) { $_SESSION['notification'] = ['type' => $type, 'message' => $message]; }
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif() {
                var n = document.getElementById('notifPopup');
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 4500);
        </script>";
    }
}

$profilePictureSrc = getProfilePicture($currentUserId, $conn);
$displayName        = getDisplayName();

// ── Export to Excel ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    while (ob_get_level() > 0) ob_end_clean();

    $exportRes = $conn->query("
        SELECT first_name, last_name, email, role, email_verified, account_locked, last_login, last_activity
        FROM employees
        ORDER BY FIELD(role, 'Super Admin', 'Admin', 'Office Staff', 'Engineer', 'Area Engineer'),
                 first_name ASC, last_name ASC
    ");
    $rows = [];
    while ($r = $exportRes->fetch_assoc()) {
        $rows[] = [
            trim($r['first_name'] . ' ' . $r['last_name']),
            $r['email'],
            $r['role'],
            $r['account_locked'] ? 'Locked' : 'Active',
            $r['email_verified'] ? 'Yes' : 'No',
            $r['last_login'] ? date('M j, Y g:i A', strtotime($r['last_login'])) : 'Never',
        ];
    }

    $actor = activity_actor_name();
    $sheetDef = [
        'name'        => 'Users',
        'title'       => 'User Management — Employee Accounts',
        'meta_period' => 'All accounts',
        'meta_by'     => $actor,
        'meta_date'   => date('M d, Y h:i A'),
        'headers'     => ['Name', 'Email', 'Role', 'Status', 'Verified', 'Last Login'],
        'rows'        => $rows,
        'centerCols'  => [false, false, true, true, true, true],
    ];

    $tmpFile = buildXLSX([$sheetDef], 'User Accounts');

    log_activity($conn, 'user_management', 'user', $currentUserId, 'export',
        "{$actor} exported the user list to Excel (" . count($rows) . " accounts).");

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="user_accounts_' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

// ── AJAX POST handler ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // Shared lookup: fetch the target user's current name/role.
    function um_find_user(mysqli $conn, int $id): ?array {
        if ($id <= 0) return null;
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, role FROM employees WHERE user_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    if ($action === 'change_role') {
        $targetId = (int)($input['user_id'] ?? 0);
        $newRole  = trim($input['role'] ?? '');

        if ($targetId <= 0 || !in_array($newRole, UM_VALID_ROLES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
        }
        if ($targetId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'You cannot change your own role.']); exit;
        }
        $target = um_find_user($conn, $targetId);
        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'User not found.']); exit;
        }
        $targetIsSuperAdmin = strcasecmp($target['role'], 'Super Admin') === 0;
        if (!$isSuperAdmin && ($targetIsSuperAdmin || strcasecmp($newRole, 'Super Admin') === 0)) {
            echo json_encode(['success' => false, 'message' => 'Only a Super Admin can assign or modify the Super Admin role.']); exit;
        }
        if (strcasecmp($target['role'], $newRole) === 0) {
            echo json_encode(['success' => false, 'message' => 'That user already has that role.']); exit;
        }

        $stmt = $conn->prepare("UPDATE employees SET role = ? WHERE user_id = ?");
        $stmt->bind_param('si', $newRole, $targetId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
            $actor = activity_actor_name();
            log_activity($conn, 'user_management', 'user', $targetId, 'role_changed',
                "{$actor} changed {$targetName}'s role from {$target['role']} to {$newRole}.");
            notifyAdminsOnly(
                $conn,
                '👤 Role Updated',
                "{$actor} changed {$targetName}'s role to {$newRole}.",
                'user_management.php',
                'User Management',
                $currentUserId
            );
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Role updated successfully.' : 'Database error.', 'role' => $newRole]);
        exit;
    }

    if ($action === 'toggle_lock') {
        $targetId = (int)($input['user_id'] ?? 0);
        $lock     = !empty($input['lock']);

        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
        }
        if ($targetId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'You cannot lock your own account.']); exit;
        }
        $target = um_find_user($conn, $targetId);
        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'User not found.']); exit;
        }
        if (!$isSuperAdmin && strcasecmp($target['role'], 'Super Admin') === 0) {
            echo json_encode(['success' => false, 'message' => 'Only a Super Admin can lock or unlock a Super Admin account.']); exit;
        }

        if ($lock) {
            $stmt = $conn->prepare("UPDATE employees SET account_locked = 1 WHERE user_id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE employees SET account_locked = 0, failed_login_attempts = 0 WHERE user_id = ?");
        }
        $stmt->bind_param('i', $targetId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
            $verb  = $lock ? 'locked' : 'unlocked';
            $actor = activity_actor_name();
            log_activity($conn, 'user_management', 'user', $targetId, $lock ? 'account_locked' : 'account_unlocked',
                "{$actor} {$verb} {$targetName}'s account.");
            notifyAdminsOnly(
                $conn,
                $lock ? '🔒 Account Locked' : '🔓 Account Unlocked',
                "{$actor} {$verb} {$targetName}'s account.",
                'user_management.php',
                'User Management',
                $currentUserId
            );
        }
        echo json_encode(['success' => $ok, 'message' => $ok ? ($lock ? 'Account locked.' : 'Account unlocked.') : 'Database error.', 'locked' => $lock]);
        exit;
    }

    if ($action === 'delete_user') {
        $targetId = (int)($input['user_id'] ?? 0);

        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
        }
        if ($targetId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']); exit;
        }
        $target = um_find_user($conn, $targetId);
        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'User not found.']); exit;
        }
        if (!$isSuperAdmin && strcasecmp($target['role'], 'Super Admin') === 0) {
            echo json_encode(['success' => false, 'message' => 'Only a Super Admin can delete a Super Admin account.']); exit;
        }

        $targetName = trim($target['first_name'] . ' ' . $target['last_name']);

        // engineer_profiles has no FK to employees, so it never blocks the
        // delete — clean it up first so it doesn't become an orphaned row.
        $stmt = $conn->prepare("DELETE FROM engineer_profiles WHERE user_id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM employees WHERE user_id = ?");
        $stmt->bind_param('i', $targetId);
        $ok = @$stmt->execute();
        $dbError = $stmt->error;
        $stmt->close();

        if (!$ok) {
            // 1451 = FK constraint violation (e.g. still assigned as an engineer
            // on a maintenance schedule or archive record).
            if ($conn->errno === 1451) {
                echo json_encode(['success' => false, 'message' => "Cannot delete {$targetName} — this account is still referenced by existing schedule or archive records. Reassign those first."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $dbError]);
            }
            exit;
        }

        $actor = activity_actor_name();
        log_activity($conn, 'user_management', 'user', $targetId, 'account_deleted',
            "{$actor} deleted {$targetName}'s account.");
        notifyAdminsOnly(
            $conn,
            '🗑️ Account Deleted',
            "{$actor} deleted {$targetName}'s account.",
            'user_management.php',
            'User Management',
            $currentUserId
        );

        echo json_encode(['success' => true, 'message' => 'Account deleted.']);
        exit;
    }

    if ($action === 'get_profile') {
        $targetId = (int)($input['user_id'] ?? 0);
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']); exit;
        }

        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, email, role, profile_picture,
                   email_verified, is_first_login, account_locked, last_login, last_activity
            FROM employees WHERE user_id = ?
        ");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$emp) {
            echo json_encode(['success' => false, 'message' => 'User not found.']); exit;
        }

        $picPath = $emp['profile_picture'] ?? null;
        $picSrc  = ($picPath && file_exists(__DIR__ . '/../' . $picPath)) ? ('../' . $picPath) : null;
        $avatarMeta = um_avatar_meta(trim($emp['first_name'] . ' ' . $emp['last_name']));

        $profile = [
            'id'        => (int)$emp['user_id'],
            'name'      => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'email'     => $emp['email'],
            'role'      => $emp['role'],
            'picture'   => $picSrc,
            'initials'  => $avatarMeta['initials'],
            'avatarColor' => $avatarMeta['color'],
            'verified'  => (bool)$emp['email_verified'],
            'firstLogin'=> (bool)$emp['is_first_login'],
            'locked'    => (bool)$emp['account_locked'],
            'lastLogin' => $emp['last_login'] ? date('M j, Y g:i A', strtotime($emp['last_login'])) : 'Never',
            'engineer'  => null,
        ];

        // Engineer / Area Engineer accounts have an extended profile.
        if (in_array($emp['role'], ['Engineer', 'Area Engineer'], true)) {
            $stmt = $conn->prepare("SELECT * FROM engineer_profiles WHERE user_id = ?");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $ep = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ep) {
                $profile['engineer'] = [
                    'fullName'       => $ep['full_name'] ?? '',
                    'gender'         => $ep['gender'] ?? '',
                    'dateOfBirth'    => $ep['date_of_birth'] ? date('M j, Y', strtotime($ep['date_of_birth'])) : '',
                    'address'        => $ep['address'] ?? '',
                    'contactNumber'  => $ep['contact_number'] ?? '',
                    'discipline'     => $ep['engineering_discipline'] ?? '',
                    'department'     => $ep['department'] ?? '',
                    'yearsExperience'=> $ep['years_of_experience'],
                    'specialization' => $ep['areas_of_specialization'] ?? '',
                    'skills'         => array_values(array_filter([
                        !empty($ep['skill_structural_design']) ? 'Structural Design' : null,
                        !empty($ep['skill_site_inspection'])   ? 'Site Inspection'   : null,
                        !empty($ep['skill_project_planning'])  ? 'Project Planning'  : null,
                    ])),
                    'cadSoftware'    => $ep['cad_software'] ?? '',
                    'district'       => $ep['district'] ?? '',
                ];
            }
        }

        echo json_encode(['success' => true, 'profile' => $profile]);
        exit;
    }

    if ($action === 'create_user') {
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name']  ?? '');
        $email     = trim($input['email']      ?? '');
        $role      = trim($input['role']       ?? '');

        if (empty($firstName) || empty($lastName) || empty($email) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']); exit;
        }
        if (!in_array($role, UM_VALID_ROLES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid role.']); exit;
        }
        if ($role === 'Super Admin' && !$isSuperAdmin) {
            echo json_encode(['success' => false, 'message' => 'Only a Super Admin can create another Super Admin account.']); exit;
        }

        $emailValidation = um_validate_email($email);
        if (!$emailValidation['valid']) {
            echo json_encode(['success' => false, 'message' => $emailValidation['message']]); exit;
        }
        $emailNormalized = strtolower($email);

        $checkStmt = $conn->prepare("SELECT user_id, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
        $checkStmt->bind_param("s", $emailNormalized);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $checkStmt->close();
            echo json_encode(['success' => false, 'message' => 'Email already exists in the system.']); exit;
        }
        $checkStmt->close();

        $pendingStmt = $conn->prepare("SELECT penreg_id, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
        $pendingStmt->bind_param("s", $emailNormalized);
        $pendingStmt->execute();
        $pendingResult = $pendingStmt->get_result();
        if ($pendingResult->num_rows > 0) {
            $pendingRow = $pendingResult->fetch_assoc();
            if (time() > strtotime($pendingRow['verification_token_expires'])) {
                $delStmt = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                $delStmt->bind_param("i", $pendingRow['penreg_id']);
                $delStmt->execute();
                $delStmt->close();
            } else {
                $pendingStmt->close();
                echo json_encode(['success' => false, 'message' => 'A verification email has already been sent to this address. Please check the inbox.']); exit;
            }
        }
        $pendingStmt->close();

        $throwaway = ['10minutemail.com','guerrillamail.com','tempmail.com','trashmail.com','mailinator.com','tempmail.org','maildrop.cc','throwaway.email'];
        $domainCheck = strtolower(explode('@', $emailNormalized)[1]);
        if (in_array($domainCheck, $throwaway)) {
            echo json_encode(['success' => false, 'message' => 'Temporary or disposable email addresses are not allowed.']); exit;
        }

        $tempPassword      = um_generate_temp_password();
        $hashedPassword    = password_hash($tempPassword, PASSWORD_DEFAULT);
        $verificationToken = um_generate_verification_token();
        $tokenExpires      = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $conn->query("DELETE FROM pending_registrations WHERE verification_token_expires < NOW()");
        $delOldStmt = $conn->prepare("DELETE FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
        $delOldStmt->bind_param("s", $emailNormalized);
        $delOldStmt->execute();
        $delOldStmt->close();

        $pendingInsert = $conn->prepare("INSERT INTO pending_registrations (first_name, last_name, email, role, password, verification_token, verification_token_expires) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pendingInsert->bind_param("sssssss", $firstName, $lastName, $emailNormalized, $role, $hashedPassword, $verificationToken, $tokenExpires);

        if (!$pendingInsert->execute()) {
            $pendingInsert->close();
            echo json_encode(['success' => false, 'message' => 'Failed to store registration data: ' . $conn->error]); exit;
        }
        $pendingInsert->close();

        $protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'];
        $scriptPath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $verificationLink = $protocol . '://' . $host . $scriptPath . '/../functionality/verify.php?token=' . urlencode($verificationToken);

        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug  = 0;
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'lguportal2026@gmail.com';
            $mail->Password   = 'krdatioghgqriruh';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'quoted-printable';
            $mail->Timeout    = 30;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT]];
            $mail->SMTPAutoTLS   = true;
            $mail->SMTPKeepAlive = false;
            $mail->WordWrap = 0;

            $mail->setFrom('lguportal2026@gmail.com', 'LGU Portal', false);
            $mail->addAddress($emailNormalized, htmlspecialchars($firstName . ' ' . $lastName));
            $mail->isHTML(true);
            $mail->Subject = 'Verify Your Email Address - LGU Portal Account Creation';

            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
            <body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f5f5">
                <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:44px 36px;box-shadow:0 4px 20px rgba(0,0,0,0.1)">
                    <h1 style="color:#27417b;margin:0 0 6px 0;font-size:30px;text-align:center;">LGU Portal</h1>
                    <h2 style="color:#4e627f;margin:0 0 32px 0;font-size:17px;font-weight:400;text-align:center;">Email Verification Required</h2>
                    <div style="color:#555;font-size:15px;line-height:1.65;margin:0 0 28px 0;text-align:center;">
                        Hello <strong style="color:#27417b;">' . htmlspecialchars($firstName) . '</strong>,<br><br>
                        An administrator has created an account for you on the <strong>LGU Portal</strong>.
                        Click the button below to verify your email and activate your account.
                    </div>
                    <div style="text-align:center;margin:0 0 28px 0">
                        <a href="' . $verificationLink . '"
                           style="display:inline-block;background:linear-gradient(135deg,#3762c8,#5f8cff);
                                  color:#fff;text-decoration:none;padding:17px 54px;border-radius:13px;
                                  font-size:17px;font-weight:700;
                                  box-shadow:0 6px 18px rgba(55,98,200,0.45);letter-spacing:0.02em;">
                            ✉&nbsp; Confirm Email
                        </a>
                    </div>
                    <div style="background:linear-gradient(135deg,#eef2ff,#e8edff);
                                border:2px solid #b6c6f5;border-radius:14px;padding:22px 24px;margin:0 0 28px 0;text-align:center;">
                        <div style="font-size:12px;font-weight:700;color:#3762c8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:10px;">
                            🔑 &nbsp;Your Temporary Password
                        </div>
                        <div style="font-size:26px;font-weight:800;color:#1a2f6e;letter-spacing:0.12em;
                                    font-family:\'Courier New\',Courier,monospace;background:#fff;border:1.5px dashed #b6c6f5;
                                    border-radius:9px;padding:12px 18px;display:inline-block;word-break:break-all;">
                            ' . htmlspecialchars($tempPassword) . '
                        </div>
                        <div style="font-size:12.5px;color:#5a6e9e;margin-top:10px;line-height:1.5;">
                            Use this password to log in after you confirm your email.<br>
                            You will be asked to <strong>change it on first login</strong>.
                        </div>
                    </div>
                    <div style="background:linear-gradient(135deg,#f0fff4,#e6f9ee);border:2px solid #6fcf97;
                                border-radius:14px;padding:22px 24px;margin:0 0 28px 0;text-align:center;">
                        <div style="font-size:12px;font-weight:700;color:#219653;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:8px;">
                            🔗 &nbsp;Your Portal Login Link
                        </div>
                        <div style="font-size:13px;color:#333;line-height:1.6;margin-bottom:14px;">
                            <strong>Important:</strong> This is the link you will use to log in to the portal.<br>
                            <span style="color:#555;">Please save or bookmark it — you will need it every time you sign in.</span>
                        </div>
                        <a href="https://cimm.infragovservices.com/lgu-portal/public/citizen/citizencimm.php?staff=infrastructure_staff_2026_qr8p"
                           style="display:inline-block;background:linear-gradient(135deg,#27ae60,#2ecc71);
                                  color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;
                                  font-size:14px;font-weight:700;box-shadow:0 4px 14px rgba(39,174,96,0.4);letter-spacing:0.02em;margin-bottom:12px;">
                            🌐&nbsp; Go to Login Page
                        </a>
                    </div>
                    <div style="border-top:1px solid #eee;margin:0 0 22px 0;"></div>
                    <div style="color:#888;font-size:12.5px;line-height:1.6;margin:0 0 18px 0;text-align:center;">
                        Button not working? Copy and paste this link into your browser:<br>
                        <a href="' . $verificationLink . '" style="color:#3762c8;word-break:break-all;font-size:11.5px;">' . $verificationLink . '</a>
                    </div>
                    <div style="background:#fff5f5;border:1px solid #fcc;border-radius:10px;padding:13px 18px;margin:0 0 20px 0;text-align:center;">
                        <span style="color:#c0392b;font-size:13.5px;font-weight:700;">⚠️ &nbsp;This link expires in <u>24 hours</u>.</span><br>
                        <span style="color:#c0392b;font-size:12.5px;">Your account will NOT be created unless you click the confirmation button.</span>
                    </div>
                    <p style="color:#bbb;font-size:11px;text-align:center;margin:0">&copy; ' . date('Y') . ' LGU Portal &mdash; Do not reply to this email.</p>
                </div>
            </body></html>';

            $mail->Body = $htmlBody;
            $mail->AltBody = "LGU Portal - Email Verification\n\nHello " . htmlspecialchars($firstName) . ",\n\n" .
                "An administrator has created an account for you. Please verify your email:\n\n{$verificationLink}\n\n" .
                "This link expires in 24 hours.\n\nTemporary password: " . htmlspecialchars($tempPassword) . "\n" .
                "You will be asked to change this on first login.\n\n© " . date('Y') . " LGU Portal";

            if (!$mail->validateAddress($emailNormalized)) {
                throw new PHPMailerException("Invalid email address: $emailNormalized");
            }
            $mail->send();

            $actor = activity_actor_name();
            log_activity($conn, 'user_management', 'user', 0, 'account_created',
                "{$actor} sent an account-verification email to {$firstName} {$lastName} ({$emailNormalized}) as {$role}.");

            echo json_encode(['success' => true, 'message' => 'Verification email sent to ' . htmlspecialchars($emailNormalized) . '. The account will be created once they confirm their email.']);
        } catch (\Throwable $e) {
            $cleanStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
            $cleanStmt->bind_param("s", $verificationToken);
            $cleanStmt->execute();
            $cleanStmt->close();

            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            echo json_encode(['success' => false, 'message' => 'Failed to send verification email: ' . htmlspecialchars($errorInfo) . '. Account was NOT created.']);
            error_log('PHPMailer Error in user_management.php: ' . $e->getMessage());
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Fetch all users for the list ──────────────────────────────────────────────
$usersResult = $conn->query("
    SELECT user_id, first_name, last_name, email, role, profile_picture,
           email_verified, is_first_login, account_locked, failed_login_attempts, last_login, last_activity
    FROM employees
    ORDER BY FIELD(role, 'Super Admin', 'Admin', 'Office Staff', 'Engineer', 'Area Engineer'),
             first_name ASC, last_name ASC
");
$users = [];
$roleCounts = ['Super Admin' => 0, 'Admin' => 0, 'Office Staff' => 0, 'Engineer' => 0, 'Area Engineer' => 0];
$lockedCount = 0;
const UM_ACTIVE_WINDOW = 120; // seconds — within this window of last_activity, show "Active now"
if ($usersResult) {
    while ($row = $usersResult->fetch_assoc()) {
        $picPath = $row['profile_picture'] ?? null;
        $hasPic  = $picPath && file_exists(__DIR__ . '/../' . $picPath);
        $picSrc  = $hasPic ? ('../' . $picPath) : null;
        $locked  = (bool)$row['account_locked'];
        if ($locked) $lockedCount++;
        if (isset($roleCounts[$row['role']])) $roleCounts[$row['role']]++;
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        $avatarMeta = um_avatar_meta($name);

        $lastActivityTs = $row['last_activity'] ? strtotime($row['last_activity']) : null;
        $isOnlineNow    = $lastActivityTs !== null && (time() - $lastActivityTs) <= UM_ACTIVE_WINDOW;

        $users[] = [
            'id'        => (int)$row['user_id'],
            'name'      => $name,
            'email'     => $row['email'],
            'role'      => $row['role'],
            'picture'   => $picSrc,
            'initials'  => $avatarMeta['initials'],
            'avatarColor' => $avatarMeta['color'],
            'verified'  => (bool)$row['email_verified'],
            'firstLogin'=> (bool)$row['is_first_login'],
            'locked'    => $locked,
            'failed'    => (int)$row['failed_login_attempts'],
            'lastLogin' => $row['last_login'],
            'lastActivityTs' => $lastActivityTs,
            'onlineNow' => $isOnlineNow,
            'isSelf'    => (int)$row['user_id'] === $currentUserId,
            'isSuperAdmin' => strcasecmp($row['role'], 'Super Admin') === 0,
        ];
    }
}
$totalUsers = count($users);

// Server-rendered label for the status pill; JS refreshes this client-side
// every 30s using the raw lastActivityTs so it stays "live" without a reload.
function um_status_label(array $u): string {
    if ($u['locked']) return 'Locked';
    if ($u['onlineNow']) return 'Active now';
    if ($u['lastActivityTs']) return 'Active ' . um_relative_time(date('Y-m-d H:i:s', $u['lastActivityTs']));
    return 'Never active';
}

// Matching CSS class — "Never active" gets its own neutral/gray scheme instead
// of the green "active" one, since the account has no recorded activity at all.
function um_status_class(array $u): string {
    if ($u['locked']) return 'status-locked';
    if (!$u['lastActivityTs']) return 'status-neutral';
    return $u['onlineNow'] ? 'status-active status-online' : 'status-active';
}

function um_status_icon(array $u): string {
    if ($u['locked']) return 'fa-lock';
    if (!$u['lastActivityTs']) return 'fa-circle-minus';
    return 'fa-check-circle';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="../assets/css/emp-global.css?v=10">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<title>User Management | LGU Portal</title>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
(function(){
    let t=localStorage.getItem('theme');
    if(t!=='dark'&&t!=='light') t='light';
    if(t==='dark') document.documentElement.setAttribute('data-theme','dark');
    else document.documentElement.removeAttribute('data-theme');
    localStorage.setItem('theme',t);
})();
</script>
<style>
/* ── Layout ── */
:root {
    --sidebar-expanded: 250px;
    --sidebar-collapsed: 70px;
    --bg-primary: #ffffff;
    --bg-secondary: rgba(255,255,255,.95);
    --bg-tertiary: rgba(255,255,255,.9);
    --text-primary: #000000;
    --text-secondary: #333333;
    --border-color: rgba(0,0,0,.1);
    --shadow-color: rgba(0,0,0,.2);
    --card-bg: #ffffff;
}
[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26,26,26,.95);
    --bg-tertiary: rgba(30,30,30,.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255,255,255,.1);
    --shadow-color: rgba(0,0,0,.5);
    --card-bg: rgba(30,30,30,.95);
}
body { overflow: hidden; height: 100vh; }
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 85px;
    height: 100vh;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left .3s ease;
    overflow-y: auto;
    overflow-x: hidden;
}
.main-content.expanded { margin-left: calc(var(--sidebar-collapsed) + 20px); }
.page-container { padding: 0 20px 50px; }

/* ── Metric cards ── */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.metric-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 4px 16px var(--shadow-color);
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: transform .3s ease, box-shadow .3s ease;
}
.metric-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px var(--shadow-color); }
.metric-card::before {
    content:''; position:absolute; top:50%; right:14px;
    transform: translateY(-50%);
    width:72px; height:72px; border-radius:50%; opacity:.18;
    pointer-events: none;
}
.metric-card.blue::before   { background:#2196f3; }
.metric-card.amber::before  { background:#f59e0b; }
.metric-card.green::before  { background:#4caf50; }
.metric-card.red::before    { background:#f44336; }
.metric-title { font-size:12px; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
.metric-value { font-size:32px; font-weight:800; color:var(--text-primary); line-height:1; }
.metric-sub   { font-size:12px; color:var(--text-secondary); margin-top:5px; }
.metric-icon-box {
    position:absolute; top:50%; right:14px;
    transform: translateY(-50%);
    width:64px; height:64px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:26px; flex-shrink: 0;
}
.metric-card.blue .metric-icon-box  { background:linear-gradient(135deg,#2196f3,#64b5f6); }
.metric-card.amber .metric-icon-box { background:linear-gradient(135deg,#f59e0b,#ffb74d); }
.metric-card.green .metric-icon-box { background:linear-gradient(135deg,#4caf50,#81c784); }
.metric-card.red .metric-icon-box   { background:linear-gradient(135deg,#f44336,#e57373); }
.metric-icon-box i { color:#fff; font-size:24px; line-height:1; }

/* ── Toolbar ── */
.search-toolbar {
    display: flex; align-items: center; width: 100%;
    padding: 8px 10px; border-radius: 14px;
    border: 1px solid rgba(55,98,200,.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    box-sizing: border-box; margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,.14) 0%, rgba(22,26,46,.85) 100%);
    border-color: rgba(95,140,255,.18);
}
.req-search-row { display: flex; align-items: center; width: 100%; gap: 10px; flex-wrap: wrap; }
.req-search-row .search-wrap { flex: 1; position: relative; display: flex; align-items: center; min-width: 200px; }
.req-search-row .search-wrap svg {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; pointer-events: none; flex-shrink: 0;
}
[data-theme="dark"] .req-search-row .search-wrap svg { color: #64748b; }
#userSearch {
    width: 100%; height: 36px; padding: 0 12px 0 34px;
    border-radius: 10px; border: 1.5px solid #94a3b8;
    background: #fff; font-size: 13px; color: var(--text-primary);
    outline: none; transition: border-color .15s, box-shadow .15s, background .15s;
    box-sizing: border-box; box-shadow: 0 1px 5px rgba(55,98,200,.14);
}
#userSearch:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.20); background: #fff; }
#userSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #userSearch { background: rgba(255,255,255,.07); border-color: rgba(95,140,255,.22); color: var(--text-primary); }
[data-theme="dark"] #userSearch:focus { border-color: #5f8cff; box-shadow: 0 0 0 3px rgba(95,140,255,.18); background: rgba(255,255,255,.10); }
[data-theme="dark"] #userSearch::placeholder { color: #64748b; }
/* ── Sort/Filter dropdown button (matches emp_feedback.php / requests.php) ── */
.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer;
    transition: all .22s ease; box-shadow: 0 2px 8px rgba(55,98,200,.30);
    white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#2851b3,#1f3e99); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(55,98,200,.40); }
.sort-btn i { font-size: 12px; }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 200px; overflow-y: auto; overflow-x: hidden;
    max-height: min(360px, calc(100vh - 120px));
    animation: sortDropIn .18s ease;
    /* Same thin, glowing branded scrollbar as .table-scroll-wrap (requests.php) */
    scrollbar-width: thin;
    scrollbar-color: #5f8cff transparent;
}
.sort-dropdown::-webkit-scrollbar { width: 6px; }
.sort-dropdown::-webkit-scrollbar-track { background: transparent; }
.sort-dropdown::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #7ba3ff, #3762c8);
    border-radius: 999px;
    box-shadow: 0 0 8px 1px rgba(95,140,255,.65);
}
.sort-dropdown::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #9cbaff, #5f8cff);
    box-shadow: 0 0 12px 2px rgba(95,140,255,.85);
}
[data-theme="dark"] .sort-dropdown::-webkit-scrollbar-thumb { box-shadow: 0 0 10px 1px rgba(95,140,255,.55); }
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-dropdown-section-label {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 16px 4px;
    font-size: 10px; font-weight: 800; letter-spacing: .09em;
    text-transform: uppercase; color: #3762c8;
    border-top: 1px solid var(--border-color,rgba(0,0,0,.08));
    margin-top: 3px;
}
.sort-dropdown-section-label:first-child { border-top: none; margin-top: 0; }
[data-theme="dark"] .sort-dropdown-section-label { color: #8fb4ff; }
.sort-filter-option {
    display: flex; align-items: center; gap: 9px; padding: 8px 16px;
    font-size: 12.5px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-filter-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.sort-filter-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.sort-filter-option i { width: 14px; text-align: center; font-size: 11px; flex-shrink: 0; }
[data-theme="dark"] .sort-filter-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-filter-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-filter-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }

/* ── Role combobox (matches admin_create.php's prof-combobox) ── */
.um-role-combobox { position: relative; width: 100%; min-width: 150px; }
.um-role-display {
    display: flex; align-items: center; justify-content: space-between; gap: 6px;
    padding: 6px 10px; border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-secondary); color: var(--text-primary);
    font-size: 12.5px; cursor: pointer; user-select: none;
    transition: border-color .2s, box-shadow .2s; font-family: inherit;
}
.um-role-display:hover { border-color: #3762c8; }
.um-role-display.open { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.15); }
.um-role-display.disabled { opacity: .55; cursor: not-allowed; pointer-events: none; }
.um-role-label { display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.um-role-arrow { font-size: 10px; color: var(--text-secondary); transition: transform .2s; flex-shrink: 0; }
.um-role-display.open .um-role-arrow { transform: rotate(180deg); }
/* Positioned via JS (position:fixed, top/left computed from the trigger's
   getBoundingClientRect()) so it always lines up with its button and is never
   clipped by the table's scrolling container — see the JS below. */
.um-role-dropdown {
    display: none; position: fixed; min-width: 170px;
    background: var(--bg-secondary,#fff); border: 1.5px solid #3762c8; border-radius: 9px;
    box-shadow: 0 10px 28px rgba(0,0,0,.22); z-index: 10000; overflow: hidden;
}
.um-role-dropdown.open { display: block; }
[data-theme="dark"] .um-role-dropdown { background: #1e1e24; box-shadow: 0 10px 28px rgba(0,0,0,.45); }
.um-role-option {
    padding: 9px 14px; font-size: 12.5px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background .12s; display: flex; align-items: center; gap: 8px;
}
.um-role-option:last-child { border-bottom: none; }
.um-role-option:hover { background: rgba(55,98,200,.09); }
.um-role-option.selected-opt { background: rgba(55,98,200,.14); font-weight: 600; color: #3762c8; }
[data-theme="dark"] .um-role-option.selected-opt { color: #7aa3f5; }
.um-role-option i { width: 14px; opacity: .75; }

/* ── Empty state (no search/filter results) ── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-secondary); }
.empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: .4; }
.empty-state p { font-size: 15px; margin: 0 0 4px; font-weight: 600; color: var(--text-primary); }
.empty-state span { font-size: 13px; }

.table-card {
    background: var(--bg-secondary);
    border-radius: 18px; padding: 30px 35px;
    box-shadow: 0 6px 20px var(--shadow-color);
    border: 1px solid var(--border-color);
    display: flex; flex-direction: column; gap: 18px;
    width: 100%; max-width: 100%; box-sizing: border-box;
}
.table-card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.table-card-header h2 { font-size: 1.15rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.badge-count { background: linear-gradient(135deg,#3762c8,#5f8cff); color:#fff; font-size:11px; font-weight:700; padding: 2px 9px; border-radius:20px; }
.admin-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff; font-size: 11px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    box-shadow: 0 3px 12px rgba(245,158,11,0.4);
}

table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13.5px; color: var(--text-primary); }
thead tr { background: #3762c8; }
[data-theme="dark"] thead tr { background: #2851b3; }
th {
    padding: 14px; text-align: left; font-size: 13px; font-weight: 700; color: #fff; white-space: nowrap;
    position: sticky; top: 0; z-index: 2; background: #3762c8;
}
[data-theme="dark"] th { background: #2851b3; }
th:first-child { border-top-left-radius: 12px; }
th:last-child  { border-top-right-radius: 12px; }

/* ── Scrollable table wrapper — caps height so a large user list doesn't
   push the rest of the page down (matches requests.php's .table-scroll-wrap) ── */
.desktop-user-table {
    max-height: 560px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 12px;
    scrollbar-width: thin;
    scrollbar-color: #5f8cff transparent;
}
.desktop-user-table::-webkit-scrollbar { width: 6px; height: 6px; }
.desktop-user-table::-webkit-scrollbar-track { background: transparent; }
.desktop-user-table::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #7ba3ff, #3762c8);
    border-radius: 999px;
    box-shadow: 0 0 8px 1px rgba(95,140,255,.65);
}
.desktop-user-table::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #9cbaff, #5f8cff);
    box-shadow: 0 0 12px 2px rgba(95,140,255,.85);
}
[data-theme="dark"] .desktop-user-table::-webkit-scrollbar-thumb { box-shadow: 0 0 10px 1px rgba(95,140,255,.55); }
td { padding: 14px; vertical-align: middle; border-bottom: 1px solid var(--border-color); font-size: 13.5px; }
tr:last-child td { border-bottom: none; }
tbody tr { transition: background .15s; }
tbody tr:hover { background: rgba(55,98,200,.08); }
[data-theme="dark"] tbody tr:hover { background: rgba(95,140,255,.08); }

.um-user-cell { display: flex; align-items: center; gap: 10px; }
.um-avatar {
    width: 36px; height: 36px; border-radius: 50%; object-fit: cover;
    background: #e0f2fe; flex-shrink: 0; border: 1.5px solid var(--border-color);
}
/* Google-account-style fallback: colored circle + initials, no image needed */
.um-avatar-letter {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 13px; font-weight: 700; letter-spacing: .02em;
    text-transform: uppercase; border: 1.5px solid var(--border-color);
}
.um-name { font-weight: 700; color: var(--text-primary); }
.um-email { font-size: 12px; color: var(--text-secondary); }
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }

/* ── Status pill ── */

.status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 11px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.status-pill.status-active { background:#dcfce7; color:#15803d; }
.status-pill.status-locked { background:#fee2e2; color:#dc2626; }
.status-pill.status-neutral { background:#f1f5f9; color:#64748b; }
[data-theme="dark"] .status-pill.status-active { background:rgba(21,128,61,.3); color:#86efac; }
[data-theme="dark"] .status-pill.status-locked { background:rgba(220,38,38,.3); color:#fca5a5; }
[data-theme="dark"] .status-pill.status-neutral { background:rgba(100,116,139,.25); color:#cbd5e1; }
/* "Online now" gets a clean standalone pulsing dot in place of the check icon —
   gluing the pulse onto the checkmark glyph read as a blobby, misshapen icon. */
.um-online-dot { display: none; width: 8px; height: 8px; border-radius: 50%; background: #22c55e; flex-shrink: 0; animation: umPulse 1.4s infinite; }
.status-pill.status-online i { display: none; }
.status-pill.status-online .um-online-dot { display: inline-block; }
[data-theme="dark"] .um-online-dot { animation-name: umPulseDark; }
@keyframes umPulse {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.55); }
    70%  { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}
@keyframes umPulseDark {
    0%   { box-shadow: 0 0 0 0 rgba(74,222,128,.6); }
    70%  { box-shadow: 0 0 0 6px rgba(74,222,128,0); }
    100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); }
}

.verified-icon-yes { color: #16a34a; }
.verified-icon-no   { color: #94a3b8; }

/* ── Action buttons ── */
.um-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn-action {
    border: none; font-family: inherit; cursor: pointer;
    transition: all .22s cubic-bezier(.34,1.56,.64,1);
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    font-weight: 700; white-space: nowrap;
    padding: 6px 14px; border-radius: 20px; font-size: 12px;
}
.btn-action:disabled { opacity: .45; cursor: not-allowed; transform: none !important; }
.btn-lock {
    background: rgba(239,68,68,.10); color: #dc2626; border: 1.5px solid rgba(239,68,68,.25);
}
.btn-lock:hover:not(:disabled) { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border-color: transparent; transform: translateY(-2px); box-shadow: 0 5px 14px rgba(239,68,68,.38); }
.btn-unlock {
    background: rgba(34,197,94,.10); color: #15803d; border: 1.5px solid rgba(34,197,94,.25);
}
.btn-unlock:hover:not(:disabled) { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; border-color: transparent; transform: translateY(-2px); box-shadow: 0 5px 14px rgba(34,197,94,.38); }
[data-theme="dark"] .btn-lock   { background: rgba(239,68,68,.14); border-color: rgba(239,68,68,.30); color: #f87171; }
[data-theme="dark"] .btn-unlock { background: rgba(34,197,94,.14); border-color: rgba(34,197,94,.30); color: #86efac; }

.btn-view {
    background: rgba(55,98,200,.10); color: #3762c8; border: 1.5px solid rgba(55,98,200,.25);
}
.btn-view:hover:not(:disabled) { background: linear-gradient(135deg, #3762c8, #5f8cff); color: #fff; border-color: transparent; transform: translateY(-2px); box-shadow: 0 5px 14px rgba(55,98,200,.38); }
[data-theme="dark"] .btn-view { background: rgba(95,140,255,.14); border-color: rgba(95,140,255,.30); color: #8fb4ff; }

.btn-delete {
    background: rgba(100,116,139,.10); color: #475569; border: 1.5px solid rgba(100,116,139,.25);
}
.btn-delete:hover:not(:disabled) { background: linear-gradient(135deg, #64748b, #475569); color: #fff; border-color: transparent; transform: translateY(-2px); box-shadow: 0 5px 14px rgba(100,116,139,.38); }
[data-theme="dark"] .btn-delete { background: rgba(148,163,184,.14); border-color: rgba(148,163,184,.30); color: #cbd5e1; }

.um-header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.btn-export {
    background: rgba(22,163,74,.10); color: #15803d; border: 1.5px solid rgba(22,163,74,.25);
}
.btn-export:hover { background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; border-color: transparent; transform: translateY(-2px); box-shadow: 0 5px 14px rgba(22,163,74,.38); }
[data-theme="dark"] .btn-export { background: rgba(34,197,94,.14); border-color: rgba(34,197,94,.30); color: #86efac; }
.btn-add-user {
    background: linear-gradient(135deg, #3762c8, #5f8cff); color: #fff; border: none;
    box-shadow: 0 2px 8px rgba(55,98,200,.30);
}
.btn-add-user:hover { transform: translateY(-2px); box-shadow: 0 5px 14px rgba(55,98,200,.45); }

.you-tag { font-size: 10px; font-weight: 700; color: #3762c8; background: rgba(55,98,200,.12); padding: 1px 7px; border-radius: 10px; margin-left: 6px; }
[data-theme="dark"] .you-tag { color: #8fb4ff; background: rgba(95,140,255,.16); }

.you-tag { font-size: 10px; font-weight: 700; color: #3762c8; background: rgba(55,98,200,.12); padding: 1px 7px; border-radius: 10px; margin-left: 6px; }
[data-theme="dark"] .you-tag { color: #8fb4ff; background: rgba(95,140,255,.16); }

/* ── Mobile cards (shown via the max-width:768px block further down) ── */
.mobile-user-list {
    display: none;
    scrollbar-width: thin;
    scrollbar-color: #5f8cff transparent;
}
.mobile-user-list::-webkit-scrollbar { width: 6px; }
.mobile-user-list::-webkit-scrollbar-track { background: transparent; }
.mobile-user-list::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #7ba3ff, #3762c8);
    border-radius: 999px;
    box-shadow: 0 0 8px 1px rgba(95,140,255,.65);
}
.mobile-user-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #9cbaff, #5f8cff);
    box-shadow: 0 0 12px 2px rgba(95,140,255,.85);
}
[data-theme="dark"] .mobile-user-list::-webkit-scrollbar-thumb { box-shadow: 0 0 10px 1px rgba(95,140,255,.55); }
.um-card {
    background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px;
    padding: 16px; margin-bottom: 12px; box-shadow: 0 2px 8px var(--shadow-color);
}
.um-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.um-card-body { display: flex; flex-direction: column; gap: 8px; font-size: 13px; color: var(--text-secondary); }
.um-card-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.um-card-actions { display: flex; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border-color); flex-wrap: wrap; }

/* ── Confirm modal (role change / lock) ── */
.confirm-modal-backdrop {
    position: fixed; z-index: 9998; inset: 0; background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
.confirm-modal-backdrop.active { display: flex; }
.confirm-modal {
    background: var(--card-bg, #ffffff); border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 24px; width: 340px; max-width: 92vw;
    animation: umModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
@keyframes umModalPop { from { transform: translateY(24px) scale(.93); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
.confirm-modal .lo-icon-wrap { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.confirm-modal.role-confirm .lo-icon-wrap { background: linear-gradient(135deg, rgba(55,98,200,.13), rgba(55,98,200,.07)); border: 1.5px solid rgba(55,98,200,.22); }
.confirm-modal.lock-confirm .lo-icon-wrap { background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07)); border: 1.5px solid rgba(239,68,68,.22); }
.confirm-modal .lo-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary, #1a1a2e); margin-bottom: 8px; }
.confirm-modal .lo-desc { font-size: .92rem; color: var(--text-secondary, #64748b); margin-bottom: 24px; line-height: 1.55; }
.confirm-modal .lo-btns { display: flex; gap: 10px; width: 100%; }
.confirm-modal .lo-btn { flex: 1; padding: 11px 0; border-radius: 10px; border: none; font-weight: 600; font-size: 14px; cursor: pointer; transition: all .18s ease; font-family: inherit; line-height: 1; }
.confirm-modal .lo-cancel { background: var(--bg-secondary, #f1f5f9); color: var(--text-primary, #374151); border: 1px solid var(--border-color, #e2e8f0) !important; }
.confirm-modal .lo-cancel:hover { background: var(--border-color, #e2e8f0); }
.confirm-modal .lo-confirm-role { background: linear-gradient(135deg, #3762c8, #5f8cff); color: #fff; box-shadow: 0 4px 12px rgba(55,98,200,.35); }
.confirm-modal .lo-confirm-role:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(55,98,200,.45); }
.confirm-modal .lo-confirm-lock { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 4px 12px rgba(239,68,68,.35); }
.confirm-modal .lo-confirm-lock:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(239,68,68,.45); }
[data-theme="dark"] .confirm-modal { background: rgba(24,24,30,.98); box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07); }

/* ── Add User / View Profile modals ── */
.um-form-modal {
    background: var(--card-bg, #ffffff); border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    width: 460px; max-width: 92vw; max-height: 88vh;
    display: flex; flex-direction: column;
    animation: umModalPop .28s cubic-bezier(.34,1.56,.64,1);
}
[data-theme="dark"] .um-form-modal { background: rgba(24,24,30,.98); box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07); }
.um-form-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
}
.um-form-modal-header h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.um-form-modal-header .modal-close {
    background: none; border: none; font-size: 24px; color: var(--text-secondary, #64748b); cursor: pointer;
    width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; transition: all .2s; flex-shrink: 0; line-height: 1;
}
.um-form-modal-header .modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
.um-form-modal-body {
    padding: 20px 22px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #5f8cff rgba(0,0,0,.07);
}
.um-form-modal-body::-webkit-scrollbar { width: 5px; }
.um-form-modal-body::-webkit-scrollbar-thumb { background: #5f8cff; border-radius: 3px; }
.um-form-modal-footer { display: flex; gap: 10px; padding: 16px 22px; border-top: 1px solid var(--border-color); flex-shrink: 0; justify-content: center; }
.um-form-modal-footer .lo-btn { padding: 12px 28px; }
.um-form-modal-footer .lo-cancel { background: var(--bg-secondary, #f1f5f9); color: var(--text-primary, #374151); border: 1px solid var(--border-color, #e2e8f0) !important; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all .18s ease; font-family: inherit; }
.um-form-modal-footer .lo-cancel:hover { background: var(--border-color, #e2e8f0); }
.um-form-hint { font-size: 12.5px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.5; }
.um-form-row { margin-bottom: 14px; }
.um-form-row label { display: block; font-size: 12px; font-weight: 700; color: var(--text-secondary); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .03em; }
.um-form-row input, .um-form-row select {
    width: 100%; padding: 10px 12px; border-radius: 9px;
    border: 1.5px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);
    font-size: 13.5px; font-family: inherit; outline: none; box-sizing: border-box;
}
.um-form-row input:focus, .um-form-row select:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.15); }
.um-form-error {
    background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.25); color: #dc2626;
    padding: 10px 14px; border-radius: 9px; font-size: 12.5px; margin-top: 6px;
}
[data-theme="dark"] .um-form-error { background: rgba(239,68,68,.14); color: #f87171; }

/* ── View Profile modal (structure mirrors current_reports.php's #engDetailsModal,
   recolored to this page's blue theme; avatar uses this page's own letter-avatar
   fallback instead of the orange engineer icon) ── */
.um-profile-loading { text-align: center; padding: 40px 0; color: var(--text-secondary); font-size: 14px; }
#vpDetModal {
    background: var(--bg-primary, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.22), 0 0 0 1px rgba(0,0,0,.05);
    width: 500px; max-width: 94vw; max-height: 88vh;
    display: flex; flex-direction: column;
    animation: umModalPop .28s cubic-bezier(.34,1.56,.64,1);
    overflow: hidden;
}
[data-theme="dark"] #vpDetModal { background: rgba(24,24,30,.98); box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.08); }
.vp-det-band { height: 6px; width: 100%; background: linear-gradient(90deg,#3762c8,#5f8cff); flex-shrink: 0; }
.vp-det-header { display: flex; align-items: center; gap: 14px; padding: 18px 22px 12px; flex-shrink: 0; }
.vp-det-avatar-wrap {
    width: 62px; height: 62px; border-radius: 50%; flex-shrink: 0; overflow: hidden;
    border: 2.5px solid #3762c8; box-shadow: 0 4px 12px rgba(55,98,200,.25);
    display: flex; align-items: center; justify-content: center;
}
.vp-det-avatar-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%; }
.vp-det-avatar-wrap .um-avatar-letter { width: 100%; height: 100%; font-size: 24px; border: none; }
.vp-det-title-wrap { flex: 1; min-width: 0; }
.vp-det-name { font-size: 1.05rem; font-weight: 700; color: var(--text-primary, #1a1a2e); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vp-det-role { font-size: 12px; color: #3762c8; font-weight: 600; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
[data-theme="dark"] .vp-det-role { color: #8fb4ff; }
.vp-det-close {
    background: none; border: none; font-size: 24px; color: var(--text-secondary, #64748b); cursor: pointer;
    width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; transition: all .2s; flex-shrink: 0;
}
.vp-det-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
.vp-det-body {
    padding: 4px 22px 20px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #5f8cff rgba(0,0,0,.07);
}
.vp-det-body::-webkit-scrollbar { width: 5px; }
.vp-det-body::-webkit-scrollbar-thumb { background: #5f8cff; border-radius: 3px; }
.vp-det-section-title { font-size: 10px; font-weight: 800; letter-spacing: .1em; color: #2851b3; text-transform: uppercase; margin: 18px 0 12px; }
[data-theme="dark"] .vp-det-section-title { color: #8fb4ff; }
.vp-det-section-title:first-child { margin-top: 4px; }
.vp-det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
@media (min-width: 769px) { #vpDetModal { width: 620px; } .vp-det-grid { grid-template-columns: 1fr 1fr 1fr; } }
.vp-det-field-label { display: flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; color: var(--text-secondary, #64748b); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
.vp-det-field-value { font-size: 13.5px; color: var(--text-primary, #1a1a2e); line-height: 1.55; word-break: break-word; }
.vp-det-field-single { margin-top: 14px; }
.vp-det-skills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.vp-det-skill-badge { padding: 5px 13px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(55,98,200,.12); color: #2851b3; border: 1px solid rgba(55,98,200,.3); }
[data-theme="dark"] .vp-det-skill-badge { background: rgba(95,140,255,.16); color: #8fb4ff; }
.vp-det-divider { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 16px 0 0; }
.vp-det-footer { padding: 12px 22px; border-top: 1px solid var(--border-color, rgba(0,0,0,.08)); flex-shrink: 0; display: flex; justify-content: center; }
.vp-det-close-btn {
    padding: 9px 22px; border-radius: 10px; border: none; cursor: pointer; font-size: 13px; font-weight: 600;
    background: linear-gradient(135deg,#3762c8,#2851b3); color: #fff; box-shadow: 0 4px 12px rgba(55,98,200,.3);
    transition: all .18s ease;
}
.vp-det-close-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(55,98,200,.4); }

/* ── Area Engineer's "assigned district" summary (in place of full personal/professional/skills sections) ── */
.vp-det-district-card {
    display: flex; align-items: center; gap: 14px; margin-top: 8px;
    padding: 16px 18px; border-radius: 14px;
    background: rgba(55,98,200,.06); border: 1px solid rgba(55,98,200,.18);
}
[data-theme="dark"] .vp-det-district-card { background: rgba(95,140,255,.1); border-color: rgba(95,140,255,.22); }
.vp-det-district-icon {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
    background: linear-gradient(135deg,#3762c8,#5f8cff); color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 19px;
    box-shadow: 0 4px 12px rgba(55,98,200,.3);
}
.vp-det-district-label { font-size: 10px; font-weight: 700; color: var(--text-secondary, #64748b); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 3px; }
.vp-det-district-value { font-size: 15px; font-weight: 700; color: var(--text-primary, #1a1a2e); }

/* ── Engineer performance metrics (Report Activity / Behaviour / Rating) — copied from
   current_reports.php's engineer details modal so both pages stay visually identical ── */
:root {
    --emc-card-bg:     #ffffff;
    --emc-green:       #4caf50; --emc-green-l:  #81c784;
    --emc-blue:        #2196f3; --emc-blue-l:   #64b5f6;
    --emc-orange:      #ff9800; --emc-orange-l: #ffb74d;
    --emc-teal:        #009688; --emc-teal-l:   #4db6ac;
    --emc-red:         #f44336; --emc-red-l:    #e57373;
    --emc-purple:      #9c27b0; --emc-purple-l: #ba68c8;
    --emc-amber:       #ff6f00; --emc-amber-l:  #ffa000;
    --emc-indigo:      #3f51b5; --emc-indigo-l: #7986cb;
}
[data-theme="dark"] {
    --emc-card-bg:     rgba(30,30,30,0.95);
    --emc-green:       #66bb6a; --emc-green-l:  #81c784;
    --emc-blue:        #42a5f5; --emc-blue-l:   #64b5f6;
    --emc-orange:      #ffa726; --emc-orange-l: #ffb74d;
    --emc-teal:        #26a69a; --emc-teal-l:   #4db6ac;
    --emc-red:         #ef5350; --emc-red-l:    #e57373;
    --emc-purple:      #ab47bc; --emc-purple-l: #ba68c8;
    --emc-amber:       #ffa000; --emc-amber-l:  #ffb300;
    --emc-indigo:      #5c6bc0; --emc-indigo-l: #7986cb;
}
.emc-section-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--text-secondary, #64748b); opacity: .65; margin: 14px 0 8px; }
.emc-section-label:first-child { margin-top: 2px; }
.emc-grid-wrap { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.emc-grid-wrap .emc-section-label { grid-column: 1 / -1; margin-top: 10px; margin-bottom: 0; }
.emc-grid-wrap .emc-section-label:first-child { margin-top: 0; }
.emc-card {
    background: var(--emc-card-bg, #fff); border-radius: 16px; padding: 16px 18px 14px;
    box-shadow: 0 4px 16px var(--shadow-color, rgba(0,0,0,.15)); border: 1px solid var(--border-color, rgba(0,0,0,.08));
    position: relative; overflow: hidden; transition: transform .25s ease, box-shadow .25s ease;
    display: flex; flex-direction: column; gap: 6px;
}
.emc-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px var(--shadow-color, rgba(0,0,0,.2)); }
.emc-card::before {
    content: ''; position: absolute; top: 4px; right: 6px; width: 64px; height: 64px; border-radius: 50%;
    opacity: .45; transition: opacity .3s ease; pointer-events: none; z-index: 0;
}
.emc-card:hover::before { opacity: .55; }
[data-theme="dark"] .emc-card::before       { opacity: .18; }
[data-theme="dark"] .emc-card:hover::before { opacity: .28; }
.emc-card.emc-green::before  { background: var(--emc-green); }
.emc-card.emc-blue::before   { background: var(--emc-blue); }
.emc-card.emc-orange::before { background: var(--emc-orange); }
.emc-card.emc-teal::before   { background: var(--emc-teal); }
.emc-card.emc-red::before    { background: var(--emc-red); }
.emc-card.emc-purple::before { background: var(--emc-purple); }
.emc-card.emc-amber::before  { background: var(--emc-amber); }
.emc-card.emc-indigo::before { background: var(--emc-indigo); }
.emc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; position: relative; z-index: 1; }
.emc-title { font-size: 11px; font-weight: 600; color: var(--text-secondary, #64748b); text-transform: uppercase; letter-spacing: .5px; line-height: 1.3; flex: 1; position: relative; z-index: 1; }
.emc-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; transition: transform .25s ease; position: relative; z-index: 1; }
.emc-card:hover .emc-icon { transform: scale(1.08) rotate(4deg); }
.emc-icon i { color: rgba(20,20,40,.80); -webkit-text-stroke: 2px rgba(0,0,0,.75); paint-order: stroke fill; }
[data-theme="dark"] .emc-icon i { color: #fff; -webkit-text-stroke: 2px rgba(0,0,0,.75); paint-order: stroke fill; }
.emc-card.emc-green  .emc-icon { background: linear-gradient(135deg, var(--emc-green), var(--emc-green-l)); box-shadow: 0 3px 10px rgba(76,175,80,.35); border: 2px solid rgba(76,175,80,.55); }
.emc-card.emc-blue   .emc-icon { background: linear-gradient(135deg, var(--emc-blue),  var(--emc-blue-l));  box-shadow: 0 3px 10px rgba(33,150,243,.35); border: 2px solid rgba(33,150,243,.55); }
.emc-card.emc-orange .emc-icon { background: linear-gradient(135deg, var(--emc-orange),var(--emc-orange-l));box-shadow: 0 3px 10px rgba(255,152,0,.35);  border: 2px solid rgba(255,152,0,.55); }
.emc-card.emc-teal   .emc-icon { background: linear-gradient(135deg, var(--emc-teal),  var(--emc-teal-l));  box-shadow: 0 3px 10px rgba(0,150,136,.35);  border: 2px solid rgba(0,150,136,.55); }
.emc-card.emc-red    .emc-icon { background: linear-gradient(135deg, var(--emc-red),   var(--emc-red-l));   box-shadow: 0 3px 10px rgba(244,67,54,.35);  border: 2px solid rgba(244,67,54,.55); }
.emc-card.emc-purple .emc-icon { background: linear-gradient(135deg, var(--emc-purple),var(--emc-purple-l));box-shadow: 0 3px 10px rgba(156,39,176,.35); border: 2px solid rgba(156,39,176,.55); }
.emc-card.emc-amber  .emc-icon { background: linear-gradient(135deg, var(--emc-amber), var(--emc-amber-l)); box-shadow: 0 3px 10px rgba(255,111,0,.35);  border: 2px solid rgba(255,111,0,.55); }
.emc-card.emc-indigo .emc-icon { background: linear-gradient(135deg, var(--emc-indigo),var(--emc-indigo-l));box-shadow: 0 3px 10px rgba(63,81,181,.35);  border: 2px solid rgba(63,81,181,.55); }
[data-theme="dark"] .emc-card.emc-green  .emc-icon { border-color: rgba(102,187,106,.85); }
[data-theme="dark"] .emc-card.emc-blue   .emc-icon { border-color: rgba(66,165,245,.85); }
[data-theme="dark"] .emc-card.emc-orange .emc-icon { border-color: rgba(255,167,38,.85); }
[data-theme="dark"] .emc-card.emc-teal   .emc-icon { border-color: rgba(77,182,172,.85); }
[data-theme="dark"] .emc-card.emc-red    .emc-icon { border-color: rgba(239,83,80,.85); }
[data-theme="dark"] .emc-card.emc-purple .emc-icon { border-color: rgba(186,104,200,.85); }
[data-theme="dark"] .emc-card.emc-amber  .emc-icon { border-color: rgba(255,167,38,.85); }
[data-theme="dark"] .emc-card.emc-indigo .emc-icon { border-color: rgba(121,134,203,.85); }
.emc-value { font-size: 32px; font-weight: 700; color: var(--text-primary, #1a1a2e); line-height: 1; letter-spacing: -1px; position: relative; z-index: 1; }
[data-theme="dark"] .emc-value { color: var(--text-primary, #fff); }
.emc-sub { font-size: 11px; font-weight: 600; color: var(--text-secondary, #64748b); display: flex; align-items: center; gap: 5px; position: relative; z-index: 1; }
.emc-sub-icon { font-size: 12px; }
.emc-sub.positive { color: var(--emc-green, #4caf50); }
.emc-sub.warning  { color: var(--emc-orange, #ff9800); }
.emc-sub.danger   { color: var(--emc-red, #f44336); }
.emc-sub.neutral  { color: var(--text-secondary, #64748b); }
@media (max-width: 560px) {
    .vp-det-body .emc-grid-wrap { grid-template-columns: repeat(2, 1fr) !important; gap: 8px; }
    .vp-det-body .emc-grid-wrap .emc-section-label { grid-column: 1 / -1; margin-top: 6px; }
    .vp-det-body .emc-card { padding: 11px 12px 10px; }
    .vp-det-body .emc-card::before { width: 52px; height: 52px; top: 3px; right: 4px; opacity: .35; }
    .vp-det-body .emc-value { font-size: 26px; }
    .vp-det-body .emc-icon  { width: 34px; height: 34px; font-size: 14px; border-radius: 9px; }
    .vp-det-body .emc-title { font-size: 10px; }
    .vp-det-body .emc-sub   { font-size: 10px; }
}
.um-metrics-loading { font-size: 12px; color: var(--text-secondary, #64748b); padding: 6px 0; display: flex; align-items: center; gap: 6px; }

/* ── Add User form fields (mirrors admin_create.php's form-group / input-with-icon / submit-btn) ── */
.form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 20px; position: relative; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; }
.input-with-icon { position: relative; display: flex; align-items: center; }
.input-with-icon .field-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-secondary); opacity: .7; pointer-events: none; transition: color .2s, opacity .2s; }
.input-with-icon input {
    width: 100%; padding: 11px 14px 11px 40px; border: 1.5px solid var(--border-color);
    border-radius: 10px; font-family: inherit; font-size: 13.5px;
    background: var(--bg-secondary); color: var(--text-primary);
    transition: all .25s ease; outline: none; box-sizing: border-box; height: 44px;
}
.input-with-icon input:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.15); }
.input-with-icon input:focus ~ .field-icon { color: #3762c8; opacity: 1; }
.info-card {
    background: rgba(55,98,200,.06); border: 1px solid rgba(55,98,200,.18);
    border-radius: 12px; padding: 14px 18px; display: flex; align-items: flex-start; gap: 12px;
    margin-bottom: 22px; color: var(--text-secondary); font-size: 12.5px; line-height: 1.55;
}
[data-theme="dark"] .info-card { background: rgba(95,140,255,.08); border-color: rgba(95,140,255,.22); }
.info-card .info-icon { font-size: 18px; color: #3762c8; flex-shrink: 0; margin-top: 1px; }
[data-theme="dark"] .info-card .info-icon { color: #8fb4ff; }
.save-wrapper { justify-content: center; }
.submit-btn {
    padding: 12px 32px; background: linear-gradient(135deg, #3762c8, #5f8cff); color: #fff;
    border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer;
    transition: all .25s ease; display: flex; align-items: center; gap: 8px; font-family: inherit;
}
.submit-btn:hover:not(:disabled) { background: linear-gradient(135deg, #2851b3, #4a76f5); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(55,98,200,.35); }
.submit-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

/* ── Logout modal (shared across admin pages) ── */
#logoutAlertBackdrop {
    position: fixed; z-index: 9999; inset: 0; background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
#logoutAlertBackdrop.active { display: flex; }
#logoutAlertModal {
    background: var(--card-bg, #ffffff); border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 24px; width: 320px; max-width: 92vw;
    animation: logoutModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
@keyframes logoutModalPop { from { transform: translateY(24px) scale(.93); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
#logoutAlertModal .lo-icon-wrap { width: 64px; height: 64px; background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07)); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; border: 1.5px solid rgba(239,68,68,.22); flex-shrink: 0; }
#logoutAlertModal .lo-title { font-size: 1.05rem !important; font-weight: 700 !important; color: var(--text-primary, #1a1a2e) !important; margin-bottom: 8px !important; }
#logoutAlertModal .lo-desc { font-size: .92rem !important; color: var(--text-secondary, #64748b) !important; margin-bottom: 24px !important; line-height: 1.55 !important; }
#logoutAlertModal .lo-btns { display: flex !important; gap: 10px !important; width: 100% !important; }
#logoutAlertModal .lo-btn { flex: 1 !important; padding: 11px 0 !important; border-radius: 10px !important; border: none !important; font-weight: 600 !important; font-size: 14px !important; cursor: pointer !important; transition: all .18s ease !important; font-family: inherit !important; line-height: 1 !important; }
#logoutAlertModal .lo-cancel { background: var(--bg-secondary, #f1f5f9) !important; color: var(--text-primary, #374151) !important; border: 1px solid var(--border-color, #e2e8f0) !important; }
#logoutAlertModal .lo-cancel:hover { background: var(--border-color, #e2e8f0) !important; }
#logoutAlertModal .lo-confirm { background: linear-gradient(135deg, #ef4444, #dc2626) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(239,68,68,.35) !important; }
#logoutAlertModal .lo-confirm:hover { transform: translateY(-1px) !important; box-shadow: 0 6px 18px rgba(239,68,68,.45) !important; }
[data-theme="dark"] #logoutAlertModal { background: rgba(24,24,30,.98) !important; box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07) !important; }

/* ── Notification popup (flash message) ── */
.notif-popup {
    position: fixed; top: 24px; right: 24px; z-index: 10000;
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.2);
    font-size: 14px; font-weight: 600; color: #fff; max-width: 360px;
    transition: opacity .4s ease;
}
.notif-popup.notif-success { background: linear-gradient(135deg,#22c55e,#16a34a); }
.notif-popup.notif-error   { background: linear-gradient(135deg,#ef4444,#dc2626); }
.notif-popup.notif-warning { background: linear-gradient(135deg,#f59e0b,#d97706); }
.notif-popup .notif-close { background:none; border:none; color:#fff; font-size:18px; cursor:pointer; margin-left:auto; }

@media (max-width:768px) {
    body { overflow:auto !important; height:auto !important; }

    /* ── Mobile top nav ── */
    .desktop-top-nav { display: none !important; }
    .mobile-top-nav {
        display: flex !important;
        position: fixed; top: 0; left: 0;
        height: 64px; width: 100%;
        align-items: center; justify-content: center;
        background: var(--bg-secondary);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
    }
    .mobile-toggle {
        position: absolute; left: 14px;
        background: #3762c8; color: #fff;
        border: none; border-radius: 10px;
        width: 38px; height: 38px; font-size: 20px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .mobile-cimm-label { position: absolute; left: 70px; font-size: 16px; font-weight: 600; color: #3762c8; letter-spacing: .05em; }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex !important; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }

    /* ── Sidebar — slide off-screen, show only when .mobile-active ── */
    .sidebar-nav {
        left: -110% !important; width: calc(100% - 24px) !important; height: calc(100vh - 24px) !important;
        top: 12px !important; bottom: 12px !important; border-radius: 18px !important;
        transition: left .35s ease !important; z-index: 4000 !important;
        backdrop-filter: blur(10px) !important; -webkit-backdrop-filter: blur(10px) !important;
        position: fixed !important;
    }
    .sidebar-nav.mobile-active { left: 12px !important; }
    .sidebar-nav.collapsed { width: calc(100% - 24px) !important; }
    .sidebar-top { padding-top: 30px; position: relative; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; width: 45px; height: 47px; }
    .site-logo { margin: 10px auto 20px auto; }
    .nav-list { padding: 0 20px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .user-info { padding-bottom: 20px; }
    .sidebar-mobile-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 3999;
        backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px);
    }
    .sidebar-mobile-overlay.active { display: block; }

    /* ── Main content — full width, under mobile top nav ── */
    .main-content, .main-content.expanded {
        margin-left: 0 !important; margin-right: 0 !important; margin: 0 !important;
        padding: 20px 12px !important; padding-top: 80px !important;
        width: 100% !important; max-width: 100vw !important;
        height: auto !important; min-height: calc(100vh - 64px) !important;
        overflow-y: auto !important; overflow-x: hidden !important; box-sizing: border-box !important;
    }
    .main-content::-webkit-scrollbar { display:none; }
    .page-container { padding: 0 0 40px; }

    /* ── Metrics — 2 column pill grid (matches emp_feedback.php) ── */
    .metrics-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .metric-value { font-size: 26px; }
    .metric-card::before { width: 52px; height: 52px; right: 10px; }
    .metric-icon-box { width: 48px !important; height: 48px !important; display: flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important; }
    .metric-icon-box i { font-size: 18px !important; color: #fff !important; }

    /* ── Table card ── */
    .table-card { border-radius: 14px; padding: 16px; }

    /* ── Search toolbar — keep filter button inline with search ── */
    .search-toolbar { flex-direction: column; align-items: stretch; gap: 0; padding: 8px 10px; }
    .req-search-row { flex-direction: row !important; align-items: center !important; gap: 8px !important; width: 100%; }
    .req-search-row .search-wrap { flex: 1; min-width: 0; }
    #userSearch { font-size: 13px; }
    .sort-dropdown-wrap { flex-shrink: 0; }

    /* ── Hide desktop table, show mobile cards ── */
    .desktop-user-table { display: none !important; }
    .mobile-user-list {
        display: block !important; max-height: 560px !important;
        overflow-y: auto !important; padding-right: 6px;
    }

    /* ── Confirm modals (role change / lock / logout) ── */
    .confirm-modal, #logoutAlertModal { width: 320px !important; max-width: 90vw !important; box-sizing: border-box !important; }

    /* Mobile prevent overflow */
    body, html { overflow-x: hidden !important; max-width: 100vw !important; }
    * { max-width: 100%; box-sizing: border-box !important; }

    /* ── Notification popup — mobile positioning ── */
    .notif-popup {
        top: 76px !important; z-index: 5050 !important; left: 12px; right: 12px;
        transform: none; min-width: unset; max-width: unset; width: calc(100vw - 24px);
        padding: 13px 14px; font-size: 14px; gap: 10px; align-items: flex-start;
        border-radius: 11px; flex-wrap: nowrap; box-sizing: border-box;
    }
    .notif-popup .notif-icon  { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .notif-popup .notif-message { flex: 1; word-break: break-word; line-height: 1.5; }
    .notif-popup .notif-close { font-size: 18px; margin-left: 6px; margin-top: 1px; }
}
</style>
</head>
<body>

<!-- DESKTOP TOP NAV -->
<div class="desktop-top-nav">
    <div class="desktop-nav-inner">
        <div class="desktop-cimm-label">CIMM</div>
        <div class="desktop-clock" id="desktopClock"></div>
        <div class="nav-actions">
            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display:none;">☀️</span>
            </button>
            <button class="nav-btn notif-btn" id="notifBtn" title="Notifications">
                🔔
                <span class="notif-badge hidden" id="notifBadge"></span>
            </button>
        </div>
    </div>
</div>

<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3><span class="notif-header-icon">🔔</span> Notifications <span class="notif-unread-count" id="notifUnreadCount" style="display:none;"></span></h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Mark all read</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>
    </div>
</div>

<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle" onclick="(function(){var s=document.getElementById('sidebarNav'),o=document.getElementById('sidebarMobileOverlay');if(!s)return;var open=s.classList.contains('mobile-active');s.classList.toggle('mobile-active',!open);if(o)o.classList.toggle('active',!open);})()">☰</button>
    <span class="mobile-cimm-label">CIMM</span>
    <img src="../assets/img/officiallogo.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn notif-btn mobile-notif-btn" id="mobileNotifBtn" title="Notifications">
        🔔 <span class="notif-badge" id="mobileNotifBadge"></span>
    </button>
</div>

<?php showNotification(); ?>

<!-- SIDEBAR -->
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>
    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg"
                 onerror="this.style.display='none';var f=document.getElementById('profileFallbackIcon');if(f){f.style.display='flex';}"
                 <?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? 'style="display:none;"' : '' ?>>
            <span class="profile-fallback-icon" id="profileFallbackIcon"<?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? ' style="display:flex;"' : '' ?>>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="50" fill="#e0f2fe"/>
                    <circle cx="50" cy="36" r="20" fill="#2563eb"/>
                    <ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/>
                </svg>
            </span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display:none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="../assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
            <li class="nav-dropdown-item">
                <a href="#" class="nav-link nav-dropdown-toggle" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i><span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <li><a href="emp_feedback.php" class="nav-link" data-tooltip="Citizen Feedback"><i class="fas fa-comment-dots"></i><span>Citizen Feedback</span></a></li>
            <li><a href="admin_create.php" class="nav-link" data-tooltip="Create Account"><i class="fas fa-user-plus"></i><span>Create Account</span></a></li>
            <!-- Admin-only: User Management (active on this page) -->
            <li>
                <a href="#" class="nav-link active" data-tooltip="User Management">
                    <i class="fas fa-users-cog"></i>
                    <span>User Management</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sidebar-divider"></div>
    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">
            <span class="logout-label">Logout</span> <i class="fas fa-sign-out-alt"></i>
        </button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>
<div class="sidebar-mobile-overlay" id="sidebarMobileOverlay"></div>
<?php include __DIR__ . '/../../includes/partials/eng_profile_warning.php'; ?>

<!-- Logout modal -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="lo-icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>
        <div class="lo-title">Log out of your account?</div>
        <div class="lo-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="logoutCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm" id="logoutConfirmBtn">Log out</button>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">
<div class="page-container">

    <!-- Metrics -->
    <div class="metrics-grid">
        <div class="metric-card blue">
            <div class="metric-icon-box"><i class="fas fa-users"></i></div>
            <div class="metric-title">Total Users</div>
            <div class="metric-value" id="metricTotal"><?= $totalUsers ?></div>
            <div class="metric-sub">All employee accounts</div>
        </div>
        <div class="metric-card amber">
            <div class="metric-icon-box"><i class="fas fa-user-shield"></i></div>
            <div class="metric-title">Admins</div>
            <div class="metric-value" id="metricAdmins"><?= $roleCounts['Super Admin'] + $roleCounts['Admin'] ?></div>
            <div class="metric-sub">Admin &amp; Super Admin</div>
        </div>
        <div class="metric-card green">
            <div class="metric-icon-box"><i class="fas fa-hard-hat"></i></div>
            <div class="metric-title">Engineers</div>
            <div class="metric-value" id="metricEngineers"><?= $roleCounts['Engineer'] + $roleCounts['Area Engineer'] ?></div>
            <div class="metric-sub">Engineer &amp; Area Engineer</div>
        </div>
        <div class="metric-card red">
            <div class="metric-icon-box"><i class="fas fa-lock"></i></div>
            <div class="metric-title">Locked Accounts</div>
            <div class="metric-value" id="metricLocked"><?= $lockedCount ?></div>
            <div class="metric-sub">Currently unable to log in</div>
        </div>
    </div>

    <!-- Table card -->
    <div class="table-card">
        <div class="table-card-header">
            <h2>
                <i class="fas fa-users-cog" style="color:#3762c8;"></i>
                User Management
                <span class="badge-count" id="rowCount"><?= $totalUsers ?></span>
                <span class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Only</span>
            </h2>
            <div class="um-header-actions">
                <button class="btn-action btn-export" id="umExportBtn" title="Export the full user list to Excel">
                    <i class="fas fa-file-excel"></i> Export
                </button>
                <button class="btn-action btn-add-user" id="umAddUserBtn">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
            </div>
        </div>

        <!-- Search & filter toolbar -->
        <div class="search-toolbar">
            <div class="req-search-row">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="userSearch" placeholder="Search by name, email, or role…">
                </div>
                <!-- Filter dropdown -->
                <div class="sort-dropdown-wrap" id="umFilterWrap">
                    <button class="sort-btn" id="umFilterBtn" title="Filter users">
                        <i class="fas fa-sliders-h"></i>
                        <span class="sort-btn-label" id="umFilterBtnLabel">Filter</span>
                        <i class="fas fa-chevron-down sort-chevron"></i>
                    </button>
                    <div class="sort-dropdown" id="umFilterDropdown">
                        <div class="sort-dropdown-section-label" style="border-top:none;margin-top:0;"><i class="fas fa-user-tag"></i> Role</div>
                        <div class="sort-filter-option active" data-filter="role" data-val=""><i class="fas fa-layer-group"></i> All Roles</div>
                        <div class="sort-filter-option" data-filter="role" data-val="Super Admin"><i class="fas fa-crown"></i> Super Admin</div>
                        <div class="sort-filter-option" data-filter="role" data-val="Admin"><i class="fas fa-user-shield"></i> Admin</div>
                        <div class="sort-filter-option" data-filter="role" data-val="Office Staff"><i class="fas fa-user-clock"></i> Office Staff</div>
                        <div class="sort-filter-option" data-filter="role" data-val="Engineer"><i class="fas fa-hard-hat"></i> Engineer</div>
                        <div class="sort-filter-option" data-filter="role" data-val="Area Engineer"><i class="fas fa-user-tie"></i> Area Engineer</div>
                        <div class="sort-dropdown-section-label"><i class="fas fa-circle-dot"></i> Status</div>
                        <div class="sort-filter-option active" data-filter="status" data-val=""><i class="fas fa-layer-group"></i> All Statuses</div>
                        <div class="sort-filter-option" data-filter="status" data-val="active"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Active</div>
                        <div class="sort-filter-option" data-filter="status" data-val="locked"><i class="fas fa-lock" style="color:#dc2626;"></i> Locked</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DESKTOP TABLE -->
        <div class="desktop-user-table" style="overflow-x:auto;">
        <table id="userTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Verified</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="userTbody">
                <?php foreach ($users as $u): ?>
                <tr class="um-row"
                    data-user-id="<?= $u['id'] ?>"
                    data-name="<?= htmlspecialchars(strtolower($u['name'])) ?>"
                    data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>"
                    data-role="<?= htmlspecialchars($u['role']) ?>"
                    data-status="<?= $u['locked'] ? 'locked' : 'active' ?>">
                    <td>
                        <div class="um-user-cell">
                            <?php if ($u['picture']): ?>
                                <img class="um-avatar" src="<?= htmlspecialchars($u['picture']) ?>" alt=""
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="um-avatar-letter" style="display:none;background:<?= htmlspecialchars($u['avatarColor']) ?>;"><?= htmlspecialchars($u['initials']) ?></div>
                            <?php else: ?>
                                <div class="um-avatar-letter" style="background:<?= htmlspecialchars($u['avatarColor']) ?>;"><?= htmlspecialchars($u['initials']) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="um-name searchable"><?= htmlspecialchars($u['name']) ?><?= $u['isSelf'] ? '<span class="you-tag">You</span>' : '' ?></div>
                                <div class="um-email searchable"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php $canEditRole = !$u['isSelf'] && ($isSuperAdmin || !$u['isSuperAdmin']); ?>
                        <div class="um-role-combobox">
                            <div class="um-role-display<?= $canEditRole ? '' : ' disabled' ?>" data-user-id="<?= $u['id'] ?>" title="<?= $canEditRole ? '' : 'You do not have permission to change this role' ?>">
                                <span class="um-role-label"><i class="fas <?= UM_ROLE_ICONS[$u['role']] ?? 'fa-user' ?>"></i> <span class="searchable"><?= htmlspecialchars($u['role']) ?></span></span>
                                <span class="um-role-arrow">▾</span>
                            </div>
                            <div class="um-role-dropdown">
                                <?php foreach (UM_VALID_ROLES as $roleOpt):
                                    if ($roleOpt === 'Super Admin' && !$isSuperAdmin && !$u['isSuperAdmin']) continue;
                                ?>
                                    <div class="um-role-option<?= $roleOpt === $u['role'] ? ' selected-opt' : '' ?>" data-value="<?= htmlspecialchars($roleOpt) ?>">
                                        <i class="fas <?= UM_ROLE_ICONS[$roleOpt] ?>"></i> <?= htmlspecialchars($roleOpt) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="status-pill <?= um_status_class($u) ?> um-live-status"
                              data-locked="<?= $u['locked'] ? '1' : '0' ?>"
                              data-last-activity="<?= $u['lastActivityTs'] ?? '' ?>">
                            <i class="fas <?= um_status_icon($u) ?>"></i>
                            <span class="um-online-dot"></span>
                            <span class="um-status-text"><?= htmlspecialchars(um_status_label($u)) ?></span>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['verified']): ?>
                            <i class="fas fa-check-circle verified-icon-yes" title="Email verified"></i>
                        <?php else: ?>
                            <i class="fas fa-circle-xmark verified-icon-no" title="Not verified"></i>
                        <?php endif; ?>
                    </td>
                    <td><?= $u['lastLogin'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($u['lastLogin']))) : '<span style="color:var(--text-secondary);">Never</span>' ?></td>
                    <td>
                        <div class="um-actions">
                            <?php
                                $canLock = !$u['isSelf'] && ($isSuperAdmin || !$u['isSuperAdmin']);
                            ?>
                            <button class="btn-action btn-view" data-user-id="<?= $u['id'] ?>" title="View profile">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($u['locked']): ?>
                                <button class="btn-action btn-unlock" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" <?= $canLock ? '' : 'disabled' ?>>
                                    <i class="fas fa-unlock"></i> Unlock
                                </button>
                            <?php else: ?>
                                <button class="btn-action btn-lock" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" <?= $canLock ? '' : 'disabled' ?>>
                                    <i class="fas fa-lock"></i> Lock
                                </button>
                            <?php endif; ?>
                            <button class="btn-action btn-delete" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" title="Delete account" <?= $canLock ? '' : 'disabled' ?>>
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="userNoResults" style="display:none;">
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No matching users found</p>
                            <span>Try a different keyword or filter</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php if ($totalUsers === 0): ?>
            <div style="text-align:center;padding:40px;color:var(--text-secondary);">No employee accounts found.</div>
        <?php endif; ?>
        </div>

        <!-- MOBILE CARDS -->
        <div class="mobile-user-list" id="mobileUserList">
            <?php foreach ($users as $u): ?>
            <div class="um-card"
                 data-name="<?= htmlspecialchars(strtolower($u['name'])) ?>"
                 data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>"
                 data-role="<?= htmlspecialchars($u['role']) ?>"
                 data-status="<?= $u['locked'] ? 'locked' : 'active' ?>">
                <div class="um-card-header">
                    <?php if ($u['picture']): ?>
                        <img class="um-avatar" src="<?= htmlspecialchars($u['picture']) ?>" alt=""
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="um-avatar-letter" style="display:none;background:<?= htmlspecialchars($u['avatarColor']) ?>;"><?= htmlspecialchars($u['initials']) ?></div>
                    <?php else: ?>
                        <div class="um-avatar-letter" style="background:<?= htmlspecialchars($u['avatarColor']) ?>;"><?= htmlspecialchars($u['initials']) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="um-name searchable"><?= htmlspecialchars($u['name']) ?><?= $u['isSelf'] ? '<span class="you-tag">You</span>' : '' ?></div>
                        <div class="um-email searchable"><?= htmlspecialchars($u['email']) ?></div>
                    </div>
                </div>
                <div class="um-card-body">
                    <?php $canEditRole = !$u['isSelf'] && ($isSuperAdmin || !$u['isSuperAdmin']); ?>
                    <div class="um-card-row">
                        <strong>Role</strong>
                        <div class="um-role-combobox" style="width:auto;min-width:150px;">
                            <div class="um-role-display<?= $canEditRole ? '' : ' disabled' ?>" data-user-id="<?= $u['id'] ?>" title="<?= $canEditRole ? '' : 'You do not have permission to change this role' ?>">
                                <span class="um-role-label"><i class="fas <?= UM_ROLE_ICONS[$u['role']] ?? 'fa-user' ?>"></i> <span class="searchable"><?= htmlspecialchars($u['role']) ?></span></span>
                                <span class="um-role-arrow">▾</span>
                            </div>
                            <div class="um-role-dropdown">
                                <?php foreach (UM_VALID_ROLES as $roleOpt):
                                    if ($roleOpt === 'Super Admin' && !$isSuperAdmin && !$u['isSuperAdmin']) continue;
                                ?>
                                    <div class="um-role-option<?= $roleOpt === $u['role'] ? ' selected-opt' : '' ?>" data-value="<?= htmlspecialchars($roleOpt) ?>">
                                        <i class="fas <?= UM_ROLE_ICONS[$roleOpt] ?>"></i> <?= htmlspecialchars($roleOpt) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="um-card-row"><strong>Status</strong>
                        <span class="status-pill <?= um_status_class($u) ?> um-live-status"
                              data-locked="<?= $u['locked'] ? '1' : '0' ?>"
                              data-last-activity="<?= $u['lastActivityTs'] ?? '' ?>">
                            <i class="fas <?= um_status_icon($u) ?>"></i>
                            <span class="um-online-dot"></span>
                            <span class="um-status-text"><?= htmlspecialchars(um_status_label($u)) ?></span>
                        </span>
                    </div>
                    <div class="um-card-row"><strong>Verified</strong> <?= $u['verified'] ? '<i class="fas fa-check-circle verified-icon-yes"></i>' : '<i class="fas fa-circle-xmark verified-icon-no"></i>' ?></div>
                    <div class="um-card-row"><strong>Last Login</strong> <?= $u['lastLogin'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($u['lastLogin']))) : 'Never' ?></div>
                </div>
                <?php $canLock = !$u['isSelf'] && ($isSuperAdmin || !$u['isSuperAdmin']); ?>
                <div class="um-card-actions">
                    <button class="btn-action btn-view" data-user-id="<?= $u['id'] ?>" title="View profile"><i class="fas fa-eye"></i> View</button>
                    <?php if ($u['locked']): ?>
                        <button class="btn-action btn-unlock" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" <?= $canLock ? '' : 'disabled' ?>><i class="fas fa-unlock"></i> Unlock</button>
                    <?php else: ?>
                        <button class="btn-action btn-lock" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" <?= $canLock ? '' : 'disabled' ?>><i class="fas fa-lock"></i> Lock</button>
                    <?php endif; ?>
                    <button class="btn-action btn-delete" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name']) ?>" title="Delete account" <?= $canLock ? '' : 'disabled' ?>><i class="fas fa-trash"></i> Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="um-card" id="userNoResultsMobile" style="display:none;">
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <p>No matching users found</p>
                    <span>Try a different keyword or filter</span>
                </div>
            </div>
        </div>
    </div>

</div>
</div><!-- /.main-content -->

<!-- Role change confirm modal -->
<div class="confirm-modal-backdrop" id="roleConfirmBackdrop">
    <div class="confirm-modal role-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3762c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div class="lo-title">Change Role?</div>
        <div class="lo-desc" id="roleConfirmDesc">Are you sure you want to change this user's role?</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="roleCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-role" id="roleConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- Lock/unlock confirm modal -->
<div class="confirm-modal-backdrop" id="lockConfirmBackdrop">
    <div class="confirm-modal lock-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div class="lo-title" id="lockConfirmTitle">Lock Account?</div>
        <div class="lo-desc" id="lockConfirmDesc">Are you sure you want to lock this account?</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="lockCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-lock" id="lockConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- Export confirm modal -->
<div class="confirm-modal-backdrop" id="exportConfirmBackdrop">
    <div class="confirm-modal role-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3762c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h8M8 9h1"/></svg>
        </div>
        <div class="lo-title">Export to Excel?</div>
        <div class="lo-desc" id="exportConfirmDesc">This will download an Excel file containing every employee account currently in the system.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="exportCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-role" id="exportConfirmBtn"><i class="fas fa-file-excel"></i> Export</button>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="confirm-modal-backdrop" id="deleteConfirmBackdrop">
    <div class="confirm-modal lock-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        </div>
        <div class="lo-title">Delete Account?</div>
        <div class="lo-desc" id="deleteConfirmDesc">This action is permanent and cannot be undone.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="deleteCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-lock" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- Add User modal (admin_create.php form styling: form-group / input-with-icon / submit-btn) -->
<div class="confirm-modal-backdrop" id="addUserBackdrop">
    <div class="um-form-modal">
        <div class="um-form-modal-header">
            <h3><i class="fas fa-user-plus" style="color:#3762c8;"></i> Add New User</h3>
            <button class="modal-close" id="addUserCloseBtn">&times;</button>
        </div>
        <div class="um-form-modal-body">
            <div class="info-card">
                <i class="fas fa-envelope-open-text info-icon"></i>
                <div>A verification email with a temporary password will be sent to this address. The account is created once they confirm it.</div>
            </div>

            <div class="form-group">
                <label for="auFirstName">First Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-user field-icon"></i>
                    <input type="text" id="auFirstName" placeholder="First name">
                </div>
            </div>
            <div class="form-group">
                <label for="auLastName">Last Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-user field-icon"></i>
                    <input type="text" id="auLastName" placeholder="Last name">
                </div>
            </div>
            <div class="form-group">
                <label for="auEmail">Email Address</label>
                <div class="input-with-icon">
                    <i class="fas fa-envelope field-icon"></i>
                    <input type="email" id="auEmail" placeholder="name@example.com">
                </div>
            </div>
            <div class="form-group">
                <label for="auRoleDisplay">Role</label>
                <div class="um-role-combobox" id="auRoleCombobox">
                    <input type="hidden" id="auRole" value="">
                    <div class="um-role-display" id="auRoleDisplay" tabindex="0">
                        <span class="um-role-label"><i class="fas fa-user-tag"></i> <span class="searchable">— Select Role —</span></span>
                        <span class="um-role-arrow">▾</span>
                    </div>
                    <div class="um-role-dropdown">
                        <div class="um-role-option" data-value="Area Engineer"><i class="fas fa-user-tie"></i> Area Engineer</div>
                        <div class="um-role-option" data-value="Engineer"><i class="fas fa-hard-hat"></i> Engineer</div>
                        <div class="um-role-option" data-value="Office Staff"><i class="fas fa-user-clock"></i> Office Staff</div>
                        <div class="um-role-option" data-value="Admin"><i class="fas fa-user-shield"></i> Admin</div>
                        <?php if ($isSuperAdmin): ?>
                        <div class="um-role-option" data-value="Super Admin"><i class="fas fa-crown"></i> Super Admin</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="um-form-error" id="auError" style="display:none;"></div>
        </div>
        <div class="um-form-modal-footer save-wrapper">
            <button class="lo-btn lo-cancel" id="addUserCancelBtn">Cancel</button>
            <button class="submit-btn" id="addUserSubmitBtn"><i class="fas fa-paper-plane"></i> Send Invite</button>
        </div>
    </div>
</div>

<!-- Invite confirm modal -->
<div class="confirm-modal-backdrop" id="inviteConfirmBackdrop">
    <div class="confirm-modal role-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3762c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="lo-title">Send Invite?</div>
        <div class="lo-desc" id="inviteConfirmDesc">A verification email with a temporary password will be sent to this address.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="inviteCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-role" id="inviteConfirmBtn"><i class="fas fa-paper-plane"></i> Send Invite</button>
        </div>
    </div>
</div>

<!-- View Profile modal (structure mirrors current_reports.php's engineer details modal, recolored to this page's blue theme with its own letter-avatar fallback) -->
<div class="confirm-modal-backdrop" id="viewProfileBackdrop">
    <div id="vpDetModal">
        <div class="vp-det-band"></div>
        <div class="vp-det-header">
            <div id="vpDetAvatarWrap" class="vp-det-avatar-wrap"></div>
            <div class="vp-det-title-wrap">
                <div class="vp-det-name" id="vpDetName"></div>
                <div class="vp-det-role" id="vpDetRole"></div>
            </div>
            <button class="vp-det-close" id="viewProfileCloseBtn">&times;</button>
        </div>
        <div class="vp-det-body" id="viewProfileBody">
            <div class="um-profile-loading"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div class="vp-det-footer">
            <button class="vp-det-close-btn" id="viewProfileFooterCloseBtn">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/partials/admin_scripts.php'; ?>

<script>
const UM_CURRENT_USER_ID = <?= (int)$currentUserId ?>;

// ── Filter dropdown open/close ──────────────────────────────────────────────
(function(){
    var wrap = document.getElementById('umFilterWrap');
    var btn = document.getElementById('umFilterBtn');
    var drop = document.getElementById('umFilterDropdown');
    if (!wrap || !btn || !drop) return;
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        wrap.classList.toggle('open');
    });
    document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) wrap.classList.remove('open'); });
    drop.addEventListener('click', function(e){ e.stopPropagation(); });
})();

// ── Search + role/status filter + highlight ─────────────────────────────────
(function(){
    var search = document.getElementById('userSearch');
    var tbody = document.getElementById('userTbody');
    var rows = Array.from(document.querySelectorAll('#userTbody .um-row'));
    var noDesk = document.getElementById('userNoResults');
    var mobileList = document.getElementById('mobileUserList');
    var cards = Array.from(document.querySelectorAll('#mobileUserList .um-card')).filter(function(c){ return c.id !== 'userNoResultsMobile'; });
    var noMobile = document.getElementById('userNoResultsMobile');
    var countEl = document.getElementById('rowCount');
    var filterBtnLabel = document.getElementById('umFilterBtnLabel');

    var filterRole = '';
    var filterStatus = '';

    function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
    function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
    function highlightEl(el, kw) {
        if (!kw) return;
        var regex = new RegExp('(' + escapeRegExp(kw) + ')', 'gi');
        var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
        var textNodes = [];
        var node;
        while ((node = walker.nextNode())) textNodes.push(node);
        textNodes.forEach(function(tn){
            if (!tn.nodeValue.trim()) return;
            var parts = tn.nodeValue.split(regex);
            if (parts.length < 2) return;
            var frag = document.createDocumentFragment();
            parts.forEach(function(part, i){
                if (i % 2 === 1) {
                    var mark = document.createElement('span');
                    mark.className = 'search-highlight';
                    mark.textContent = part;
                    frag.appendChild(mark);
                } else {
                    frag.appendChild(document.createTextNode(part));
                }
            });
            tn.parentNode.replaceChild(frag, tn);
        });
    }

    function updateFilterBtnLabel(){
        var count = (filterRole ? 1 : 0) + (filterStatus ? 1 : 0);
        if (filterBtnLabel) filterBtnLabel.textContent = count > 0 ? 'Filter · ' + count : 'Filter';
    }

    function applyFilters(){
        var q = (search.value || '').trim();
        var ql = q.toLowerCase();

        document.querySelectorAll('#userTbody .searchable[data-original], #mobileUserList .searchable[data-original]')
            .forEach(function(el){ resetEl(el); });

        var visible = 0;

        rows.forEach(function(r){
            if (filterRole && r.dataset.role !== filterRole) { r.style.display = 'none'; return; }
            if (filterStatus && r.dataset.status !== filterStatus) { r.style.display = 'none'; return; }
            var els = r.querySelectorAll('.searchable');
            els.forEach(function(el){ storeOriginal(el); });
            var match = !ql || Array.prototype.some.call(els, function(el){ return el.textContent.toLowerCase().indexOf(ql) !== -1; });
            r.style.display = match ? '' : 'none';
            if (match) { if (ql) els.forEach(function(el){ highlightEl(el, q); }); visible++; }
        });
        if (noDesk) noDesk.style.display = visible ? 'none' : '';

        var mVisible = 0;
        cards.forEach(function(c){
            if (filterRole && c.dataset.role !== filterRole) { c.style.display = 'none'; return; }
            if (filterStatus && c.dataset.status !== filterStatus) { c.style.display = 'none'; return; }
            var els = c.querySelectorAll('.searchable');
            els.forEach(function(el){ storeOriginal(el); });
            var match = !ql || Array.prototype.some.call(els, function(el){ return el.textContent.toLowerCase().indexOf(ql) !== -1; });
            c.style.display = match ? '' : 'none';
            if (match) { if (ql) els.forEach(function(el){ highlightEl(el, q); }); mVisible++; }
        });
        if (noMobile) noMobile.style.display = mVisible ? 'none' : '';

        if (countEl) countEl.textContent = visible;
    }

    search.addEventListener('input', applyFilters);

    document.querySelectorAll('#umFilterDropdown .sort-filter-option').forEach(function(opt){
        opt.addEventListener('click', function(){
            var filterKey = opt.dataset.filter;
            var val = opt.dataset.val;
            document.querySelectorAll('#umFilterDropdown .sort-filter-option[data-filter="' + filterKey + '"]').forEach(function(o){
                o.classList.toggle('active', o === opt);
            });
            if (filterKey === 'role') filterRole = val;
            if (filterKey === 'status') filterStatus = val;
            updateFilterBtnLabel();
            applyFilters();
        });
    });
})();

// ── Role combobox open/close + selection ────────────────────────────────────
(function(){
    var backdrop = document.getElementById('roleConfirmBackdrop');
    var desc = document.getElementById('roleConfirmDesc');
    var confirmBtn = document.getElementById('roleConfirmBtn');
    var cancelBtn = document.getElementById('roleCancelBtn');
    var pending = null; // { display, dropdown, userId, newRole, newIcon, prevRole }

    function closeAllCombos(){
        document.querySelectorAll('.um-role-display.open').forEach(function(d){ d.classList.remove('open'); });
        document.querySelectorAll('.um-role-dropdown.open').forEach(function(d){ d.classList.remove('open'); });
    }

    // Position the dropdown with position:fixed, computed from the button's
    // actual screen location — this is what makes it line up correctly and
    // escape the table's scrolling container instead of being clipped.
    function positionDropdown(display, dropdown){
        var rect = display.getBoundingClientRect();
        dropdown.style.minWidth = rect.width + 'px';
        dropdown.style.left = rect.left + 'px';
        var spaceBelow = window.innerHeight - rect.bottom;
        var estHeight = dropdown.scrollHeight || 220;
        if (spaceBelow < estHeight && rect.top > estHeight) {
            dropdown.style.top = 'auto';
            dropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
        } else {
            dropdown.style.bottom = 'auto';
            dropdown.style.top = (rect.bottom + 4) + 'px';
        }
    }

    document.querySelectorAll('.um-role-display').forEach(function(display){
        if (display.classList.contains('disabled')) return;
        var dropdown = display.nextElementSibling;
        display.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !display.classList.contains('open');
            closeAllCombos();
            if (willOpen && dropdown) {
                positionDropdown(display, dropdown);
                display.classList.add('open');
                dropdown.classList.add('open');
            }
        });
    });
    document.addEventListener('click', closeAllCombos);
    window.addEventListener('scroll', closeAllCombos, true);
    window.addEventListener('resize', closeAllCombos);

    document.querySelectorAll('.um-role-option').forEach(function(opt){
        if (opt.closest('#addUserBackdrop')) return; // handled separately — no "change role" AJAX for a brand-new user
        opt.addEventListener('click', function(e){
            e.stopPropagation();
            var dropdown = opt.closest('.um-role-dropdown');
            var combobox = opt.closest('.um-role-combobox');
            var display = combobox ? combobox.querySelector('.um-role-display') : null;
            if (!display) return;
            closeAllCombos();

            var newRole = opt.dataset.value;
            var prevRole = display.querySelector('.um-role-label .searchable').textContent.trim();
            if (newRole === prevRole) return;

            var newIcon = opt.querySelector('i').className;
            var container = display.closest('.um-row') || display.closest('.um-card');
            var name = container ? container.querySelector('.um-name').firstChild.textContent.trim() : 'this user';

            pending = {
                display: display, dropdown: dropdown,
                userId: display.dataset.userId, newRole: newRole, newIcon: newIcon, prevRole: prevRole
            };
            desc.textContent = 'Change ' + name + '’s role from ' + prevRole + ' to ' + newRole + '?';
            backdrop.classList.add('active');
        });
    });

    function closeModal(){ backdrop.classList.remove('active'); pending = null; }

    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) closeModal(); });

    confirmBtn.addEventListener('click', async function(){
        if (!pending) return;
        confirmBtn.disabled = true; confirmBtn.textContent = 'Saving…';
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'change_role', user_id: pending.userId, role: pending.newRole })
            });
            const data = await res.json();
            if (data.success) {
                // Update every combobox for this user — desktop row AND mobile card both
                // render their own '.um-role-display', so both need the same refresh.
                document.querySelectorAll('.um-role-display[data-user-id="' + pending.userId + '"]').forEach(function(disp){
                    var labelIcon = disp.querySelector('.um-role-label i');
                    var labelText = disp.querySelector('.um-role-label .searchable');
                    if (labelIcon) labelIcon.className = pending.newIcon;
                    if (labelText) { labelText.textContent = pending.newRole; delete labelText.dataset.original; }

                    var container = disp.closest('.um-row') || disp.closest('.um-card');
                    if (container) container.dataset.role = pending.newRole;

                    var dd = disp.nextElementSibling;
                    if (dd) {
                        dd.querySelectorAll('.um-role-option').forEach(function(o){
                            o.classList.toggle('selected-opt', o.dataset.value === pending.newRole);
                        });
                    }
                });
            } else {
                alert(data.message || 'Failed to change role.');
            }
        } catch (e) {
            alert('Network error. Please try again.');
        }
        confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm';
        closeModal();
    });
})();

// ── Lock / unlock flow ──────────────────────────────────────────────────────
(function(){
    var backdrop = document.getElementById('lockConfirmBackdrop');
    var title = document.getElementById('lockConfirmTitle');
    var desc = document.getElementById('lockConfirmDesc');
    var confirmBtn = document.getElementById('lockConfirmBtn');
    var cancelBtn = document.getElementById('lockCancelBtn');
    var pending = null; // { btn, userId, lock }

    function wireButtons(){
        document.querySelectorAll('.btn-lock, .btn-unlock').forEach(function(btn){
            if (btn.dataset.wired) return;
            btn.dataset.wired = '1';
            btn.addEventListener('click', function(){
                var lock = btn.classList.contains('btn-lock');
                var name = btn.dataset.name || 'this user';
                pending = { btn: btn, userId: btn.dataset.userId, lock: lock };
                title.textContent = lock ? 'Lock Account?' : 'Unlock Account?';
                desc.textContent = lock
                    ? name + ' will be immediately signed out and unable to log back in until unlocked.'
                    : name + ' will be able to log in again.';
                backdrop.classList.add('active');
            });
        });
    }
    wireButtons();

    function closeModal(){ backdrop.classList.remove('active'); }
    cancelBtn.addEventListener('click', function(){ pending = null; closeModal(); });
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) { pending = null; closeModal(); } });

    confirmBtn.addEventListener('click', async function(){
        if (!pending) return;
        confirmBtn.disabled = true; confirmBtn.textContent = 'Saving…';
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_lock', user_id: pending.userId, lock: pending.lock })
            });
            const data = await res.json();
            if (data.success) {
                // Update BOTH the desktop row's button and the mobile card's button for this user.
                var targets = document.querySelectorAll(
                    '.btn-lock[data-user-id="' + pending.userId + '"], .btn-unlock[data-user-id="' + pending.userId + '"]'
                );
                targets.forEach(function(actionBtn){
                    var row = actionBtn.closest('.um-row') || actionBtn.closest('.um-card');
                    if (!row) return;
                    row.dataset.status = data.locked ? 'locked' : 'active';
                    var pill = row.querySelector('.status-pill');
                    if (pill) {
                        pill.className = 'status-pill ' + (data.locked ? 'status-locked' : 'status-active');
                        pill.innerHTML = '<i class="fas ' + (data.locked ? 'fa-lock' : 'fa-check-circle') + '"></i> ' + (data.locked ? 'Locked' : 'Active');
                    }
                    var actionsWrap = actionBtn.parentElement;
                    if (actionsWrap) {
                        var newBtn = document.createElement('button');
                        newBtn.className = 'btn-action ' + (data.locked ? 'btn-unlock' : 'btn-lock');
                        newBtn.dataset.userId = pending.userId;
                        newBtn.dataset.name = actionBtn.dataset.name;
                        newBtn.innerHTML = data.locked
                            ? '<i class="fas fa-unlock"></i> Unlock'
                            : '<i class="fas fa-lock"></i> Lock';
                        actionsWrap.replaceChild(newBtn, actionBtn);
                    }
                });
                wireButtons();
            } else {
                alert(data.message || 'Failed to update account.');
            }
        } catch (e) {
            alert('Network error. Please try again.');
        }
        confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm';
        pending = null;
        closeModal();
    });
})();

// ── Export to Excel (with confirmation) ──────────────────────────────────────
(function(){
    var btn = document.getElementById('umExportBtn');
    var backdrop = document.getElementById('exportConfirmBackdrop');
    var cancelBtn = document.getElementById('exportCancelBtn');
    var confirmBtn = document.getElementById('exportConfirmBtn');
    if (!btn || !backdrop) return;

    function closeModal(){ backdrop.classList.remove('active'); }

    btn.addEventListener('click', function(){ backdrop.classList.add('active'); });
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) closeModal(); });
    confirmBtn.addEventListener('click', function(){
        closeModal();
        window.location.href = window.location.pathname + '?export=xlsx';
    });
})();

// ── Add User modal ───────────────────────────────────────────────────────────
(function(){
    var openBtn = document.getElementById('umAddUserBtn');
    var backdrop = document.getElementById('addUserBackdrop');
    var closeBtn = document.getElementById('addUserCloseBtn');
    var cancelBtn = document.getElementById('addUserCancelBtn');
    var submitBtn = document.getElementById('addUserSubmitBtn');
    var errorBox = document.getElementById('auError');
    var fields = {
        firstName: document.getElementById('auFirstName'),
        lastName:  document.getElementById('auLastName'),
        email:     document.getElementById('auEmail'),
        role:      document.getElementById('auRole')
    };
    var roleDisplay = document.getElementById('auRoleDisplay');
    var roleLabel   = roleDisplay ? roleDisplay.querySelector('.searchable') : null;
    var roleIcon    = roleDisplay ? roleDisplay.querySelector('.um-role-label > i') : null;
    if (!openBtn || !backdrop) return;

    // Role selection is scoped separately from the per-row role-change combobox
    // (that one fires an AJAX "change_role" call — irrelevant for a brand-new user).
    document.querySelectorAll('#addUserBackdrop .um-role-option').forEach(function(opt){
        opt.addEventListener('click', function(e){
            e.stopPropagation();
            fields.role.value = opt.dataset.value;
            if (roleLabel) roleLabel.textContent = opt.dataset.value;
            if (roleIcon) roleIcon.className = opt.querySelector('i').className;
            document.querySelectorAll('#addUserBackdrop .um-role-option').forEach(function(o){
                o.classList.toggle('selected-opt', o === opt);
            });
            if (roleDisplay) roleDisplay.classList.remove('open');
            var dd = opt.closest('.um-role-dropdown');
            if (dd) dd.classList.remove('open');
        });
    });

    function resetForm(){
        fields.firstName.value = ''; fields.lastName.value = '';
        fields.email.value = ''; fields.role.value = '';
        if (roleLabel) roleLabel.textContent = '— Select Role —';
        if (roleIcon) roleIcon.className = 'fas fa-user-tag';
        document.querySelectorAll('#addUserBackdrop .um-role-option').forEach(function(o){ o.classList.remove('selected-opt'); });
        errorBox.style.display = 'none'; errorBox.textContent = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Invite';
    }
    function closeModal(){ backdrop.classList.remove('active'); }

    openBtn.addEventListener('click', function(){ resetForm(); backdrop.classList.add('active'); });
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) closeModal(); });

    // ── Invite confirmation step ──
    var inviteBackdrop = document.getElementById('inviteConfirmBackdrop');
    var inviteDesc = document.getElementById('inviteConfirmDesc');
    var inviteCancelBtn = document.getElementById('inviteCancelBtn');
    var inviteConfirmBtn = document.getElementById('inviteConfirmBtn');

    function closeInviteConfirm(){ inviteBackdrop.classList.remove('active'); }
    inviteCancelBtn.addEventListener('click', closeInviteConfirm);
    inviteBackdrop.addEventListener('mousedown', function(e){ if (e.target === inviteBackdrop) closeInviteConfirm(); });

    submitBtn.addEventListener('click', function(){
        var payload = {
            action: 'create_user',
            first_name: fields.firstName.value.trim(),
            last_name:  fields.lastName.value.trim(),
            email:      fields.email.value.trim(),
            role:       fields.role.value
        };
        if (!payload.first_name || !payload.last_name || !payload.email || !payload.role) {
            errorBox.textContent = 'All fields are required.';
            errorBox.style.display = 'block';
            return;
        }
        errorBox.style.display = 'none';
        inviteDesc.textContent = 'Send an invite to ' + payload.first_name + ' ' + payload.last_name + ' (' + payload.email + ') as ' + payload.role + '?';
        inviteConfirmBtn.dataset.pending = JSON.stringify(payload);
        inviteBackdrop.classList.add('active');
    });

    inviteConfirmBtn.addEventListener('click', async function(){
        var payload;
        try { payload = JSON.parse(inviteConfirmBtn.dataset.pending || '{}'); } catch(e) { payload = null; }
        if (!payload || !payload.email) { closeInviteConfirm(); return; }

        inviteConfirmBtn.disabled = true;
        inviteConfirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            closeInviteConfirm();
            if (data.success) {
                closeModal();
                alert(data.message);
            } else {
                errorBox.textContent = data.message || 'Failed to send invite.';
                errorBox.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Invite';
            }
        } catch (e) {
            closeInviteConfirm();
            errorBox.textContent = 'Network error. Please try again.';
            errorBox.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Invite';
        }
        inviteConfirmBtn.disabled = false;
        inviteConfirmBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Invite';
    });
})();

// ── View Profile modal ───────────────────────────────────────────────────────
(function(){
    var backdrop = document.getElementById('viewProfileBackdrop');
    var closeBtn = document.getElementById('viewProfileCloseBtn');
    var footerCloseBtn = document.getElementById('viewProfileFooterCloseBtn');
    var body = document.getElementById('viewProfileBody');
    var avatarWrap = document.getElementById('vpDetAvatarWrap');
    var nameEl = document.getElementById('vpDetName');
    var roleEl = document.getElementById('vpDetRole');
    if (!backdrop) return;

    function closeModal(){ backdrop.classList.remove('active'); }
    closeBtn.addEventListener('click', closeModal);
    footerCloseBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) closeModal(); });

    function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function fv(v){ return v ? esc(v) : '<span style="opacity:.5;">—</span>'; }

    function renderProfile(p){
        // Header: avatar (real photo, falling back to this page's own letter-avatar), name, role
        avatarWrap.innerHTML = p.picture
            ? '<img src="' + esc(p.picture) + '" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">' +
              '<div class="um-avatar-letter" style="display:none;background:' + esc(p.avatarColor) + ';">' + esc(p.initials) + '</div>'
            : '<div class="um-avatar-letter" style="background:' + esc(p.avatarColor) + ';">' + esc(p.initials) + '</div>';
        nameEl.textContent = p.name || '—';
        roleEl.textContent = p.role || '';

        var html = '<div class="vp-det-section-title"><i class="fas fa-info-circle"></i> Account</div><div class="vp-det-grid">'
            + '<div><div class="vp-det-field-label">Status</div><div class="vp-det-field-value">' + (p.locked ? 'Locked' : 'Active') + '</div></div>'
            + '<div><div class="vp-det-field-label">Verified</div><div class="vp-det-field-value">' + (p.verified ? 'Yes' : 'No') + '</div></div>'
            + '<div><div class="vp-det-field-label">Last Login</div><div class="vp-det-field-value">' + esc(p.lastLogin) + '</div></div>'
            + '</div>'
            + '<div class="vp-det-field-single"><div class="vp-det-field-label">Email Address</div><div class="vp-det-field-value">' + esc(p.email) + '</div></div>';

        if (p.engineer && p.role === 'Area Engineer') {
            // Area Engineer: no Personal Information / Professional Details / Skills — just their assigned district.
            var eAe = p.engineer;
            html += '<div class="vp-det-divider"></div><div class="vp-det-section-title"><i class="fas fa-map-marked-alt"></i> Assignment</div>'
                + '<div class="vp-det-district-card">'
                + '<div class="vp-det-district-icon"><i class="fas fa-map-marker-alt"></i></div>'
                + '<div><div class="vp-det-district-label">Assigned District</div><div class="vp-det-district-value">' + (eAe.district ? esc(eAe.district) : 'Not yet assigned') + '</div></div>'
                + '</div>';
        } else if (p.engineer && p.role === 'Engineer') {
            var e = p.engineer;
            html += '<div class="vp-det-divider"></div><div class="vp-det-section-title"><i class="fas fa-user"></i> Personal Information</div><div class="vp-det-grid">'
                + '<div><div class="vp-det-field-label">Full Name</div><div class="vp-det-field-value">' + fv(e.fullName) + '</div></div>'
                + '<div><div class="vp-det-field-label">Gender</div><div class="vp-det-field-value">' + fv(e.gender) + '</div></div>'
                + '<div><div class="vp-det-field-label">Date of Birth</div><div class="vp-det-field-value">' + fv(e.dateOfBirth) + '</div></div>'
                + '<div><div class="vp-det-field-label">Contact Number</div><div class="vp-det-field-value">' + fv(e.contactNumber) + '</div></div>'
                + '</div>'
                + '<div class="vp-det-field-single"><div class="vp-det-field-label">Address</div><div class="vp-det-field-value">' + fv(e.address) + '</div></div>';

            html += '<div class="vp-det-divider"></div><div class="vp-det-section-title"><i class="fas fa-hard-hat"></i> Professional Details</div><div class="vp-det-grid">'
                + '<div><div class="vp-det-field-label">Engineering Discipline</div><div class="vp-det-field-value">' + fv(e.discipline) + '</div></div>'
                + '<div><div class="vp-det-field-label">Department</div><div class="vp-det-field-value">' + fv(e.department) + '</div></div>'
                + '<div><div class="vp-det-field-label">Years of Experience</div><div class="vp-det-field-value">' + (e.yearsExperience != null && e.yearsExperience !== '' ? esc(e.yearsExperience) + ' yr(s)' : '<span style="opacity:.5;">—</span>') + '</div></div>'
                + '<div><div class="vp-det-field-label">District</div><div class="vp-det-field-value">' + fv(e.district) + '</div></div>'
                + '</div>';
            if (e.specialization) {
                html += '<div class="vp-det-field-single"><div class="vp-det-field-label">Areas of Specialization</div><div class="vp-det-field-value">' + fv(e.specialization) + '</div></div>';
            }

            html += '<div class="vp-det-divider"></div><div class="vp-det-section-title"><i class="fas fa-tools"></i> Skills &amp; Tools</div>';
            if (e.skills && e.skills.length) {
                html += '<div class="vp-det-skills">' + e.skills.map(function(s){ return '<span class="vp-det-skill-badge">' + esc(s) + '</span>'; }).join('') + '</div>';
            } else {
                html += '<div class="vp-det-field-value" style="opacity:.5;">No skills listed</div>';
            }
            if (e.cadSoftware) {
                html += '<div class="vp-det-field-single"><div class="vp-det-field-label">CAD Software</div><div class="vp-det-field-value">' + fv(e.cadSoftware) + '</div></div>';
            }

            html += '<div class="vp-det-divider"></div><div class="vp-det-section-title"><i class="fas fa-chart-line"></i> Performance Metrics</div>'
                + '<div id="vpEngMetrics"><div class="um-metrics-loading"><i class="fas fa-spinner fa-spin"></i> Loading metrics…</div></div>';
        }

        body.innerHTML = html;

        if (p.engineer && p.role === 'Engineer') {
            Promise.all([fetchEngineerMetrics(p.id), fetchEngineerRating(p.id)]).then(function(results){
                renderEngMetricsFull(results[0], 'vpEngMetrics', results[1]);
            });
        }
    }

    async function fetchEngineerMetrics(engineerId) {
        try {
            const res  = await fetch('../functionality/get_engineer_metrics.php?id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            return data.success ? data.metrics : null;
        } catch(e) { return null; }
    }

    async function fetchEngineerRating(engineerId) {
        try {
            const res  = await fetch('archive_reports.php?ajax=engineer_rating&id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            return data.success ? data : null;
        } catch(e) { return null; }
    }

    function renderEngMetricsFull(m, containerId, ratingData) {
        const el = document.getElementById(containerId);
        if (!el) return;
        if (!m) {
            el.innerHTML = '<div class="um-metrics-loading"><span style="font-size:16px;">⚠️</span> Could not load metrics.</div>';
            return;
        }

        const retCurrent = m.admin_returned_current ?? m.admin_rejected ?? 0;
        const retPending = m.admin_returned_pending ?? 0;

        function card(color, icon, value, title, subIcon, subText, subClass) {
            return '<div class="emc-card emc-' + color + '">'
                + '<div class="emc-header"><div class="emc-title">' + title + '</div><div class="emc-icon"><i class="' + icon + '"></i></div></div>'
                + '<div class="emc-value">' + value + '</div>'
                + '<div class="emc-sub ' + subClass + '"><span class="emc-sub-icon">' + subIcon + '</span><span>' + subText + '</span></div>'
                + '</div>';
        }

        const completedSub = m.completed > 0 ? 'positive' : 'neutral';
        const delayedSub   = m.delayed   > 0 ? 'danger'   : 'neutral';
        const declinedSub  = m.declined_count > 0 ? 'warning' : 'neutral';
        const retCurSub    = retCurrent > 0 ? 'warning' : 'neutral';
        const retPenSub    = retPending > 0 ? 'warning' : 'neutral';

        const avgRating   = ratingData ? (parseFloat(ratingData.avg_rating) || 0) : 0;
        const ratingCount = ratingData ? (ratingData.total || 0) : 0;
        const ratingSub   = avgRating >= 4 ? 'positive' : 'neutral';
        const ratingSubText = ratingCount > 0 ? ratingCount + ' valid feedback(s)' : 'No valid feedbacks yet';

        let ratingStarsHtml = '<div style="display:inline-flex;align-items:center;gap:1px;font-size:15px;line-height:1;margin:4px 0 2px;position:relative;z-index:1;">';
        for (let _i = 1; _i <= 5; _i++) {
            if (avgRating >= _i)
                ratingStarsHtml += '<span style="color:#f59e0b;">★</span>';
            else if (avgRating >= _i - 0.5)
                ratingStarsHtml += '<span style="position:relative;display:inline-block;"><span style="color:#d1d5db;">★</span><span style="position:absolute;top:0;left:0;width:50%;overflow:hidden;color:#f59e0b;white-space:nowrap;">★</span></span>';
            else
                ratingStarsHtml += '<span style="color:#d1d5db;">☆</span>';
        }
        ratingStarsHtml += '</div>';

        const ratingCard = '<div class="emc-card emc-amber">'
            + '<div class="emc-header"><div class="emc-title">Rating</div><div class="emc-icon"><i class="fas fa-star"></i></div></div>'
            + '<div class="emc-value">' + (avgRating > 0 ? avgRating.toFixed(1) + '<span style="font-size:14px;font-weight:500;letter-spacing:0"> / 5</span>' : '—') + '</div>'
            + ratingStarsHtml
            + '<div class="emc-sub ' + ratingSub + '"><span class="emc-sub-icon">★</span><span>' + ratingSubText + '</span></div>'
            + '</div>';

        el.innerHTML = '<div class="emc-grid-wrap">'
            + '<div class="emc-section-label">Report Activity</div>'
            + card('green',  'fas fa-check-circle', m.completed,        'Completed',       '↗', 'Finished reports',      completedSub)
            + card('orange', 'fas fa-spinner',      m.ongoing,          'Ongoing',         '●', 'Currently in progress', 'neutral')
            + card('red',    'fas fa-clock',        m.delayed,          'Delayed',         '↘', 'Past due date',         delayedSub)
            + card('indigo', 'fas fa-calendar-check', m.scheduled,      'Scheduled',       '▸', 'Pending reports queue', 'neutral')
            + card('teal',   'fas fa-clipboard-list', m.current_assigned, 'Curr. Assigned', '▸', 'In current reports',    'neutral')
            + card('blue',   'far fa-calendar-alt', m.pending_assigned, 'Pend. Assigned',  '▸', 'In pending reports',    'neutral')
            + '<div class="emc-section-label">Behaviour</div>'
            + card('amber',  'fas fa-times-circle', m.declined_count,   'Times Declined',  '↻', 'Engineer declined',     declinedSub)
            + card('purple', 'fas fa-undo-alt',     retCurrent,         'Returned (Approval)', '↩', 'Admin sent back to revise', retCurSub)
            + card('purple', 'fas fa-ban',          retPending,         'Returned (Not Done)', '↩', 'Admin marked incomplete',   retPenSub)
            + (m.pending_completion > 0 ? card('teal', 'fas fa-hourglass-half', m.pending_completion, 'Pend. Completion', '⏳', 'Awaiting admin review', 'neutral') : '')
            + ratingCard
            + '</div>';
    }

    document.querySelectorAll('.btn-view').forEach(function(btn){
        btn.addEventListener('click', async function(){
            var userId = btn.dataset.userId;
            avatarWrap.innerHTML = '';
            nameEl.textContent = ''; roleEl.textContent = '';
            body.innerHTML = '<div class="um-profile-loading"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
            backdrop.classList.add('active');
            try {
                const res = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_profile', user_id: userId })
                });
                const data = await res.json();
                if (data.success) {
                    renderProfile(data.profile);
                } else {
                    body.innerHTML = '<div class="um-profile-loading">' + esc(data.message || 'Failed to load profile.') + '</div>';
                }
            } catch (e) {
                body.innerHTML = '<div class="um-profile-loading">Network error. Please try again.</div>';
            }
        });
    });
})();

// ── Delete account ───────────────────────────────────────────────────────────
(function(){
    var backdrop = document.getElementById('deleteConfirmBackdrop');
    var desc = document.getElementById('deleteConfirmDesc');
    var confirmBtn = document.getElementById('deleteConfirmBtn');
    var cancelBtn = document.getElementById('deleteCancelBtn');
    var pending = null; // { userId, name }

    function closeModal(){ backdrop.classList.remove('active'); pending = null; }
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function(e){ if (e.target === backdrop) closeModal(); });

    document.querySelectorAll('.btn-delete').forEach(function(btn){
        if (btn.disabled) return;
        btn.addEventListener('click', function(){
            pending = { userId: btn.dataset.userId, name: btn.dataset.name || 'this user' };
            desc.textContent = 'Delete ' + pending.name + '’s account? This action is permanent and cannot be undone.';
            backdrop.classList.add('active');
        });
    });

    confirmBtn.addEventListener('click', async function(){
        if (!pending) return;
        confirmBtn.disabled = true; confirmBtn.textContent = 'Deleting…';
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_user', user_id: pending.userId })
            });
            const data = await res.json();
            if (data.success) {
                document.querySelectorAll('.um-row[data-user-id="' + pending.userId + '"]').forEach(function(el){ el.remove(); });
                document.querySelectorAll('#mobileUserList [data-user-id="' + pending.userId + '"]').forEach(function(btnEl){
                    var card = btnEl.closest('.um-card');
                    if (card) card.remove();
                });
                var countEl = document.getElementById('rowCount');
                if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent, 10) - 1);
            } else {
                alert(data.message || 'Failed to delete account.');
            }
        } catch (e) {
            alert('Network error. Please try again.');
        }
        confirmBtn.disabled = false; confirmBtn.textContent = 'Delete';
        closeModal();
    });
})();

// ── Live status refresh — recompute "Active X ago" client-side every 30s ─────
(function(){
    function relativeTime(seconds){
        if (seconds < 60) return 'just now';
        var mins = Math.floor(seconds / 60);
        if (mins < 60) return mins + ' minute' + (mins === 1 ? '' : 's') + ' ago';
        var hours = Math.floor(mins / 60);
        if (hours < 24) return hours + ' hour' + (hours === 1 ? '' : 's') + ' ago';
        var days = Math.floor(hours / 24);
        if (days < 30) return days + ' day' + (days === 1 ? '' : 's') + ' ago';
        var months = Math.floor(days / 30);
        if (months < 12) return months + ' month' + (months === 1 ? '' : 's') + ' ago';
        var years = Math.floor(months / 12);
        return years + ' year' + (years === 1 ? '' : 's') + ' ago';
    }

    function refresh(){
        var now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.um-live-status').forEach(function(el){
            if (el.dataset.locked === '1') return; // locked pills don't change
            var ts = parseInt(el.dataset.lastActivity, 10);
            var textEl = el.querySelector('.um-status-text');
            var iconEl = el.querySelector('i');
            if (!textEl) return;
            if (!ts) {
                textEl.textContent = 'Never active';
                el.classList.remove('status-active', 'status-online');
                el.classList.add('status-neutral');
                if (iconEl) iconEl.className = 'fas fa-circle-minus';
                return;
            }
            el.classList.remove('status-neutral');
            el.classList.add('status-active');
            if (iconEl) iconEl.className = 'fas fa-check-circle';
            var diff = now - ts;
            if (diff <= 120) {
                textEl.textContent = 'Active now';
                el.classList.add('status-online');
            } else {
                textEl.textContent = 'Active ' + relativeTime(diff);
                el.classList.remove('status-online');
            }
        });
    }

    setInterval(refresh, 30000);
})();
</script>

</body>
</html>
