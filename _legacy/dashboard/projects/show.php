<?php include '../includes/header.php'; ?>
<div class="app-container">

  <!-- App hero header starts -->
  <div class="app-hero-header d-flex align-items-center">
    <ol class="breadcrumb">
      <li class="breadcrumb-item">
        <i class="ri-home-8-line lh-1 pe-3 me-3 border-end"></i>
        <a href="index.php">Home</a>
      </li>
      <li class="breadcrumb-item">
        <a href="list.php">Projects List</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Project Profile
      </li>
    </ol>
  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <?php
    $id = $_GET['s'] ?? null;
    $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");

    if ($conn && $id) {
        $cmd = "SELECT * FROM it_projects WHERE id='$id'";
        $result = mysqli_query($conn, $cmd);

        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
    ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Project Profile - <?php echo $row['project_name']; ?></h5>
        <a href="list.php" class="btn btn-secondary">Back to List</a>
      </div>
      <div class="card-body">

        <div class="row gx-4 gy-3">
          <div class="col-md-6">
            <p><strong>Project Name:</strong> <?php echo $row['project_name']; ?></p>
            <p><strong>Client Name</strong> <?php echo $row['client_name']; ?></p>
            <p><strong>Dead Line:</strong> <?php echo $row['deadline']; ?></p>
            <p><strong>Team Members:</strong> <?php echo $row['team_members']; ?></p>
            <p><strong>Status:</strong> <?php echo $row['status']; ?></p>
          </div>
          <div class="col-md-6">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <a href="edit.php?u=<?php echo $row['id']; ?>" class="btn btn-success">
            <i class="ri-edit-box-line"></i> Edit
          </a>
          <a href="delete.php?d=<?php echo $row['id']; ?>" class="btn btn-danger" 
             onclick="return confirm('Are you sure you want to delete this record?');">
            <i class="ri-delete-bin-line"></i> Delete
          </a>
        </div>

      </div>
    </div>

    <?php
        } else {
            echo "<div class='alert alert-warning'>No record found!</div>";
        }
    } else {
        echo "<div class='alert alert-danger'>Invalid request or DB connection failed.</div>";
    }
    ?>

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© Adorableit 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include '../includes/footer.php'; ?>
