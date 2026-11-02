<?php
$name = $_POST['emp_name'];
$position = $_POST['position'];
$location = $_POST['location'];
$working_days = $_POST['working_days'];
$month = $_POST['month'];
$salary = $_POST['salary'];

$conn = mysqli_connect("localhost", "root", "", "login_register");
if ($conn == true) {
	$cmd = "insert into it_salaries(emp_name, position, location, working_days, month, salary) values('$name', '$position', '$location', '$working_days', '$month', '$salary')";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Saved Successfully');window.location.href='salary-list.php';</script>";
	} else{
		echo "<script>alert('Record not Saved');window.location.href='salary-list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>