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
        Candidates List
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
          <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title">Candidates List</h5>
            <a href="export_xlsx.php" class="btn btn-primary ms-auto">
              <i class="ri-download-2-line"></i> Export</a>
              <form action="import_xlsx.php" method="post" enctype="multipart/form-data">
                <div class="input-group mb-3">
                    <input type="file" class="form-control" name="excel_file" id="excel_file" required>
                    <button type="submit" class="btn btn-primary" name="import_submit">Import Data</button>
                </div>
              </form>
            <a href="add.php" class="btn btn-primary ms-auto">Add Candidate</a>
          </div>
          <div class="card-body">

            <!-- Table starts -->
            <div class="table-responsive">
              <table id="basicExample" class="table truncate m-0 align-middle">
                <thead>
                  <tr>
                    <th>No.</th>
                    <th>Candidate Name</th>
                    <th>Contact No.</th>
                    <th>Email</th>
                    <th>Work Exp</th>
                    <th>Expert Key Skills</th>
                    <th>Annual Salary</th>
                    <th>Current Location</th>
                    <th>Preferred Location</th>
                    <th>Current Employer</th>
                    <th>Designation</th>
                    <th>Education</th>
                    <th>Comment</th>
                    <th>Resume</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $conn = mysqli_connect("127.0.0.1", "u918639175_admin", "u918639175_Admin", "u918639175_hrme2");
                  if ($conn == true) {
                    $cmd = "select * from candidates";
                    $r = mysqli_query($conn, $cmd);
                    if (mysqli_num_rows($r)>0) {
                      while ($rs = mysqli_fetch_assoc($r)) {
                        echo "<tr>
                    <td>".$rs["id"]."</td>
                    <td>".$rs["candidate_name"]."</td>
                    <td>".$rs["contact_no"]."</td>
                    <td>".$rs["email"]."</td>
                    <td>".$rs["work_exp"]."</td>
                    <td>".$rs["expert_key_skills"]."</td>
                    <td>".$rs["annual_salary"]."</td>
                    <td>".$rs["current_location"]."</td>
                    <td>".$rs["preferred_location"]."</td>
                    <td>".$rs["current_employer"]."</td>
                    <td>".$rs["designation"]."</td>
                    <td>".$rs["education"]."</td>
                    <td>".$rs["comment_1"]."</td>
                    <td>
                      ".(!empty($rs["resume"]) 
                      ? "<a href='".$rs["resume"]."' download
                           class='btn btn-outline-primary btn-sm'
                           title='Download Resume'>
                           <i class='ri-download-2-line'></i>
                         </a>"
                      : "—")."
                    </td>
                    <td>
                      <div class='d-inline-flex gap-1'>
                        <a href='delete.php?d=".$rs["id"]."' class='btn btn-outline-danger btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='Delete User Details'>
                          <i class='ri-delete-bin-line'></i>
                        </a>
                        <a href='edit.php?u=".$rs["id"]."' class='btn btn-outline-success btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='Edit User Details'>
                          <i class='ri-edit-box-line'></i>
                        </a>
                        <a href='show.php?s=".$rs["id"]."' class='btn btn-outline-info btn-sm'
                          data-bs-toggle='tooltip' data-bs-placement='top' data-bs-title='View List'>
                          <i class='ri-eye-line'></i>
                        </a>
                      </div>
                    </td>
                    </tr>";
                      }
                    } else {
                      echo "No Record Found!";
                    }
                  } else {
                    mysqli_error($conn);
                  }
                  ?>
                </tbody>
              </table>
            </div>
            <!-- Table ends -->

            <!-- Modal Delete Row -->
            <div class="modal fade" id="delRow" tabindex="-1" aria-labelledby="delRowLabel" aria-hidden="true">
              <div class="modal-dialog modal-sm">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="delRowLabel">
                      Confirm
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    Are you sure you want to delete the patient?
                  </div>
                  <div class="modal-footer">
                    <div class="d-flex justify-content-end gap-2">
                      <button class="btn btn-outline-secondary" data-bs-dismiss="modal"
                        aria-label="Close">No</button>
                      <button class="btn btn-danger" data-bs-dismiss="modal" aria-label="Close">Yes</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
    <!-- Row ends -->

  </div>
  <!-- App body ends -->

  <!-- App footer starts -->
  <div class="app-footer bg-white">
    <span>© Adorableit 2025</span>
  </div>
  <!-- App footer ends -->

</div>
<?php include '../includes/footer.php'; ?>