<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

function jsonOut(bool $ok, string $msg, array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// Must be logged in
if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    jsonOut(false, 'Unauthorized');
}

// Office staff, manager, and admins can fetch the full list.
// Engineers may only fetch their own profile (must pass ?id= matching their session).
$userRole   = strtolower(trim($_SESSION['employee_role'] ?? ''));
$sessionId  = (int)($_SESSION['employee_id'] ?? 0);
$isEngineer = $userRole === 'engineer';
$allowed    = ['office staff', 'manager', 'admin', 'super admin'];

$singleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($isEngineer) {
    // Engineers can only request their own record, and must specify their own ID
    if ($singleId <= 0 || $singleId !== $sessionId) {
        jsonOut(false, 'Permission denied. Engineers may only view their own profile.');
    }
} elseif (!in_array($userRole, $allowed)) {
    jsonOut(false, 'Permission denied.');
}

require __DIR__ . '/db.php';

// Optional single-engineer lookup
$idClause = $singleId > 0 ? ' AND e.user_id = ?' : '';

// INNER JOIN with engineer_profiles — engineers without a profile record are excluded entirely
$stmt = $conn->prepare(
    "SELECT
        e.user_id         AS id,
        CONCAT(e.first_name, ' ', e.last_name) AS name,
        e.email,
        e.profile_picture,
        ep.full_name,
        ep.gender,
        ep.date_of_birth,
        ep.address,
        ep.contact_number,
        ep.engineering_discipline,
        ep.department,
        ep.years_of_experience,
        ep.areas_of_specialization,
        ep.skill_structural_design,
        ep.skill_site_inspection,
        ep.skill_project_planning,
        ep.cad_software,
        ep.district
     FROM employees e
     INNER JOIN engineer_profiles ep ON ep.user_id = e.user_id
     WHERE e.role = 'Engineer'{$idClause}
     ORDER BY e.first_name ASC, e.last_name ASC"
);

if (!$stmt) {
    jsonOut(false, 'DB prepare error: ' . $conn->error);
}

if ($singleId > 0) {
    $stmt->bind_param('i', $singleId);
}

$stmt->execute();
$result    = $stmt->get_result();
$engineers = [];

while ($row = $result->fetch_assoc()) {
    // Resolve profile picture path; fall back to default avatar
    $picPath = $row['profile_picture'] ?? null;
    if (!$picPath || !file_exists(__DIR__ . '/' . $picPath)) {
        $picPath = 'profile.png';
    }

    $engineers[] = [
        'id'                     => (int)$row['id'],
        'name'                   => $row['name'],
        'profile_picture'        => $picPath,
        // Profile detail fields
        'full_name'              => $row['full_name']              ?? '',
        'gender'                 => $row['gender']                 ?? '',
        'date_of_birth'          => $row['date_of_birth']          ?? '',
        'address'                => $row['address']                ?? '',
        'contact_number'         => $row['contact_number']         ?? '',
        'engineering_discipline' => $row['engineering_discipline'] ?? '',
        'department'             => $row['department']             ?? '',
        'years_of_experience'    => $row['years_of_experience'] !== null ? (int)$row['years_of_experience'] : null,
        'areas_of_specialization'=> $row['areas_of_specialization'] ?? '',
        'skill_structural_design'=> (bool)$row['skill_structural_design'],
        'skill_site_inspection'  => (bool)$row['skill_site_inspection'],
        'skill_project_planning' => (bool)$row['skill_project_planning'],
        'cad_software'           => $row['cad_software']           ?? '',
        'email'                  => $row['email']                  ?? '',
        'district'               => $row['district']               ?? '',
    ];
}

$stmt->close();

if (empty($engineers)) {
    jsonOut(false, 'No engineers found.', ['engineers' => []]);
}

jsonOut(true, 'OK', ['engineers' => $engineers]);