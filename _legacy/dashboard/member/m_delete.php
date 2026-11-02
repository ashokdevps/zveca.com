<?php
$id = $_GET['d'];

$conn = mysqli_connect("localhost", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
if ($conn == true) {
	$cmd = "delete from it_members where id='$id'";
	$x = mysqli_query($conn, $cmd);
	if ($x > 0) {
		echo "<script>alert('Record Deleted');window.location.href='list.php';</script>";
	} else{
		echo "<script>alert('Record not Deleted');window.location.href='list.php';</script>";
	}
} else{
	mysqli_error($conn);
}

?>