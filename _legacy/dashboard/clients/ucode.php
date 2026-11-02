<?php
$name = $_POST['name'];
$employee_id = $_POST['employee_id'];
$department = $_POST['department'];
$check_in = $_POST['check_in'];
$shift = $_POST['shift'];
$status = $_POST['status'];


$conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");

if ($conn == true) {
	$cmd = "insert into clients(name, employee_id, check_in, shift, department, status) values('$name', '$employee_id', '$check_in', '$shift', '$department', '$status')";
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