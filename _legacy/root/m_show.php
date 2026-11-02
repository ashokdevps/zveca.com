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
        <a href="members-list.php">Members List</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Member Profile
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
        $cmd = "SELECT * FROM it_members WHERE id='$id'";
        $result = mysqli_query($conn, $cmd);

        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
    ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Member Profile - <?php echo $row['member_name']; ?></h5>
        <a href="members-list.php" class="btn btn-secondary">Back to List</a>
      </div>
      <div class="card-body">

        <div class="row gx-4 gy-3">
          <div class="col-md-6">
            <p><strong>Full Name:</strong> <?php echo $row['member_name']; ?></p>
            <p><strong>Designation:</strong> <?php echo $row['designation']; ?></p>
            <p><strong>Mobile:</strong> <?php echo $row['contact']; ?></p>
            <p><strong>Email:</strong> <?php echo $row['email']; ?></p>
            <p><strong>DOB:</strong> <?php echo $row['dob']; ?></p>
          </div>
          <div class="col-md-6">
            <img src="assets/images/error-bg.jpg" width="502px">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <a href="m_edit.php?u=<?php echo $row['id']; ?>" class="btn btn-success">
            <i class="ri-edit-box-line"></i> Edit
          </a>
          <a href="m_delete.php?d=<?php echo $row['id']; ?>" class="btn btn-danger" 
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
    <span>© Medflex admin 2024</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include 'includes/footer.php'; ?>
