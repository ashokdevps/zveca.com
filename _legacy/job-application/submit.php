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

$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$work_exp = trim($_POST['work_exp'] ?? '');
$notes    = trim($_POST['notes'] ?? '');

if ($name === '' || $email === '' || $phone === '') {
    die("Please fill all required fields.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Please enter a valid email address.");
}

if (
    !isset($_FILES['resume']) ||
    $_FILES['resume']['error'] !== UPLOAD_ERR_OK
) {
    die("Please upload your resume.");
}

$file = $_FILES['resume'];

$allowed_extensions = ['pdf', 'doc', 'docx'];

$extension = strtolower(
    pathinfo($file['name'], PATHINFO_EXTENSION)
);

if (!in_array($extension, $allowed_extensions, true)) {
    die("Only PDF, DOC and DOCX files are allowed.");
}

if ($file['size'] > 5 * 1024 * 1024) {
    die("Resume file size must be less than 5 MB.");
}

$application_folder = __DIR__ . '/job-applications/';

if (!is_dir($application_folder)) {
    mkdir($application_folder, 0755, true);
}

$filename = basename($file['name']);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

$destination = $application_folder . $filename;

if (file_exists($destination)) {
    $filename = time() . '_' . $filename;
    $destination = $application_folder . $filename;
}

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    die("Resume upload failed.");
}

$resume_url = 'https://hrms.adorableit.com/apply-now/job-applications/' . $filename;

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO hrm_candidates
    (name, email, phone, work_exp, resume, notes)
    VALUES (?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    die("Database query preparation failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "ssssss",
    $name,
    $email,
    $phone,
    $work_exp,
    $resume_url,
    $notes
);

if (mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    header("Location: https://hrms.adorableit.com/find-jobs.php");
    exit;

}

die("Database insert failed: " . mysqli_stmt_error($stmt));