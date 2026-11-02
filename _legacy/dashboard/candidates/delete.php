<?php
$id = $_GET['d'];

$conn = mysqli_connect("localhost", "root", "", "login_register");
if ($conn == true) {
	$cmd = "delete from hr_sales where id='$id'";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Deleted');window.location.href='patients-list.php';</script>";
	} else{
		echo "<script>alert('Record not Deleted');window.location.href='patients-list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>