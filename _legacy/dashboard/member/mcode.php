<?php
$name = $_POST['member_name'];
$designation = $_POST['designation'];
$contact = $_POST['contact'];
$email = $_POST['email'];
$dob = $_POST['dob'];

$conn = mysqli_connect("localhost", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
if ($conn == true) {
	$cmd = "insert into it_members(member_name, designation, contact, email, dob) values('$name', '$designation', '$contact', '$email', '$dob')";
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