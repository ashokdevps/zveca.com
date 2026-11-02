<?php

require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Database connection
$conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
if (!$conn) {
    die("Database connection failed");
}

// Create a new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Define column headers
$headers = ['ID', 'Candidate Name', 'Contact No', 'Email', 'Work Exp', 'Expert Key Skills', 'Annual Salary', 'Current Location', 'Preferred Location', 'Current Employer', 'Designation', 'Education', 'Comment'];
$sheet->fromArray([$headers], NULL, 'A1'); // Write headers to the sheet starting at A1

// Fetch data from the database
$query = "SELECT * FROM hr_sales";
$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) > 0) {
    // Collect all data into an array
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = [
            $row['id'],
            $row['candidate_name'],
            $row['contact_no'],
            $row['email'],
            $row['work_exp'],
            $row['expert_key_skills'],
            $row['annual_salary'],
            $row['current_location'],
            $row['preferred_location'],
            $row['current_employer'],
            $row['designation'],
            $row['education'],
            $row['comment_1']
        ];
    }
    
    // Write data to the spreadsheet, starting on the next row (A2)
    $sheet->fromArray($data, NULL, 'A2');
}

// Create the writer object and set headers for download
$writer = new Xlsx($spreadsheet);
$filename = 'hr_sales.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Save the file to php://output
$writer->save('php://output');

// Clean up and exit
mysqli_close($conn);
exit();
?>