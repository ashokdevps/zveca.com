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
        Add Candidate
      </li>
    </ol>
    <!-- Breadcrumb ends -->
    
  </div>
  <!-- App Hero header ends -->

  <!-- App body starts -->
  <div class="app-body">

    <!-- Row starts -->
    <div class="row gx-3">
      <div class="col-sm-12">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">Add Candidate Details</h5>
          </div>
          <div class="card-body">

            <form method="POST" enctype="multipart/form-data">
            <!-- Row starts -->
            <div class="row gx-3">
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a1">Full Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="candidate_name" id="a1" placeholder="Enter Name">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a2">Mobile Number <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="contact_no" id="a2" placeholder="Enter Mobile Number">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a3">Email <span class="text-danger">*</span></label>
                  <input type="email" class="form-control" name="email" id="a3" placeholder="Enter Email">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a4">Work Exp <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="work_exp" id="a4" placeholder="Create Work Exp">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a5">Expert Key Skills </label>
                  <input type="text" class="form-control" name="expert_key_skills" id="a5" placeholder="Enter Expert Key Skills">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a6">Annual Salary <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="annual_salary" id="a6" placeholder="Enter Annual Salary">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a7">Current Location</label>
                  <input type="text" class="form-control" name="current_location" id="a7" placeholder="Enter Current Location">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a8">Preferred Location</label>
                  <input type="text" class="form-control" name="preferred_location" id="a8" placeholder="Enter Preferred Location">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="9">Current Employer</label>
                  <input type="text" class="form-control" name="current_employer" id="9" placeholder="Enter Current Employer">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a10">Designation</label>
                  <input type="text" class="form-control" name="designation" id="a10" placeholder="Enter Designation">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a11">Education</label>
                  <input type="text" class="form-control" name="education" id="a11" placeholder="Enter Education">
                </div>
              </div>
              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label" for="a12">Comment</label>
                  <input type="text" class="form-control" name="comment_1" id="a12" placeholder="Enter Comment">
                </div>
              </div>

              <div class="col-xxl-3 col-lg-4 col-sm-6">
                <div class="mb-3">
                  <label class="form-label">Upload Resume (PDF)</label>
                  <input type="file" class="form-control" name="resume" accept="application/pdf">
                </div>
              </div>

              <div class="col-sm-12">
                <div class="d-flex gap-2 justify-content-end">
                  <a href="list.php" type="button" class="btn btn-outline-secondary">
                    Cancel
                  </a>
                  <button type="submit" class="btn btn-primary">Save</button>
                </div>
              </div>
            </div>
            <!-- Row ends -->
            </form>

          </div>
        </div>
      </div>
    </div>
    <!-- Row ends -->

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© adorableit 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php
if(isset($_POST['candidate_name'])){

    $name = $_POST['candidate_name'];
    $email = $_POST['email'];
    $contact = $_POST['contact_no'];
    $work_exp = $_POST['work_exp'];
    $expert_key_skills = $_POST['expert_key_skills'];
    $annual_salary = $_POST['annual_salary'];
    $current_location = $_POST['current_location'];
    $preferred_location = $_POST['preferred_location'];
    $current_employer = $_POST['current_employer'];
    $designation = $_POST['designation'];
    $education = $_POST['education'];
    $comment_1 = $_POST['comment_1'];

    $resume_name = "";

    /* ===== resume upload ===== */

    if(isset($_FILES['resume']) && $_FILES['resume']['error'] == 0){

        $fileName = $_FILES['resume']['name'];
        $tmpName  = $_FILES['resume']['tmp_name'];

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if($ext != "pdf"){
            echo "<script>alert('Only PDF resume allowed');</script>";
            return;
        }

        $resume_name = time()."_".$fileName;
        $uploadPath = "uploads/resume/".$resume_name;

        move_uploaded_file($tmpName, $uploadPath);
    }

    /* ===== DB ===== */

    $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");

    if ($conn) {

        $cmd = "INSERT INTO candidates
        (candidate_name, email, contact_no, work_exp, expert_key_skills, annual_salary, current_location, preferred_location, current_employer, designation, education, comment_1, resume)
        VALUES
        ('$name', '$email', '$contact', '$work_exp', '$expert_key_skills', '$annual_salary', '$current_location', '$preferred_location', '$current_employer', '$designation', '$education', '$comment_1', '$resume_name')";

        $x = mysqli_query($conn, $cmd);

        if ($x) {
            echo "<script>alert('Record Saved Successfully');window.location.href='list.php';</script>";
            exit;
        } else{
            echo mysqli_error($conn);
        }

    } else{
        echo mysqli_connect_error();
    }
}

?>
<?php include '../includes/footer.php'; ?>