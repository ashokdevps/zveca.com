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
$id = $_POST['id'];

$resume_sql = "";

if(isset($_FILES['resume']) && $_FILES['resume']['error'] == 0){

    $fileName = $_FILES['resume']['name'];
    $tmpName  = $_FILES['resume']['tmp_name'];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if($ext != "pdf"){
        echo "<script>alert('Only PDF resume allowed');window.history.back();</script>";
        exit;
    }

    $new_resume = time()."_".$fileName;
    $uploadPath = "uploads/resume/".$new_resume;

    move_uploaded_file($tmpName, $uploadPath);

    $resume_sql = ", resume='$new_resume'";
}

$conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
if ($conn == true) {
	$cmd = "UPDATE candidates SET candidate_name='$name', email='$email', contact_no='$contact', work_exp='$work_exp', expert_key_skills='$expert_key_skills', annual_salary='$annual_salary', current_location='$current_location', preferred_location='$preferred_location', current_employer='$current_employer', designation='$designation', education='$education', comment_1='$comment_1' $resume_sql where id='$id'";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Updated');window.location.href='list.php';</script>";
	} else{
		echo "<script>alert('Record not Updated');window.location.href='list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>