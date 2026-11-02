<?php include '../includes/header.php'; ?>
<div class="app-container">

  <!-- App hero header starts -->
  <div class="app-hero-header d-flex align-items-center">

    <!-- Breadcrumb starts -->
    <ol class="breadcrumb">
      <li class="breadcrumb-item">
        <i class="ri-home-8-line lh-1 pe-3 me-3 border-end"></i>
        <a href="index.html">Home</a>
      </li>
      <li class="breadcrumb-item text-primary" aria-current="page">
        Update Candidate
      </li>
    </ol>
    <!-- Breadcrumb ends -->

    <!-- Sales stats starts -->
    <div class="ms-auto d-lg-flex d-none flex-row">
      <div class="d-flex flex-row gap-1 day-sorting">
        <button class="btn btn-sm btn-primary">Today</button>
        <button class="btn btn-sm">7d</button>
        <button class="btn btn-sm">2w</button>
        <button class="btn btn-sm">1m</button>
        <button class="btn btn-sm">3m</button>
        <button class="btn btn-sm">6m</button>
        <button class="btn btn-sm">1y</button>
      </div>
    </div>
    <!-- Sales stats ends -->

  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <!-- Row starts -->
    <div class="row gx-3">
      <div class="col-sm-12">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">Update Candidate Details</h5>
          </div>
          <div class="card-body">
          <?php
          $id = $_GET['u'];
          $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
          if ($conn == true) {
            $cmd = "select * from candidates where id='$id'";
            $r = mysqli_query($conn, $cmd);
            if (mysqli_num_rows($r)>0) {
              while ($rs = mysqli_fetch_assoc($r)) { 
                ?>
            <form action="update.php" method="POST" enctype="multipart/form-data">
            <!-- Row starts -->
            <div class="row gx-3">
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a1">Full Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['candidate_name']; ?>" name="candidate_name" id="a1" placeholder="Enter Name">
                  <input type="hidden" value="<?php echo $rs['id'] ?>" name="id">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a2">Mobile Number <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['contact_no']; ?>" name="contact_no" id="a2" placeholder="Enter Mobile Number">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a3">Email <span class="text-danger">*</span></label>
                  <input type="email" class="form-control" value="<?php echo $rs['email']; ?>" name="email" id="a3" placeholder="Enter Email">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a4">Work Exp <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['work_exp']; ?>" name="work_exp" id="a4" placeholder="Create Work Exp">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a5">Expert Key Skills </label>
                  <input type="text" class="form-control" value="<?php echo $rs['expert_key_skills']; ?>" name="expert_key_skills" id="a5" placeholder="Enter Expert Key Skills">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a6">Annual Salary <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" value="<?php echo $rs['annual_salary']; ?>" name="annual_salary" id="a6" placeholder="Enter Annual Salary">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a7">Current Location</label>
                  <input type="text" class="form-control" value="<?php echo $rs['current_location']; ?>" name="current_location" id="a7" placeholder="Enter Current Location">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a8">Preferred Location</label>
                  <input type="text" class="form-control" value="<?php echo $rs['preferred_location']; ?>" name="preferred_location" id="a8" placeholder="Enter Preferred Location">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="9">Current Employer</label>
                  <input type="text" class="form-control" value="<?php echo $rs['current_employer']; ?>" name="current_employer" id="9" placeholder="Enter Current Employer">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a10">Designation</label>
                  <input type="text" class="form-control" value="<?php echo $rs['designation']; ?>" name="designation" id="a10" placeholder="Enter Designation">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a11">Education</label>
                  <input type="text" class="form-control" value="<?php echo $rs['education']; ?>" name="education" id="a11" placeholder="Enter Education">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a12">Comment 1</label>
                  <input type="text" class="form-control" value="<?php echo $rs['comment_1']; ?>" name="comment_1" id="a12" placeholder="Enter Comment">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label">Upload Resume (PDF)</label>
                  <input type="file" class="form-control" name="resume" accept="application/pdf">

                  <?php if(!empty($rs['resume'])){ ?>
                    <small class="text-muted d-block mt-1">
                      <strong>Current Resume :</strong>
                      <a href="uploads/resume/<?php echo $rs['resume']; ?>" target="_blank">
                        <?php echo $rs['resume']; ?>
                      </a>
                    </small>
                  <?php } ?>
                </div>
              </div>

              <div class="col-sm-12">
                <div class="d-flex gap-2 justify-content-end">
                  <a href="list.php" type="button" class="btn btn-outline-secondary">
                    Cancel
                  </a>
                  <button type="submit" class="btn btn-primary">Update</button>
                </div>
              </div>
            </div>
            <!-- Row ends -->
            </form>
            <?php
                }
              }
            } else{
              mysqli_error($conn);
            }
            ?>

          </div>
        </div>
      </div>
    </div>
    <!-- Row ends -->

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© Medflex admin 2024</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include '../includes/footer.php'; ?>