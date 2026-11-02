<?php include 'includes/header.php'; ?>
<div class="app-container">

  <!-- App hero header starts -->
  <div class="app-hero-header d-flex align-items-center">
    <ol class="breadcrumb">
      <li class="breadcrumb-item">
        <i class="ri-home-8-line lh-1 pe-3 me-3 border-end"></i>
        <a href="index.php">Home</a>
      </li>
      <li class="breadcrumb-item">
        <a href="members-list.php">Salarys List</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Salary Profile
      </li>
    </ol>
  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <?php
    $id = $_GET['s'] ?? null;
    $conn = mysqli_connect("localhost", "root", "", "login_register");

    if ($conn && $id) {
        $cmd = "SELECT * FROM it_salaries WHERE id='$id'";
        $result = mysqli_query($conn, $cmd);

        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
    ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Emp Profile - <?php echo $row['emp_name']; ?></h5>
        <a href="salary-list.php" class="btn btn-secondary">Back to List</a>
      </div>
      <div class="card-body">

        <div class="row gx-4 gy-3">
          <div class="col-md-6">
            <p><strong>Emp Name:</strong> <?php echo $row['emp_name']; ?></p>
            <p><strong>Position:</strong> <?php echo $row['position']; ?></p>
            <p><strong>Location:</strong> <?php echo $row['location']; ?></p>
            <p><strong>Working Days:</strong> <?php echo $row['working_days']; ?></p>
            <p><strong>Month:</strong> <?php echo $row['month']; ?></p>
            <p><strong>Salary:</strong> <?php echo $row['salary']; ?></p>
          </div>
          <div class="col-md-6">
            <img src="assets/images/error-bg.jpg" width="502px">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <a href="s_edit.php?u=<?php echo $row['id']; ?>" class="btn btn-success">
            <i class="ri-edit-box-line"></i> Edit
          </a>
          <a href="s_delete.php?d=<?php echo $row['id']; ?>" class="btn btn-danger" 
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
    <span>© Adorableit admin 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include 'includes/footer.php'; ?>
