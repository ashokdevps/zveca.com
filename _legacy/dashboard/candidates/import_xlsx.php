<?php
// Ensure you have the PhpSpreadsheet library installed via Composer.
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check if the form was submitted and a file was uploaded
if (isset($_POST['import_submit']) && isset($_FILES['excel_file'])) {
    
    // Database connection
    $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
    if (!$conn) {
        die("Database connection failed: " . mysqli_connect_error());
    }

    // Get the file details
    $file_info = pathinfo($_FILES['excel_file']['name']);
    $file_extension = strtolower($file_info['extension']);
    
    // Check if the file is an XLSX file
    if ($file_extension == 'xlsx') {
        $inputFileName = $_FILES['excel_file']['tmp_name'];

        try {
            // Load the spreadsheet from the uploaded file
            $spreadsheet = IOFactory::load($inputFileName);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            // Loop through each row of the spreadsheet
            // We start at row 2 to skip the header row
            for ($row = 2; $row <= $highestRow; $row++) {
                
                // Get the data from each cell in the row
                // This assumes your Excel columns are in the same order as your database:
                // A -> id, B -> candidate_name, etc.
                $candidate_name = $sheet->getCell('B' . $row)->getValue();
                $contact_no = $sheet->getCell('C' . $row)->getValue();
                $email = $sheet->getCell('D' . $row)->getValue();
                $work_exp = $sheet->getCell('E' . $row)->getValue();
                $expert_key_skills = $sheet->getCell('F' . $row)->getValue();
                $annual_salary = $sheet->getCell('G' . $row)->getValue();
                $current_location = $sheet->getCell('H' . $row)->getValue();
                $preferred_location = $sheet->getCell('I' . $row)->getValue();
                $current_employer = $sheet->getCell('J' . $row)->getValue();
                $designation = $sheet->getCell('K' . $row)->getValue();
                $education = $sheet->getCell('L' . $row)->getValue();
                $comment_1 = $sheet->getCell('M' . $row)->getValue();
                $resume = $sheet->getCell('N' . $row)->getValue();

                // Prepare the SQL INSERT statement
                $sql = "INSERT INTO candidates (candidate_name, email, contact_no, work_exp, expert_key_skills, annual_salary, current_location, preferred_location, current_employer, designation, education, comment_1, resume) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                // Use a prepared statement to prevent SQL injection
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "sssssssssssss", $candidate_name, $email, $contact_no, $work_exp, $expert_key_skills, $annual_salary, $current_location, $preferred_location, $current_employer, $designation, $education, $comment_1, $resume);
                
                // Execute the statement
                mysqli_stmt_execute($stmt);
            }

            mysqli_close($conn);

            // Redirect back to the patients list page with a success message
            header('Location: list.php?status=success');
            exit();

        } catch (Exception $e) {
            // Handle any errors that occur during the file processing
            header('Location: list.php?status=error&message=' . urlencode($e->getMessage()));
            exit();
        }

    } else {
        // Handle incorrect file type
        header('Location: list.php?status=error&message=' . urlencode('Please upload a valid XLSX file.'));
        exit();
    }
} else {
    // Handle no file uploaded or form not submitted
    header('Location: list.php?status=error&message=' . urlencode('No file uploaded or form submission error.'));
    exit();
}
?>