<?php
$name = $_POST['member_name'];
$designation = $_POST['designation'];
$contact = $_POST['contact'];
$email = $_POST['email'];
$dob = $_POST['dob'];
$id = $_POST['id'];

$conn = mysqli_connect("localhost", "root", "", "login_register");
if ($conn == true) {
	$cmd = "UPDATE it_members SET member_name='$name', designation='$designation', contact='$contact', email='$email', dob='$dob' where id='$id'";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Updated');window.location.href='members-list.php';</script>";
	} else{
		echo "<script>alert('Record not Updated');window.location.href='members-list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>