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

// Only office staff and manager can fetch this list
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$allowed  = ['office staff', 'manager'];
if (!in_array($userRole, $allowed)) {
    jsonOut(false, 'Permission denied.');
}

require __DIR__ . '/db.php';

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
        ep.cad_software
     FROM employees e
     INNER JOIN engineer_profiles ep ON ep.user_id = e.user_id
     WHERE e.role = 'Engineer'
     ORDER BY e.first_name ASC, e.last_name ASC"
);

if (!$stmt) {
    jsonOut(false, 'DB prepare error: ' . $conn->error);
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
    ];
}

$stmt->close();

if (empty($engineers)) {
    jsonOut(false, 'No engineers found.', ['engineers' => []]);
}

jsonOut(true, 'OK', ['engineers' => $engineers]);