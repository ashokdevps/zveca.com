<?php

$conn = mysqli_connect(
    "localhost",
    "u918639175_hrms",
    "Hrms@143_#5798$!!",
    "u918639175_hrms"
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request.");
}


// =============================
// Get Form Data
// =============================

$name               = trim($_POST['name'] ?? '');
$email              = trim($_POST['email'] ?? '');
$phone              = trim($_POST['phone'] ?? '');
$work_exp           = trim($_POST['work_exp'] ?? '');
$key_skills         = trim($_POST['key_skills'] ?? '');
$current_ctc        = trim($_POST['current_ctc'] ?? '');
$expected_ctc       = trim($_POST['expected_ctc'] ?? '');
$current_location   = trim($_POST['current_location'] ?? '');
$preferred_location = trim($_POST['preferred_location'] ?? '');
$current_employer   = trim($_POST['current_employer'] ?? '');
$designation        = trim($_POST['designation'] ?? '');
$education          = trim($_POST['education'] ?? '');
$notice_period      = trim($_POST['notice_period'] ?? '');
$notes              = trim($_POST['notes'] ?? '');


// =============================
// Required Fields
// =============================

if ($name === '' || $email === '' || $phone === '') {
    die("Please fill all required fields.");
}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Please enter a valid email address.");
}


// =============================
// Resume Upload
// =============================

if (
    !isset($_FILES['resume']) ||
    $_FILES['resume']['error'] !== UPLOAD_ERR_OK
) {
    die("Please upload your resume.");
}


$file = $_FILES['resume'];


// Allowed file types
$allowed_extensions = [
    'pdf',
    'doc',
    'docx'
];


$extension = strtolower(
    pathinfo($file['name'], PATHINFO_EXTENSION)
);


if (!in_array($extension, $allowed_extensions, true)) {
    die("Only PDF, DOC and DOCX files are allowed.");
}


// Maximum 5 MB
if ($file['size'] > 5 * 1024 * 1024) {
    die("Resume file size must be less than 5 MB.");
}


// =============================
// Resume Upload Folder
// =============================

$application_folder = __DIR__ . '/job-applications/';


if (!is_dir($application_folder)) {
    mkdir($application_folder, 0755, true);
}


// =============================
// Create Unique File Name
// =============================

$filename = basename($file['name']);

$filename = preg_replace(
    '/[^A-Za-z0-9._-]/',
    '_',
    $filename
);


// Add unique prefix
$filename = time() . '_' . $filename;

$destination = $application_folder . $filename;


// =============================
// Move Uploaded File
// =============================

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    die("Resume upload failed.");
}


// Resume URL
$resume_url =
    'https://hrms.adorableit.com/job-application/job-applications/'
    . $filename;


// =============================
// Insert into Database
// =============================

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO hrm_candidates
    (
        name,
        email,
        phone,
        work_exp,
        key_skills,
        current_ctc,
        expected_ctc,
        current_location,
        preferred_location,
        current_employer,
        designation,
        education,
        notice_period,
        resume,
        notes
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);


if (!$stmt) {
    die(
        "Database query preparation failed: "
        . mysqli_error($conn)
    );
}


// =============================
// Bind Parameters
// =============================

mysqli_stmt_bind_param(
    $stmt,
    "sssssssssssssss",
    $name,
    $email,
    $phone,
    $work_exp,
    $key_skills,
    $current_ctc,
    $expected_ctc,
    $current_location,
    $preferred_location,
    $current_employer,
    $designation,
    $education,
    $notice_period,
    $resume_url,
    $notes
);


// =============================
// Execute
// =============================

if (mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    header(
        "Location: https://hrms.adorableit.com/find-jobs.php"
    );

    exit;
}


$error = mysqli_stmt_error($stmt);

mysqli_stmt_close($stmt);
mysqli_close($conn);

die("Database insert failed: " . $error);