<?php
$name = $_POST['project_name'];
$client_name = $_POST['client_name'];
$deadline = $_POST['deadline'];
$team_members = $_POST['team_members'];
$status = $_POST['status'];


$conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");

if ($conn == true) {
	$cmd = "insert into it_projects(project_name, client_name, team_members, deadline, status) values('$name', '$client_name', '$team_members', '$deadline', '$status')";
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