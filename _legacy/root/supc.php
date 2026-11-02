<?php
$name = $_POST['emp_name'];
$position = $_POST['position'];
$location = $_POST['location'];
$working_days = $_POST['working_days'];
$month = $_POST['month'];
$salary = $_POST['salary'];
$id = $_POST['id'];

$conn = mysqli_connect("localhost", "root", "", "login_register");
if ($conn == true) {
	$cmd = "UPDATE it_salaries SET emp_name='$name', position='$position', location='$location', working_days='$working_days', month='$month', salary='$salary' where id='$id'";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Updated');window.location.href='salary-list.php';</script>";
	} else{
		echo "<script>alert('Record not Updated');window.location.href='salary-list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>