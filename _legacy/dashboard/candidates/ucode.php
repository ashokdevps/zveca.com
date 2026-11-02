<?php
$name = $_POST['candidate_name'];
$email = $_POST['email'];
$contact = $_POST['contact_no'];
$work_exp = $_POST['work_exp'];
$expert_key_skills = $_POST['expert_key_skills'];
$annual_salary = $_POST['annual_salary'];
$current_location = $_POST['current_location'];
$preferred_location = $_POST['preferred_location'];
$current_employer = $_POST['current_employer'];
$designation = $_POST['designation'];
$education = $_POST['education'];
$comment_1 = $_POST['comment_1'];

$resume_name = "";

if(isset($_FILES['resume']) && $_FILES['resume']['error'] == 0){

    $fileName = $_FILES['resume']['name'];
    $tmpName  = $_FILES['resume']['tmp_name'];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if($ext != "pdf"){
        echo "<script>alert('Only PDF resume allowed');window.history.back();</script>";
        exit;
    }

    $resume_name = time()."_".$fileName;

    $uploadPath = "uploads/resume/".$resume_name;

    move_uploaded_file($tmpName, $uploadPath);
}


$conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");

if ($conn == true) {
	$cmd = "insert into candidates(candidate_name, email, contact_no, work_exp, expert_key_skills, annual_salary, current_location, preferred_location, current_employer, designation, education, comment_1, resume) values('$name', '$email', '$contact', '$work_exp', '$expert_key_skills', '$annual_salary', '$current_location', '$preferred_location', '$current_employer', '$designation', '$education', '$comment_1', '$resume_name')";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Saved Successfully');window.location.href='list.php';</script>";
	} else{
		echo "<script>alert('Record not Saved');window.location.href='list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>