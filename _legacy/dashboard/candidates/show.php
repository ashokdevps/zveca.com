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
        <a href="list.php">Candidates List</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Candidate Profile
      </li>
    </ol>
  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <?php
    $id = $_GET['s'] ?? null;
    $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrms");

    if ($conn && $id) {
        $cmd = "SELECT * FROM hrm_candidates WHERE id='$id'";
        $result = mysqli_query($conn, $cmd);

        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
    ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Candidate Profile - <?php echo $row['candidate_name']; ?></h5>
        <a href="list.php" class="btn btn-secondary">Back to List</a>
      </div>
      <div class="card-body">

        <div class="row gx-4 gy-3">
          <div class="col-md-2">
            <img src="../assets/images/user6.png" class="img-shadow img-3x me-3 rounded-5" alt="">
            <p><strong><?php echo $row['candidate_name']; ?></strong></p>
            <p><strong><?php echo $row['designation']; ?></strong></p>
          </div>
          <div class="col-md-5">
            <p><strong>Full Name:</strong> <?php echo $row['candidate_name']; ?></p>
            <p><strong>Mobile:</strong> <?php echo $row['contact_no']; ?></p>
            <p><strong>Email:</strong> <?php echo $row['email']; ?></p>
            <p><strong>Work Exp:</strong> <?php echo $row['work_exp']; ?></p>
            <p><strong>Expert Key Skills:</strong> <?php echo $row['expert_key_skills']; ?></p>
            <p><strong>Annual Salary:</strong> <?php echo $row['annual_salary']; ?></p>
          </div>
          <div class="col-md-5">
            <p><strong>Current Location:</strong> <?php echo $row['current_location']; ?></p>
            <p><strong>Preferred Location:</strong> <?php echo $row['preferred_location']; ?></p>
            <p><strong>Current Employer:</strong> <?php echo $row['current_employer']; ?></p>
            <p><strong>Designation:</strong> <?php echo $row['designation']; ?></p>
            <p><strong>Education:</strong> <?php echo $row['education']; ?></p>
            <p><strong>Comment:</strong> <?php echo $row['comment_1']; ?></p>
            <p><strong>Resume:</strong>
            <?php if(!empty($row['resume'])){ ?>
    
              <a href="<?php echo $row['resume']; ?>" 
                 target="_blank" 
                 class="btn btn-sm btn-primary">
                  <i class="ri-eye-line"></i> View
              </a>

              <a href="<?php echo $row['resume']; ?>" 
                 download 
                 class="btn btn-sm btn-success ms-2">
                  <i class="ri-download-2-line"></i> Download
              </a>

            <?php } else { ?>
                Not Uploaded
            <?php } ?>
            </p>
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
    <span>© Medflex admin 2024</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include '../includes/footer.php'; ?>
